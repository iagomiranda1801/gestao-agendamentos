<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Product;
use App\Models\StockDocument;
use App\Models\StockDocumentItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockDocumentItem>
 */
class StockDocumentItemFactory extends Factory
{
    protected $model = StockDocumentItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'stock_document_id' => StockDocument::factory(),
            'product_id' => Product::factory(),
            'quantity' => fake()->randomFloat(4, 1, 10),
            'unit_cost' => fake()->randomFloat(6, 0.01, 100),
        ];
    }

    public function forDocument(StockDocument $document): static
    {
        return $this->state(fn (array $attributes) => [
            'company_id' => $document->company_id,
            'stock_document_id' => $document->getKey(),
        ]);
    }

    public function forProduct(Product $product): static
    {
        return $this->state(fn (array $attributes) => [
            'company_id' => $product->company_id,
            'product_id' => $product->getKey(),
        ]);
    }
}
