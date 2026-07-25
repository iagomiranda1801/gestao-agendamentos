<?php

namespace App\Filament\App\Widgets;

use App\Filament\App\Pages\InventoryPosition;
use App\Models\Company;
use App\Models\Product;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class InventoryPositionStatsWidget extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        /** @var Company $company */
        $company = Filament::getTenant();

        $products = Product::query()
            ->where('company_id', $company->getKey())
            ->with('inventoryBalance')
            ->get();

        $trackedProducts = $products->where('tracks_stock', true);

        $totalInventoryValue = '0';
        $lowStockCount = 0;
        $outOfStockCount = 0;

        foreach ($trackedProducts as $product) {
            $totalInventoryValue = bcadd($totalInventoryValue, $product->getCurrentStockValue(), 6);

            $quantity = $product->getCurrentStockQuantity();

            if (bccomp($quantity, '0', 4) <= 0) {
                $outOfStockCount++;

                continue;
            }

            if ($product->isBelowMinimumStock()) {
                $lowStockCount++;
            }
        }

        return [
            Stat::make('Valor total em estoque', 'R$ '.number_format((float) $totalInventoryValue, 2, ',', '.'))
                ->description('Produtos com controle de estoque'),
            Stat::make('Produtos com estoque baixo', (string) $lowStockCount)
                ->description('Abaixo do mínimo configurado')
                ->color('warning'),
            Stat::make('Produtos sem estoque', (string) $outOfStockCount)
                ->description('Quantidade zerada')
                ->color('danger'),
            Stat::make('Produtos controlados', (string) $trackedProducts->count())
                ->description('Com controle de estoque ativo'),
        ];
    }

    public static function canView(): bool
    {
        return InventoryPosition::canAccess();
    }
}
