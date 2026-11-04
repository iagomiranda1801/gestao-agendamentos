<?php

namespace App\Filament\App\Resources\Receivables\Tables;

use App\Enums\AsaasBillingType;
use App\Enums\ReceivableStatus;
use App\Filament\App\Resources\Concerns\InteractsWithPaymentRegistration;
use App\Models\Company;
use App\Models\Receivable;
use App\Services\Asaas\AsaasChargeService;
use App\Services\Asaas\CompanyAsaasSettingService;
use App\Support\CompanyDateTime;
use App\Support\DecimalMoney;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class ReceivablesTable
{
    use InteractsWithPaymentRegistration;

    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('#')
                    ->sortable(),
                TextColumn::make('attendance.client_name_snapshot')
                    ->label('Cliente')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('attendance.service_name_snapshot')
                    ->label('Serviço')
                    ->searchable(),
                TextColumn::make('original_amount')
                    ->label('Valor original')
                    ->money('BRL', locale: 'pt_BR')
                    ->sortable(),
                TextColumn::make('paid_amount')
                    ->label('Valor pago')
                    ->money('BRL', locale: 'pt_BR')
                    ->sortable(),
                TextColumn::make('outstanding_amount')
                    ->label('Saldo em aberto')
                    ->money('BRL', locale: 'pt_BR')
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (ReceivableStatus $state): string => $state->label())
                    ->color(fn (ReceivableStatus $state): string => match ($state) {
                        ReceivableStatus::Open => 'warning',
                        ReceivableStatus::Partial => 'info',
                        ReceivableStatus::Paid => 'success',
                        ReceivableStatus::Cancelled => 'gray',
                    }),
                TextColumn::make('due_date')
                    ->label('Vencimento')
                    ->date('d/m/Y')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('settled_at')
                    ->label('Quitado em')
                    ->formatStateUsing(function ($state, Receivable $record): string {
                        if (! $record->settled_at) {
                            return '—';
                        }

                        /** @var Company $company */
                        $company = Filament::getTenant();

                        return CompanyDateTime::formatLocal($company, $record->settled_at);
                    })
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(ReceivableStatus::options())
                    ->native(false),
                Filter::make('due_date')
                    ->label('Vencimento')
                    ->schema([
                        DatePicker::make('from')
                            ->label('De')
                            ->native(false),
                        DatePicker::make('until')
                            ->label('Até')
                            ->native(false),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['from'] ?? null,
                                fn (Builder $query, string $date): Builder => $query->whereDate('due_date', '>=', $date),
                            )
                            ->when(
                                $data['until'] ?? null,
                                fn (Builder $query, string $date): Builder => $query->whereDate('due_date', '<=', $date),
                            );
                    }),
                SelectFilter::make('client_id')
                    ->label('Cliente')
                    ->relationship('client', 'name', fn (Builder $query): Builder => $query->where(
                        'company_id',
                        Filament::getTenant()?->getKey(),
                    ))
                    ->searchable()
                    ->preload()
                    ->native(false),
            ])
            ->defaultSort('due_date')
            ->recordActions([
                self::makeRegisterPaymentTableAction(),
                self::makeAsaasChargeTableAction(),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['attendance', 'client']));
    }

    protected static function makeAsaasChargeTableAction(): Action
    {
        return Action::make('chargeAsaas')
            ->label('Cobrar no Asaas')
            ->icon('heroicon-o-qr-code')
            ->visible(function (Receivable $record): bool {
                if (! in_array($record->status, [ReceivableStatus::Open, ReceivableStatus::Partial], true)) {
                    return false;
                }

                $company = Filament::getTenant();
                if (! $company instanceof Company || app(CompanyAsaasSettingService::class)->ready($company) === null) {
                    return false;
                }

                return auth()->user()?->can('registerPayment', $record) ?? false;
            })
            ->schema(fn (Receivable $record): array => [
                Select::make('billing_type')
                    ->label('Forma de cobrança')
                    ->options(AsaasBillingType::options())
                    ->default(AsaasBillingType::Pix->value)
                    ->required()
                    ->native(false),
                TextInput::make('amount')
                    ->label('Valor')
                    ->numeric()
                    ->minValue(0.01)
                    ->step(0.01)
                    ->prefix('R$')
                    ->required()
                    ->default((string) $record->outstanding_amount),
                DatePicker::make('due_date')
                    ->label('Vencimento')
                    ->native(false)
                    ->default($record->due_date?->toDateString() ?? now()->toDateString())
                    ->required(),
            ])
            ->action(function (Receivable $record, array $data): void {
                /** @var Company $company */
                $company = Filament::getTenant();
                $charge = app(AsaasChargeService::class)->createForReceivable(
                    $company,
                    $record,
                    AsaasBillingType::from($data['billing_type']),
                    DecimalMoney::round((string) $data['amount']),
                    Carbon::parse($data['due_date']),
                );

                $body = filled($charge->pix_payload)
                    ? "PIX copia e cola:\n".$charge->pix_payload
                    : (string) ($charge->bank_slip_url ?: $charge->invoice_url);
                if (filled($charge->invoice_url) && filled($charge->pix_payload)) {
                    $body .= "\n\nLink: ".$charge->invoice_url;
                }

                Notification::make()
                    ->success()
                    ->title('Cobrança criada no Asaas')
                    ->body($body)
                    ->persistent()
                    ->send();
            });
    }
}
