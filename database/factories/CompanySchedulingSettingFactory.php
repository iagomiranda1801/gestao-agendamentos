<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\CompanySchedulingSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompanySchedulingSetting>
 */
class CompanySchedulingSettingFactory extends Factory
{
    protected $model = CompanySchedulingSetting::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'slot_interval_minutes' => 15,
            'calendar_start_time' => '07:00:00',
            'calendar_end_time' => '22:00:00',
            'week_starts_on' => 1,
            'default_calendar_view' => 'timeGridWeek',
            'allow_employee_self_view' => true,
            'public_booking_enabled' => false,
            'online_auto_confirm' => false,
            'require_email_for_online_booking' => false,
            'allow_public_cancellation' => true,
            'allow_public_reschedule' => true,
            'allow_professional_selection' => true,
            'allow_no_professional_preference' => false,
            'show_service_price' => true,
            'show_service_duration' => true,
            'minimum_advance_minutes' => 120,
            'maximum_advance_days' => 60,
            'cancellation_minimum_advance_minutes' => 720,
            'reschedule_minimum_advance_minutes' => 720,
            'whatsapp_notifications_enabled' => false,
            'whatsapp_instance' => null,
            'whatsapp_sender_phone' => null,
            'whatsapp_confirmation_template' => null,
        ];
    }

    public function publicBookingEnabled(): static
    {
        return $this->state(fn (array $attributes) => [
            'public_booking_enabled' => true,
        ]);
    }

    public function publicBookingDisabled(): static
    {
        return $this->state(fn (array $attributes) => [
            'public_booking_enabled' => false,
        ]);
    }

    public function autoConfirm(): static
    {
        return $this->state(fn (array $attributes) => [
            'public_booking_enabled' => true,
            'online_auto_confirm' => true,
        ]);
    }

    public function manualConfirm(): static
    {
        return $this->state(fn (array $attributes) => [
            'public_booking_enabled' => true,
            'online_auto_confirm' => false,
        ]);
    }

    public function cancellationDisabled(): static
    {
        return $this->state(fn (array $attributes) => [
            'allow_public_cancellation' => false,
        ]);
    }

    public function rescheduleDisabled(): static
    {
        return $this->state(fn (array $attributes) => [
            'allow_public_reschedule' => false,
        ]);
    }

    public function forCompany(Company $company): static
    {
        return $this->state(fn (array $attributes) => [
            'company_id' => $company->getKey(),
        ]);
    }
}
