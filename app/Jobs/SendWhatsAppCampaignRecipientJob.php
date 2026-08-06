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
use Illuminate\Support\Arr;
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
            ->with(['campaign.company', 'client'])
            ->find($this->recipientId);

        if (! $recipient || $recipient->status !== WhatsAppCampaignRecipientStatus::Queued) {
            return;
        }

        $campaign = $recipient->campaign;

        if (! $recipient->client || ! $recipient->client->is_active || ! $recipient->client->whatsapp_marketing_opt_in) {
            $recipient->forceFill([
                'status' => WhatsAppCampaignRecipientStatus::Skipped,
                'error_message' => 'Cliente sem autorização ativa para campanhas WhatsApp.',
            ])->save();
            $campaigns->refreshCounters($campaign);

            return;
        }

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

            $response = $client->sendText($instance, $recipient->phone, $recipient->message_snapshot);
            $providerStatus = $this->providerStatus($response);

            $recipient->forceFill([
                'status' => $this->recipientStatusFromProvider($providerStatus),
                'sent_at' => $this->providerConfirmsSend($providerStatus) ? now() : null,
                'provider_message_id' => $this->providerMessageId($response),
                'provider_status' => $providerStatus,
                'provider_response' => $response,
            ])->save();

            if (filled($recipient->email_snapshot)) {
                SendWhatsAppCampaignEmailJob::dispatch($recipient->getKey());
            }
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

    protected function recipientStatusFromProvider(?string $providerStatus): WhatsAppCampaignRecipientStatus
    {
        if (! $this->providerConfirmsSend($providerStatus)) {
            return WhatsAppCampaignRecipientStatus::Accepted;
        }

        return WhatsAppCampaignRecipientStatus::Sent;
    }

    protected function providerConfirmsSend(?string $providerStatus): bool
    {
        if ($providerStatus === null || $providerStatus === '') {
            return true;
        }

        return in_array(strtoupper($providerStatus), [
            'SENT',
            'DELIVERED',
            'READ',
            'SERVER_ACK',
        ], true);
    }

    /**
     * @param  array<string, mixed>  $response
     */
    protected function providerStatus(array $response): ?string
    {
        $status = Arr::get($response, 'status')
            ?: Arr::get($response, 'message.status')
            ?: Arr::get($response, 'key.status');

        return filled($status) ? (string) $status : null;
    }

    /**
     * @param  array<string, mixed>  $response
     */
    protected function providerMessageId(array $response): ?string
    {
        $id = Arr::get($response, 'key.id')
            ?: Arr::get($response, 'messageId')
            ?: Arr::get($response, 'id');

        return filled($id) ? (string) $id : null;
    }
}
