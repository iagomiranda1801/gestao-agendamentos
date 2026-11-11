<?php

namespace App\Services\CarWash;

use App\Enums\CompanyModule;
use App\Enums\CompanyRole;
use App\Jobs\SendCarWashBotReplyJob;
use App\Models\CarWashBotConversation;
use App\Models\CarWashBotFlow;
use App\Models\CarWashBotFlowVersion;
use App\Models\CarWashBotMessage;
use App\Models\Client;
use App\Models\Company;
use App\Models\CompanySchedulingSetting;
use App\Models\CompanyWhatsAppInstance;
use App\Models\Service;
use App\Services\AI\CompanyAIService;
use App\Services\Beauty\BeautyAISchedulingService;
use App\Services\Client\ClientService;
use App\Services\Company\CompanyModuleService;
use App\Services\WhatsApp\WhatsAppHumanTakeover;
use App\Support\CustomerNameDetector;
use App\Support\PhoneNormalizer;
use App\Support\VehiclePlate;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class CarWashBotService
{
    public function enabled(Company $company): bool
    {
        return $company->is_active && $company->isCarWash()
            && app(CompanyModuleService::class)->hasModule($company, CompanyModule::WhatsApp)
            && app(CompanyModuleService::class)->hasModule($company, CompanyModule::Scheduling)
            && CarWashBotFlow::where('company_id', $company->id)->where('is_enabled', true)->whereNotNull('published_version_id')->exists();
    }

    public function receive(Company $company, CompanyWhatsAppInstance $instance, string $phone, string $text, ?string $messageId): void
    {
        $phone = PhoneNormalizer::normalize($phone) ?? '';
        if (! $this->enabled($company) || (int) $instance->company_id !== (int) $company->id || $phone === '' || ! filled($messageId)) {
            return;
        }

        Cache::lock($this->lockKey($instance->id, $phone), 30)->block(2, function () use ($company, $instance, $phone, $text, $messageId): void {
            $pending = null;
            DB::transaction(function () use ($company, $instance, $phone, $text, $messageId, &$pending): void {
                $key = hash('sha256', $company->id.':'.$instance->id.':'.$messageId);
                if (CarWashBotMessage::where('provider_message_key', $key)->exists()) {
                    return;
                }
                $flow = CarWashBotFlow::where('company_id', $company->id)->firstOrFail();
                $conversation = CarWashBotConversation::where('company_whatsapp_instance_id', $instance->id)
                    ->whereIn('phone_normalized', PhoneNormalizer::candidates($phone))->first();
                if (! $conversation) {
                    $conversation = CarWashBotConversation::create([
                        'company_id' => $company->id, 'company_whatsapp_instance_id' => $instance->id,
                        'phone_normalized' => $phone, 'car_wash_bot_flow_version_id' => $flow->published_version_id,
                    ]);
                }
                $message = $conversation->messages()->create(['provider_message_key' => $key, 'incoming' => mb_substr($text, 0, 4000)]);
                if ($conversation->human_takeover || app(WhatsAppHumanTakeover::class)->isPaused($instance->instance_name, $phone)) {
                    $message->update(['status' => 'cancelled']);

                    return;
                }
                if (! $conversation->car_wash_bot_flow_version_id || ($conversation->state['ended'] ?? false)) {
                    $conversation->car_wash_bot_flow_version_id = $flow->published_version_id;
                    $conversation->state = [];
                }
                $version = CarWashBotFlowVersion::where('car_wash_bot_flow_id', $flow->id)->findOrFail($conversation->car_wash_bot_flow_version_id);
                $state = $conversation->state ?? [];

                if (! $conversation->client_id) {
                    Company::whereKey($company->id)->lockForUpdate()->firstOrFail();
                    $matches = Client::where('company_id', $company->id)->whereIn('phone_normalized', PhoneNormalizer::candidates($phone))->limit(2)->get();
                    if ($matches->count() > 1) {
                        $message->update(['reply' => 'Sua mensagem ficou registrada. A equipe precisa conferir seu cadastro antes de seguir.']);
                        $this->queue($message);

                        return;
                    }
                    $client = $matches->first();
                    $name = self::contactName($text);
                    if (! $client && ! ($state['_asking_name'] ?? false) && ! $name) {
                        $conversation->state = ['_asking_name' => true];
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
                        $message->update(['reply' => 'Sua mensagem ficou registrada para conferência do cadastro pela equipe.']);
                        $this->queue($message);

                        return;
                    }
                    $conversation->client_id = $client->id;
                    $state = [];
                }

                $client = Client::where('company_id', $company->id)->where('is_active', true)->findOrFail($conversation->client_id);
                if (Str::lower(trim($text)) === 'menu') {
                    $state = [];
                }
                $result = app(CarWashFlowEngine::class)->advance(
                    $version->definition,
                    $state,
                    ($state['waiting'] ?? false) ? $text : null,
                    ['name' => $client->name, 'company' => $company->name, 'plate' => $client->vehicle_plate],
                    fn (string $action, array $flowState, string $nodeId): array => $this->execute($company, $client, $action, $flowState, $key.':'.$nodeId),
                    fn (array $node, string $incoming, array $flowState): array => $this->interpret($company, $node, $incoming, $flowState),
                );
                $conversation->state = $result['state'];
                if (! empty($result['state']['handoff'])) {
                    $conversation->human_takeover = true;
                }
                if (! empty($result['state']['booked_client_id'])) {
                    $conversation->client_id = $result['state']['booked_client_id'];
                }
                $conversation->save();
                $message->update(['reply' => mb_substr($result['reply'], 0, 8000)]);
                $this->queue($message);
                if ($result['notify'] !== null) {
                    $pending = [$result['notify'], (string) $result['excerpt']];
                }
            });
            if ($pending !== null) {
                $this->notifyTeam($company, $phone, $pending[1], $pending[0]);
            }
        });
    }

    /** @param  array<string, mixed>  $state */
    public function execute(Company $company, Client $client, string $action, array $state, string $command): array
    {
        $services = app(BeautyAISchedulingService::class)->services($company);
        if ($action === 'list_services') {
            return ['reply' => $services->isEmpty()
                ? 'Ainda não há lavagem disponível para agendar por aqui.'
                : "A gente faz:\n".$this->numbered($services->pluck('name')->take(12)->all())];
        }
        if ($action === 'show_prices') {
            $settings = CompanySchedulingSetting::where('company_id', $company->id)->first();
            if (! $settings?->show_service_price) {
                return ['reply' => 'Os valores a equipe informa por aqui. Posso te ajudar a marcar um horário.'];
            }
            $priced = $services->filter(fn (Service $service) => (float) $service->price > 0)->take(12);
            if ($priced->isEmpty()) {
                return ['reply' => 'Os valores a equipe informa por aqui.'];
            }

            return ['reply' => "Os valores daqui:\n".$this->numbered($priced->map(
                fn (Service $service) => $service->name.' '.$this->money((float) $service->price)
            )->all())];
        }
        if ($action === 'handoff') {
            return ['reply' => 'Vou chamar a equipe do lava-jato pra falar com você.', 'state' => ['handoff' => true], 'stop' => true];
        }

        $service = $services->firstWhere('id', (int) ($state['data']['service_id'] ?? 0));
        if (! $service instanceof Service) {
            return ['reply' => 'Não encontrei essa lavagem para agendar. Digite menu e escolha de novo.', 'stop' => true];
        }
        if ($action === 'offer_slots') {
            $slots = app(BeautyAISchedulingService::class)->slots($company, $service, null);
            if ($slots === []) {
                return ['reply' => 'No momento não achei horário livre. Vou chamar a equipe pra te encaixar.', 'state' => ['handoff' => true], 'stop' => true];
            }
            $lines = [];
            foreach ($slots as $i => $slot) {
                $lines[] = ($i + 1).'. '.$slot['label'];
            }

            return ['reply' => "Pra {$service->name}, tenho estes horários:\n".implode("\n", $lines), 'state' => ['data' => [
                'offered_slots' => $slots,
                'service_name' => $service->name,
                'booking_uuid' => $state['data']['booking_uuid'] ?? (string) Str::uuid(),
            ]]];
        }
        if ($action === 'book') {
            $slot = $state['data']['selected_slot'] ?? null;
            $plate = $state['data']['plate'] ?? null;
            if (! is_array($slot) || empty($slot['value']) || ! is_string($plate)) {
                return ['reply' => 'Faltam a placa ou o horário. Digite menu para começar de novo.', 'stop' => true];
            }
            try {
                $result = app(BeautyAISchedulingService::class)->book(
                    $company, $service, (int) $slot['professional_id'], (string) $slot['value'],
                    $client->name, $client->phone_normalized ?: $client->phone, $client->email,
                    (string) ($state['data']['booking_uuid'] ?? $command),
                );
            } catch (ValidationException) {
                return ['reply' => 'Eita, esse horário acabou de ser preenchido. Vou buscar outros.', 'state' => ['data' => [
                    'selected_slot' => null, 'offered_slots' => [], 'booking_uuid' => (string) Str::uuid(),
                ]], 'next' => $this->actionNodeId($company, 'offer_slots')];
            } catch (HttpExceptionInterface) {
                return ['reply' => 'Vou chamar a equipe do lava-jato pra marcar esse horário com você.', 'state' => ['handoff' => true], 'stop' => true];
            }
            $owner = Client::where('company_id', $company->id)->find($result->appointment->client_id) ?? $client;
            app(ClientService::class)->update($company, $owner, ['vehicle_plate' => $plate]);

            return ['reply' => "Prontinho, {$owner->name}! Seu horário tá marcado:\n{$service->name}\n{$slot['label']}\nPlaca ".VehiclePlate::format($plate),
                'state' => ['booked_client_id' => $owner->id], 'stop' => true];
        }

        return ['reply' => 'Não consegui concluir esta etapa. Digite menu para recomeçar.', 'stop' => true];
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, mixed>  $state
     * @return array{kind: string, outcome?: string, data?: array<string, mixed>, reply?: string}
     */
    public function interpret(Company $company, array $node, string $input, array $state): array
    {
        $setting = CompanySchedulingSetting::where('company_id', $company->id)->first();
        if (! $setting || blank($setting->ai_provider) || blank($setting->ai_api_key) || blank($setting->ai_model)) {
            return ['kind' => 'unavailable'];
        }
        $services = app(BeautyAISchedulingService::class)->services($company);
        $catalog = $services->map(fn (Service $service) => ['id' => $service->id, 'name' => $service->name])->values()->all();
        $outcomes = collect($node['outcomes'] ?? [])->map(fn ($outcome) => $outcome['id'].' ('.$outcome['label'].')')->implode(', ');
        $instruction = 'Você classifica uma mensagem de WhatsApp de um lava-jato. Responda só JSON com outcome, service_id, value e reply. '
            .'outcome é um destes identificadores: '.$outcomes.', ou fora_do_assunto. '
            .'service_id é o id do catálogo quando a pessoa escolhe uma lavagem; senão null. '
            .'value é a placa ou o dado pedido, ou null. reply fica vazio, ou uma frase curta só para esclarecer entre serviços do catálogo. '
            .'Nunca escreva preço, horário, data nem diga que o agendamento foi confirmado. '
            .'Se a mensagem não for sobre lavagem, horário, placa ou os serviços do catálogo, use fora_do_assunto e reply vazio. '
            .'Instrução deste bloco: '.mb_substr((string) ($node['instruction'] ?? ''), 0, 1000)
            .'. Catálogo: '.json_encode($catalog, JSON_UNESCAPED_UNICODE)
            .'. Já coletado: '.json_encode(array_intersect_key($state['data'] ?? [], array_flip(['service_name', 'plate'])), JSON_UNESCAPED_UNICODE);
        try {
            $parsed = app(CompanyAIService::class)->structured($instruction, $input, context: [
                'company_id' => $company->id,
            ]);
        } catch (Throwable) {
            return ['kind' => 'unavailable'];
        }

        return $this->decisionFromModel($node, $services, $parsed);
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, mixed>  $parsed
     * @return array{kind: string, outcome?: string, data?: array<string, mixed>, reply?: string}
     */
    public function decisionFromModel(array $node, $services, array $parsed): array
    {
        $outcome = is_string($parsed['outcome'] ?? null) ? $parsed['outcome'] : '';
        if ($outcome === '' || $outcome === 'fora_do_assunto') {
            return ['kind' => 'off_topic'];
        }
        $allowed = collect($node['outcomes'] ?? [])->contains(fn ($item) => $item['id'] === $outcome);
        if (! $allowed) {
            return ['kind' => 'off_topic'];
        }
        $reply = is_string($parsed['reply'] ?? null) ? trim($parsed['reply']) : '';
        if ($this->mentionsUnverifiedFacts($reply)) {
            $reply = '';
        }
        $field = (string) ($node['field'] ?? '');
        if ($field === 'service') {
            $service = $this->resolveService($services, $parsed);
            if (! $service instanceof Service) {
                return ['kind' => 'stay', 'reply' => $reply];
            }

            return ['kind' => 'advance', 'outcome' => $outcome, 'data' => [
                'service_id' => $service->id, 'service_name' => $service->name,
            ]];
        }
        if ($field === 'plate') {
            $plate = VehiclePlate::normalize(is_string($parsed['value'] ?? null) ? $parsed['value'] : '');
            if ($plate === null || strlen($plate) !== 7 || ! VehiclePlate::isValid($plate)) {
                return ['kind' => 'stay', 'reply' => $reply];
            }

            return ['kind' => 'advance', 'outcome' => $outcome, 'data' => ['plate' => $plate]];
        }

        return ['kind' => 'advance', 'outcome' => $outcome];
    }

    public function notifyTeam(Company $company, string $phone, string $excerpt, string $reason): void
    {
        $users = $company->users()->wherePivot('is_active', true)->where('users.is_active', true)->get()
            ->filter(fn ($user) => in_array(
                $user->pivot->role instanceof CompanyRole ? $user->pivot->role->value : $user->pivot->role,
                [CompanyRole::CompanyAdmin->value, CompanyRole::Manager->value],
                true,
            ));
        if ($users->isEmpty()) {
            return;
        }
        $snippet = Str::limit(trim($excerpt), 180, '');
        $unavailable = $reason === 'ai_unavailable';
        Notification::make()
            ->title($unavailable ? 'A IA do lava-jato não respondeu' : 'Cliente saiu do assunto do lava-jato')
            ->body($unavailable
                ? "Não foi possível classificar a mensagem de {$phone}. Confira a chave de IA nas configurações da agenda.\n{$snippet}"
                : "{$phone} escreveu algo fora do atendimento:\n{$snippet}")
            ->sendToDatabase($users->values());
    }

    public function pauseForPhone(string $instanceName, string $phone): void
    {
        $instance = CompanyWhatsAppInstance::where('instance_name', $instanceName)->first();
        if (! $instance?->company?->isCarWash() || ! ($normalized = PhoneNormalizer::normalize($phone))) {
            return;
        }
        $conversations = CarWashBotConversation::where('company_whatsapp_instance_id', $instance->id)
            ->whereIn('phone_normalized', PhoneNormalizer::candidates($phone));
        if ($conversations->exists()) {
            CarWashBotMessage::whereIn('car_wash_bot_conversation_id', (clone $conversations)->select('id'))
                ->where('status', 'queued')->update(['status' => 'cancelled']);
            $conversations->update(['human_takeover' => true]);

            return;
        }
        CarWashBotConversation::create([
            'company_id' => $instance->company_id,
            'company_whatsapp_instance_id' => $instance->id,
            'phone_normalized' => $normalized,
            'human_takeover' => true,
        ]);
    }

    public function lockKey(int $instanceId, string $phone): string
    {
        $candidates = PhoneNormalizer::candidates($phone);
        sort($candidates);

        return 'car-wash-bot:'.$instanceId.':'.hash('sha256', implode(',', $candidates));
    }

    public static function contactName(string $text): ?string
    {
        $name = CustomerNameDetector::fromMessage($text);

        return $name && ! preg_match('/\b(lavagem|lavar|placa|horario|agendar|preco|carro)\b/u', Str::lower(Str::ascii($name)))
            ? $name : null;
    }

    private function queue(CarWashBotMessage $message): void
    {
        SendCarWashBotReplyJob::dispatch($message->id)->afterCommit();
    }

    private function resolveService($services, array $parsed): ?Service
    {
        $id = filter_var($parsed['service_id'] ?? null, FILTER_VALIDATE_INT);
        if ($id) {
            $match = $services->firstWhere('id', $id);
            if ($match instanceof Service) {
                return $match;
            }
        }
        $value = Str::lower(Str::ascii(trim((string) ($parsed['value'] ?? ''))));
        if ($value === '') {
            return null;
        }
        $named = $services->filter(fn (Service $service) => Str::lower(Str::ascii($service->name)) === $value
            || str_contains($value, Str::lower(Str::ascii($service->name))));

        return $named->count() === 1 ? $named->first() : null;
    }

    private function mentionsUnverifiedFacts(string $reply): bool
    {
        return $reply !== '' && (bool) preg_match('/R\$\s*\d|\b\d+(?:[.,]\d+)?\s*(?:reais|real)\b|\b(pagamento|sinal|agendamento)\s+(foi\s+)?confirmado\b|\b\d{1,2}(?::\d{2}|h\d{0,2})\b/iu', $reply);
    }

    private function actionNodeId(Company $company, string $action): ?string
    {
        $flow = CarWashBotFlow::where('company_id', $company->id)->first();
        $version = $flow ? CarWashBotFlowVersion::where('car_wash_bot_flow_id', $flow->id)->whereKey($flow->published_version_id)->first() : null;
        $node = collect($version?->definition['nodes'] ?? [])->first(fn ($node) => ($node['action'] ?? '') === $action);

        return $node['id'] ?? null;
    }

    /** @param  list<string>  $lines */
    private function numbered(array $lines): string
    {
        $text = '';
        foreach (array_values($lines) as $i => $line) {
            $text .= ($i + 1).'. '.$line."\n";
        }

        return trim($text);
    }

    private function money(float $value): string
    {
        return 'R$ '.number_format($value, 2, ',', '.');
    }
}
