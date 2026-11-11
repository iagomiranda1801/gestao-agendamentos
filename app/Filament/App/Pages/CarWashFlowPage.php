<?php

namespace App\Filament\App\Pages;

use App\Models\CarWashBotFlow;
use App\Models\Company;
use App\Models\Service;
use App\Models\User;
use App\Services\Beauty\BeautyAISchedulingService;
use App\Services\CarWash\CarWashBotService;
use App\Services\CarWash\CarWashFlowDefinition;
use App\Services\CarWash\CarWashFlowEngine;
use App\Services\CarWash\CarWashFlowService;
use App\Support\VehiclePlate;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use UnitEnum;

class CarWashFlowPage extends Page
{
    protected static ?string $slug = 'fluxo-lava-jato';

    protected static ?string $navigationLabel = 'Fluxo de atendimento';

    protected static ?string $title = 'Fluxo de atendimento';

    protected static string|UnitEnum|null $navigationGroup = 'WhatsApp';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquaresPlus;

    protected string $view = 'filament.app.pages.car-wash-flow';

    public array $definition = [];

    #[Locked]
    public int $revision = 1;

    public int $selected = 0;

    public string $newType = 'message';

    public string $simulatorInput = '';

    public bool $simulateKnownCustomer = false;

    #[Locked]
    public array $simulation = [];

    #[Locked]
    public array $transcript = [];

    #[Locked]
    public array $simulationDefinition = [];

    #[Locked]
    public bool $simulationNeedsName = false;

    #[Locked]
    public string $simulationName = 'João';

    public static function canAccess(): bool
    {
        return Filament::getTenant() instanceof Company && auth()->user() instanceof User
            && app(CarWashFlowService::class)->allows(Filament::getTenant(), auth()->user());
    }

    public function mount(): void
    {
        $this->loadFlow(app(CarWashFlowService::class)->get(Filament::getTenant(), auth()->user()));
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')->label('Salvar rascunho')->color('gray')->action(fn () => $this->saveDraft()),
            Action::make('publish')->label('Publicar versão')->requiresConfirmation()
                ->modalDescription('A versão será usada em novos atendimentos quando o bot estiver ativo. Conversas em andamento mantêm a versão anterior.')
                ->action(fn () => $this->publish()),
            Action::make('toggle')->label(fn () => $this->flow()->is_enabled ? 'Desativar bot' : 'Ativar bot')
                ->color(fn () => $this->flow()->is_enabled ? 'danger' : 'success')->requiresConfirmation()
                ->modalDescription('A ativação substitui o menu numerado de agendamento. Use o simulador antes de ativar.')
                ->action(function (): void {
                    app(CarWashFlowService::class)->setEnabled(Filament::getTenant(), auth()->user(), ! $this->flow()->is_enabled);
                    Notification::make()->success()->title('Ativação atualizada.')->send();
                }),
        ];
    }

    public function flow(): CarWashBotFlow
    {
        return app(CarWashFlowService::class)->get(Filament::getTenant(), auth()->user());
    }

    public function saveDraft(): void
    {
        $flow = app(CarWashFlowService::class)->save(Filament::getTenant(), auth()->user(), $this->definition, $this->revision);
        $this->revision = $flow->revision;
        Notification::make()->success()->title('Rascunho salvo.')->send();
    }

    public function publish(): void
    {
        $flow = app(CarWashFlowService::class)->publish(Filament::getTenant(), auth()->user(), $this->definition, $this->revision);
        $this->revision = $flow->revision;
        Notification::make()->success()->title('Versão publicada.')->send();
    }

    public function restoreVersion(int $id): void
    {
        $this->loadFlow(app(CarWashFlowService::class)->restore(Filament::getTenant(), auth()->user(), $id, $this->revision));
        $this->transcript = [];
        Notification::make()->success()->title('Versão restaurada como rascunho. Publique para utilizar.')->send();
    }

    public function selectNode(int $index): void
    {
        abort_unless(static::canAccess(), 403);
        abort_unless(isset($this->definition['nodes'][$index]), 422);
        $this->selected = $index;
        $this->resetValidation();
    }

    public function addNode(): void
    {
        abort_unless(static::canAccess(), 403);
        abort_unless(isset(CarWashFlowDefinition::TYPES[$this->newType]) && count($this->definition['nodes']) < 50, 422);
        $node = ['id' => 'block_'.Str::lower(Str::random(8)), 'type' => $this->newType, 'label' => 'Novo bloco', 'text' => 'Edite esta mensagem.', 'next' => $this->definition['entry']];
        $node += match ($this->newType) {
            'question' => ['field' => 'plate'],
            'action' => ['action' => 'list_services'],
            'ai' => ['field' => '', 'instruction' => 'Entender a mensagem dentro do assunto deste bloco.', 'outcomes' => [
                ['id' => 'seguiu', 'label' => 'Seguiu no assunto', 'next' => $this->definition['entry']],
            ]],
            'menu' => ['options' => [['label' => 'Continuar', 'next' => $this->definition['entry']]]],
            default => [],
        };
        $this->definition['nodes'][] = $node;
        $this->selected = count($this->definition['nodes']) - 1;
    }

    public function removeNode(): void
    {
        abort_unless(static::canAccess(), 403);
        $node = $this->definition['nodes'][$this->selected] ?? null;
        abort_unless($node && $node['id'] !== $this->definition['entry'], 422);
        array_splice($this->definition['nodes'], $this->selected, 1);
        $this->selected = 0;
    }

    public function addOption(): void
    {
        abort_unless(static::canAccess(), 403);
        $node = &$this->definition['nodes'][$this->selected];
        abort_unless($node['type'] === 'menu' && count($node['options']) < 6, 422);
        $node['options'][] = ['label' => 'Nova opção', 'next' => $this->definition['entry']];
    }

    public function removeOption(int $index): void
    {
        abort_unless(static::canAccess(), 403);
        $node = &$this->definition['nodes'][$this->selected];
        abort_unless($node['type'] === 'menu' && count($node['options']) > 1 && isset($node['options'][$index]), 422);
        array_splice($node['options'], $index, 1);
    }

    public function addOutcome(): void
    {
        abort_unless(static::canAccess(), 403);
        $node = &$this->definition['nodes'][$this->selected];
        abort_unless($node['type'] === 'ai' && count($node['outcomes']) < 6, 422);
        $node['outcomes'][] = ['id' => 'saida_'.Str::lower(Str::random(6)), 'label' => 'Nova saída', 'next' => $this->definition['entry']];
    }

    public function removeOutcome(int $index): void
    {
        abort_unless(static::canAccess(), 403);
        $node = &$this->definition['nodes'][$this->selected];
        abort_unless($node['type'] === 'ai' && count($node['outcomes']) > 1 && isset($node['outcomes'][$index]), 422);
        array_splice($node['outcomes'], $index, 1);
    }

    public function startSimulation(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->simulationDefinition = app(CarWashFlowDefinition::class)->validate($this->definition);
        $this->simulation = [];
        $this->transcript = [];
        $this->simulatorInput = '';
        $this->simulationName = 'João';
        $this->simulationNeedsName = ! $this->simulateKnownCustomer;
        if ($this->simulationNeedsName) {
            $this->transcript[] = ['role' => 'bot', 'text' => $this->definition['name_prompt']];
        } else {
            $this->simulate(null);
        }
    }

    public function sendSimulation(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->validate(['simulatorInput' => 'required|string|min:1|max:1000']);
        abort_unless($this->simulationDefinition !== [] && count($this->transcript) < 100, 422);
        $input = trim($this->simulatorInput);
        $this->transcript[] = ['role' => 'client', 'text' => $input];
        $this->simulatorInput = '';
        if ($this->simulationNeedsName) {
            $name = CarWashBotService::contactName($input);
            if (! $name) {
                $this->transcript[] = ['role' => 'bot', 'text' => 'Para começar, informe apenas seu nome.'];

                return;
            }
            $this->simulationName = $name;
            $this->simulationNeedsName = false;
            $this->simulate(null);

            return;
        }
        if (Str::lower($input) === 'menu') {
            $this->simulation = [];
            $input = null;
        }
        $this->simulate($input);
    }

    private function simulate(?string $input): void
    {
        $result = app(CarWashFlowEngine::class)->advance($this->simulationDefinition, $this->simulation, $input, [
            'name' => $this->simulationName, 'company' => Filament::getTenant()->name, 'plate' => null,
        ], fn (string $action, array $state, string $nodeId): array => $this->simulateAction($action, $state), fn (array $node, string $incoming, array $state): array => $this->simulateInterpret($node, $incoming));
        $this->simulation = $result['state'];
        $this->transcript[] = ['role' => 'bot', 'text' => $result['reply']];
    }

    /** @param  array<string, mixed>  $state */
    private function simulateAction(string $action, array $state): array
    {
        $slots = [
            ['value' => '2099-01-02 10:00', 'professional_id' => 1, 'professional_name' => 'Lavador', 'label' => 'amanhã às 10h'],
            ['value' => '2099-01-02 14:00', 'professional_id' => 1, 'professional_name' => 'Lavador', 'label' => 'amanhã às 14h'],
        ];

        return match ($action) {
            'list_services' => ['reply' => "Simulação, sem consultar a agenda de verdade:\n1. Lavagem simples\n2. Lavagem completa"],
            'show_prices' => ['reply' => 'Simulação: os preços saem do cadastro. Nenhum valor foi inventado pela IA.'],
            'offer_slots' => ['reply' => "Simulação de horários livres:\n1. amanhã às 10h\n2. amanhã às 14h", 'state' => ['data' => [
                'offered_slots' => $slots, 'service_name' => $state['data']['service_name'] ?? 'Lavagem',
            ]]],
            'book' => ['reply' => 'Simulação: horário marcado e placa anotada. Nenhum agendamento real foi criado.', 'stop' => true],
            'handoff' => ['reply' => 'Simulação: a equipe foi chamada e o bot pausou. Ninguém foi notificado de verdade.', 'stop' => true],
            default => ['reply' => 'Simulação: esta ação não está disponível.', 'stop' => true],
        };
    }

    /** @param  array<string, mixed>  $node */
    private function simulateInterpret(array $node, string $input): array
    {
        $services = app(BeautyAISchedulingService::class)->services(Filament::getTenant());
        $matched = $services->first(fn (Service $service) => str_contains(Str::lower(Str::ascii($input)), Str::lower(Str::ascii($service->name))));
        if (($node['field'] ?? '') === 'service' && ! $matched) {
            return ['kind' => 'off_topic'];
        }
        if (($node['field'] ?? '') === 'plate') {
            $plate = VehiclePlate::normalize($input);
            if ($plate === null || strlen($plate) !== 7 || ! VehiclePlate::isValid($plate)) {
                return ['kind' => 'off_topic'];
            }

            return ['kind' => 'advance', 'outcome' => $node['outcomes'][0]['id'], 'data' => ['plate' => $plate]];
        }
        if (str_contains(Str::lower(Str::ascii($input)), 'fora')) {
            return ['kind' => 'off_topic'];
        }
        $data = $matched instanceof Service ? ['service_id' => $matched->id, 'service_name' => $matched->name] : [];

        return ['kind' => 'advance', 'outcome' => $node['outcomes'][0]['id'], 'data' => $data];
    }

    private function loadFlow(CarWashBotFlow $flow): void
    {
        $this->definition = $flow->draft;
        $this->revision = $flow->revision;
        $this->selected = 0;
    }
}
