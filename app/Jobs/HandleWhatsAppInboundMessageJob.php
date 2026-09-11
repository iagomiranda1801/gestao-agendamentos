<?php

namespace App\Jobs;

use App\Enums\CompanyModule;
use App\Enums\WhatsAppOutboundKind;
use App\Jobs\Concerns\DefersViaWhatsAppOutboundGate;
use App\Models\Company;
use App\Models\CompanyWhatsAppInstance;
use App\Services\Company\CompanyModuleService;
use App\Services\Scheduling\CompanySchedulingSettingService;
use App\Services\WhatsApp\Bot\WhatsAppBookingBotService;
use App\Services\WhatsApp\EvolutionApiClient;
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
    ) {}

    public function handle(
        WhatsAppBookingBotService $bot,
        EvolutionApiClient $client,
        CompanyModuleService $modules,
        CompanySchedulingSettingService $settingsService,
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

        if (! $this->botIsAllowed($company, $modules, $settingsService)) {
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

        if ($reply === null || trim($reply) === '') {
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

    protected function resolveCompany(?CompanyWhatsAppInstance $instance): ?Company
    {
        if ($instance instanceof CompanyWhatsAppInstance) {
            return $instance->company;
        }

        return null;
    }

    protected function botIsAllowed(
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
}
