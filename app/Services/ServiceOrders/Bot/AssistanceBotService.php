<?php

namespace App\Services\ServiceOrders\Bot;

use App\Enums\CompanyModule;
use App\Jobs\SendAssistanceBotReplyJob;
use App\Models\AssistanceBotConversation;
use App\Models\AssistanceBotFlow;
use App\Models\AssistanceBotFlowVersion;
use App\Models\AssistanceBotMessage;
use App\Models\AssistanceBotRequest;
use App\Models\Client;
use App\Models\Company;
use App\Models\CompanyWhatsAppInstance;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Services\Company\CompanyModuleService;
use App\Services\ServiceOrders\ServiceOrderService;
use App\Services\WhatsApp\WhatsAppHumanTakeover;
use App\Support\CustomerNameDetector;
use App\Support\PhoneNormalizer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AssistanceBotService
{
    public function enabled(Company $company): bool
    {
        return $company->is_active && $company->isTechnicalAssistance()
            && app(CompanyModuleService::class)->hasModule($company, CompanyModule::WhatsApp)
            && app(CompanyModuleService::class)->hasModule($company, CompanyModule::ServiceOrders)
            && AssistanceBotFlow::where('company_id', $company->id)->where('is_enabled', true)->whereNotNull('published_version_id')->exists();
    }

    public function receive(Company $company, CompanyWhatsAppInstance $instance, string $phone, string $text, ?string $messageId): void
    {
        $phone = PhoneNormalizer::normalize($phone) ?? '';
        if (! $this->enabled($company) || (int) $instance->company_id !== (int) $company->id || $phone === '' || ! filled($messageId)) {
            return;
        }

        Cache::lock($this->lockKey($instance->id, $phone), 30)->block(2, function () use ($company, $instance, $phone, $text, $messageId): void {
            DB::transaction(function () use ($company, $instance, $phone, $text, $messageId): void {
                $key = hash('sha256', $company->id.':'.$instance->id.':'.$messageId);
                if (AssistanceBotMessage::where('provider_message_key', $key)->exists()) {
                    return;
                }
                $flow = AssistanceBotFlow::where('company_id', $company->id)->firstOrFail();
                $conversation = AssistanceBotConversation::where('company_whatsapp_instance_id', $instance->id)
                    ->whereIn('phone_normalized', PhoneNormalizer::candidates($phone))->first();
                if (! $conversation) {
                    $conversation = AssistanceBotConversation::create([
                        'company_id' => $company->id, 'company_whatsapp_instance_id' => $instance->id,
                        'phone_normalized' => $phone, 'assistance_bot_flow_version_id' => $flow->published_version_id,
                    ]);
                }
                $message = $conversation->messages()->create(['provider_message_key' => $key, 'incoming' => mb_substr($text, 0, 4000)]);
                if ($conversation->human_takeover || app(WhatsAppHumanTakeover::class)->isPaused($instance->instance_name, $phone)) {
                    $message->update(['status' => 'cancelled']);

                    return;
                }

                if (! $conversation->assistance_bot_flow_version_id || ($conversation->state['ended'] ?? false)) {
                    $conversation->assistance_bot_flow_version_id = $flow->published_version_id;
                    $conversation->state = [];
                }
                $version = AssistanceBotFlowVersion::where('assistance_bot_flow_id', $flow->id)->findOrFail($conversation->assistance_bot_flow_version_id);
                $state = $conversation->state ?? [];

                if (! $conversation->client_id) {
                    // Serialise client creation across connections belonging to this company.
                    Company::whereKey($company->id)->lockForUpdate()->firstOrFail();
                    $matches = Client::where('company_id', $company->id)->whereIn('phone_normalized', PhoneNormalizer::candidates($phone))->limit(2)->get();
                    if ($matches->count() > 1) {
                        $message->update(['reply' => 'Sua mensagem ficou registrada. A loja precisa conferir seu cadastro antes de consultar os consertos.']);
                        $this->queue($message);

                        return;
                    }
                    $client = $matches->first();
                    $name = self::contactName($text);
                    if (! $client && ! ($state['_asking_name'] ?? false) && ! $name) {
                        $conversation->state = ['_asking_name' => true, '_initial_text' => mb_substr($text, 0, 1000)];
                        $conversation->save();
                        $message->update(['reply' => $version->definition['name_prompt']]);
                        $this->queue($message);

                        return;
                    }
                    if (! $client) {
                        if (! $name) {
                            $message->update(['reply' => 'Para começar, informe apenas seu nome.']);
                            $this->queue($message);

                            return;
                        }
                        $client = new Client(['name' => $name, 'phone' => $phone, 'source' => 'whatsapp',
                            'whatsapp_marketing_opt_in' => false, 'whatsapp_confirmation_opt_in' => false]);
                        $client->company_id = $company->id;
                        $client->save();
                        $client->refresh();
                    }
                    if (! $client->is_active) {
                        $message->update(['reply' => 'Sua mensagem ficou registrada para conferência do cadastro pela loja.']);
                        $this->queue($message);

                        return;
                    }
                    $conversation->client_id = $client->id;
                    $state = [];
                }

                // All actions use the resolved customer, never a client ID supplied in a block or message.
                $client = Client::where('company_id', $company->id)->where('is_active', true)->findOrFail($conversation->client_id);
                if (Str::lower(trim($text)) === 'menu') {
                    $state = [];
                }
                $result = app(AssistanceFlowEngine::class)->advance($version->definition, $state,
                    ($state['waiting'] ?? false) ? $text : null,
                    ['name' => $client->name, 'company' => $company->name, 'has_open_orders' => $this->orders($company, $client)->exists()],
                    fn (string $action, array $data, string $nodeId): string => $this->execute($conversation, $client, $action, $data, $key.':'.$nodeId));
                $conversation->state = $result['state'];
                $conversation->save();
                $message->update(['reply' => mb_substr($result['reply'], 0, 8000)]);
                $this->queue($message);
            });
        });
    }

    private function queue(AssistanceBotMessage $message): void
    {
        SendAssistanceBotReplyJob::dispatch($message->id)->afterCommit();
    }

    private function orders(Company $company, Client $client)
    {
        return ServiceOrder::where('company_id', $company->id)->where('client_id', $client->id)
            ->whereNotIn('status', ['delivered', 'returned', 'reversed']);
    }

    private function execute(AssistanceBotConversation $conversation, Client $client, string $action, array $data, string $command): string
    {
        if ($action === 'order_status') {
            $orders = $this->orders($conversation->company, $client)->latest('id')->limit(10)->get();

            return $orders->isEmpty() ? 'Nenhum conserto em andamento encontrado.' : $orders->map(fn ($order) => 'OS #'.$order->number.' · '.$order->device.' · '.ServiceOrder::STATUSES[$order->status])->implode("\n");
        }
        if (! filled($data['device'] ?? null) || ($action === 'repair_request' && ! filled($data['problem'] ?? null))
            || ($action === 'accessory_interest' && ! in_array($data['accessory_category'] ?? '', ['film', 'case'], true))) {
            return 'Faltam informações para registrar o pedido. Digite menu e informe o aparelho e os detalhes do atendimento.';
        }
        $request = AssistanceBotRequest::firstOrCreate(['command_key' => hash('sha256', $command)], [
            'company_id' => $conversation->company_id, 'assistance_bot_conversation_id' => $conversation->id,
            'client_id' => $client->id, 'kind' => $action === 'repair_request' ? 'repair' : 'accessory',
            'protocol' => 'AT-'.Str::upper(Str::random(10)), 'data' => $data,
        ]);

        return $action === 'repair_request'
            ? 'Solicitação registrada. Protocolo '.$request->protocol.'. A loja fará a avaliação ao receber o aparelho. O orçamento será informado após a análise.'
            : 'Interesse registrado. Protocolo '.$request->protocol.'. A loja vai conferir o acessório para '.$data['device'].'. Isso ainda não é uma reserva ou confirmação de estoque.';
    }

    public function pauseForPhone(string $instanceName, string $phone): void
    {
        $instance = CompanyWhatsAppInstance::where('instance_name', $instanceName)->first();
        if (! $instance?->company?->isTechnicalAssistance() || ! ($normalized = PhoneNormalizer::normalize($phone))) {
            return;
        }
        $conversations = AssistanceBotConversation::where('company_whatsapp_instance_id', $instance->id)
            ->whereIn('phone_normalized', PhoneNormalizer::candidates($phone));
        if ($conversations->exists()) {
            AssistanceBotMessage::whereIn('assistance_bot_conversation_id', (clone $conversations)->select('id'))->where('status', 'queued')->update(['status' => 'cancelled']);
            $conversations->update(['human_takeover' => true]);
        } else {
            AssistanceBotConversation::create(['company_id' => $instance->company_id, 'company_whatsapp_instance_id' => $instance->id, 'phone_normalized' => $normalized, 'human_takeover' => true]);
        }
    }

    public function setHuman(Company $company, User $user, int $id, bool $human): void
    {
        abort_unless(app(AssistanceFlowService::class)->allows($company, $user, false), 403);
        $conversation = AssistanceBotConversation::where('company_id', $company->id)->findOrFail($id);
        Cache::lock($this->lockKey($conversation->company_whatsapp_instance_id, $conversation->phone_normalized), 30)->block(2, function () use ($conversation, $human): void {
            $conversation->update(['human_takeover' => $human]);
            if ($human) {
                $conversation->messages()->where('status', 'queued')->update(['status' => 'cancelled']);
            }
            if (! $human) {
                app(WhatsAppHumanTakeover::class)->resume($conversation->instance->instance_name, $conversation->phone_normalized);
            }
        });
    }

    public function receiveDevice(Company $company, User $user, int $requestId): ServiceOrder
    {
        abort_unless(app(AssistanceFlowService::class)->allows($company, $user, false), 403);

        return DB::transaction(function () use ($company, $user, $requestId): ServiceOrder {
            $request = AssistanceBotRequest::where('company_id', $company->id)->lockForUpdate()->findOrFail($requestId);
            abort_unless($request->kind === 'repair', 422);
            if ($request->service_order_id) {
                return ServiceOrder::where('company_id', $company->id)->findOrFail($request->service_order_id);
            }
            $order = app(ServiceOrderService::class)->receive($company, $user, [
                'client_id' => $request->client_id, 'device' => mb_substr($request->data['device'], 0, 255),
                'problem' => $request->data['problem'], 'notes' => 'Solicitação WhatsApp '.$request->protocol,
            ]);
            $request->update(['service_order_id' => $order->id, 'status' => 'received']);

            return $order;
        });
    }

    public function lockKey(int $instanceId, string $phone): string
    {
        $candidates = PhoneNormalizer::candidates($phone);
        sort($candidates);

        return 'assistance-bot:'.$instanceId.':'.hash('sha256', implode(',', $candidates));
    }

    public static function contactName(string $text): ?string
    {
        $name = CustomerNameDetector::fromMessage($text);

        return $name && ! preg_match('/\b(celular|pelicula|capa|tela|conserto|quebrou|samsung|iphone|motorola)\b/u', Str::lower(Str::ascii($name))) ? $name : null;
    }
}
