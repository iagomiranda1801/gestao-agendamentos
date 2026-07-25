<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

class TimeRangeValidator
{
    public static function timesOverlap(int $startA, int $endA, int $startB, int $endB): bool
    {
        return $startA < $endB && $endA > $startB;
    }

    public static function assertEndAfterStart(int $start, int $end, string $field = 'end_time'): void
    {
        if ($end <= $start) {
            throw ValidationException::withMessages([
                $field => 'O horário final deve ser posterior ao horário inicial.',
            ]);
        }
    }

    /**
     * @param  iterable<array{start: int, end: int, id?: int}>  $ranges
     */
    public static function assertNoOverlap(iterable $ranges, string $field = 'start_time'): void
    {
        $list = [];

        foreach ($ranges as $range) {
            foreach ($list as $existing) {
                if (isset($range['id'], $existing['id']) && $range['id'] === $existing['id']) {
                    continue;
                }

                if (self::timesOverlap($range['start'], $range['end'], $existing['start'], $existing['end'])) {
                    throw ValidationException::withMessages([
                        $field => 'Existem faixas de horário sobrepostas.',
                    ]);
                }
            }

            $list[] = $range;
        }
    }
}
