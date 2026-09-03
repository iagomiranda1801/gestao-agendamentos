<?php

namespace App\Jobs;

use App\Enums\WhatsAppCampaignRecipientStatus;
use App\Enums\WhatsAppCampaignStatus;
use App\Enums\WhatsAppOutboundKind;
use App\Jobs\Concerns\DefersViaWhatsAppOutboundGate;
use App\Models\WhatsAppCampaign;
use App\Models\WhatsAppCampaignRecipient;
use App\Services\Scheduling\CompanySchedulingSettingService;
use App\Services\WhatsApp\Campaigns\WhatsAppCampaignService;
use App\Services\WhatsApp\CompanyWhatsAppInstanceService;
use App\Services\WhatsApp\EvolutionApiClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class SendWhatsAppCampaignRecipientJob implements ShouldQueue
{
    use DefersViaWhatsAppOutboundGate;
    use Queueable;

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

        if (! $recipient) {
            return;
        }

        $campaign = $recipient->campaign;

        if ($this->shouldWaitForQueuedStatus($recipient, $campaign)) {
            return;
        }

        if ($recipient->status !== WhatsAppCampaignRecipientStatus::Queued) {
            Log::info('WhatsApp campaign recipient job skipped.', [
                'recipient_id' => $recipient->getKey(),
                'status' => $recipient->status->value,
            ]);

            return;
        }

        if ($campaign === null) {
            return;
        }

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

        if (! $this->deferUntilOutboundSlot($campaign->company, WhatsAppOutboundKind::Marketing)) {
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

            $response = $this->sendCampaignMessage($client, $instance, $campaign, $recipient);
            $this->rememberOutboundSuccess($campaign->company);
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
            $this->rememberOutboundFailure($campaign->company);
            $recipient->forceFill([
                'status' => WhatsAppCampaignRecipientStatus::Failed,
                'attempts' => $attempts ?? ($recipient->attempts + 1),
                'attempted_at' => now(),
                'error_message' => mb_substr($exception->getMessage(), 0, 2000),
            ])->save();
        }

        $campaigns->refreshCounters($campaign);
    }

    public function failed(?Throwable $exception): void
    {
        $recipient = WhatsAppCampaignRecipient::query()
            ->with('campaign')
            ->find($this->recipientId);

        if (! $recipient || ! in_array($recipient->status, [
            WhatsAppCampaignRecipientStatus::Pending,
            WhatsAppCampaignRecipientStatus::Queued,
        ], true)) {
            return;
        }

        $recipient->forceFill([
            'status' => WhatsAppCampaignRecipientStatus::Failed,
            'attempts' => $recipient->attempts + 1,
            'attempted_at' => now(),
            'error_message' => mb_substr(
                $exception?->getMessage() ?: 'O job da campanha esgotou as tentativas na fila.',
                0,
                2000,
            ),
        ])->save();

        if ($recipient->campaign) {
            app(WhatsAppCampaignService::class)->refreshCounters($recipient->campaign);
        }
    }

    protected function shouldWaitForQueuedStatus(
        WhatsAppCampaignRecipient $recipient,
        ?WhatsAppCampaign $campaign,
    ): bool {
        if ($campaign?->status !== WhatsAppCampaignStatus::Sending) {
            return false;
        }

        if ($recipient->status !== WhatsAppCampaignRecipientStatus::Pending) {
            return false;
        }

        $recipient->forceFill([
            'status' => WhatsAppCampaignRecipientStatus::Queued,
            'queued_at' => $recipient->queued_at ?? now(),
        ])->save();

        Log::info('WhatsApp campaign recipient job released until queued status is visible.', [
            'recipient_id' => $recipient->getKey(),
            'campaign_id' => $campaign->getKey(),
        ]);

        $this->release(5);

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    protected function sendCampaignMessage(
        EvolutionApiClient $client,
        string $instance,
        WhatsAppCampaign $campaign,
        WhatsAppCampaignRecipient $recipient,
    ): array {
        if (! $campaign->hasImage()) {
            return $client->sendText($instance, $recipient->phone, $recipient->message_snapshot);
        }

        $diskName = (string) ($campaign->image_disk ?: config('filesystems.company_logo_disk', 's3'));
        $disk = Storage::disk($diskName);
        $path = (string) $campaign->image_path;

        if (! $disk->exists($path)) {
            throw new RuntimeException('A imagem da campanha não foi encontrada.');
        }

        $binary = $disk->get($path);

        if (! is_string($binary) || $binary === '') {
            throw new RuntimeException('Não foi possível ler a imagem da campanha.');
        }

        $mime = (string) ($campaign->image_mime ?: $disk->mimeType($path) ?: 'image/jpeg');
        $fileName = basename($path) ?: 'campanha.jpg';

        return $client->sendImage(
            $instance,
            $recipient->phone,
            $binary,
            $mime,
            $fileName,
            $recipient->message_snapshot,
        );
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
