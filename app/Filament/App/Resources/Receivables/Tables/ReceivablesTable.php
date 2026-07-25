<?php

namespace App\Filament\App\Resources\Receivables\Tables;

use App\Enums\ReceivableStatus;
use App\Filament\App\Resources\Concerns\InteractsWithPaymentRegistration;
use App\Models\Company;
use App\Models\Receivable;
use App\Support\CompanyDateTime;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

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
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['attendance', 'client']));
    }
}
