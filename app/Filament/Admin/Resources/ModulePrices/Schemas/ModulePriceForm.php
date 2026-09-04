<?php

namespace App\Filament\Admin\Resources\ModulePrices\Schemas;

use App\Enums\BillingInterval;
use App\Enums\CompanyModule;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class ModulePriceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('module')
                    ->label('Módulo')
                    ->options(CompanyModule::options())
                    ->disabled()
                    ->dehydrated(),
                Select::make('interval')
                    ->label('Ciclo')
                    ->options(BillingInterval::options())
                    ->disabled()
                    ->dehydrated(),
                TextInput::make('price_cents')
                    ->label('Preço (R$)')
                    ->numeric()
                    ->required()
                    ->minValue(0)
                    ->step(0.01)
                    ->formatStateUsing(fn (mixed $state): string => number_format(((int) $state) / 100, 2, '.', ''))
                    ->dehydrateStateUsing(fn (mixed $state): int => (int) round((float) str_replace(',', '.', (string) $state) * 100)),
            ]);
    }
}
