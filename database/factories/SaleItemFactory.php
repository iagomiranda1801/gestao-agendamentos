<?php

namespace Database\Factories;

use App\Enums\SaleItemType;
use App\Models\Company;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SaleItem>
 */
class SaleItemFactory extends Factory
{
    protected $model = SaleItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'sale_id' => Sale::factory(),
            'item_type' => SaleItemType::Product,
            'product_id' => Product::factory(),
            'name_snapshot' => fake()->words(2, true),
            'quantity' => '1.0000',
            'unit_price_snapshot' => '50.00',
            'unit_cost_snapshot' => '20.000000',
            'discount_amount' => '0.00',
            'line_total' => '50.00',
            'tracks_stock_snapshot' => true,
        ];
    }

    public function forCompany(Company $company): static
    {
        return $this->state(fn (array $attributes) => [
            'company_id' => $company->getKey(),
            'sale_id' => Sale::factory()->forCompany($company),
            'product_id' => Product::factory()->forCompany($company),
        ]);
    }
}
