<?php

namespace App\Services\PublicBooking;

use App\Models\Company;
use App\Models\Professional;
use App\Models\Service;
use App\Services\Scheduling\AvailabilityService;
use App\Services\Scheduling\CompanySchedulingSettingService;
use App\Support\CompanyDateTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class OnlineBookingCatalogService
{
    public function __construct(
        protected CompanySchedulingSettingService $settingsService,
        protected AvailabilityService $availabilityService,
        protected OnlineProfessionalResolver $professionalResolver,
    ) {}

    /**
     * @return Collection<int, Service>
     */
    public function getEligibleServices(Company $company): Collection
    {
        return Service::query()
            ->where('company_id', $company->getKey())
            ->where('is_active', true)
            ->availableForOnlineBooking()
            ->whereHas('professionals', function ($query) use ($company): void {
                $query
                    ->where('professionals.company_id', $company->getKey())
                    ->where('professionals.is_active', true)
                    ->where('professionals.is_bookable', true)
                    ->where('professional_service.is_active', true)
                    ->whereHas('workingHours', fn ($hours) => $hours->active());
            })
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, Professional>
     */
    public function getEligibleProfessionals(Company $company, Service $service): Collection
    {
        $this->assertServiceBelongsToCompany($company, $service);

        return $this->professionalResolver->listEligibleProfessionals($company, $service);
    }

    /**
     * @return Collection<int, CarbonImmutable>
     */
    public function getAvailableDates(
        Company $company,
        Service $service,
        ?int $professionalId = null,
    ): Collection {
        $this->assertServiceBelongsToCompany($company, $service);

        $settings = $this->settingsService->getOrCreate($company);
        $now = CompanyDateTime::nowLocal($company);
        $firstAllowed = $now->addMinutes((int) $settings->minimum_advance_minutes)->startOfDay();
        $lastAllowed = $now->addDays((int) $settings->maximum_advance_days)->endOfDay();

        return $this->getAvailableDatesInRange(
            $company,
            $service,
            $professionalId,
            $firstAllowed,
            $lastAllowed,
        );
    }

    /**
     * @return Collection<int, CarbonImmutable>
     */
    public function getAvailableDatesInRange(
        Company $company,
        Service $service,
        ?int $professionalId,
        CarbonImmutable $from,
        CarbonImmutable $to,
    ): Collection {
        $this->assertServiceBelongsToCompany($company, $service);

        $settings = $this->settingsService->getOrCreate($company);
        $now = CompanyDateTime::nowLocal($company);
        $firstAllowed = $now->addMinutes((int) $settings->minimum_advance_minutes)->startOfDay();
        $lastAllowed = $now->addDays((int) $settings->maximum_advance_days)->endOfDay();

        $rangeStart = $from->startOfDay()->max($firstAllowed);
        $rangeEnd = $to->endOfDay()->min($lastAllowed);

        if ($rangeStart->gt($rangeEnd)) {
            return collect();
        }

        $dates = collect();
        $cursor = $rangeStart->startOfDay();

        while ($cursor->lte($rangeEnd)) {
            $slots = $this->getAvailableSlots($company, $service, $professionalId, $cursor);

            if ($slots->isNotEmpty()) {
                $dates->push($cursor->startOfDay());
            }

            $cursor = $cursor->addDay();
        }

        return $dates->values();
    }

    /**
     * @return Collection<int, CarbonImmutable>
     */
    public function getAvailableSlots(
        Company $company,
        Service $service,
        ?int $professionalId,
        CarbonImmutable $localDate,
    ): Collection {
        $this->assertServiceBelongsToCompany($company, $service);

        if ($professionalId === null) {
            return $this->getAvailableSlotsForNoPreference($company, $service, $localDate);
        }

        $professional = Professional::query()
            ->where('company_id', $company->getKey())
            ->whereKey($professionalId)
            ->first();

        if ($professional === null) {
            return collect();
        }

        return $this->availabilityService->getAvailableSlots(
            $company,
            $professional,
            $service,
            $localDate,
        );
    }

    /**
     * @return Collection<int, CarbonImmutable>
     */
    protected function getAvailableSlotsForNoPreference(
        Company $company,
        Service $service,
        CarbonImmutable $localDate,
    ): Collection {
        $professionals = $this->professionalResolver->listEligibleProfessionals($company, $service);
        $slots = collect();

        foreach ($professionals as $professional) {
            $professionalSlots = $this->availabilityService->getAvailableSlots(
                $company,
                $professional,
                $service,
                $localDate,
            );

            foreach ($professionalSlots as $slot) {
                $key = $slot->format('Y-m-d H:i');

                if (! $slots->has($key)) {
                    $slots->put($key, $slot);
                }
            }
        }

        return $slots->sortBy(fn (CarbonImmutable $slot) => $slot->timestamp)->values();
    }

    protected function assertServiceBelongsToCompany(Company $company, Service $service): void
    {
        if ((int) $service->company_id !== (int) $company->getKey()) {
            abort(404);
        }
    }
}
