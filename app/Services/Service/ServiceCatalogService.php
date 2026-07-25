<?php

namespace App\Services\Service;

use App\Models\Company;
use App\Models\Service;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ServiceCatalogService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Company $company, array $data): Service
    {
        return DB::transaction(function () use ($company, $data): Service {
            $payload = $this->preparePayload($data);

            $this->validateBusinessRules($company, $payload);

            $service = new Service($payload);
            $service->company()->associate($company);

            if (blank($service->slug)) {
                $service->slug = Service::generateUniqueSlug($service->name, $company->getKey());
            }

            $service->save();

            return $service->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Company $company, Service $service, array $data): Service
    {
        return DB::transaction(function () use ($company, $service, $data): Service {
            $this->ensureBelongsToCompany($company, $service);

            $payload = $this->preparePayload($data);

            $this->validateBusinessRules($company, $payload, $service);

            $service->fill($payload);
            $service->save();

            return $service->refresh();
        });
    }

    public function changeStatus(Company $company, Service $service, bool $isActive): Service
    {
        $this->ensureBelongsToCompany($company, $service);

        $service->update(['is_active' => $isActive]);

        return $service->refresh();
    }

    public function ensureBelongsToCompany(Company $company, Service $service): void
    {
        if ((int) $service->company_id !== (int) $company->getKey()) {
            abort(404);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function preparePayload(array $data): array
    {
        unset($data['company_id']);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function validateBusinessRules(Company $company, array $payload, ?Service $ignore = null): void
    {
        $this->assertNameIsUniqueInCompany($company, $payload['name'] ?? '', $ignore);
        $this->assertSlugIsUniqueInCompany($company, $payload['slug'] ?? null, $ignore);

        if (bccomp((string) ($payload['price'] ?? 0), '0', 2) < 0) {
            throw ValidationException::withMessages([
                'price' => 'O preço não pode ser negativo.',
            ]);
        }

        if ((int) ($payload['duration_minutes'] ?? 0) <= 0) {
            throw ValidationException::withMessages([
                'duration_minutes' => 'A duração deve ser maior que zero.',
            ]);
        }

        $isBookable = (bool) ($payload['is_bookable'] ?? true);
        $isOnline = (bool) ($payload['is_online_booking_enabled'] ?? true);

        if ($isOnline && ! $isBookable) {
            throw ValidationException::withMessages([
                'is_online_booking_enabled' => 'Serviços com agendamento online precisam estar disponíveis para agendamento.',
            ]);
        }
    }

    protected function assertNameIsUniqueInCompany(Company $company, string $name, ?Service $ignore = null): void
    {
        $exists = Service::query()
            ->where('company_id', $company->getKey())
            ->where('name', $name)
            ->when($ignore, fn ($query) => $query->whereKeyNot($ignore->getKey()))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'name' => 'Já existe um serviço com este nome nesta empresa.',
            ]);
        }
    }

    protected function assertSlugIsUniqueInCompany(Company $company, ?string $slug, ?Service $ignore = null): void
    {
        if (blank($slug)) {
            return;
        }

        $exists = Service::query()
            ->where('company_id', $company->getKey())
            ->where('slug', $slug)
            ->when($ignore, fn ($query) => $query->whereKeyNot($ignore->getKey()))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'slug' => 'Já existe um serviço com este slug nesta empresa.',
            ]);
        }
    }
}
