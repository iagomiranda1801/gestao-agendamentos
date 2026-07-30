<?php

namespace App\Jobs;

use App\Enums\WhatsAppCampaignRecipientStatus;
use App\Enums\WhatsAppCampaignStatus;
use App\Models\WhatsAppCampaignRecipient;
use App\Services\Scheduling\CompanySchedulingSettingService;
use App\Services\WhatsApp\Campaigns\WhatsAppCampaignService;
use App\Services\WhatsApp\CompanyWhatsAppInstanceService;
use App\Services\WhatsApp\EvolutionApiClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SendWhatsAppCampaignRecipientJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public int $recipientId,
    ) {}

    public function handle(
        EvolutionApiClient $client,
        CompanySchedulingSettingService $settings,
        CompanyWhatsAppInstanceService $instances,
        WhatsAppCampaignService $campaigns,
    ): void {
        $recipient = WhatsAppCampaignRecipient::query()
            ->with(['campaign.company'])
            ->find($this->recipientId);

        if (! $recipient || $recipient->status !== WhatsAppCampaignRecipientStatus::Queued) {
            return;
        }

        $campaign = $recipient->campaign;

        if ($campaign->status !== WhatsAppCampaignStatus::Sending) {
            $recipient->forceFill([
                'status' => WhatsAppCampaignRecipientStatus::Skipped,
                'error_message' => 'Campanha não está em envio.',
            ])->save();
            $campaigns->refreshCounters($campaign);

            return;
        }

        try {
            $companySetting = $settings->getOrCreate($campaign->company);
            $defaultInstance = $instances->defaultForCompany($campaign->company);
            $instance = $client->resolveInstance($defaultInstance?->instance_name ?: $companySetting->whatsapp_instance);

            $attempts = $recipient->attempts + 1;

            $recipient->forceFill([
                'attempts' => $attempts,
                'attempted_at' => now(),
                'error_message' => null,
            ])->save();

            $client->sendText($instance, $recipient->phone, $recipient->message_snapshot);

            $recipient->forceFill([
                'status' => WhatsAppCampaignRecipientStatus::Sent,
                'sent_at' => now(),
            ])->save();
        } catch (Throwable $exception) {
            $recipient->forceFill([
                'status' => WhatsAppCampaignRecipientStatus::Failed,
                'attempts' => $attempts ?? ($recipient->attempts + 1),
                'attempted_at' => now(),
                'error_message' => mb_substr($exception->getMessage(), 0, 2000),
            ])->save();
        }

        $campaigns->refreshCounters($campaign);
    }
}
