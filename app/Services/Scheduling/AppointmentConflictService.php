<?php

namespace App\Services\Scheduling;

use App\Models\Appointment;
use App\Models\Company;
use App\Models\Professional;
use Carbon\CarbonImmutable;

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
        $newBlockStart = $startUtc->subMinutes($bufferBeforeMinutes);
        $newBlockEnd = $endUtc->addMinutes($bufferAfterMinutes);

        $appointments = Appointment::query()
            ->where('company_id', $company->getKey())
            ->where('professional_id', $professional->getKey())
            ->blocking()
            ->when($ignore, fn ($q) => $q->whereKeyNot($ignore->getKey()))
            ->get();

        foreach ($appointments as $existing) {
            $existingBlockStart = CarbonImmutable::parse($existing->start_at)
                ->subMinutes($existing->buffer_before_minutes_snapshot);
            $existingBlockEnd = CarbonImmutable::parse($existing->end_at)
                ->addMinutes($existing->buffer_after_minutes_snapshot);

            if ($existingBlockStart->lt($newBlockEnd) && $existingBlockEnd->gt($newBlockStart)) {
                return true;
            }
        }

        return false;
    }
}
