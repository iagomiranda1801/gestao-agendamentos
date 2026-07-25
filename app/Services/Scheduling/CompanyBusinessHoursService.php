<?php

namespace App\Services\Scheduling;

use App\Models\Company;
use App\Models\CompanyBusinessHour;
use App\Support\CompanyDateTime;
use App\Support\TimeRangeValidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CompanyBusinessHoursService
{
    /**
     * @return list<array<string, mixed>>
     */
    public function getWeeklyHours(Company $company): array
    {
        return CompanyBusinessHour::query()
            ->where('company_id', $company->getKey())
            ->orderBy('weekday')
            ->orderBy('sort_order')
            ->get()
            ->map(fn (CompanyBusinessHour $hour): array => [
                'id' => $hour->getKey(),
                'weekday' => $hour->weekday,
                'start_time' => substr((string) $hour->start_time, 0, 5),
                'end_time' => substr((string) $hour->end_time, 0, 5),
                'is_active' => $hour->is_active,
            ])
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $hours
     */
    public function replaceWeeklyHours(Company $company, array $hours): void
    {
        DB::transaction(function () use ($company, $hours): void {
            $this->validateHours($hours);

            CompanyBusinessHour::query()
                ->where('company_id', $company->getKey())
                ->delete();

            foreach ($hours as $index => $hour) {
                $record = new CompanyBusinessHour([
                    'weekday' => (int) $hour['weekday'],
                    'start_time' => $this->normalizeTime($hour['start_time']),
                    'end_time' => $this->normalizeTime($hour['end_time']),
                    'sort_order' => $index,
                    'is_active' => (bool) ($hour['is_active'] ?? true),
                ]);
                $record->company()->associate($company);
                $record->save();
            }
        });
    }

    /**
     * @param  list<array<string, mixed>>  $hours
     */
    protected function validateHours(array $hours): void
    {
        $grouped = [];

        foreach ($hours as $index => $hour) {
            if (! ($hour['is_active'] ?? true)) {
                continue;
            }

            $weekday = (int) $hour['weekday'];
            $start = CompanyDateTime::timeToMinutes($this->normalizeTime($hour['start_time']));
            $end = CompanyDateTime::timeToMinutes($this->normalizeTime($hour['end_time']));

            try {
                TimeRangeValidator::assertEndAfterStart($start, $end);
            } catch (ValidationException $exception) {
                throw ValidationException::withMessages([
                    "business_hours.{$index}.end_time" => 'O horário final deve ser posterior ao horário inicial.',
                ]);
            }

            $grouped[$weekday][] = ['start' => $start, 'end' => $end];
        }

        foreach ($grouped as $weekday => $ranges) {
            try {
                TimeRangeValidator::assertNoOverlap($ranges, 'start_time');
            } catch (ValidationException) {
                throw ValidationException::withMessages([
                    'business_hours' => "Existem faixas sobrepostas para {$weekday}.",
                ]);
            }
        }
    }

    protected function normalizeTime(string $time): string
    {
        return strlen($time) === 5 ? "{$time}:00" : $time;
    }
}
