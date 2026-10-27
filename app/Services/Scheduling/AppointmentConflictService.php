<?php

namespace App\Services\Scheduling;

use App\Models\Appointment;
use App\Models\Company;
use App\Models\Professional;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class AppointmentConflictService
{
    public function hasConflict(
        Company $company,
        Professional $professional,
        CarbonImmutable $startUtc,
        CarbonImmutable $endUtc,
        int $bufferBeforeMinutes,
        int $bufferAfterMinutes,
        ?Appointment $ignore = null,
    ): bool {
        $blockStart = $startUtc->subMinutes($bufferBeforeMinutes);
        $blockEnd = $endUtc->addMinutes($bufferAfterMinutes);

        $query = Appointment::query()
            ->where('company_id', $company->getKey())
            ->where('professional_id', $professional->getKey())
            ->blocking()
            ->where('start_at', '<', $blockEnd)
            ->where('end_at', '>', $blockStart);

        if ($ignore) {
            $query->whereKeyNot($ignore->getKey());
        }

        return $query->exists();
    }

    /**
     * Check conflicts considering each existing appointment's own buffers.
     */
    public function hasConflictWithExistingBuffers(
        Company $company,
        Professional $professional,
        CarbonImmutable $startUtc,
        CarbonImmutable $endUtc,
        int $bufferBeforeMinutes,
        int $bufferAfterMinutes,
        ?Appointment $ignore = null,
    ): bool {
        return $this->conflictingAppointments(
            $company,
            $professional,
            $startUtc,
            $endUtc,
            $bufferBeforeMinutes,
            $bufferAfterMinutes,
            $ignore,
        )->isNotEmpty();
    }

    /**
     * Appointments of the same company and professional whose blocked window overlaps the new one.
     *
     * @return Collection<int, Appointment>
     */
    public function conflictingAppointments(
        Company $company,
        Professional $professional,
        CarbonImmutable $startUtc,
        CarbonImmutable $endUtc,
        int $bufferBeforeMinutes,
        int $bufferAfterMinutes,
        ?Appointment $ignore = null,
    ): Collection {
        $newBlockStart = $startUtc->subMinutes($bufferBeforeMinutes);
        $newBlockEnd = $endUtc->addMinutes($bufferAfterMinutes);

        return Appointment::query()
            ->where('company_id', $company->getKey())
            ->where('professional_id', $professional->getKey())
            ->blocking()
            ->when($ignore, fn ($query) => $query->whereKeyNot($ignore->getKey()))
            ->with(['client', 'service'])
            ->orderBy('start_at')
            ->get()
            ->filter(function (Appointment $existing) use ($newBlockStart, $newBlockEnd): bool {
                $existingBlockStart = CarbonImmutable::parse($existing->start_at)
                    ->subMinutes((int) $existing->buffer_before_minutes_snapshot);
                $existingBlockEnd = CarbonImmutable::parse($existing->end_at)
                    ->addMinutes((int) $existing->buffer_after_minutes_snapshot);

                return $existingBlockStart->lt($newBlockEnd) && $existingBlockEnd->gt($newBlockStart);
            })
            ->values();
    }
}
