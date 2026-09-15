<?php

namespace App\Services\WhatsApp\Bot;

use App\Enums\WhatsAppBotConversationState;
use App\Models\Company;
use App\Models\CompanyWhatsAppInstance;
use App\Models\WhatsAppBotConversation;
use App\Services\Scheduling\CompanySchedulingSettingService;
use App\Services\WhatsApp\Bot\Steps\AcceptTermsStep;
use App\Services\WhatsApp\Bot\Steps\ChooseDateStep;
use App\Services\WhatsApp\Bot\Steps\ChooseProfessionalStep;
use App\Services\WhatsApp\Bot\Steps\ChooseServiceStep;
use App\Services\WhatsApp\Bot\Steps\ChooseTimeStep;
use App\Services\WhatsApp\Bot\Steps\CollectEmailStep;
use App\Services\WhatsApp\Bot\Steps\CollectNameStep;
use App\Services\WhatsApp\Bot\Steps\ConfirmStep;
use App\Services\WhatsApp\Bot\Steps\GreetingStep;
use App\Support\CompanyDateTime;
use App\Support\PhoneNormalizer;
use App\Support\WhatsAppInboundText;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class WhatsAppBookingBotService
{
    public const CONVERSATION_TTL_MINUTES = 30;

    public const GREETING_REPEAT_COOLDOWN_SECONDS = 900;

    public const SENT_KEY_GRACE_MINUTES = 30;

    /**
     * @var list<string>
     */
    public const STRONG_RESTART_PHRASES = [
        'menu',
        'inicio',
        'início',
        'comecar',
        'começar',
        'agendar',
        'agendamento',
        'marcar',
    ];

    public function __construct(
        protected CompanySchedulingSettingService $settingsService,
        protected WhatsAppBookingBotMessageBuilder $messages,
        protected GreetingStep $greetingStep,
        protected ChooseServiceStep $chooseServiceStep,
        protected ChooseProfessionalStep $chooseProfessionalStep,
        protected ChooseDateStep $chooseDateStep,
        protected ChooseTimeStep $chooseTimeStep,
        protected CollectNameStep $collectNameStep,
        protected CollectEmailStep $collectEmailStep,
        protected AcceptTermsStep $acceptTermsStep,
        protected ConfirmStep $confirmStep,
    ) {}

    /**
     * Processa uma mensagem recebida.
     *
     * Retorna a resposta a enviar, ou null se a mensagem deve ser ignorada
     * (duplicada, cooldown, ou sem texto útil).
     */
    public function handleIncoming(
        Company $company,
        ?CompanyWhatsAppInstance $instance,
        string $remoteJid,
        string $rawPhone,
        string $text,
        ?string $messageId = null,
    ): ?string {
        $phone = PhoneNormalizer::normalize($rawPhone);

        if ($phone === null) {
            return null;
        }

        $lock = Cache::lock("wa:bot:{$company->getKey()}:{$phone}", 10);

        try {
            $lock->block(5);

            return $this->processLocked($company, $instance, $remoteJid, $phone, $text, $messageId);
        } finally {
            optional($lock)->release();
        }
    }

    protected function processLocked(
        Company $company,
        ?CompanyWhatsAppInstance $instance,
        string $remoteJid,
        string $phone,
        string $text,
        ?string $messageId,
    ): ?string {
        if ($messageId !== null && $messageId !== '') {
            $messageKey = "wa:bot-msgid:{$company->getKey()}:{$messageId}";

            if (! Cache::add($messageKey, true, now()->addMinutes(30))) {
                Log::info('WhatsApp booking bot: suppressed (duplicate message id).', [
                    'company_id' => $company->getKey(),
                    'phone' => $phone,
                    'message_id' => $messageId,
                ]);

                return null;
            }
        }

        $conversation = $this->findActiveConversation($company, $instance, $phone, $remoteJid);

        if ($conversation === null) {
            if (! $this->shouldStartNewConversation($company, $phone, $text)) {
                return null;
            }

            $conversation = $this->createConversation($company, $instance, $phone, $remoteJid);
        } elseif ($messageId !== null && $messageId !== '' && $conversation->last_incoming_message_id === $messageId) {
            return null;
        }

        $settings = $this->settingsService->getOrCreate($company);
        $context = new BotContext($company, $conversation, $settings);
        $trimmed = trim($text);

        if ($trimmed === '') {
            $this->persistConversation($conversation, $messageId);
            Log::info('WhatsApp booking bot: suppressed (empty text).', [
                'company_id' => $company->getKey(),
                'phone' => $phone,
            ]);

            return null;
        }

        if ($this->isGlobalReset($trimmed) || ($conversation->wasRecentlyCreated && $this->isExplicitBookingIntent($trimmed))) {
            return $this->handleGlobalReset($company, $phone, $conversation, $context, $messageId);
        }

        if ($this->isExitCommand($trimmed)) {
            $conversation->finished_at = now();
            $conversation->finished_reason = 'exit';
            $conversation->last_incoming_message_id = $messageId ?: $conversation->last_incoming_message_id;
            $conversation->last_activity_at = now();
            $conversation->save();

            return 'Ok, encerrei o atendimento pelo bot. Envie *menu* ou *agendar* para começar novamente.';
        }

        $currentState = $conversation->state ?? WhatsAppBotConversationState::Greeting;
        $step = $this->stepFor($currentState);
        $action = $step->process($context, $trimmed);

        if ($currentState === WhatsAppBotConversationState::Greeting && $action->kind === 'stay') {
            $this->persistConversation($conversation, $messageId);
            Log::info('WhatsApp booking bot: suppressed (unrecognized text at greeting).', [
                'company_id' => $company->getKey(),
                'phone' => $phone,
            ]);

            return null;
        }

        return $this->applyAction($conversation, $context, $action, $messageId);
    }

    protected function handleGlobalReset(
        Company $company,
        string $phone,
        WhatsAppBotConversation $conversation,
        BotContext $context,
        ?string $messageId,
    ): ?string {
        if ($this->alreadyShowingGreeting($conversation)) {
            $this->persistConversation($conversation, $messageId);
            Log::info('WhatsApp booking bot: suppressed (already at greeting).', [
                'company_id' => $company->getKey(),
                'phone' => $phone,
            ]);

            return null;
        }

        $reply = $this->resetConversation($conversation, $context);
        $this->markGreetingSent($conversation, $company, $phone);

        return $this->applyReply($conversation, $messageId, $reply, WhatsAppBotConversationState::Greeting);
    }

    protected function applyAction(
        WhatsAppBotConversation $conversation,
        BotContext $context,
        BotAction $action,
        ?string $messageId,
    ): string {
        return DB::transaction(function () use ($conversation, $context, $action, $messageId): string {
            $currentState = $conversation->state ?? WhatsAppBotConversationState::Greeting;

            if ($action->dataUpdates !== []) {
                $data = is_array($conversation->data) ? $conversation->data : [];
                $conversation->data = array_replace($data, $action->dataUpdates);
                $context = new BotContext($context->company, $conversation, $context->settings);
            }

            switch ($action->kind) {
                case 'stay':
                    $reply = $action->errorMessage ?? $this->stepFor($currentState)->prompt($context);
                    break;

                case 'go_to':
                    $conversation->state = $action->nextState ?? $currentState;
                    $reply = $this->stepFor($conversation->state)->prompt($context);
                    break;

                case 'finish':
                    $conversation->state = WhatsAppBotConversationState::Done;
                    $conversation->finished_at = now();
                    $conversation->finished_reason = $action->finishedReason ?? 'completed';

                    if ($action->appointmentId !== null) {
                        $conversation->appointment_id = $action->appointmentId;
                    }

                    $reply = (string) $action->closingMessage;
                    break;

                case 'abandon':
                    $conversation->finished_at = now();
                    $conversation->finished_reason = $action->finishedReason ?? 'abandoned';
                    $reply = (string) $action->closingMessage;
                    break;

                default:
                    $reply = $this->messages->invalidOption();
            }

            $this->persistConversation($conversation, $messageId);

            return $reply;
        });
    }

    protected function applyReply(
        WhatsAppBotConversation $conversation,
        ?string $messageId,
        string $reply,
        ?WhatsAppBotConversationState $newState,
    ): string {
        if ($newState !== null) {
            $conversation->state = $newState;
        }

        $this->persistConversation($conversation, $messageId);

        return $reply;
    }

    protected function persistConversation(WhatsAppBotConversation $conversation, ?string $messageId): void
    {
        if ($messageId !== null && $messageId !== '') {
            $conversation->last_incoming_message_id = $messageId;
        }

        $conversation->last_activity_at = now();

        if ($conversation->finished_at === null) {
            $conversation->expires_at = now()->addMinutes(self::CONVERSATION_TTL_MINUTES);
        }

        $conversation->save();
    }

    protected function resetConversation(WhatsAppBotConversation $conversation, BotContext $context): string
    {
        $conversation->state = WhatsAppBotConversationState::Greeting;
        $conversation->data = [];
        $conversation->finished_at = null;
        $conversation->finished_reason = null;
        $conversation->appointment_id = null;

        return $this->greetingStep->prompt(new BotContext(
            $context->company,
            $conversation,
            $context->settings,
        ));
    }

    protected function findActiveConversation(
        Company $company,
        ?CompanyWhatsAppInstance $instance,
        string $phone,
        string $remoteJid,
    ): ?WhatsAppBotConversation {
        $active = WhatsAppBotConversation::query()
            ->where('company_id', $company->getKey())
            ->where('phone_normalized', $phone)
            ->whereNull('finished_at')
            ->orderByDesc('id')
            ->first();

        if ($active === null) {
            return null;
        }

        if ($active->expires_at !== null && $active->expires_at->isPast()) {
            $active->finished_at = now();
            $active->finished_reason = 'expired';
            $active->save();

            return null;
        }

        if ($instance !== null && $active->company_whatsapp_instance_id === null) {
            $active->company_whatsapp_instance_id = $instance->getKey();
            $active->save();
        }

        if ($remoteJid !== '' && $active->remote_jid === null) {
            $active->remote_jid = $remoteJid;
            $active->save();
        }

        return $active;
    }

    protected function createConversation(
        Company $company,
        ?CompanyWhatsAppInstance $instance,
        string $phone,
        string $remoteJid,
    ): WhatsAppBotConversation {
        $conversation = new WhatsAppBotConversation([
            'phone_normalized' => $phone,
            'remote_jid' => $remoteJid !== '' ? $remoteJid : null,
            'state' => WhatsAppBotConversationState::Greeting,
            'data' => ['idempotency_uuid' => (string) Str::uuid()],
            'last_activity_at' => now(),
            'expires_at' => now()->addMinutes(self::CONVERSATION_TTL_MINUTES),
        ]);
        $conversation->company()->associate($company);

        if ($instance !== null) {
            $conversation->instance()->associate($instance);
        }

        $conversation->save();

        return $conversation->refresh();
    }

    protected function shouldStartNewConversation(Company $company, string $phone, string $text): bool
    {
        $trimmed = trim($text);
        $hasPrior = $this->latestConversation($company, $phone) !== null;
        $isNumericOption = in_array($trimmed, ['0', '1'], true);
        $isStartIntent = $this->isGlobalReset($trimmed) || $this->isExplicitBookingIntent($trimmed);

        if (! $hasPrior) {
            if ($isStartIntent || $isNumericOption) {
                return true;
            }

            Log::info('WhatsApp booking bot: suppressed (not a start intent).', [
                'company_id' => $company->getKey(),
                'phone' => $phone,
            ]);

            return false;
        }

        if ($this->greetedRecently($company, $phone)) {
            Log::info('WhatsApp booking bot: suppressed (greeting cooldown).', [
                'company_id' => $company->getKey(),
                'phone' => $phone,
            ]);

            return false;
        }

        if ($this->isStrongRestartCommand($trimmed)) {
            return true;
        }

        if ($this->isGlobalReset($trimmed) && ! $this->alreadyGreetedToday($company, $phone)) {
            return true;
        }

        Log::info('WhatsApp booking bot: suppressed (already greeted today or casual inbound).', [
            'company_id' => $company->getKey(),
            'phone' => $phone,
            'local_date' => CompanyDateTime::nowLocal($company)->toDateString(),
            'timezone' => CompanyDateTime::timezone($company),
        ]);

        return false;
    }

    protected function latestConversation(Company $company, string $phone): ?WhatsAppBotConversation
    {
        return WhatsAppBotConversation::query()
            ->where('company_id', $company->getKey())
            ->where('phone_normalized', $phone)
            ->orderByDesc('id')
            ->first();
    }

    protected function alreadyShowingGreeting(WhatsAppBotConversation $conversation): bool
    {
        $state = $conversation->state ?? WhatsAppBotConversationState::Greeting;
        $data = is_array($conversation->data) ? $conversation->data : [];

        return $state === WhatsAppBotConversationState::Greeting
            && filled($data['greeting_sent_at'] ?? null);
    }

    protected function markGreetingSent(WhatsAppBotConversation $conversation, Company $company, string $phone): void
    {
        $data = is_array($conversation->data) ? $conversation->data : [];
        $data['greeting_sent_at'] = now()->toIso8601String();
        $conversation->data = $data;

        $this->rememberGreeting($company, $phone);
    }

    protected function rememberGreeting(Company $company, string $phone): void
    {
        $localNow = CompanyDateTime::nowLocal($company);
        $localDate = $localNow->toDateString();

        Cache::put(
            $this->dailyGreetingKey($company, $phone, $localDate),
            true,
            $localNow->endOfDay()->addMinutes(self::SENT_KEY_GRACE_MINUTES),
        );
        Cache::put(
            $this->recentGreetingKey($company, $phone),
            true,
            now()->addSeconds(self::GREETING_REPEAT_COOLDOWN_SECONDS),
        );
    }

    protected function alreadyGreetedToday(Company $company, string $phone): bool
    {
        $localNow = CompanyDateTime::nowLocal($company);
        $localDate = $localNow->toDateString();

        if (Cache::has($this->dailyGreetingKey($company, $phone, $localDate))) {
            return true;
        }

        $sentAt = $this->latestGreetingSentAt($company, $phone);

        if ($sentAt === null) {
            return false;
        }

        return CompanyDateTime::utcToLocal($company, $sentAt)->toDateString() === $localDate;
    }

    protected function greetedRecently(Company $company, string $phone): bool
    {
        if (Cache::has($this->recentGreetingKey($company, $phone))) {
            return true;
        }

        $sentAt = $this->latestGreetingSentAt($company, $phone);

        if ($sentAt === null) {
            return false;
        }

        return $sentAt->gte(now()->subSeconds(self::GREETING_REPEAT_COOLDOWN_SECONDS));
    }

    protected function latestGreetingSentAt(Company $company, string $phone): ?CarbonImmutable
    {
        $latest = $this->latestConversation($company, $phone);
        $sentAt = is_array($latest?->data) ? ($latest->data['greeting_sent_at'] ?? null) : null;

        if (! is_string($sentAt) || $sentAt === '') {
            return null;
        }

        return CarbonImmutable::parse($sentAt);
    }

    protected function dailyGreetingKey(Company $company, string $phone, string $localDate): string
    {
        return "wa:bot-greeted:{$company->getKey()}:{$phone}:{$localDate}";
    }

    protected function recentGreetingKey(Company $company, string $phone): string
    {
        return "wa:bot-greeted-recent:{$company->getKey()}:{$phone}";
    }

    protected function stepFor(WhatsAppBotConversationState $state): BotStep
    {
        return match ($state) {
            WhatsAppBotConversationState::Greeting => $this->greetingStep,
            WhatsAppBotConversationState::ChoosingService => $this->chooseServiceStep,
            WhatsAppBotConversationState::ChoosingProfessional => $this->chooseProfessionalStep,
            WhatsAppBotConversationState::ChoosingDate => $this->chooseDateStep,
            WhatsAppBotConversationState::ChoosingTime => $this->chooseTimeStep,
            WhatsAppBotConversationState::CollectingName => $this->collectNameStep,
            WhatsAppBotConversationState::CollectingEmail => $this->collectEmailStep,
            WhatsAppBotConversationState::AcceptingTerms => $this->acceptTermsStep,
            WhatsAppBotConversationState::Confirming => $this->confirmStep,
            WhatsAppBotConversationState::Done => $this->greetingStep,
        };
    }

    protected function isGlobalReset(string $text): bool
    {
        return WhatsAppInboundText::containsAnyPhrase($text, [
            'menu',
            'inicio',
            'início',
            'comecar',
            'começar',
            'oi',
            'olá',
            'ola',
        ]);
    }

    protected function isExplicitBookingIntent(string $text): bool
    {
        return WhatsAppInboundText::containsAnyPhrase($text, ['agendar', 'agendamento', 'marcar', 'horario', 'horário', 'agenda']);
    }

    protected function isStrongRestartCommand(string $text): bool
    {
        return WhatsAppInboundText::containsAnyPhrase($text, self::STRONG_RESTART_PHRASES);
    }

    protected function isExitCommand(string $text): bool
    {
        $lower = mb_strtolower(trim($text));

        return in_array($lower, ['sair', 'cancelar tudo', 'parar'], true);
    }
}
