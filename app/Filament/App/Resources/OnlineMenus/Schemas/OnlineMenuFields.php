<?php

namespace App\Filament\App\Resources\OnlineMenus\Schemas;

use App\Models\Company;
use App\Services\Orders\MenuCategoryService;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Illuminate\Database\Eloquent\Builder;

class OnlineMenuFields
{
    public static function categorySelect(): Select
    {
        return Select::make('menu_category_id')
            ->label('Categoria')
            ->relationship(
                name: 'menuCategory',
                titleAttribute: 'name',
                modifyQueryUsing: function (Builder $query): Builder {
                    $company = Filament::getTenant();

                    if ($company instanceof Company) {
                        $query->where('company_id', $company->getKey());
                    }

                    return $query
                        ->where('is_active', true)
                        ->orderBy('sort_order')
                        ->orderBy('name');
                },
            )
            ->searchable()
            ->preload()
            ->native(false)
            ->createOptionForm([
                TextInput::make('name')
                    ->label('Nome')
                    ->required()
                    ->maxLength(80),
            ])
            ->createOptionUsing(function (array $data): int {
                /** @var Company $company */
                $company = Filament::getTenant();

                $category = app(MenuCategoryService::class)->create($company, [
                    'name' => $data['name'] ?? '',
                    'is_active' => true,
                ]);

                return (int) $category->getKey();
            });
    }

    public static function variantsRepeater(): Repeater
    {
        return Repeater::make('variants')
            ->label('Tamanhos')
            ->schema([
                Hidden::make('id'),
                TextInput::make('name')
                    ->label('Nome')
                    ->placeholder('Média, Grande…')
                    ->required()
                    ->maxLength(40),
                TextInput::make('price')
                    ->label('Preço')
                    ->numeric()
                    ->prefix('R$')
                    ->step(0.01)
                    ->minValue(0.01)
                    ->required(),
                Toggle::make('is_default')
                    ->label('Padrão')
                    ->default(false),
                Toggle::make('is_active')
                    ->label('Ativo')
                    ->default(true),
            ])
            ->columns(4)
            ->defaultItems(0)
            ->reorderable(false)
            ->addActionLabel('Adicionar tamanho')
            ->helperText('Opcional. Sem tamanhos, o pedido usa o preço do item. Com tamanhos, o cliente escolhe um (o padrão já vem selecionado).')
            ->columnSpanFull();
    }
}
