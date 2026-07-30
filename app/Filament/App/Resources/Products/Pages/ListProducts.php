<?php

namespace App\Filament\App\Resources\Products\Pages;

use App\Enums\ProductType;
use App\Filament\App\Resources\Products\ProductResource;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListProducts extends ListRecords
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        return [
            'all' => Tab::make('Todos'),
            'sale' => Tab::make('Produtos de venda')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('type', ProductType::Sale->value)),
            'consumable' => Tab::make('Produtos de consumo')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('type', ProductType::Consumable->value)),
            'asset' => Tab::make('Materiais operacionais')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('type', ProductType::Asset->value)),
        ];
    }
}
