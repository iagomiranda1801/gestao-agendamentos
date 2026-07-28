<?php

namespace App\Services\Service;

use App\Models\Company;
use App\Models\Professional;
use App\Models\Service;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ServiceProfessionalSyncService
{
    public function __construct(
        protected ServiceCatalogService $serviceCatalogService,
    ) {}

    /**
     * @param  array<int, mixed>  $professionalIds
     */
    public function sync(Company $company, Service $service, array $professionalIds): void
    {
        DB::transaction(function () use ($company, $service, $professionalIds): void {
            $this->serviceCatalogService->ensureBelongsToCompany($company, $service);

            $ids = collect($professionalIds)
                ->filter(fn (mixed $id): bool => filled($id))
                ->map(fn (mixed $id): int => (int) $id)
                ->unique()
                ->values();

            $professionals = Professional::query()
                ->where('company_id', $company->getKey())
                ->where('is_active', true)
                ->whereIn('id', $ids)
                ->pluck('id');

            if ($professionals->count() !== $ids->count()) {
                throw ValidationException::withMessages([
                    'professional_ids' => 'Selecione apenas profissionais ativos desta empresa.',
                ]);
            }

            $currentIds = $service->professionals()
                ->wherePivot('company_id', $company->getKey())
                ->pluck('professionals.id');

            $service->professionals()->detach($currentIds->diff($ids)->all());

            $ids
                ->diff($currentIds)
                ->each(fn (int $professionalId): mixed => $service->professionals()->attach($professionalId, [
                    'company_id' => $company->getKey(),
                    'is_active' => true,
                ]));
        });
    }
}
