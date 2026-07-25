<?php

namespace App\Filament\App\Resources\Payables\Tables;

use App\Enums\PayableOrigin;
use App\Enums\PayableStatus;
use App\Models\Payable;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
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
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['supplier', 'expenseCategory', 'installments']));
    }
}
