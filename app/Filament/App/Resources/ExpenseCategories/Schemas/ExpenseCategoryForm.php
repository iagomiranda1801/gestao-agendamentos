<?php

namespace App\Filament\App\Resources\ExpenseCategories\Schemas;

use App\Enums\ExpenseCategoryType;
use App\Models\ExpenseCategory;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class ExpenseCategoryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Categoria')
                    ->schema([
                        TextInput::make('name')
                            ->label('Nome')
                            ->required()
                            ->maxLength(255)
                            ->scopedUnique(ignoreRecord: true),
                        TextInput::make('code')
                            ->label('Código')
                            ->maxLength(255)
                            ->scopedUnique(ignoreRecord: true),
                        Select::make('parent_id')
                            ->label('Categoria pai')
                            ->relationship(
                                name: 'parent',
                                titleAttribute: 'name',
                                modifyQueryUsing: fn (Builder $query) => $query->where('is_active', true),
                            )
                            ->searchable()
                            ->preload(),
                        Select::make('type')
                            ->label('Tipo')
                            ->options(ExpenseCategoryType::options())
                            ->required()
                            ->native(false),
                        Textarea::make('description')
                            ->label('Descrição')
                            ->rows(3)
                            ->columnSpanFull(),
                        Toggle::make('affects_managerial_result')
                            ->label('Afeta resultado gerencial')
                            ->default(true)
                            ->disabled(fn (?ExpenseCategory $record): bool => $record?->type === ExpenseCategoryType::StockPurchase),
                        TextInput::make('sort_order')
                            ->label('Ordem')
                            ->numeric()
                            ->default(0)
                            ->minValue(0),
                        Toggle::make('is_active')
                            ->label('Ativa')
                            ->default(true)
                            ->disabled(fn (?ExpenseCategory $record): bool => (bool) $record?->is_system),
                    ])
                    ->columns(2),
            ]);
    }
}
