<?php

namespace App\Services\ServiceOrders;

use App\Enums\CompanyModule;
use App\Jobs\SendServiceOrderWhatsAppJob;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderMessage;
use App\Models\User;
use App\Services\Company\CompanyModuleService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ServiceOrderMessagingService
{
    public function retry(ServiceOrder $order, User $user, int $messageId): ServiceOrderMessage
    {
        app(ServiceOrderService::class)->authorize($order->company, $user);
        $lock = Cache::lock('service-order-message:'.$messageId, 120);
        try {
            $lock->block(2);

            return DB::transaction(function () use ($order, $user, $messageId) {
                $message = $order->messages()->lockForUpdate()->findOrFail($messageId);
                $stale = in_array($message->status, ['queued', 'sending']) && $message->updated_at->lt(now()->subMinutes(5));
                if (! in_array($message->status, ['failed', 'uncertain']) && ! $stale) {
                    throw ValidationException::withMessages(['message_id' => 'Este envio está em andamento ou já foi aceito pelo provedor.']);
                }
                if ($message->quote_id && $message->quote_id !== $order->latestQuote()?->id) {
                    throw ValidationException::withMessages(['message_id' => 'Orçamento substituído. Envie o orçamento atual.']);
                }
                $message->update(['status' => 'failed', 'error' => 'Reenvio confirmado pelo funcionário.']);
                $order->events()->create(['description' => 'Nova tentativa da mensagem #'.$message->id.' confirmada', 'user_id' => $user->id]);

                return $this->queue($order, $user, $message->kind, $message->body);
            });
        } catch (LockTimeoutException $exception) {
            throw ValidationException::withMessages(['message_id' => 'O envio ainda está em andamento. Aguarde antes de tentar novamente.']);
        } finally {
            $lock->release();
        }
    }

    public function preview(ServiceOrder $order, string $kind): string
    {
        $quote = $order->latestQuote();
        $url = match ($kind) {
            'quote' => $quote ? route('service-orders.quote', $quote->token) : '',
            'receipt' => route('service-orders.document', ['token' => $order->receipt_token]),
            default => route('service-orders.document', ['token' => $order->document_token]),
        };
        $text = match ($kind) {
            'quote' => 'Orçamento: R$ '.number_format((float) ($quote?->total ?? 0), 2, ',', '.').'. Confira os itens, o prazo e aprove ou recuse pelo link:',
            'ready' => 'Seu aparelho está pronto para retirada. Confira a OS:',
            'receipt' => 'Confira seu comprovante e as informações de garantia:',
            default => 'Recebemos seu aparelho. Confira sua ordem de serviço:',
        };

        return $order->company->name.' — OS #'.$order->number."\n".$order->device."\n".$text."\n".$url;
    }

    public function queue(ServiceOrder $order, User $user, string $kind, string $body): ServiceOrderMessage
    {
        app(ServiceOrderService::class)->authorize($order->company, $user);
        abort_unless(app(CompanyModuleService::class)->hasModule($order->company, CompanyModule::WhatsApp), 403);
        validator(['kind' => $kind, 'body' => $body], ['kind' => 'required|in:order,quote,ready,receipt', 'body' => 'required|string|max:8000'])->validate();
        if (! $order->client->whatsapp_confirmation_opt_in) {
            throw ValidationException::withMessages(['body' => 'O cliente não autorizou mensagens operacionais no WhatsApp.']);
        }

        return DB::transaction(function () use ($order, $user, $kind, $body) {
            $order = ServiceOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (($kind === 'ready' && ! in_array($order->status, ['ready', 'delivered'])) || ($kind === 'receipt' && $order->status !== 'delivered')) {
                throw ValidationException::withMessages(['body' => 'Esta mensagem não está disponível nesta etapa.']);
            }
            if ($kind === 'quote' && (! $order->latestQuote() || ! str_contains($body, route('service-orders.quote', $order->latestQuote()->token)))) {
                throw ValidationException::withMessages(['body' => 'A mensagem precisa conter o link do orçamento atual. Feche e abra novamente Enviar orçamento.']);
            }
            $quote = $kind === 'quote' ? app(ServiceOrderService::class)->publish($order, $user) : null;
            $existing = $order->messages()->where('kind', $kind)->where('body', $body)->whereIn('status', ['queued', 'sending', 'sent', 'uncertain'])->latest('id')->first();
            if ($existing) {
                return $existing;
            }
            $message = $order->messages()->create([
                'kind' => $kind, 'quote_id' => $quote?->id,
                'recipient' => $order->client->phone_normalized, 'body' => $body,
                'status' => 'queued', 'attempts' => 0,
            ]);
            SendServiceOrderWhatsAppJob::dispatch($message->id)->afterCommit();

            return $message;
        });
    }
}
