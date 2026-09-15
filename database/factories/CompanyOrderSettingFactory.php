<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\CompanyOrderSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompanyOrderSetting>
 */
class CompanyOrderSettingFactory extends Factory
{
    protected $model = CompanyOrderSetting::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'online_ordering_enabled' => false,
            'pickup_enabled' => true,
            'delivery_enabled' => true,
            'dine_in_enabled' => false,
            'delivery_fee_cents' => 0,
            'min_order_cents' => 0,
            'delivery_radius_note' => null,
            'orders_whatsapp_notify' => true,
            'whatsapp_order_link_bot_enabled' => true,
            'page_title' => null,
            'page_description' => null,
            'confirmation_message' => null,
            'primary_color' => null,
        ];
    }

    public function forCompany(Company $company): static
    {
        return $this->state(fn (array $attributes) => [
            'company_id' => $company->getKey(),
        ]);
    }

    public function enabled(): static
    {
        return $this->state(fn (array $attributes) => [
            'online_ordering_enabled' => true,
        ]);
    }
}
