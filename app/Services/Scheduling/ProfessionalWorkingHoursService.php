<?php

namespace App\Services\Scheduling;

use App\Enums\Weekday;
use App\Models\Company;
use App\Models\Professional;
use App\Models\ProfessionalWorkingHour;
use App\Support\CompanyDateTime;
use App\Support\TimeRangeValidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProfessionalWorkingHoursService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Company $company, Professional $professional, array $data): ProfessionalWorkingHour
    {
        return DB::transaction(function () use ($company, $professional, $data): ProfessionalWorkingHour {
            $this->ensureBelongsToCompany($company, $professional);

            return $this->persistHour($company, $professional, $data);
        });
    }

    /**
     * @param  list<array<string, mixed>>  $hours
     * @return list<ProfessionalWorkingHour>
     */
    public function createMany(Company $company, Professional $professional, array $hours): array
    {
        if ($hours === []) {
            throw ValidationException::withMessages([
                'hours' => 'Informe pelo menos uma faixa de horário.',
            ]);
        }

        return DB::transaction(function () use ($company, $professional, $hours): array {
            $this->ensureBelongsToCompany($company, $professional);

            $created = [];

            foreach ($hours as $index => $data) {
                try {
                    $created[] = $this->persistHour($company, $professional, $data);
                } catch (ValidationException $exception) {
                    throw $this->withWeekdayContext($exception, $data, $index);
                }
            }

            return $created;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(
        Company $company,
        ProfessionalWorkingHour $hour,
        array $data,
    ): ProfessionalWorkingHour {
        return DB::transaction(function () use ($company, $hour, $data): ProfessionalWorkingHour {
            $this->ensureHourBelongsToCompany($company, $hour);

            $payload = $this->preparePayload($data);
            $this->validatePayload($company, $hour->professional, $payload, $hour);

            $hour->fill($payload);
            $hour->save();

            return $hour->refresh();
        });
    }

    /**
     * @param  list<array<string, mixed>>  $hours
     */
    public function replaceWeekly(Company $company, Professional $professional, array $hours): void
    {
        DB::transaction(function () use ($company, $professional, $hours): void {
            $this->ensureBelongsToCompany($company, $professional);

            ProfessionalWorkingHour::query()
                ->where('company_id', $company->getKey())
                ->where('professional_id', $professional->getKey())
                ->delete();

            foreach ($hours as $index => $hour) {
                $payload = $this->preparePayload([
                    'weekday' => (int) $hour['weekday'],
                    'start_time' => $hour['start_time'],
                    'end_time' => $hour['end_time'],
                    'sort_order' => $index,
                    'is_active' => $hour['is_active'] ?? true,
                    'valid_from' => $hour['valid_from'] ?? null,
                    'valid_until' => $hour['valid_until'] ?? null,
                ]);

                $this->validatePayload($company, $professional, $payload);

                $hour = new ProfessionalWorkingHour($payload);
                $hour->company()->associate($company);
                $hour->professional()->associate($professional);
                $hour->save();
            }
        });
    }

    public function changeStatus(
        Company $company,
        ProfessionalWorkingHour $hour,
        bool $isActive,
    ): ProfessionalWorkingHour {
        $this->ensureHourBelongsToCompany($company, $hour);
        $hour->update(['is_active' => $isActive]);

        return $hour->refresh();
    }

    public function ensureBelongsToCompany(Company $company, Professional $professional): void
    {
        if ((int) $professional->company_id !== (int) $company->getKey()) {
            abort(404);
        }
    }

    public function ensureHourBelongsToCompany(Company $company, ProfessionalWorkingHour $hour): void
    {
        if ((int) $hour->company_id !== (int) $company->getKey()) {
            abort(404);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function persistHour(Company $company, Professional $professional, array $data): ProfessionalWorkingHour
    {
        $payload = $this->preparePayload($data);
        $this->validatePayload($company, $professional, $payload);

        $hour = new ProfessionalWorkingHour($payload);
        $hour->company()->associate($company);
        $hour->professional()->associate($professional);
        $hour->save();

        return $hour->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function withWeekdayContext(ValidationException $exception, array $data, int $index): ValidationException
    {
        $weekday = Weekday::tryFrom((int) ($data['weekday'] ?? -1));
        $label = $weekday?->label() ?? 'Faixa';

        $messages = [];

        foreach ($exception->errors() as $key => $errors) {
            $prefixed = array_map(
                fn (string $message): string => "{$label}: {$message}",
                $errors,
            );

            $messages[$key] = $prefixed;
            $messages["hours.{$index}.{$key}"] = $prefixed;
        }

        return ValidationException::withMessages($messages);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function preparePayload(array $data): array
    {
        unset($data['company_id'], $data['professional_id']);

        if (isset($data['start_time'])) {
            $data['start_time'] = $this->normalizeTime($data['start_time']);
        }

        if (isset($data['end_time'])) {
            $data['end_time'] = $this->normalizeTime($data['end_time']);
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function validatePayload(
        Company $company,
        Professional $professional,
        array $payload,
        ?ProfessionalWorkingHour $ignore = null,
    ): void {
        if (! ($payload['is_active'] ?? true)) {
            return;
        }

        $start = CompanyDateTime::timeToMinutes($payload['start_time']);
        $end = CompanyDateTime::timeToMinutes($payload['end_time']);
        TimeRangeValidator::assertEndAfterStart($start, $end);

        if (isset($payload['valid_from'], $payload['valid_until'])
            && $payload['valid_until'] < $payload['valid_from']) {
            throw ValidationException::withMessages([
                'valid_until' => 'A data final não pode ser anterior à data inicial.',
            ]);
        }

        $existing = ProfessionalWorkingHour::query()
            ->where('company_id', $company->getKey())
            ->where('professional_id', $professional->getKey())
            ->where('weekday', $payload['weekday'])
            ->where('is_active', true)
            ->when($ignore, fn ($query) => $query->whereKeyNot($ignore->getKey()))
            ->get();

        $ranges = $existing
            ->map(fn (ProfessionalWorkingHour $hour): array => [
                'id' => $hour->getKey(),
                'start' => CompanyDateTime::timeToMinutes(substr((string) $hour->start_time, 0, 8)),
                'end' => CompanyDateTime::timeToMinutes(substr((string) $hour->end_time, 0, 8)),
            ])
            ->push(['start' => $start, 'end' => $end])
            ->all();

        TimeRangeValidator::assertNoOverlap($ranges);
    }

    protected function normalizeTime(string $time): string
    {
        return strlen($time) === 5 ? "{$time}:00" : $time;
    }
}
