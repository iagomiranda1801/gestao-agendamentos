<?php

namespace Database\Factories;

use App\Enums\StockDocumentStatus;
use App\Enums\StockDocumentType;
use App\Models\Company;
use App\Models\StockDocument;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockDocument>
 */
class StockDocumentFactory extends Factory
{
    protected $model = StockDocument::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'type' => StockDocumentType::Purchase,
            'status' => StockDocumentStatus::Draft,
            'occurred_at' => now(),
            'notes' => fake()->optional()->sentence(),
            'total_amount' => 0,
            'created_by' => User::factory(),
        ];
    }

    public function forCompany(Company $company): static
    {
        return $this->state(fn (array $attributes) => [
            'company_id' => $company->getKey(),
        ]);
    }

    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => StockDocumentStatus::Draft,
        ]);
    }

    public function posted(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => StockDocumentStatus::Posted,
            'posted_at' => now(),
        ]);
    }

    public function reversed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => StockDocumentStatus::Reversed,
            'reversed_at' => now(),
        ]);
    }

    public function purchase(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => StockDocumentType::Purchase,
        ]);
    }

    public function openingBalance(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => StockDocumentType::OpeningBalance,
        ]);
    }

    public function manualEntry(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => StockDocumentType::ManualEntry,
        ]);
    }

    public function manualExit(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => StockDocumentType::ManualExit,
        ]);
    }

    public function loss(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => StockDocumentType::Loss,
        ]);
    }

    public function inventoryCount(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => StockDocumentType::InventoryCount,
        ]);
    }
}
