<?php

namespace App\Services\Service;

use App\Enums\CommissionType;
use App\Models\Company;
use App\Models\Professional;
use App\Models\Service;
use App\Services\Financial\CompanyFinancialSettingService;
use App\Services\Financial\FinancialDistributionValidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ServiceProfessionalAssignmentService
{
    public function __construct(
        protected ServiceCatalogService $serviceCatalogService,
        protected CompanyFinancialSettingService $financialSettingService,
        protected FinancialDistributionValidator $distributionValidator,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function attach(Company $company, Service $service, array $data): void
    {
        DB::transaction(function () use ($company, $service, $data): void {
            $this->serviceCatalogService->ensureBelongsToCompany($company, $service);

            $professional = $this->resolveProfessional($company, (int) $data['professional_id']);

            $this->assertNotDuplicate($company, $service, $professional);

            $customPrice = $data['custom_price'] ?? null;
            $customDuration = $data['custom_duration_minutes'] ?? null;
            $commissionType = $this->resolveCommissionType($data['commission_type'] ?? null);
            $commissionValue = $data['commission_value'] ?? null;

            $this->validateCustomValues($customPrice, $customDuration);
            $this->validateCommission($company, $commissionType, $commissionValue);

            $service->professionals()->attach($professional->getKey(), [
                'company_id' => $company->getKey(),
                'custom_price' => $customPrice,
                'custom_duration_minutes' => $customDuration,
                'is_active' => $data['is_active'] ?? true,
                'commission_type' => $commissionType?->value,
                'commission_value' => $commissionType === null || $commissionType === CommissionType::None
                    ? null
                    : $commissionValue,
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Company $company, Service $service, Professional $professional, array $data): void
    {
        DB::transaction(function () use ($company, $service, $professional, $data): void {
            $this->serviceCatalogService->ensureBelongsToCompany($company, $service);
            $this->resolveProfessional($company, $professional->getKey());
            $this->ensureLinkExists($company, $service, $professional);

            $customPrice = $data['custom_price'] ?? null;
            $customDuration = $data['custom_duration_minutes'] ?? null;
            $commissionType = $this->resolveCommissionType($data['commission_type'] ?? null);
            $commissionValue = $data['commission_value'] ?? null;

            $this->validateCustomValues($customPrice, $customDuration);
            $this->validateCommission($company, $commissionType, $commissionValue);

            $service->professionals()->updateExistingPivot($professional->getKey(), [
                'custom_price' => $customPrice,
                'custom_duration_minutes' => $customDuration,
                'is_active' => $data['is_active'] ?? true,
                'commission_type' => $commissionType?->value,
                'commission_value' => $commissionType === null || $commissionType === CommissionType::None
                    ? null
                    : $commissionValue,
            ]);
        });
    }

    public function detach(Company $company, Service $service, Professional $professional): void
    {
        $this->serviceCatalogService->ensureBelongsToCompany($company, $service);
        $this->ensureLinkExists($company, $service, $professional);

        $service->professionals()->detach($professional->getKey());
    }

    protected function resolveProfessional(Company $company, int $professionalId): Professional
    {
        $professional = Professional::query()
            ->whereKey($professionalId)
            ->where('company_id', $company->getKey())
            ->first();

        if (! $professional) {
            throw ValidationException::withMessages([
                'professional_id' => 'Profissional inválido para esta empresa.',
            ]);
        }

        if (! $professional->is_active) {
            throw ValidationException::withMessages([
                'professional_id' => 'O profissional selecionado está inativo.',
            ]);
        }

        return $professional;
    }

    protected function assertNotDuplicate(Company $company, Service $service, Professional $professional): void
    {
        $exists = $service->professionals()
            ->where('professionals.id', $professional->getKey())
            ->wherePivot('company_id', $company->getKey())
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'professional_id' => 'Este profissional já está vinculado ao serviço.',
            ]);
        }
    }

    protected function ensureLinkExists(Company $company, Service $service, Professional $professional): void
    {
        $exists = $service->professionals()
            ->where('professionals.id', $professional->getKey())
            ->wherePivot('company_id', $company->getKey())
            ->exists();

        if (! $exists) {
            abort(404);
        }
    }

    protected function validateCustomValues(mixed $customPrice, mixed $customDuration): void
    {
        if ($customPrice !== null && bccomp((string) $customPrice, '0', 2) < 0) {
            throw ValidationException::withMessages([
                'custom_price' => 'O preço personalizado não pode ser negativo.',
            ]);
        }

        if ($customDuration !== null && (int) $customDuration <= 0) {
            throw ValidationException::withMessages([
                'custom_duration_minutes' => 'A duração personalizada deve ser maior que zero.',
            ]);
        }
    }

    protected function resolveCommissionType(mixed $value): ?CommissionType
    {
        if ($value === null || $value === '' || $value === 'default') {
            return null;
        }

        return $value instanceof CommissionType
            ? $value
            : CommissionType::from((string) $value);
    }

    protected function validateCommission(
        Company $company,
        ?CommissionType $commissionType,
        mixed $commissionValue,
    ): void {
        if ($commissionType === null) {
            return;
        }

        if ($commissionType === CommissionType::None) {
            return;
        }

        $value = (string) ($commissionValue ?? '0');
        $settings = $this->financialSettingService->getOrCreate($company);

        if ($commissionType === CommissionType::Percentage) {
            $this->distributionValidator->validateCustomCommissionPercentage(
                (string) $settings->materials_reserve_percentage,
                (string) $settings->business_reserve_percentage,
                $value,
            );

            return;
        }

        $this->distributionValidator->validateFixedCommissionValue($value);
    }
}
