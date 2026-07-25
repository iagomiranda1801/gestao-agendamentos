<?php

namespace App\Services\Scheduling;

use App\Models\Company;
use App\Models\ScheduleBlock;
use App\Models\User;
use App\Support\CompanyDateTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ScheduleBlockService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Company $company, User $user, array $data): ScheduleBlock
    {
        return DB::transaction(function () use ($company, $user, $data): ScheduleBlock {
            $payload = $this->preparePayload($company, $data);
            $this->validatePayload($company, $payload);

            $block = new ScheduleBlock($payload);
            $block->company()->associate($company);
            $block->creator()->associate($user);
            $block->save();

            return $block->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Company $company, ScheduleBlock $block, array $data): ScheduleBlock
    {
        return DB::transaction(function () use ($company, $block, $data): ScheduleBlock {
            $this->ensureBelongsToCompany($company, $block);

            $payload = $this->preparePayload($company, $data);
            $this->validatePayload($company, $payload, $block);

            $block->fill($payload);
            $block->save();

            return $block->refresh();
        });
    }

    public function changeStatus(Company $company, ScheduleBlock $block, bool $isActive): ScheduleBlock
    {
        $this->ensureBelongsToCompany($company, $block);
        $block->update(['is_active' => $isActive]);

        return $block->refresh();
    }

    public function ensureBelongsToCompany(Company $company, ScheduleBlock $block): void
    {
        if ((int) $block->company_id !== (int) $company->getKey()) {
            abort(404);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function preparePayload(Company $company, array $data): array
    {
        unset($data['company_id'], $data['created_by']);

        if (isset($data['start_date'], $data['start_time'])) {
            $data['start_at'] = CompanyDateTime::localToUtc(
                $company,
                CompanyDateTime::parseLocal($company, $data['start_date'], $data['start_time']),
            );
            unset($data['start_date'], $data['start_time']);
        }

        if (isset($data['end_date'], $data['end_time'])) {
            $data['end_at'] = CompanyDateTime::localToUtc(
                $company,
                CompanyDateTime::parseLocal($company, $data['end_date'], $data['end_time']),
            );
            unset($data['end_date'], $data['end_time']);
        }

        if (($data['is_all_day'] ?? false) && isset($data['start_at'], $data['end_at'])) {
            $localStart = CompanyDateTime::utcToLocal($company, CarbonImmutable::parse($data['start_at']));
            $localEnd = CompanyDateTime::utcToLocal($company, CarbonImmutable::parse($data['end_at']));
            $data['start_at'] = CompanyDateTime::localToUtc($company, $localStart->startOfDay());
            $data['end_at'] = CompanyDateTime::localToUtc($company, $localEnd->endOfDay());
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function validatePayload(Company $company, array $payload, ?ScheduleBlock $ignore = null): void
    {
        if (! isset($payload['start_at'], $payload['end_at'])) {
            return;
        }

        $start = CarbonImmutable::parse($payload['start_at']);
        $end = CarbonImmutable::parse($payload['end_at']);

        if ($end <= $start) {
            throw ValidationException::withMessages([
                'end_at' => 'A data e hora final devem ser posteriores ao início.',
            ]);
        }

        if (filled($payload['professional_id'] ?? null)) {
            $exists = $company->professionals()
                ->whereKey($payload['professional_id'])
                ->exists();

            if (! $exists) {
                throw ValidationException::withMessages([
                    'professional_id' => 'Profissional inválido para esta empresa.',
                ]);
            }
        }
    }
}
