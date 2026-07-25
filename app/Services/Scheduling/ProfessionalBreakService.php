<?php

namespace App\Services\Scheduling;

use App\Models\Company;
use App\Models\Professional;
use App\Models\ProfessionalBreak;
use App\Support\CompanyDateTime;
use App\Support\TimeRangeValidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProfessionalBreakService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Company $company, Professional $professional, array $data): ProfessionalBreak
    {
        return DB::transaction(function () use ($company, $professional, $data): ProfessionalBreak {
            $this->ensureBelongsToCompany($company, $professional);

            $payload = $this->preparePayload($data);
            $this->validatePayload($company, $professional, $payload);

            $break = new ProfessionalBreak($payload);
            $break->company()->associate($company);
            $break->professional()->associate($professional);
            $break->save();

            return $break->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Company $company, ProfessionalBreak $break, array $data): ProfessionalBreak
    {
        return DB::transaction(function () use ($company, $break, $data): ProfessionalBreak {
            $this->ensureBreakBelongsToCompany($company, $break);

            $payload = $this->preparePayload($data);
            $this->validatePayload($company, $break->professional, $payload, $break);

            $break->fill($payload);
            $break->save();

            return $break->refresh();
        });
    }

    /**
     * @param  list<array<string, mixed>>  $breaks
     */
    public function replaceWeekly(Company $company, Professional $professional, array $breaks): void
    {
        DB::transaction(function () use ($company, $professional, $breaks): void {
            $this->ensureBelongsToCompany($company, $professional);

            ProfessionalBreak::query()
                ->where('company_id', $company->getKey())
                ->where('professional_id', $professional->getKey())
                ->delete();

            foreach ($breaks as $breakData) {
                $payload = $this->preparePayload([
                    'name' => $breakData['name'],
                    'weekday' => (int) $breakData['weekday'],
                    'start_time' => $breakData['start_time'],
                    'end_time' => $breakData['end_time'],
                    'is_active' => $breakData['is_active'] ?? true,
                    'valid_from' => $breakData['valid_from'] ?? null,
                    'valid_until' => $breakData['valid_until'] ?? null,
                ]);

                $this->validatePayload($company, $professional, $payload);

                $break = new ProfessionalBreak($payload);
                $break->company()->associate($company);
                $break->professional()->associate($professional);
                $break->save();
            }
        });
    }

    public function changeStatus(Company $company, ProfessionalBreak $break, bool $isActive): ProfessionalBreak
    {
        $this->ensureBreakBelongsToCompany($company, $break);
        $break->update(['is_active' => $isActive]);

        return $break->refresh();
    }

    public function ensureBelongsToCompany(Company $company, Professional $professional): void
    {
        if ((int) $professional->company_id !== (int) $company->getKey()) {
            abort(404);
        }
    }

    public function ensureBreakBelongsToCompany(Company $company, ProfessionalBreak $break): void
    {
        if ((int) $break->company_id !== (int) $company->getKey()) {
            abort(404);
        }
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
        ?ProfessionalBreak $ignore = null,
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

        $existing = ProfessionalBreak::query()
            ->where('company_id', $company->getKey())
            ->where('professional_id', $professional->getKey())
            ->where('weekday', $payload['weekday'])
            ->where('is_active', true)
            ->when($ignore, fn ($query) => $query->whereKeyNot($ignore->getKey()))
            ->get();

        $ranges = $existing
            ->map(fn (ProfessionalBreak $break): array => [
                'id' => $break->getKey(),
                'start' => CompanyDateTime::timeToMinutes(substr((string) $break->start_time, 0, 8)),
                'end' => CompanyDateTime::timeToMinutes(substr((string) $break->end_time, 0, 8)),
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
