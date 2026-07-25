<?php

namespace App\Filament\App\Resources\Transfers\Pages;

use App\DataTransferObjects\Financial\FinancialTransferData;
use App\Filament\App\Resources\Transfers\TransferResource;
use App\Models\Company;
use App\Models\FinancialAccount;
use App\Models\FinancialTransfer;
use App\Services\Financial\FinancialTransferService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Carbon;

class ListTransfers extends ListRecords
{
    protected static string $resource = TransferResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('createTransfer')
                ->label('Nova transferência')
                ->icon('heroicon-o-plus')
                ->visible(fn (): bool => auth()->user()?->can('create', FinancialTransfer::class) ?? false)
                ->schema([
                    Select::make('from_financial_account_id')
                        ->label('Conta de origem')
                        ->options(fn (): array => $this->accountOptions())
                        ->required()
                        ->searchable()
                        ->native(false),
                    Select::make('to_financial_account_id')
                        ->label('Conta de destino')
                        ->options(fn (): array => $this->accountOptions())
                        ->required()
                        ->searchable()
                        ->native(false),
                    TextInput::make('amount')
                        ->label('Valor')
                        ->numeric()
                        ->minValue(0.01)
                        ->step(0.01)
                        ->prefix('R$')
                        ->required(),
                    TextInput::make('fee_amount')
                        ->label('Taxa')
                        ->numeric()
                        ->minValue(0)
                        ->step(0.01)
                        ->prefix('R$')
                        ->default('0.00'),
                    DateTimePicker::make('occurred_at')
                        ->label('Data')
                        ->default(now())
                        ->required()
                        ->native(false),
                    Textarea::make('description')
                        ->label('Descrição')
                        ->required()
                        ->rows(2),
                ])
                ->action(function (array $data): void {
                    /** @var Company $company */
                    $company = Filament::getTenant();

                    app(FinancialTransferService::class)->transfer(
                        $company,
                        auth()->user(),
                        new FinancialTransferData(
                            fromFinancialAccountId: (int) $data['from_financial_account_id'],
                            toFinancialAccountId: (int) $data['to_financial_account_id'],
                            amount: number_format((float) $data['amount'], 2, '.', ''),
                            occurredAt: Carbon::parse($data['occurred_at']),
                            description: $data['description'],
                            feeAmount: number_format((float) ($data['fee_amount'] ?? 0), 2, '.', ''),
                        ),
                    );

                    Notification::make()
                        ->success()
                        ->title('Transferência registrada')
                        ->send();
                }),
            Action::make('reverseTransfer')
                ->label('Estornar transferência')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('danger')
                ->visible(fn (): bool => auth()->user()?->can('create', FinancialTransfer::class) ?? false)
                ->schema([
                    Select::make('transfer_id')
                        ->label('Transferência')
                        ->options(fn (): array => FinancialTransfer::query()
                            ->where('company_id', Filament::getTenant()?->getKey())
                            ->whereNull('reversed_at')
                            ->orderByDesc('occurred_at')
                            ->limit(50)
                            ->get()
                            ->mapWithKeys(fn (FinancialTransfer $transfer): array => [
                                $transfer->getKey() => "{$transfer->description} — R$ {$transfer->amount}",
                            ])
                            ->all())
                        ->required()
                        ->searchable()
                        ->native(false),
                    Textarea::make('reversal_reason')
                        ->label('Motivo')
                        ->required()
                        ->rows(2),
                ])
                ->action(function (array $data): void {
                    /** @var Company $company */
                    $company = Filament::getTenant();

                    $transfer = FinancialTransfer::query()
                        ->where('company_id', $company->getKey())
                        ->findOrFail($data['transfer_id']);

                    app(FinancialTransferService::class)->reverse(
                        $company,
                        $transfer,
                        auth()->user(),
                        $data['reversal_reason'],
                    );

                    Notification::make()
                        ->success()
                        ->title('Transferência estornada')
                        ->send();
                }),
        ];
    }

    /**
     * @return array<int|string, string>
     */
    protected function accountOptions(): array
    {
        return FinancialAccount::query()
            ->where('company_id', Filament::getTenant()?->getKey())
            ->where('is_active', true)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }
}
