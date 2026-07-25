<?php

namespace App\Filament\App\Resources\StockAdjustments\Schemas;

use App\Enums\StockDocumentType;
use App\Filament\App\Resources\Concerns\InteractsWithStockProductSelect;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class StockAdjustmentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Documento')
                    ->schema([
                        Select::make('type')
                            ->label('Tipo de ajuste')
                            ->options(StockDocumentType::adjustmentOptions())
                            ->required()
                            ->native(false)
                            ->live()
                            ->disabledOn('edit'),
                        DateTimePicker::make('occurred_at')
                            ->label('Data da ocorrência')
                            ->required()
                            ->default(now())
                            ->native(false),
                        Textarea::make('notes')
                            ->label('Observações / justificativa')
                            ->rows(3)
                            ->required(fn (Get $get): bool => self::typeRequiresJustification($get('type')))
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
                Section::make('Itens')
                    ->schema([
                        Repeater::make('items')
                            ->label('Produtos')
                            ->schema([
                                InteractsWithStockProductSelect::makeProductSelect(),
                                TextInput::make('quantity')
                                    ->label('Quantidade')
                                    ->numeric()
                                    ->step(0.0001)
                                    ->minValue(0.0001)
                                    ->required()
                                    ->visible(fn (Get $get): bool => ! self::isInventoryCountType($get('../../type')))
                                    ->live(onBlur: true),
                                TextInput::make('counted_quantity')
                                    ->label('Quantidade contada')
                                    ->numeric()
                                    ->step(0.0001)
                                    ->minValue(0)
                                    ->required()
                                    ->visible(fn (Get $get): bool => self::isInventoryCountType($get('../../type'))),
                                TextInput::make('unit_cost')
                                    ->label('Custo unitário')
                                    ->numeric()
                                    ->prefix('R$')
                                    ->step(0.000001)
                                    ->minValue(0)
                                    ->required(fn (Get $get): bool => self::requiresUnitCost($get('../../type')))
                                    ->visible(fn (Get $get): bool => self::showsUnitCost($get('../../type')))
                                    ->live(onBlur: true),
                                TextInput::make('line_total')
                                    ->label('Total da linha')
                                    ->prefix('R$')
                                    ->disabled()
                                    ->dehydrated(false)
                                    ->visible(fn (Get $get): bool => self::showsUnitCost($get('../../type')))
                                    ->formatStateUsing(function ($state, Get $get): ?string {
                                        $quantity = $get('quantity');
                                        $unitCost = $get('unit_cost');

                                        if ($quantity === null || $unitCost === null) {
                                            return null;
                                        }

                                        return number_format((float) bcmul((string) $quantity, (string) $unitCost, 6), 2, ',', '.');
                                    }),
                            ])
                            ->columns(4)
                            ->defaultItems(1)
                            ->addActionLabel('Adicionar produto')
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    protected static function isInventoryCountType(mixed $type): bool
    {
        return $type === StockDocumentType::InventoryCount->value;
    }

    protected static function showsUnitCost(mixed $type): bool
    {
        return in_array($type, [
            StockDocumentType::OpeningBalance->value,
            StockDocumentType::ManualEntry->value,
        ], true);
    }

    protected static function requiresUnitCost(mixed $type): bool
    {
        return $type === StockDocumentType::OpeningBalance->value;
    }

    protected static function typeRequiresJustification(mixed $type): bool
    {
        if (! is_string($type)) {
            return false;
        }

        $documentType = StockDocumentType::tryFrom($type);

        return $documentType?->requiresJustification() ?? false;
    }
}
