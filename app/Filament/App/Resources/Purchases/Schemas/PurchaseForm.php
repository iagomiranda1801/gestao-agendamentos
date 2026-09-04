<?php

namespace App\Filament\App\Resources\Purchases\Schemas;

use App\Filament\App\Resources\Concerns\InteractsWithStockProductSelect;
use App\Models\Company;
use App\Models\Supplier;
use Filament\Facades\Filament;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class PurchaseForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Documento')
                    ->schema([
                        DateTimePicker::make('occurred_at')
                            ->label('Data da ocorrência')
                            ->required()
                            ->default(now())
                            ->native(false),
                        Select::make('supplier_id')
                            ->label('Fornecedor')
                            ->options(fn (): array => self::getSupplierOptions())
                            ->searchable()
                            ->native(false),
                        TextInput::make('document_number')
                            ->label('Número do documento')
                            ->maxLength(255),
                        TextInput::make('external_reference')
                            ->label('Referência externa')
                            ->maxLength(255),
                        Textarea::make('notes')
                            ->label('Observações')
                            ->rows(3)
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
                                    ->live(onBlur: true),
                                TextInput::make('unit_cost')
                                    ->label('Custo unitário')
                                    ->numeric()
                                    ->prefix('R$')
                                    ->step(0.000001)
                                    ->minValue(0)
                                    ->required()
                                    ->live(onBlur: true),
                                TextInput::make('line_total')
                                    ->label('Total da linha')
                                    ->prefix('R$')
                                    ->disabled()
                                    ->dehydrated(false)
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
                            ->minItems(1)
                            ->addActionLabel('Adicionar produto')
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    /**
     * @return array<int, string>
     */
    protected static function getSupplierOptions(): array
    {
        /** @var Company $company */
        $company = Filament::getTenant();

        return Supplier::query()
            ->where('company_id', $company->getKey())
            ->active()
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }
}
