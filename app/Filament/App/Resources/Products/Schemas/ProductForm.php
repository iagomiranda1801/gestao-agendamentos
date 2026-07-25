<?php

namespace App\Filament\App\Resources\Products\Schemas;

use App\Enums\ProductType;
use App\Models\MeasurementUnit;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Identificação')
                    ->schema([
                        TextInput::make('name')
                            ->label('Nome')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('sku')
                            ->label('SKU')
                            ->maxLength(255),
                        Select::make('type')
                            ->label('Tipo')
                            ->options(ProductType::options())
                            ->required()
                            ->native(false),
                        Select::make('measurement_unit_id')
                            ->label('Unidade de medida')
                            ->options(fn (): array => MeasurementUnit::query()
                                ->active()
                                ->orderBy('name')
                                ->get()
                                ->mapWithKeys(fn (MeasurementUnit $unit): array => [
                                    $unit->getKey() => "{$unit->name} ({$unit->symbol})",
                                ])
                                ->all())
                            ->searchable()
                            ->required()
                            ->native(false),
                        Textarea::make('description')
                            ->label('Descrição')
                            ->rows(3)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
                Section::make('Custos e controle')
                    ->schema([
                        TextInput::make('reference_unit_cost')
                            ->label('Custo unitário de referência')
                            ->numeric()
                            ->prefix('R$')
                            ->step(0.000001)
                            ->minValue(0)
                            ->default(0)
                            ->required(),
                        TextInput::make('minimum_stock')
                            ->label('Estoque mínimo')
                            ->numeric()
                            ->step(0.0001)
                            ->minValue(0)
                            ->default(0)
                            ->required(),
                        Toggle::make('tracks_stock')
                            ->label('Controlar estoque')
                            ->default(true),
                        Toggle::make('is_active')
                            ->label('Produto ativo')
                            ->default(true),
                    ])
                    ->columns(2),
                Section::make('Informações adicionais')
                    ->schema([
                        Textarea::make('notes')
                            ->label('Observações')
                            ->rows(4)
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
