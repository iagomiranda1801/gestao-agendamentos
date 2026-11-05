<?php

namespace App\Filament\App\Pages;

use App\Models\AssistanceBotFlow;
use App\Models\Company;
use App\Models\User;
use App\Services\ServiceOrders\Bot\AssistanceBotService;
use App\Services\ServiceOrders\Bot\AssistanceFlowDefinition;
use App\Services\ServiceOrders\Bot\AssistanceFlowEngine;
use App\Services\ServiceOrders\Bot\AssistanceFlowService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use UnitEnum;

class AssistanceFlowPage extends Page
{
    protected static ?string $slug = 'fluxo-atendimento';

    protected static ?string $navigationLabel = 'Fluxo de atendimento';

    protected static ?string $title = 'Fluxo de atendimento';

    protected static string|UnitEnum|null $navigationGroup = 'WhatsApp';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquaresPlus;

    protected string $view = 'filament.app.pages.assistance-flow';

    public array $definition = [];

    #[Locked]
    public int $revision = 1;

    public int $selected = 0;

    public string $newType = 'message';

    public string $simulatorInput = '';

    public bool $simulateKnownCustomer = false;

    public bool $simulateHasOrders = true;

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
            && app(AssistanceFlowService::class)->allows(Filament::getTenant(), auth()->user());
    }

    public function mount(): void
    {
        $this->loadFlow(app(AssistanceFlowService::class)->get(Filament::getTenant(), auth()->user()));
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
                ->modalDescription('A ativação permite respostas automáticas na conexão WhatsApp da empresa. Use o simulador antes de ativar.')
                ->action(function (): void {
                    app(AssistanceFlowService::class)->setEnabled(Filament::getTenant(), auth()->user(), ! $this->flow()->is_enabled);
                    Notification::make()->success()->title('Ativação atualizada.')->send();
                }),
        ];
    }

    public function flow(): AssistanceBotFlow
    {
        return app(AssistanceFlowService::class)->get(Filament::getTenant(), auth()->user());
    }

    public function saveDraft(): void
    {
        $flow = app(AssistanceFlowService::class)->save(Filament::getTenant(), auth()->user(), $this->definition, $this->revision);
        $this->revision = $flow->revision;
        Notification::make()->success()->title('Rascunho salvo.')->send();
    }

    public function publish(): void
    {
        $flow = app(AssistanceFlowService::class)->publish(Filament::getTenant(), auth()->user(), $this->definition, $this->revision);
        $this->revision = $flow->revision;
        Notification::make()->success()->title('Versão publicada.')->send();
    }

    public function restoreVersion(int $id): void
    {
        $this->loadFlow(app(AssistanceFlowService::class)->restore(Filament::getTenant(), auth()->user(), $id, $this->revision));
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
        abort_unless(isset(AssistanceFlowDefinition::TYPES[$this->newType]) && count($this->definition['nodes']) < 50, 422);
        $node = ['id' => 'block_'.Str::lower(Str::random(8)), 'type' => $this->newType, 'label' => 'Novo bloco', 'text' => 'Edite esta mensagem.', 'next' => $this->definition['entry']];
        $node += match ($this->newType) {
            'question' => ['field' => 'notes'],
            'action' => ['action' => 'order_status'],
            'condition' => ['condition' => 'has_open_orders', 'yes' => $this->definition['entry'], 'no' => $this->definition['entry']],
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

    public function startSimulation(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->simulationDefinition = app(AssistanceFlowDefinition::class)->validate($this->definition);
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
            $name = AssistanceBotService::contactName($input);
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
        $result = app(AssistanceFlowEngine::class)->advance($this->simulationDefinition, $this->simulation, $input, [
            'name' => $this->simulationName, 'company' => Filament::getTenant()->name, 'has_open_orders' => $this->simulateHasOrders,
        ], fn (string $action): string => match ($action) {
            'repair_request' => 'Simulação: solicitação de conserto registrada. Protocolo DEMO-001. O aparelho ainda não foi recebido.',
            'order_status' => 'Simulação: OS #123 · Samsung A12 · Em conserto. Nenhuma previsão registrada.',
            'accessory_interest' => 'Simulação: interesse registrado para conferência da disponibilidade pela loja. Nenhuma reserva ou cobrança foi realizada.',
        });
        $this->simulation = $result['state'];
        $this->transcript[] = ['role' => 'bot', 'text' => $result['reply']];
    }

    private function loadFlow(AssistanceBotFlow $flow): void
    {
        $this->definition = $flow->draft;
        $this->revision = $flow->revision;
        $this->selected = 0;
    }
}
