<?php

namespace Database\Factories;

use App\Enums\ScheduleBlockType;
use App\Models\Company;
use App\Models\Professional;
use App\Models\ScheduleBlock;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScheduleBlock>
 */
class ScheduleBlockFactory extends Factory
{
    protected $model = ScheduleBlock::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = now()->addDay()->setTime(14, 0);

        return [
            'company_id' => Company::factory(),
            'professional_id' => null,
            'type' => ScheduleBlockType::Manual,
            'title' => fake()->sentence(3),
            'start_at' => $start,
            'end_at' => $start->copy()->addHour(),
            'is_all_day' => false,
            'reason' => fake()->optional()->sentence(),
            'created_by' => User::factory(),
            'is_active' => true,
        ];
    }

    public function forCompany(Company $company): static
    {
        return $this->state(fn (array $attributes) => [
            'company_id' => $company->getKey(),
        ]);
    }

    public function forProfessional(Professional $professional): static
    {
        return $this->state(fn (array $attributes) => [
            'company_id' => $professional->company_id,
            'professional_id' => $professional->getKey(),
        ]);
    }
}
