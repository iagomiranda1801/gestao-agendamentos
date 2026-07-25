<?php

namespace App\Models;

use App\Enums\CompanyRole;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;

#[Fillable(['name', 'email', 'password', 'is_super_admin', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, HasTenants
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_super_admin' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsToMany<Company, $this>
     */
    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class)
            ->withPivot(['role', 'is_active'])
            ->withTimestamps()
            ->using(CompanyUser::class);
    }

    public function canAccessPanel(Panel $panel): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($panel->getId() === 'admin') {
            return $this->is_super_admin;
        }

        if ($panel->getId() === 'app') {
            if ($this->is_super_admin) {
                return true;
            }

            return $this->hasActiveCompanyMembership();
        }

        return false;
    }

    /**
     * @return Collection<int, Company>
     */
    public function getTenants(Panel $panel): Collection
    {
        if ($panel->getId() !== 'app') {
            return collect();
        }

        if ($this->is_super_admin) {
            return Company::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get();
        }

        return $this->companies()
            ->where('companies.is_active', true)
            ->wherePivot('is_active', true)
            ->orderBy('companies.name')
            ->get();
    }

    public function canAccessTenant(Model $tenant): bool
    {
        if (! $tenant instanceof Company) {
            return false;
        }

        if (! $tenant->is_active) {
            return false;
        }

        if (! $this->is_active) {
            return false;
        }

        if ($this->is_super_admin) {
            return Company::query()
                ->whereKey($tenant->getKey())
                ->where('is_active', true)
                ->exists();
        }

        return $this->companies()
            ->where('companies.id', $tenant->getKey())
            ->where('companies.is_active', true)
            ->wherePivot('is_active', true)
            ->exists();
    }

    public function hasActiveCompanyMembership(): bool
    {
        return $this->companies()
            ->where('companies.is_active', true)
            ->wherePivot('is_active', true)
            ->exists();
    }

    public function companyRole(Company $company): ?CompanyRole
    {
        $membership = $this->companies()
            ->where('companies.id', $company->getKey())
            ->first()
            ?->pivot;

        if (! $membership) {
            return null;
        }

        return $membership->role instanceof CompanyRole
            ? $membership->role
            : CompanyRole::from($membership->role);
    }

    public function hasActiveRoleInCompany(Company $company, CompanyRole ...$roles): bool
    {
        if (! $this->is_active || ! $company->is_active) {
            return false;
        }

        if ($roles === []) {
            return $this->hasActiveCompanyMembershipWith($company);
        }

        $membership = $this->companies()
            ->where('companies.id', $company->getKey())
            ->where('companies.is_active', true)
            ->wherePivot('is_active', true)
            ->first();

        if (! $membership?->pivot) {
            return false;
        }

        $role = $membership->pivot->role instanceof CompanyRole
            ? $membership->pivot->role
            : CompanyRole::from($membership->pivot->role);

        return in_array($role, $roles, true);
    }

    public function hasActiveCompanyMembershipWith(Company $company): bool
    {
        if (! $this->is_active || ! $company->is_active) {
            return false;
        }

        return $this->companies()
            ->where('companies.id', $company->getKey())
            ->where('companies.is_active', true)
            ->wherePivot('is_active', true)
            ->exists();
    }

    /**
     * @return HasMany<Professional, $this>
     */
    public function professionalProfiles(): HasMany
    {
        return $this->hasMany(Professional::class);
    }
}
