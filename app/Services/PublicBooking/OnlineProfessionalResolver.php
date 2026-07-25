<?php

namespace App\Services\PublicBooking;

use App\Models\Appointment;
use App\Models\Company;
use App\Models\Professional;
use App\Models\Service;
use App\Services\Scheduling\AppointmentSnapshotResolver;
use App\Services\Scheduling\AvailabilityService;
use App\Support\CompanyDateTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OnlineProfessionalResolver
{
    public const NO_PREFERENCE = 'no_preference';

    public function __construct(
        protected AvailabilityService $availabilityService,
        protected AppointmentSnapshotResolver $snapshotResolver,
    ) {}

    /**
     * @return EloquentCollection<int, Professional>
     */
    public function listEligibleProfessionals(Company $company, Service $service): EloquentCollection
    {
        return Professional::query()
            ->where('company_id', $company->getKey())
            ->active()
            ->bookable()
            ->whereHas('services', function ($query) use ($service): void {
                $query
                    ->where('services.id', $service->getKey())
                    ->where('professional_service.is_active', true);
            })
            ->whereHas('workingHours', fn ($query) => $query->active())
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    public function autoSelectProfessional(
        Company $company,
        Service $service,
        CarbonImmutable $localStart,
    ): Professional {
        $professionals = $this->listEligibleProfessionals($company, $service);

        if ($professionals->isEmpty()) {
            throw ValidationException::withMessages([
                'professional_id' => 'Nenhum profissional disponível para este serviço.',
            ]);
        }

        $sorted = $this->sortCandidates($company, $professionals, $localStart);

        return $sorted->first();
    }

    public function resolveForBooking(
        Company $company,
        Service $service,
        ?int $professionalId,
        CarbonImmutable $localStart,
        bool $allowNoPreference = false,
    ): Professional {
        if ($professionalId === null) {
            if (! $allowNoPreference) {
                throw ValidationException::withMessages([
                    'professional_id' => 'Selecione um profissional.',
                ]);
            }

            return $this->resolveNoPreference($company, $service, $localStart);
        }

        $professional = Professional::query()
            ->where('company_id', $company->getKey())
            ->whereKey($professionalId)
            ->first();

        if ($professional === null) {
            throw ValidationException::withMessages([
                'professional_id' => 'Profissional inválido.',
            ]);
        }

        $eligible = $this->listEligibleProfessionals($company, $service);

        if (! $eligible->contains('id', $professional->getKey())) {
            throw ValidationException::withMessages([
                'professional_id' => 'Profissional indisponível para este serviço.',
            ]);
        }

        return $professional;
    }

    public function resolveNoPreference(
        Company $company,
        Service $service,
        CarbonImmutable $localStart,
    ): Professional {
        $candidates = $this->sortCandidates(
            $company,
            $this->listEligibleProfessionals($company, $service),
            $localStart,
        );

        if ($candidates->isEmpty()) {
            throw ValidationException::withMessages([
                'start_at' => 'Este horário acabou de ser reservado. Escolha outro horário.',
            ]);
        }

        $snapshots = null;
        $lastError = null;

        foreach ($candidates as $candidate) {
            try {
                return DB::transaction(function () use ($company, $service, $localStart, $candidate, &$snapshots): Professional {
                    $locked = Professional::query()
                        ->whereKey($candidate->getKey())
                        ->lockForUpdate()
                        ->firstOrFail();

                    $snapshots ??= $this->snapshotResolver->resolve($company, $locked, $service);

                    $this->availabilityService->assertAvailable(
                        $company,
                        $locked,
                        $service,
                        $localStart,
                        $snapshots['duration_minutes_snapshot'],
                        $snapshots['buffer_before_minutes_snapshot'],
                        $snapshots['buffer_after_minutes_snapshot'],
                    )->assertAvailable();

                    return $locked;
                });
            } catch (ValidationException $exception) {
                $lastError = $exception;

                continue;
            }
        }

        throw $lastError ?? ValidationException::withMessages([
            'start_at' => 'Este horário acabou de ser reservado. Escolha outro horário.',
        ]);
    }

    /**
     * @param  EloquentCollection<int, Professional>|Collection<int, Professional>  $professionals
     * @return Collection<int, Professional>
     */
    protected function sortCandidates(
        Company $company,
        EloquentCollection|Collection $professionals,
        CarbonImmutable $localStart,
    ): Collection {
        $localDayStart = CompanyDateTime::localToUtc($company, $localStart->startOfDay());
        $localDayEnd = CompanyDateTime::localToUtc($company, $localStart->endOfDay());

        $counts = Appointment::query()
            ->where('company_id', $company->getKey())
            ->blocking()
            ->whereIn('professional_id', $professionals->pluck('id'))
            ->where('start_at', '>=', $localDayStart)
            ->where('start_at', '<=', $localDayEnd)
            ->selectRaw('professional_id, COUNT(*) as total')
            ->groupBy('professional_id')
            ->pluck('total', 'professional_id');

        return $professionals
            ->sortBy([
                fn (Professional $professional) => $professional->sort_order,
                fn (Professional $professional) => $counts->get($professional->getKey(), 0),
                fn (Professional $professional) => $professional->getKey(),
            ])
            ->values();
    }
}
