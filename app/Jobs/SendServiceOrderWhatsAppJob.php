<?php

namespace App\Jobs;

use App\Enums\CompanyModule;
use App\Enums\WhatsAppOutboundKind;
use App\Jobs\Concerns\DefersViaWhatsAppOutboundGate;
use App\Models\ServiceOrderMessage;
use App\Services\Company\CompanyModuleService;
use App\Services\WhatsApp\CompanyWhatsAppInstanceService;
use App\Services\WhatsApp\EvolutionApiClient;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

class SendServiceOrderWhatsAppJob implements ShouldBeUnique, ShouldQueue
{
    use DefersViaWhatsAppOutboundGate, Queueable;

    public int $uniqueFor = 3600;

    public function __construct(public int $messageId) {}

    public function uniqueId(): string
    {
        return 'service-order-message:'.$this->messageId;
    }

    public function handle(EvolutionApiClient $client, CompanyWhatsAppInstanceService $instances): void
    {
        Cache::lock('service-order-message:'.$this->messageId, 120)->get(function () use ($client, $instances) {
            $message = ServiceOrderMessage::find($this->messageId);
            if (! $message || $message->status !== 'queued') {
                return;
            }
            $order = $message->serviceOrder;
            $company = $order->company;
            if (! $company->is_active || ! app(CompanyModuleService::class)->hasModule($company, CompanyModule::ServiceOrders) || ! app(CompanyModuleService::class)->hasModule($company, CompanyModule::WhatsApp) || ! $order->client->whatsapp_confirmation_opt_in) {
                $message->update(['status' => 'failed', 'error' => 'Envio desabilitado ou não autorizado pelo cliente.']);

                return;
            }
            if ($message->quote_id && ($order->latestQuote()?->id !== $message->quote_id || $message->quote->expired() || $message->quote->decision !== null)) {
                $message->update(['status' => 'failed', 'error' => 'Orçamento substituído, expirado ou já respondido.']);

                return;
            }
            if (($message->kind === 'ready' && ! in_array($order->status, ['ready', 'delivered'])) || ($message->kind === 'receipt' && $order->status !== 'delivered')) {
                $message->update(['status' => 'failed', 'error' => 'A etapa da OS mudou desde a solicitação do envio.']);

                return;
            }
            $instance = $instances->resolvedNameForCompany($company, allowGlobalFallback: false);
            if ($instance === '') {
                $message->update(['status' => 'failed', 'error' => 'Configure a conexão do WhatsApp e tente novamente.']);

                return;
            }
            if (! $this->deferUntilOutboundSlot($company, WhatsAppOutboundKind::Confirmation)) {
                return;
            }
            $message->update(['status' => 'sending', 'attempts' => $message->attempts + 1, 'error' => null]);
            try {
                $response = $client->sendText($instance, $message->recipient, $message->body);
                $message->update(['status' => 'sent', 'provider_reference' => data_get($response, 'key.id')]);
                $this->rememberOutboundSuccess($company);
            } catch (Throwable $exception) {
                // Uma falha de transporte pode ocorrer após o provedor aceitar o envio.
                // Não reenviar automaticamente e arriscar mensagem duplicada.
                $message->update(['status' => 'uncertain', 'error' => 'Não foi possível confirmar o envio. Confira a conversa antes de reenviar.']);
                $this->rememberOutboundFailure($company);
                report($exception);
            }
        });
    }

    public function failed(?Throwable $exception): void
    {
        $message = ServiceOrderMessage::find($this->messageId);
        if ($message && in_array($message->status, ['queued', 'sending'])) {
            $message->update(['status' => $message->status === 'sending' ? 'uncertain' : 'failed', 'error' => 'A tarefa de envio foi interrompida. Confira a conversa antes de tentar novamente.']);
        }
    }
}
