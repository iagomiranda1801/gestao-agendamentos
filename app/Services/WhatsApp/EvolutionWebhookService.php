<?php

namespace App\Services\WhatsApp;

use App\Enums\WhatsAppCampaignRecipientStatus;
use App\Jobs\HandleWhatsAppInboundMessageJob;
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
        $this->maybeDispatchInboundBot($event, $payload);

        return $event->refresh();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function maybeDispatchInboundBot(EvolutionWebhookEvent $event, array $payload): void
    {
        if (! $this->isInboundUserMessage($payload)) {
            return;
        }

        $remoteJid = (string) ($event->remote_jid ?? '');

        if ($remoteJid === '' || str_ends_with($remoteJid, '@g.us')) {
            return;
        }

        $instance = (string) ($event->instance ?? '');

        if ($instance === '') {
            return;
        }

        $phone = $this->extractPhone($remoteJid);

        if ($phone === '') {
            return;
        }

        $text = $this->extractText($payload);

        HandleWhatsAppInboundMessageJob::dispatch(
            instanceName: $instance,
            remoteJid: $remoteJid,
            phone: $phone,
            text: $text,
            messageId: (string) ($event->message_id ?? ''),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function isInboundUserMessage(array $payload): bool
    {
        $event = strtolower((string) Arr::get($payload, 'event'));

        if (! in_array($event, ['messages.upsert', 'messages-upsert'], true)) {
            return false;
        }

        $fromMe = Arr::get($payload, 'data.key.fromMe')
            ?? Arr::get($payload, 'data.0.key.fromMe')
            ?? Arr::get($payload, 'key.fromMe');

        return $fromMe === false || $fromMe === 0 || $fromMe === '0' || $fromMe === 'false';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function extractText(array $payload): string
    {
        $candidates = [
            'data.message.conversation',
            'data.0.message.conversation',
            'data.message.extendedTextMessage.text',
            'data.0.message.extendedTextMessage.text',
            'data.message.buttonsResponseMessage.selectedDisplayText',
            'data.message.listResponseMessage.title',
            'data.text',
            'message.conversation',
            'message.extendedTextMessage.text',
        ];

        foreach ($candidates as $key) {
            $value = Arr::get($payload, $key);

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return '';
    }

    protected function extractPhone(string $remoteJid): string
    {
        $digits = preg_replace('/\D+/', '', explode('@', $remoteJid)[0]) ?? '';

        return (string) $digits;
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
            ->whereIn('provider_message_id', $this->messageIds($payload))
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
            '0' => WhatsAppCampaignRecipientStatus::Failed,
            '1', 'PENDING' => WhatsAppCampaignRecipientStatus::Accepted,
            '2' => WhatsAppCampaignRecipientStatus::Sent,
            '3' => WhatsAppCampaignRecipientStatus::Delivered,
            '4', '5' => WhatsAppCampaignRecipientStatus::Read,
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
        return $this->firstString($payload, [
            'data.key.id',
            'data.0.key.id',
            'data.id',
            'data.0.id',
            'data.messageId',
            'data.0.messageId',
            'data.keyId',
            'data.0.keyId',
            'data.update.0.key.id',
            'data.0.update.0.key.id',
            'data.messages.0.key.id',
            'key.id',
            'messageId',
            'id',
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function providerStatus(array $payload): ?string
    {
        return $this->firstString($payload, [
            'data.status',
            'data.update.status',
            'data.0.status',
            'data.0.update.status',
            'data.update.0.status',
            'data.0.update.0.status',
            'data.message.status',
            'data.key.status',
            'status',
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function remoteJid(array $payload): ?string
    {
        return $this->firstString($payload, [
            'data.key.remoteJid',
            'data.0.key.remoteJid',
            'data.remoteJid',
            'data.0.remoteJid',
            'sender',
        ]);
    }

    /**
     * Evolution can send an update as an array, while the send response is a
     * single object. Keep all known message-id shapes available for matching.
     *
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    protected function messageIds(array $payload): array
    {
        $keys = [
            'data.key.id', 'data.0.key.id', 'data.id', 'data.0.id',
            'data.messageId', 'data.0.messageId', 'data.keyId', 'data.0.keyId',
            'data.update.0.key.id', 'data.0.update.0.key.id',
            'data.messages.0.key.id', 'key.id', 'messageId', 'id',
        ];

        return collect($keys)
            ->map(fn (string $key): mixed => Arr::get($payload, $key))
            ->filter(fn (mixed $value): bool => filled($value) && is_scalar($value))
            ->map(fn (mixed $value): string => (string) $value)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $keys
     */
    protected function firstString(array $payload, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = Arr::get($payload, $key);

            if (filled($value) && is_scalar($value)) {
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
