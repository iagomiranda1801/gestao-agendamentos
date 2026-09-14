<?php

namespace App\Services\Orders;

use App\Models\Company;
use App\Models\Product;
use App\Support\Money;
use Illuminate\Support\Collection;

class OrderCatalogService
{
    /**
     * @return Collection<int, Product>
     */
    public function availableProducts(Company $company): Collection
    {
        return Product::query()
            ->where('company_id', $company->getKey())
            ->availableForOnlineOrder()
            ->orderBy('online_order_category')
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<string, Collection<int, Product>>
     */
    public function groupedByCategory(Company $company): Collection
    {
        return $this->availableProducts($company)
            ->groupBy(fn (Product $product): string => filled($product->online_order_category)
                ? (string) $product->online_order_category
                : 'Cardápio');
    }

    public function findAvailable(Company $company, int $productId): ?Product
    {
        return Product::query()
            ->where('company_id', $company->getKey())
            ->availableForOnlineOrder()
            ->whereKey($productId)
            ->first();
    }

    public function unitPriceCents(Product $product): int
    {
        return Money::toCents($product->sale_price);
    }
}
