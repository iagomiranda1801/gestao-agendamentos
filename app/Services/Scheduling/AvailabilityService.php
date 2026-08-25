<?php

namespace App\Services\Scheduling;

use App\Models\Appointment;
use App\Models\Company;
use App\Models\CompanyBusinessHour;
use App\Models\Professional;
use App\Models\ProfessionalBreak;
use App\Models\ProfessionalWorkingHour;
use App\Models\ScheduleBlock;
use App\Models\Service;
use App\Scheduling\AvailabilityResult;
use App\Support\CompanyDateTime;
use App\Support\TimeRangeValidator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class AvailabilityService
{
    public function __construct(
        protected CompanySchedulingSettingService $settingsService,
        protected AppointmentConflictService $conflictService,
        protected AppointmentSnapshotResolver $snapshotResolver,
    ) {}

    public function assertAvailable(
        Company $company,
        Professional $professional,
        ?Service $service,
        CarbonImmutable $localStart,
        int $durationMinutes,
        int $bufferBefore,
        int $bufferAfter,
        ?Appointment $ignore = null,
    ): AvailabilityResult {
        if (! $company->is_active) {
            return AvailabilityResult::fail('A empresa está inativa.');
        }

        if ((int) $professional->company_id !== (int) $company->getKey()
            || ($service !== null && (int) $service->company_id !== (int) $company->getKey())) {
            return AvailabilityResult::fail('Registros inválidos para esta empresa.');
        }

        if (! $professional->is_active || ! $professional->is_bookable) {
            return AvailabilityResult::fail('Profissional indisponível para agendamento.');
        }

        if ($service !== null && (! $service->is_active || ! $service->is_bookable)) {
            return AvailabilityResult::fail('Serviço indisponível para agendamento.');
        }

        if ($service === null) {
            return $this->assertScheduleAvailability($company, $professional, $localStart, $durationMinutes, $bufferBefore, $bufferAfter, $ignore);
        }

        $linked = $professional->services()
            ->where('services.id', $service->getKey())
            ->wherePivot('is_active', true)
            ->exists();

        if (! $linked) {
            return AvailabilityResult::fail('Profissional não está associado a este serviço.');
        }

        return $this->assertScheduleAvailability($company, $professional, $localStart, $durationMinutes, $bufferBefore, $bufferAfter, $ignore);
    }

    protected function assertScheduleAvailability(
        Company $company,
        Professional $professional,
        CarbonImmutable $localStart,
        int $durationMinutes,
        int $bufferBefore,
        int $bufferAfter,
        ?Appointment $ignore,
    ): AvailabilityResult {
        if ($localStart->lt(CompanyDateTime::nowLocal($company))) {
            return AvailabilityResult::fail('Não é possível agendar no passado.');
        }

        $settings = $this->settingsService->getOrCreate($company);
        $minutesFromMidnight = ($localStart->hour * 60) + $localStart->minute;

        if ($minutesFromMidnight % $settings->slot_interval_minutes !== 0) {
            return AvailabilityResult::fail('Horário não alinhado ao intervalo da agenda.');
        }

        $localEnd = $localStart->addMinutes($durationMinutes);
        $startUtc = CompanyDateTime::localToUtc($company, $localStart);
        $endUtc = CompanyDateTime::localToUtc($company, $localEnd);

        if (! $this->isWithinBusinessHours($company, $localStart, $localEnd)) {
            return AvailabilityResult::fail('Horário fora do funcionamento da empresa.');
        }

        if (! $this->isWithinWorkingHours($company, $professional, $localStart, $localEnd)) {
            return AvailabilityResult::fail('Horário fora da jornada do profissional.');
        }

        if ($this->crossesBreak($company, $professional, $localStart, $localEnd, $bufferBefore, $bufferAfter)) {
            return AvailabilityResult::fail('Horário conflita com intervalo do profissional.');
        }

        if ($this->crossesBlock($company, $professional, $startUtc, $endUtc, $bufferBefore, $bufferAfter)) {
            return AvailabilityResult::fail('Horário conflita com bloqueio da agenda.');
        }

        if ($this->conflictService->hasConflictWithExistingBuffers(
            $company,
            $professional,
            $startUtc,
            $endUtc,
            $bufferBefore,
            $bufferAfter,
            $ignore,
        )) {
            return AvailabilityResult::fail('Horário conflita com outro agendamento.');
        }

        return AvailabilityResult::ok();
    }

    /**
     * @return Collection<int, CarbonImmutable>
     */
    public function getAvailableSlots(
        Company $company,
        Professional $professional,
        Service $service,
        CarbonImmutable $localDate,
    ): Collection {
        $settings = $this->settingsService->getOrCreate($company);
        $snapshots = $this->snapshotResolver->resolve($company, $professional, $service);
        $duration = $snapshots['duration_minutes_snapshot'];
        $bufferBefore = $snapshots['buffer_before_minutes_snapshot'];
        $bufferAfter = $snapshots['buffer_after_minutes_snapshot'];

        $weekday = $localDate->dayOfWeek;
        $businessRanges = $this->getBusinessRangesForDay($company, $weekday);
        $workingRanges = $this->getWorkingRangesForDay($company, $professional, $localDate, $weekday);

        $ranges = $this->intersectRanges($businessRanges, $workingRanges);

        if ($ranges === []) {
            return collect();
        }

        $slots = collect();
        $now = CompanyDateTime::nowLocal($company);

        foreach ($ranges as $range) {
            for ($minute = $range['start']; $minute + $duration <= $range['end']; $minute += $settings->slot_interval_minutes) {
                $time = CompanyDateTime::minutesToTime($minute);
                $localStart = CompanyDateTime::parseLocal($company, $localDate->format('Y-m-d'), substr($time, 0, 5));

                if ($localStart->lt($now)) {
                    continue;
                }

                $result = $this->assertAvailable(
                    $company,
                    $professional,
                    $service,
                    $localStart,
                    $duration,
                    $bufferBefore,
                    $bufferAfter,
                );

                if ($result->available) {
                    $slots->push($localStart);
                }
            }
        }

        return $slots;
    }

    public function hasAvailableSlot(
        Company $company,
        Professional $professional,
        Service $service,
        CarbonImmutable $localDate,
    ): bool {
        $settings = $this->settingsService->getOrCreate($company);
        $snapshots = $this->snapshotResolver->resolve($company, $professional, $service);
        $duration = $snapshots['duration_minutes_snapshot'];
        $bufferBefore = $snapshots['buffer_before_minutes_snapshot'];
        $bufferAfter = $snapshots['buffer_after_minutes_snapshot'];

        $weekday = $localDate->dayOfWeek;
        $businessRanges = $this->getBusinessRangesForDay($company, $weekday);
        $workingRanges = $this->getWorkingRangesForDay($company, $professional, $localDate, $weekday);

        $ranges = $this->intersectRanges($businessRanges, $workingRanges);

        if ($ranges === []) {
            return false;
        }

        $now = CompanyDateTime::nowLocal($company);

        foreach ($ranges as $range) {
            for ($minute = $range['start']; $minute + $duration <= $range['end']; $minute += $settings->slot_interval_minutes) {
                $time = CompanyDateTime::minutesToTime($minute);
                $localStart = CompanyDateTime::parseLocal($company, $localDate->format('Y-m-d'), substr($time, 0, 5));

                if ($localStart->lt($now)) {
                    continue;
                }

                $result = $this->assertAvailable(
                    $company,
                    $professional,
                    $service,
                    $localStart,
                    $duration,
                    $bufferBefore,
                    $bufferAfter,
                );

                if ($result->available) {
                    return true;
                }
            }
        }

        return false;
    }

    protected function isWithinBusinessHours(Company $company, CarbonImmutable $localStart, CarbonImmutable $localEnd): bool
    {
        $ranges = $this->getBusinessRangesForDay($company, $localStart->dayOfWeek);

        return $this->localRangeCoveredByRanges($localStart, $localEnd, $ranges);
    }

    protected function isWithinWorkingHours(
        Company $company,
        Professional $professional,
        CarbonImmutable $localStart,
        CarbonImmutable $localEnd,
    ): bool {
        $ranges = $this->getWorkingRangesForDay($company, $professional, $localStart, $localStart->dayOfWeek);

        if ($ranges === []) {
            return false;
        }

        return $this->localRangeCoveredByRanges($localStart, $localEnd, $ranges);
    }

    /**
     * @return list<array{start: int, end: int}>
     */
    protected function getBusinessRangesForDay(Company $company, int $weekday): array
    {
        return CompanyBusinessHour::query()
            ->where('company_id', $company->getKey())
            ->where('weekday', $weekday)
            ->active()
            ->orderBy('sort_order')
            ->get()
            ->map(fn (CompanyBusinessHour $hour) => [
                'start' => CompanyDateTime::timeToMinutes((string) $hour->start_time),
                'end' => CompanyDateTime::timeToMinutes((string) $hour->end_time),
            ])
            ->all();
    }

    /**
     * @return list<array{start: int, end: int}>
     */
    protected function getWorkingRangesForDay(
        Company $company,
        Professional $professional,
        CarbonImmutable $localDate,
        int $weekday,
    ): array {
        return ProfessionalWorkingHour::query()
            ->where('company_id', $company->getKey())
            ->where('professional_id', $professional->getKey())
            ->where('weekday', $weekday)
            ->active()
            ->where(function ($query) use ($localDate): void {
                $query->whereNull('valid_from')->orWhere('valid_from', '<=', $localDate->toDateString());
            })
            ->where(function ($query) use ($localDate): void {
                $query->whereNull('valid_until')->orWhere('valid_until', '>=', $localDate->toDateString());
            })
            ->orderBy('sort_order')
            ->get()
            ->map(fn (ProfessionalWorkingHour $hour) => [
                'start' => CompanyDateTime::timeToMinutes((string) $hour->start_time),
                'end' => CompanyDateTime::timeToMinutes((string) $hour->end_time),
            ])
            ->all();
    }

    /**
     * @param  list<array{start: int, end: int}>  $a
     * @param  list<array{start: int, end: int}>  $b
     * @return list<array{start: int, end: int}>
     */
    protected function intersectRanges(array $a, array $b): array
    {
        $result = [];

        foreach ($a as $rangeA) {
            foreach ($b as $rangeB) {
                $start = max($rangeA['start'], $rangeB['start']);
                $end = min($rangeA['end'], $rangeB['end']);

                if ($start < $end) {
                    $result[] = ['start' => $start, 'end' => $end];
                }
            }
        }

        return $result;
    }

    /**
     * @param  list<array{start: int, end: int}>  $ranges
     */
    protected function localRangeCoveredByRanges(
        CarbonImmutable $localStart,
        CarbonImmutable $localEnd,
        array $ranges,
    ): bool {
        $startMin = ($localStart->hour * 60) + $localStart->minute;
        $endMin = ($localEnd->hour * 60) + $localEnd->minute;

        foreach ($ranges as $range) {
            if ($startMin >= $range['start'] && $endMin <= $range['end']) {
                return true;
            }
        }

        return false;
    }

    protected function crossesBreak(
        Company $company,
        Professional $professional,
        CarbonImmutable $localStart,
        CarbonImmutable $localEnd,
        int $bufferBefore,
        int $bufferAfter,
    ): bool {
        $blockStart = ($localStart->hour * 60) + $localStart->minute - $bufferBefore;
        $blockEnd = ($localEnd->hour * 60) + $localEnd->minute + $bufferAfter;

        $breaks = ProfessionalBreak::query()
            ->where('company_id', $company->getKey())
            ->where('professional_id', $professional->getKey())
            ->where('weekday', $localStart->dayOfWeek)
            ->active()
            ->where(function ($query) use ($localStart): void {
                $query->whereNull('valid_from')->orWhere('valid_from', '<=', $localStart->toDateString());
            })
            ->where(function ($query) use ($localStart): void {
                $query->whereNull('valid_until')->orWhere('valid_until', '>=', $localStart->toDateString());
            })
            ->get();

        foreach ($breaks as $break) {
            $breakStart = CompanyDateTime::timeToMinutes((string) $break->start_time);
            $breakEnd = CompanyDateTime::timeToMinutes((string) $break->end_time);

            if (TimeRangeValidator::timesOverlap($blockStart, $blockEnd, $breakStart, $breakEnd)) {
                return true;
            }
        }

        return false;
    }

    protected function crossesBlock(
        Company $company,
        Professional $professional,
        CarbonImmutable $startUtc,
        CarbonImmutable $endUtc,
        int $bufferBefore,
        int $bufferAfter,
    ): bool {
        $blockStart = $startUtc->subMinutes($bufferBefore);
        $blockEnd = $endUtc->addMinutes($bufferAfter);

        return ScheduleBlock::query()
            ->where('company_id', $company->getKey())
            ->active()
            ->inPeriod($blockStart, $blockEnd)
            ->where(function ($query) use ($professional): void {
                $query->whereNull('professional_id')
                    ->orWhere('professional_id', $professional->getKey());
            })
            ->exists();
    }
}
