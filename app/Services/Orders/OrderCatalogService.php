<?php

namespace App\Services\Orders;

use App\Models\Company;
use App\Models\MenuCategory;
use App\Models\Product;
use App\Models\ProductVariant;
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
            ->with([
                'menuCategory',
                'activeVariants',
            ])
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<string, Collection<int, Product>>
     */
    public function groupedByCategory(Company $company): Collection
    {
        $products = $this->availableProducts($company);

        $categories = MenuCategory::query()
            ->where('company_id', $company->getKey())
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $grouped = collect();

        foreach ($categories as $category) {
            $items = $products
                ->where('menu_category_id', $category->getKey())
                ->values();

            if ($items->isNotEmpty()) {
                $grouped->put($category->name, $items);
            }
        }

        $assignedIds = $categories->modelKeys();

        $uncategorized = $products
            ->filter(fn (Product $product): bool => $product->menu_category_id === null
                || ! in_array((int) $product->menu_category_id, array_map('intval', $assignedIds), true))
            ->values();

        if ($uncategorized->isNotEmpty()) {
            $grouped->put('Cardápio', $uncategorized);
        }

        return $grouped;
    }

    public function findAvailable(Company $company, int $productId): ?Product
    {
        return Product::query()
            ->where('company_id', $company->getKey())
            ->availableForOnlineOrder()
            ->with('activeVariants')
            ->whereKey($productId)
            ->first();
    }

    public function findActiveVariant(Product $product, int $variantId): ?ProductVariant
    {
        if ($product->relationLoaded('activeVariants')) {
            return $product->activeVariants->firstWhere('id', $variantId);
        }

        return $product->activeVariants()->whereKey($variantId)->first();
    }

    public function defaultVariant(Product $product): ?ProductVariant
    {
        $variants = $product->relationLoaded('activeVariants')
            ? $product->activeVariants
            : $product->activeVariants()->get();

        if ($variants->isEmpty()) {
            return null;
        }

        return $variants->firstWhere('is_default', true) ?? $variants->first();
    }

    public function unitPriceCents(Product $product, ?ProductVariant $variant = null): int
    {
        if ($variant !== null) {
            return Money::toCents($variant->price);
        }

        return Money::toCents($product->sale_price);
    }

    public function displayPriceCents(Product $product): int
    {
        $variants = $product->relationLoaded('activeVariants')
            ? $product->activeVariants
            : $product->activeVariants()->get();

        if ($variants->isNotEmpty()) {
            return Money::toCents($variants->min('price'));
        }

        return Money::toCents($product->sale_price);
    }

    public function snapshotName(Product $product, ?ProductVariant $variant = null): string
    {
        if ($variant === null) {
            return (string) $product->name;
        }

        return $product->name.' — '.$variant->name;
    }
}
