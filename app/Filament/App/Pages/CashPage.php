<?php

namespace App\Filament\App\Pages;

use App\Enums\CashSessionStatus;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\Company;
use App\Policies\CashSessionPolicy;
use App\Services\Financial\CashSessionService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class CashPage extends Page
{
    protected static ?string $slug = 'caixa';

    protected static ?string $navigationLabel = 'Caixa';

    protected static ?string $title = 'Caixa';

    protected static string|UnitEnum|null $navigationGroup = 'Caixa';

    protected static ?int $navigationSort = 1;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected string $view = 'filament.app.pages.cash-page';

    public ?int $selectedRegisterId = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && (new CashSessionPolicy)->viewAny($user);
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->selectedRegisterId = CashRegister::query()
            ->where('company_id', Filament::getTenant()?->getKey())
            ->where('is_active', true)
            ->orderBy('name')
            ->value('id');
    }

    public function getOpenSessionProperty(): ?CashSession
    {
        if ($this->selectedRegisterId === null) {
            return null;
        }

        return CashSession::query()
            ->where('company_id', Filament::getTenant()?->getKey())
            ->where('cash_register_id', $this->selectedRegisterId)
            ->where('status', CashSessionStatus::Open)
            ->with(['cashRegister.financialAccount.balance', 'opener'])
            ->first();
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->openSessionAction(),
            $this->reinforcementAction(),
            $this->withdrawalAction(),
            $this->closeSessionAction(),
        ];
    }

    protected function openSessionAction(): Action
    {
        return Action::make('openSession')
            ->label('Abrir caixa')
            ->icon('heroicon-o-lock-open')
            ->visible(fn (): bool => $this->openSession === null && $this->selectedRegisterId !== null)
            ->schema([
                Select::make('cash_register_id')
                    ->label('Caixa')
                    ->options(fn (): array => CashRegister::query()
                        ->where('company_id', Filament::getTenant()?->getKey())
                        ->where('is_active', true)
                        ->pluck('name', 'id')
                        ->all())
                    ->default($this->selectedRegisterId)
                    ->required()
                    ->native(false),
                TextInput::make('counted_amount')
                    ->label('Valor contado na abertura')
                    ->numeric()
                    ->minValue(0)
                    ->step(0.01)
                    ->prefix('R$')
                    ->required(),
                Textarea::make('notes')
                    ->label('Observações')
                    ->rows(2),
            ])
            ->action(function (array $data): void {
                /** @var Company $company */
                $company = Filament::getTenant();

                $register = CashRegister::query()
                    ->where('company_id', $company->getKey())
                    ->findOrFail($data['cash_register_id']);

                app(CashSessionService::class)->open(
                    $company,
                    $register,
                    auth()->user(),
                    number_format((float) $data['counted_amount'], 2, '.', ''),
                    $data['notes'] ?? null,
                );

                $this->selectedRegisterId = (int) $register->getKey();

                Notification::make()
                    ->success()
                    ->title('Caixa aberto')
                    ->send();
            });
    }

    protected function reinforcementAction(): Action
    {
        return Action::make('reinforcement')
            ->label('Reforço')
            ->icon('heroicon-o-plus-circle')
            ->color('success')
            ->visible(fn (): bool => $this->openSession !== null)
            ->schema([
                TextInput::make('amount')
                    ->label('Valor')
                    ->numeric()
                    ->minValue(0.01)
                    ->step(0.01)
                    ->prefix('R$')
                    ->required(),
                Textarea::make('reason')
                    ->label('Motivo')
                    ->required()
                    ->rows(2),
            ])
            ->action(function (array $data): void {
                /** @var Company $company */
                $company = Filament::getTenant();

                app(CashSessionService::class)->reinforcement(
                    $company,
                    $this->openSession,
                    auth()->user(),
                    number_format((float) $data['amount'], 2, '.', ''),
                    $data['reason'],
                );

                Notification::make()
                    ->success()
                    ->title('Reforço registrado')
                    ->send();
            });
    }

    protected function withdrawalAction(): Action
    {
        return Action::make('withdrawal')
            ->label('Sangria')
            ->icon('heroicon-o-minus-circle')
            ->color('warning')
            ->visible(fn (): bool => $this->openSession !== null)
            ->schema([
                TextInput::make('amount')
                    ->label('Valor')
                    ->numeric()
                    ->minValue(0.01)
                    ->step(0.01)
                    ->prefix('R$')
                    ->required(),
                Textarea::make('reason')
                    ->label('Motivo')
                    ->required()
                    ->rows(2),
            ])
            ->action(function (array $data): void {
                /** @var Company $company */
                $company = Filament::getTenant();

                app(CashSessionService::class)->withdrawal(
                    $company,
                    $this->openSession,
                    auth()->user(),
                    number_format((float) $data['amount'], 2, '.', ''),
                    $data['reason'],
                );

                Notification::make()
                    ->success()
                    ->title('Sangria registrada')
                    ->send();
            });
    }

    protected function closeSessionAction(): Action
    {
        return Action::make('closeSession')
            ->label('Fechar caixa')
            ->icon('heroicon-o-lock-closed')
            ->color('danger')
            ->visible(fn (): bool => $this->openSession !== null)
            ->schema([
                TextInput::make('counted_amount')
                    ->label('Valor contado no fechamento')
                    ->numeric()
                    ->minValue(0)
                    ->step(0.01)
                    ->prefix('R$')
                    ->required(),
                Textarea::make('notes')
                    ->label('Observações')
                    ->rows(2),
            ])
            ->action(function (array $data): void {
                /** @var Company $company */
                $company = Filament::getTenant();

                app(CashSessionService::class)->close(
                    $company,
                    $this->openSession,
                    auth()->user(),
                    number_format((float) $data['counted_amount'], 2, '.', ''),
                    $data['notes'] ?? null,
                );

                Notification::make()
                    ->success()
                    ->title('Caixa fechado')
                    ->send();
            });
    }
}
