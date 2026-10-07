<?php

namespace App\Jobs;

use App\Enums\CompanyModule;
use App\Enums\WhatsAppOutboundKind;
use App\Jobs\Concerns\DefersViaWhatsAppOutboundGate;
use App\Models\Company;
use App\Models\CompanyWhatsAppInstance;
use App\Services\Beauty\BeautyAIConversationService;
use App\Services\Company\CompanyModuleService;
use App\Services\Scheduling\CompanySchedulingSettingService;
use App\Services\Tattoo\TattooAIConversationService;
use App\Services\Tattoo\TattooWhatsAppBotService;
use App\Services\WhatsApp\Bot\WhatsAppBookingBotService;
use App\Services\WhatsApp\Bot\WhatsAppOrderLinkBotService;
use App\Services\WhatsApp\EvolutionApiClient;
use App\Services\WhatsApp\WhatsAppHumanTakeover;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class HandleWhatsAppInboundMessageJob implements ShouldQueue
{
    use DefersViaWhatsAppOutboundGate;
    use Queueable;

    public function __construct(
        public string $instanceName,
        public string $remoteJid,
        public string $phone,
        public string $text,
        public ?string $messageId = null,
        public ?string $imageMime = null,
    ) {}

    public function handle(
        WhatsAppBookingBotService $bot,
        EvolutionApiClient $client,
        CompanyModuleService $modules,
        CompanySchedulingSettingService $settingsService,
        WhatsAppOrderLinkBotService $orderLinkBot,
    ): void {
        if (trim($this->phone) === '' || trim($this->instanceName) === '') {
            return;
        }

        $instance = CompanyWhatsAppInstance::query()
            ->where('instance_name', $this->instanceName)
            ->first();

        $company = $this->resolveCompany($instance);

        if (! $company instanceof Company) {
            Log::info('WhatsApp bot: company not resolved for instance.', [
                'instance' => $this->instanceName,
                'phone' => $this->phone,
            ]);

            return;
        }

        if ($company->isTattooStudio() && (bool) $settingsService->getOrCreate($company)->tattoo_ai_enabled) {
            if (! $company->is_active || ! $modules->hasModule($company, CompanyModule::WhatsApp)) {
                return;
            }
            app(TattooAIConversationService::class)->handle($company, $instance, $this->remoteJid,
                $this->phone, $this->text, $this->messageId, $this->imageMime,
                app(WhatsAppHumanTakeover::class)->isPaused($this->instanceName, $this->phone));

            return;
        }

        if ($company->isSalon() && (bool) $settingsService->getOrCreate($company)->beauty_ai_enabled) {
            $settings = $settingsService->getOrCreate($company);
            $disabledReason = match (true) {
                ! $company->is_active => 'inactive_company',
                ! $modules->hasModule($company, CompanyModule::WhatsApp) => 'whatsapp_module_disabled',
                ! $modules->hasModule($company, CompanyModule::Scheduling) => 'scheduling_module_disabled',
                ! (bool) $settings->public_booking_enabled => 'public_booking_disabled',
                default => null,
            };
            if ($disabledReason !== null) {
                Log::info('WhatsApp beauty AI: disabled for company.', [
                    'company_id' => $company->getKey(),
                    'instance' => $this->instanceName,
                    'reason' => $disabledReason,
                ]);

                return;
            }
            app(BeautyAIConversationService::class)->handle($company, $instance, $this->remoteJid,
                $this->phone, $this->text, $this->messageId, $this->imageMime,
                app(WhatsAppHumanTakeover::class)->isPaused($this->instanceName, $this->phone));

            return;
        }

        if ($this->humanIsHandlingConversation()) {
            return;
        }

        if ($orderLinkBot->prefersThisBot($company)) {
            if (! $orderLinkBot->canReply($company)) {
                Log::info('WhatsApp order-link bot: disabled for company.', [
                    'company_id' => $company->getKey(),
                    'instance' => $this->instanceName,
                ]);

                return;
            }

            $reply = $orderLinkBot->handleIncoming($company, $this->phone, $this->text, $this->messageId);
            $this->sendReply($company, $client, $reply);

            return;
        }

        if ($company->isTattooStudio()) {
            $disabledReason = match (true) {
                ! $company->is_active => 'inactive_company',
                ! $modules->hasModule($company, CompanyModule::WhatsApp) => 'whatsapp_module_disabled',
                ! $modules->hasModule($company, CompanyModule::Scheduling) => 'scheduling_module_disabled',
                ! (bool) $settingsService->getOrCreate($company)->whatsapp_bot_enabled => 'bot_disabled',
                default => null,
            };
            if ($disabledReason !== null) {
                Log::info('WhatsApp tattoo bot: disabled for company.', [
                    'company_id' => $company->getKey(),
                    'instance' => $this->instanceName,
                    'reason' => $disabledReason,
                ]);

                return;
            }
            $reply = app(TattooWhatsAppBotService::class)->handleIncoming($company, $instance, $this->remoteJid, $this->phone, $this->text, $this->messageId, $this->imageMime);
            $this->sendReply($company, $client, $reply);

            return;
        }

        if (! $this->bookingBotIsAllowed($company, $modules, $settingsService)) {
            Log::info('WhatsApp bot: disabled for company.', [
                'company_id' => $company->getKey(),
                'instance' => $this->instanceName,
            ]);

            return;
        }

        $reply = $bot->handleIncoming(
            company: $company,
            instance: $instance,
            remoteJid: $this->remoteJid,
            rawPhone: $this->phone,
            text: $this->text,
            messageId: $this->messageId,
        );

        $this->sendReply($company, $client, $reply);
    }

    protected function humanIsHandlingConversation(): bool
    {
        if (! app(WhatsAppHumanTakeover::class)->isPaused($this->instanceName, $this->phone)) {
            return false;
        }

        Log::info('WhatsApp bot: skipped, business is replying manually.', [
            'instance' => $this->instanceName,
            'phone' => $this->phone,
        ]);

        return true;
    }

    protected function resolveCompany(?CompanyWhatsAppInstance $instance): ?Company
    {
        if ($instance instanceof CompanyWhatsAppInstance) {
            return $instance->company;
        }

        return null;
    }

    protected function bookingBotIsAllowed(
        Company $company,
        CompanyModuleService $modules,
        CompanySchedulingSettingService $settingsService,
    ): bool {
        if (! $company->is_active) {
            return false;
        }

        if (! $modules->hasModule($company, CompanyModule::WhatsApp)) {
            return false;
        }

        if (! $modules->hasModule($company, CompanyModule::Scheduling)) {
            return false;
        }

        $settings = $settingsService->getOrCreate($company);

        if (! (bool) ($settings->public_booking_enabled ?? false)) {
            return false;
        }

        if (! (bool) ($settings->whatsapp_bot_enabled ?? false)) {
            return false;
        }

        return true;
    }

    protected function sendReply(Company $company, EvolutionApiClient $client, ?string $reply): void
    {
        if ($reply === null || trim($reply) === '') {
            return;
        }

        if ($this->humanIsHandlingConversation()) {
            return;
        }

        if (! $this->deferUntilOutboundSlot($company, WhatsAppOutboundKind::BotReply)) {
            Log::info('WhatsApp bot reply deferred.', [
                'company_id' => $company->getKey(),
                'retry_in_seconds' => $this->whatsappOutboundRetrySeconds,
            ]);

            return;
        }

        try {
            $client->sendText($this->instanceName, $this->phone, $reply);
            $this->rememberOutboundSuccess($company);
        } catch (Throwable $exception) {
            Log::warning('WhatsApp bot reply failed.', [
                'company_id' => $company->getKey(),
                'error' => $exception->getMessage(),
            ]);
            $this->rememberOutboundFailureAndMaybeRethrow($company, $exception);
        }
    }
}
