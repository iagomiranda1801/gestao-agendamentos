<?php

namespace App\Filament\App\Resources\Orders\Tables;

use App\Enums\OrderFulfillment;
use App\Enums\OrderStatus;
use App\Models\Company;
use App\Models\Order;
use App\Support\CompanyDateTime;
use App\Support\Money;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class OrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')
                    ->label('Nº')
                    ->formatStateUsing(fn (Order $record): string => $record->displayNumber())
                    ->sortable()
                    ->searchable(),
                TextColumn::make('public_code')
                    ->label('Código')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('customer_name')
                    ->label('Cliente')
                    ->searchable(['customer_name', 'customer_phone', 'customer_phone_normalized'])
                    ->sortable(),
                TextColumn::make('fulfillment')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn (OrderFulfillment $state): string => $state->label()),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (OrderStatus $state): string => $state->label())
                    ->color(fn (OrderStatus $state): string => $state->color()),
                TextColumn::make('total_cents')
                    ->label('Total')
                    ->formatStateUsing(fn (int $state): string => Money::formatCents($state))
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Recebido em')
                    ->formatStateUsing(function (Order $record): string {
                        /** @var Company $company */
                        $company = Filament::getTenant();

                        return $record->created_at
                            ? CompanyDateTime::formatLocal($company, $record->created_at)
                            : '—';
                    })
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(OrderStatus::options()),
                SelectFilter::make('fulfillment')
                    ->label('Tipo')
                    ->options(OrderFulfillment::options()),
                Filter::make('period')
                    ->label('Período')
                    ->schema([
                        DatePicker::make('from')->label('De'),
                        DatePicker::make('until')->label('Até'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn (Builder $q, $date): Builder => $q->whereDate('created_at', '>=', $date))
                            ->when($data['until'] ?? null, fn (Builder $q, $date): Builder => $q->whereDate('created_at', '<=', $date));
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                ViewAction::make(),
            ])
            ->searchable();
    }
}
