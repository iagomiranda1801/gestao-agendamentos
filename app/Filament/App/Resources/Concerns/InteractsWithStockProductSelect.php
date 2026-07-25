<?php

namespace App\Filament\App\Resources\Concerns;

use App\Models\Company;
use App\Models\Product;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;

class InteractsWithStockProductSelect
{
    /**
     * @return array<int, string>
     */
    public static function getStockProductOptions(): array
    {
        /** @var Company $company */
        $company = Filament::getTenant();

        return Product::query()
            ->where('company_id', $company->getKey())
            ->active()
            ->tracksStock()
            ->with('measurementUnit')
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Product $product): array => [
                $product->getKey() => "{$product->name} — {$product->measurementUnit->symbol}",
            ])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public static function searchStockProducts(string $search): array
    {
        /** @var Company $company */
        $company = Filament::getTenant();

        return Product::query()
            ->where('company_id', $company->getKey())
            ->active()
            ->tracksStock()
            ->where('name', 'like', "%{$search}%")
            ->with('measurementUnit')
            ->limit(50)
            ->get()
            ->mapWithKeys(fn (Product $product): array => [
                $product->getKey() => "{$product->name} — {$product->measurementUnit->symbol}",
            ])
            ->all();
    }

    public static function makeProductSelect(): Select
    {
        return Select::make('product_id')
            ->label('Produto')
            ->options(fn (): array => self::getStockProductOptions())
            ->getSearchResultsUsing(fn (string $search): array => self::searchStockProducts($search))
            ->getOptionLabelUsing(function ($value): ?string {
                $product = Product::query()
                    ->with('measurementUnit')
                    ->find($value);

                if (! $product) {
                    return null;
                }

                return "{$product->name} — {$product->measurementUnit->symbol}";
            })
            ->searchable()
            ->required()
            ->native(false)
            ->live();
    }
}
