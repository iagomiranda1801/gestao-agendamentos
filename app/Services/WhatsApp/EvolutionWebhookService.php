<?php

namespace App\Services\WhatsApp;

use App\Enums\WhatsAppCampaignRecipientStatus;
use App\Models\EvolutionWebhookEvent;
use App\Models\WhatsAppCampaignRecipient;
use App\Services\WhatsApp\Campaigns\WhatsAppCampaignService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

class EvolutionWebhookService
{
    public function __construct(
        protected WhatsAppCampaignService $campaigns,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(array $payload): EvolutionWebhookEvent
    {
        $event = EvolutionWebhookEvent::create([
            'event' => $this->stringValue(Arr::get($payload, 'event')),
            'instance' => $this->stringValue(Arr::get($payload, 'instance')),
            'message_id' => $this->messageId($payload),
            'provider_status' => $this->providerStatus($payload),
            'remote_jid' => $this->remoteJid($payload),
            'payload' => $payload,
        ]);

        $this->updateCampaignRecipient($event, $payload);

        return $event->refresh();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function updateCampaignRecipient(EvolutionWebhookEvent $event, array $payload): void
    {
        if (blank($event->message_id)) {
            Log::info('Evolution webhook received without message id.', [
                'event_id' => $event->getKey(),
                'event' => $event->event,
                'instance' => $event->instance,
            ]);

            return;
        }

        $recipient = WhatsAppCampaignRecipient::query()
            ->where('provider_message_id', $event->message_id)
            ->first();

        if ($recipient === null) {
            Log::info('Evolution webhook message id not matched to campaign recipient.', [
                'event_id' => $event->getKey(),
                'message_id' => $event->message_id,
                'provider_status' => $event->provider_status,
            ]);

            return;
        }

        $status = $this->recipientStatusFromProvider($event->provider_status);

        $recipient->forceFill([
            'status' => $status,
            'sent_at' => $recipient->sent_at ?: now(),
            'provider_status' => $event->provider_status,
            'provider_response' => $payload,
            'error_message' => null,
        ])->save();

        $event->forceFill([
            'processed_at' => now(),
        ])->save();

        $this->campaigns->refreshCounters($recipient->campaign);
    }

    protected function recipientStatusFromProvider(?string $providerStatus): WhatsAppCampaignRecipientStatus
    {
        $status = strtoupper((string) $providerStatus);

        return match ($status) {
            'READ', 'PLAYED' => WhatsAppCampaignRecipientStatus::Read,
            'DELIVERED', 'DELIVERY_ACK' => WhatsAppCampaignRecipientStatus::Delivered,
            'SENT', 'SERVER_ACK', 'SEND', 'SUCCESS' => WhatsAppCampaignRecipientStatus::Sent,
            'ERROR', 'FAILED', 'FAILURE' => WhatsAppCampaignRecipientStatus::Failed,
            default => WhatsAppCampaignRecipientStatus::Accepted,
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function messageId(array $payload): ?string
    {
        foreach ([
            'data.key.id',
            'data.id',
            'data.messageId',
            'data.keyId',
            'key.id',
            'messageId',
            'id',
        ] as $key) {
            $value = Arr::get($payload, $key);

            if (filled($value)) {
                return (string) $value;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function providerStatus(array $payload): ?string
    {
        foreach ([
            'data.status',
            'data.update.status',
            'data.message.status',
            'data.key.status',
            'status',
        ] as $key) {
            $value = Arr::get($payload, $key);

            if (filled($value)) {
                return (string) $value;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function remoteJid(array $payload): ?string
    {
        foreach ([
            'data.key.remoteJid',
            'data.remoteJid',
            'sender',
        ] as $key) {
            $value = Arr::get($payload, $key);

            if (filled($value)) {
                return (string) $value;
            }
        }

        return null;
    }

    protected function stringValue(mixed $value): ?string
    {
        return filled($value) ? (string) $value : null;
    }
}
