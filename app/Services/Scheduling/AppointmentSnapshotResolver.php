<?php

namespace App\Services\Scheduling;

use App\Models\Company;
use App\Models\Professional;
use App\Models\Service;

class AppointmentSnapshotResolver
{
    /**
     * @return array{
     *     service_name_snapshot: string,
     *     price_snapshot: string,
     *     duration_minutes_snapshot: int,
     *     buffer_before_minutes_snapshot: int,
     *     buffer_after_minutes_snapshot: int
     * }
     */
    public function resolve(Company $company, Professional $professional, Service $service): array
    {
        $link = $professional->services()
            ->where('services.id', $service->getKey())
            ->first()
            ?->pivot;

        $price = filled($link?->custom_price) ? (string) $link->custom_price : (string) $service->price;
        $duration = filled($link?->custom_duration_minutes)
            ? (int) $link->custom_duration_minutes
            : (int) $service->duration_minutes;

        return [
            'service_name_snapshot' => $service->name,
            'price_snapshot' => $price,
            'duration_minutes_snapshot' => $duration,
            'buffer_before_minutes_snapshot' => (int) ($service->buffer_before_minutes ?? 0),
            'buffer_after_minutes_snapshot' => (int) ($service->buffer_after_minutes ?? 0),
        ];
    }
}
