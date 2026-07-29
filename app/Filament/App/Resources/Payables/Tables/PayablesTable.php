<?php

namespace App\Filament\App\Resources\Payables\Tables;

use App\DataTransferObjects\Financial\PayablePaymentData;
use App\Enums\PayableOrigin;
use App\Enums\PaymentMethod;
use App\Enums\PayableStatus;
use App\Models\Company;
use App\Models\FinancialAccount;
use App\Models\Payable;
use App\Models\PayableInstallment;
use App\Models\User;
use App\Services\Financial\PayablePaymentService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Builder;

class PayablesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('installments.due_date')
                    ->label('Vencimento')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('description')
                    ->label('Descrição')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('supplier.name')
                    ->label('Fornecedor')
                    ->placeholder('—')
                    ->searchable(),
                TextColumn::make('expenseCategory.name')
                    ->label('Categoria')
                    ->sortable(),
                TextColumn::make('total_amount')
                    ->label('Valor')
                    ->money('BRL', locale: 'pt_BR')
                    ->sortable(),
                TextColumn::make('paid_amount')
                    ->label('Valor pago')
                    ->state(function (Payable $record): string {
                        $total = '0.00';

                        foreach ($record->installments as $installment) {
                            $total = bcadd($total, (string) $installment->settled_principal_amount, 2);
                        }

                        return $total;
                    })
                    ->money('BRL', locale: 'pt_BR'),
                TextColumn::make('outstanding_amount')
                    ->label('Valor em aberto')
                    ->state(function (Payable $record): string {
                        $total = '0.00';

                        foreach ($record->installments as $installment) {
                            $total = bcadd($total, (string) $installment->outstanding_amount, 2);
                        }

                        return $total;
                    })
                    ->money('BRL', locale: 'pt_BR'),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (PayableStatus $state): string => $state->label())
                    ->color(fn (PayableStatus $state): string => match ($state) {
                        PayableStatus::Draft => 'gray',
                        PayableStatus::Open => 'warning',
                        PayableStatus::Partial => 'info',
                        PayableStatus::Paid => 'success',
                        PayableStatus::Cancelled => 'danger',
                    }),
                TextColumn::make('origin')
                    ->label('Origem')
                    ->formatStateUsing(fn (PayableOrigin $state): string => $state->label())
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(PayableStatus::options())
                    ->native(false),
                SelectFilter::make('origin')
                    ->label('Origem')
                    ->options(PayableOrigin::options())
                    ->native(false),
                Filter::make('overdue')
                    ->label('Vencidos')
                    ->query(fn (Builder $query): Builder => $query
                        ->whereIn('status', [PayableStatus::Open, PayableStatus::Partial])
                        ->whereHas('installments', fn (Builder $query): Builder => $query
                            ->whereDate('due_date', '<', now()->toDateString())
                            ->where('outstanding_amount', '>', 0))),
                Filter::make('due_today')
                    ->label('Vencendo hoje')
                    ->query(fn (Builder $query): Builder => $query
                        ->whereIn('status', [PayableStatus::Open, PayableStatus::Partial])
                        ->whereHas('installments', fn (Builder $query): Builder => $query
                            ->whereDate('due_date', now()->toDateString())
                            ->where('outstanding_amount', '>', 0))),
                Filter::make('upcoming_seven_days')
                    ->label('Próximos 7 dias')
                    ->query(fn (Builder $query): Builder => $query
                        ->whereIn('status', [PayableStatus::Open, PayableStatus::Partial])
                        ->whereHas('installments', fn (Builder $query): Builder => $query
                            ->whereBetween('due_date', [now()->toDateString(), now()->addDays(7)->toDateString()])
                            ->where('outstanding_amount', '>', 0))),
                SelectFilter::make('supplier_id')
                    ->label('Fornecedor')
                    ->relationship('supplier', 'name', fn (Builder $query): Builder => $query->where(
                        'company_id',
                        Filament::getTenant()?->getKey(),
                    ))
                    ->searchable()
                    ->preload()
                    ->native(false),
                SelectFilter::make('expense_category_id')
                    ->label('Categoria')
                    ->relationship('expenseCategory', 'name', fn (Builder $query): Builder => $query->where(
                        'company_id',
                        Filament::getTenant()?->getKey(),
                    ))
                    ->searchable()
                    ->preload()
                    ->native(false),
            ])
            ->defaultSort('competence_date', 'desc')
            ->recordActions([
                self::makeRegisterPaymentAction(),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['supplier', 'expenseCategory', 'installments']));
    }

    protected static function makeRegisterPaymentAction(): Action
    {
        return Action::make('registerPayment')
            ->label('Pagar')
            ->icon('heroicon-o-banknotes')
            ->color('success')
            ->visible(fn (Payable $record): bool => auth()->user()?->can('registerPayment', $record) ?? false)
            ->modalHeading('Registrar pagamento')
            ->schema(fn (Payable $record): array => self::paymentFormSchema($record))
            ->action(fn (Payable $record, array $data): mixed => self::processPayment($record, $data));
    }

    /**
     * @return array<int, mixed>
     */
    protected static function paymentFormSchema(Payable $payable): array
    {
        $defaultInstallment = self::firstOpenInstallment($payable);

        return [
            Select::make('payable_installment_id')
                ->label('Parcela')
                ->options(fn (): array => self::installmentOptions($payable))
                ->default($defaultInstallment?->getKey())
                ->required()
                ->native(false),
            TextInput::make('settled_principal_amount')
                ->label('Valor pago')
                ->numeric()
                ->minValue(0.01)
                ->step(0.01)
                ->prefix('R$')
                ->default(fn (): ?string => $defaultInstallment !== null
                    ? (string) $defaultInstallment->outstanding_amount
                    : null)
                ->required(),
            TextInput::make('interest_amount')
                ->label('Juros')
                ->numeric()
                ->minValue(0)
                ->step(0.01)
                ->prefix('R$')
                ->default('0.00'),
            TextInput::make('penalty_amount')
                ->label('Multa')
                ->numeric()
                ->minValue(0)
                ->step(0.01)
                ->prefix('R$')
                ->default('0.00'),
            TextInput::make('fee_amount')
                ->label('Tarifa')
                ->numeric()
                ->minValue(0)
                ->step(0.01)
                ->prefix('R$')
                ->default('0.00'),
            TextInput::make('discount_amount')
                ->label('Desconto')
                ->numeric()
                ->minValue(0)
                ->step(0.01)
                ->prefix('R$')
                ->default('0.00'),
            Select::make('method')
                ->label('Forma de pagamento')
                ->options(PaymentMethod::options())
                ->required()
                ->native(false)
                ->default(PaymentMethod::Pix->value),
            Select::make('financial_account_id')
                ->label('Conta financeira')
                ->options(fn (): array => FinancialAccount::query()
                    ->where('company_id', Filament::getTenant()?->getKey())
                    ->where('is_active', true)
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->all())
                ->required()
                ->searchable()
                ->native(false),
            DateTimePicker::make('paid_at')
                ->label('Pago em')
                ->default(now())
                ->required()
                ->native(false),
            TextInput::make('reference')
                ->label('Referência')
                ->maxLength(255),
            Textarea::make('notes')
                ->label('Observações')
                ->rows(2),
        ];
    }

    /**
     * @return array<int, string>
     */
    protected static function installmentOptions(Payable $payable): array
    {
        return $payable->installments()
            ->whereIn('status', [PayableStatus::Open, PayableStatus::Partial])
            ->where('outstanding_amount', '>', 0)
            ->orderBy('due_date')
            ->get()
            ->mapWithKeys(fn (PayableInstallment $installment): array => [
                $installment->getKey() => sprintf(
                    'Parcela %d - %s - saldo R$ %s',
                    $installment->installment_number,
                    Carbon::parse($installment->due_date)->format('d/m/Y'),
                    number_format((float) $installment->outstanding_amount, 2, ',', '.'),
                ),
            ])
            ->all();
    }

    protected static function firstOpenInstallment(Payable $payable): ?PayableInstallment
    {
        return $payable->installments()
            ->whereIn('status', [PayableStatus::Open, PayableStatus::Partial])
            ->where('outstanding_amount', '>', 0)
            ->orderBy('due_date')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected static function processPayment(Payable $payable, array $data): void
    {
        /** @var Company $company */
        $company = Filament::getTenant();
        /** @var User $user */
        $user = auth()->user();

        $installment = PayableInstallment::query()
            ->where('company_id', $company->getKey())
            ->where('payable_id', $payable->getKey())
            ->findOrFail($data['payable_installment_id']);

        $account = FinancialAccount::query()
            ->where('company_id', $company->getKey())
            ->findOrFail($data['financial_account_id']);

        app(PayablePaymentService::class)->record(
            $company,
            $installment,
            $account,
            $user,
            new PayablePaymentData(
                settledPrincipalAmount: self::formatMoney($data['settled_principal_amount']),
                method: PaymentMethod::from($data['method']),
                paidAt: Carbon::parse($data['paid_at']),
                interestAmount: self::formatMoney($data['interest_amount'] ?? '0.00'),
                penaltyAmount: self::formatMoney($data['penalty_amount'] ?? '0.00'),
                feeAmount: self::formatMoney($data['fee_amount'] ?? '0.00'),
                discountAmount: self::formatMoney($data['discount_amount'] ?? '0.00'),
                reference: $data['reference'] ?? null,
                notes: $data['notes'] ?? null,
            ),
        );

        Notification::make()
            ->success()
            ->title('Conta paga')
            ->send();
    }

    protected static function formatMoney(mixed $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }
}
