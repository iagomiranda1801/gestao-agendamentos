<?php

namespace App\Filament\App\Resources\Orders\Schemas;

use App\Enums\OrderFulfillment;
use App\Enums\OrderStatus;
use App\Models\Company;
use App\Models\Order;
use App\Support\CompanyDateTime;
use App\Support\Money;
use Filament\Facades\Filament;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class OrderInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Pedido')->schema([
                TextEntry::make('number')
                    ->label('Número')
                    ->state(fn (Order $record): string => $record->displayNumber()),
                TextEntry::make('public_code')->label('Código público'),
                TextEntry::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (OrderStatus $state): string => $state->label())
                    ->color(fn (OrderStatus $state): string => $state->color()),
                TextEntry::make('fulfillment')
                    ->label('Tipo')
                    ->formatStateUsing(fn (OrderFulfillment $state): string => $state->label()),
                TextEntry::make('created_at')
                    ->label('Recebido em')
                    ->state(function (Order $record): string {
                        /** @var Company $company */
                        $company = Filament::getTenant();

                        return $record->created_at
                            ? CompanyDateTime::formatLocal($company, $record->created_at)
                            : '—';
                    }),
                TextEntry::make('total_cents')
                    ->label('Total')
                    ->state(fn (Order $record): string => Money::formatCents((int) $record->total_cents)),
            ])->columns(3),
            Section::make('Cliente')->schema([
                TextEntry::make('customer_name')->label('Nome'),
                TextEntry::make('customer_phone')->label('Telefone'),
                TextEntry::make('customer_email')->label('E-mail')->placeholder('—'),
                TextEntry::make('delivery_address')
                    ->label('Endereço')
                    ->state(fn (Order $record): string => $record->formattedDeliveryAddress() ?: '—')
                    ->columnSpanFull(),
                TextEntry::make('notes')->label('Observações')->placeholder('—')->columnSpanFull(),
                TextEntry::make('cancel_reason')->label('Motivo do cancelamento')->placeholder('—')->columnSpanFull(),
            ])->columns(2),
        ]);
    }
}
