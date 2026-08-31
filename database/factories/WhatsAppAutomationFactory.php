<?php

namespace Database\Factories;

use App\Enums\WhatsAppAutomationType;
use App\Models\Company;
use App\Models\WhatsAppAutomation;
use App\Services\WhatsApp\Automations\WhatsAppAutomationDefaults;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WhatsAppAutomation>
 */
class WhatsAppAutomationFactory extends Factory
{
    protected $model = WhatsAppAutomation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $type = WhatsAppAutomationType::Reminder;
        $defaults = WhatsAppAutomationDefaults::forType($type);

        return [
            'company_id' => Company::factory(),
            'type' => $type,
            'is_enabled' => false,
            'delay_value' => $defaults['delay_value'],
            'cooldown_days' => $defaults['cooldown_days'],
            'quiet_hours_start' => $defaults['quiet_hours_start'],
            'quiet_hours_end' => $defaults['quiet_hours_end'],
            'message_template' => $defaults['message_template'],
        ];
    }

    public function forCompany(Company $company): static
    {
        return $this->state(fn (array $attributes) => [
            'company_id' => $company->getKey(),
        ]);
    }

    public function reminder(): static
    {
        return $this->ofType(WhatsAppAutomationType::Reminder);
    }

    public function afterSales(): static
    {
        return $this->ofType(WhatsAppAutomationType::AfterSales);
    }

    public function winBack(): static
    {
        return $this->ofType(WhatsAppAutomationType::WinBack);
    }

    public function enabled(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_enabled' => true,
        ]);
    }

    public function ofType(WhatsAppAutomationType $type): static
    {
        $defaults = WhatsAppAutomationDefaults::forType($type);

        return $this->state(fn (array $attributes) => [
            'type' => $type,
            'delay_value' => $defaults['delay_value'],
            'cooldown_days' => $defaults['cooldown_days'],
            'message_template' => $defaults['message_template'],
        ]);
    }
}
