<?php

namespace App\Jobs;

use App\Enums\WhatsAppOutboundKind;
use App\Jobs\Concerns\DefersViaWhatsAppOutboundGate;
use App\Models\AssistanceBotMessage;
use App\Services\ServiceOrders\Bot\AssistanceBotService;
use App\Services\WhatsApp\EvolutionApiClient;
use App\Services\WhatsApp\WhatsAppHumanTakeover;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

class SendAssistanceBotReplyJob implements ShouldQueue
{
    use DefersViaWhatsAppOutboundGate, Queueable;

    public function __construct(public int $messageId) {}

    public function handle(EvolutionApiClient $client, AssistanceBotService $bot): void
    {
        $message = AssistanceBotMessage::find($this->messageId);
        if (! $message || $message->status !== 'queued') {
            return;
        }
        $conversation = $message->conversation;
        Cache::lock($bot->lockKey($conversation->company_whatsapp_instance_id, $conversation->phone_normalized), 45)->block(2, function () use ($message, $conversation, $client, $bot): void {
            $message->refresh();
            $conversation->refresh();
            if ($message->status !== 'queued') {
                return;
            }
            if (! $bot->enabled($conversation->company) || $conversation->human_takeover || ! $message->reply
                || app(WhatsAppHumanTakeover::class)->isPaused($conversation->instance->instance_name, $conversation->phone_normalized)) {
                $message->update(['status' => 'cancelled']);

                return;
            }
            if ($conversation->messages()->where('id', '<', $message->id)->whereIn('status', ['queued', 'sending'])->exists()) {
                $this->release(3);

                return;
            }
            if (! $this->deferUntilOutboundSlot($conversation->company, WhatsAppOutboundKind::BotReply)) {
                return;
            }
            $message->update(['status' => 'sending']);
            try {
                $response = $client->sendText($conversation->instance->instance_name, $conversation->phone_normalized, $message->reply);
                $message->update(['status' => 'sent', 'provider_reference' => data_get($response, 'key.id')]);
                $this->rememberOutboundSuccess($conversation->company);
            } catch (Throwable $exception) {
                $message->update(['status' => 'uncertain']);
                $this->rememberOutboundFailure($conversation->company);
                report($exception);
            }
        });
    }

    public function failed(?Throwable $exception): void
    {
        AssistanceBotMessage::whereKey($this->messageId)->where('status', 'sending')->update(['status' => 'uncertain']);
    }
}
