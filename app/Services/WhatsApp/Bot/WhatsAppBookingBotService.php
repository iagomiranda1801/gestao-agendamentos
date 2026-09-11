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
use App\Support\PhoneNormalizer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WhatsAppBookingBotService
{
    public const CONVERSATION_TTL_MINUTES = 30;

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
     * (duplicada ou sem texto útil).
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
        $conversation = $this->loadOrCreateConversation($company, $instance, $phone, $remoteJid);

        if ($messageId !== null && $messageId !== '' && $conversation->last_incoming_message_id === $messageId) {
            return null;
        }

        $settings = $this->settingsService->getOrCreate($company);
        $context = new BotContext($company, $conversation, $settings);
        $trimmed = trim($text);

        if ($trimmed === '') {
            return $this->applyReply($conversation, $messageId, $this->messages->unsupportedMedia(), null);
        }

        if ($this->isGlobalReset($trimmed)) {
            $reply = $this->resetConversation($conversation, $context);

            return $this->applyReply($conversation, $messageId, $reply, WhatsAppBotConversationState::Greeting);
        }

        if ($this->isExitCommand($trimmed)) {
            $conversation->finished_at = now();
            $conversation->finished_reason = 'exit';
            $conversation->last_incoming_message_id = $messageId ?: $conversation->last_incoming_message_id;
            $conversation->last_activity_at = now();
            $conversation->save();

            return "Ok, encerrei o atendimento pelo bot. Envie qualquer mensagem para começar novamente.";
        }

        $step = $this->stepFor($conversation->state ?? WhatsAppBotConversationState::Greeting);
        $action = $step->process($context, $trimmed);

        return $this->applyAction($conversation, $context, $action, $messageId);
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

    protected function loadOrCreateConversation(
        Company $company,
        ?CompanyWhatsAppInstance $instance,
        string $phone,
        string $remoteJid,
    ): WhatsAppBotConversation {
        $active = WhatsAppBotConversation::query()
            ->where('company_id', $company->getKey())
            ->where('phone_normalized', $phone)
            ->whereNull('finished_at')
            ->orderByDesc('id')
            ->first();

        if ($active !== null) {
            if ($active->expires_at !== null && $active->expires_at->isPast()) {
                $active->finished_at = now();
                $active->finished_reason = 'expired';
                $active->save();

                return $this->createConversation($company, $instance, $phone, $remoteJid);
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

        return $this->createConversation($company, $instance, $phone, $remoteJid);
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
        $lower = mb_strtolower($text);

        return in_array($lower, ['menu', 'início', 'inicio', 'começar', 'comecar', 'oi', 'olá', 'ola'], true);
    }

    protected function isExitCommand(string $text): bool
    {
        $lower = mb_strtolower($text);

        return in_array($lower, ['sair', 'cancelar tudo', 'parar'], true);
    }
}
