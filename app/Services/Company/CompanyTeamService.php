<?php

namespace App\Services\Company;

use App\Enums\CompanyPermission;
use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CompanyTeamService
{
    public function __construct(protected CompanyMembershipGuard $membershipGuard) {}

    /** @param array<string, mixed> $data */
    public function create(Company $company, array $data): User
    {
        if (User::query()->where('email', $data['email'])->exists()) {
            throw ValidationException::withMessages(['email' => 'Este e-mail já possui uma conta. Solicite ao administrador da plataforma o vínculo seguro.']);
        }

        return DB::transaction(function () use ($company, $data): User {
            $user = User::query()->create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'is_active' => true,
                'is_super_admin' => false,
            ]);
            $company->users()->attach($user, [
                'role' => CompanyRole::from($data['role'])->value,
                'is_active' => (bool) ($data['membership_active'] ?? true),
                'permissions' => $this->permissionsPayload(CompanyRole::from($data['role']), $data),
            ]);

            return $user->refresh();
        });
    }

    /** @param array<string, mixed> $data */
    public function update(Company $company, User $user, array $data): User
    {
        $membership = $company->users()->where('users.id', $user->getKey())->first()?->pivot;
        abort_unless($membership !== null, 404);
        $role = CompanyRole::from($data['role']);
        $active = (bool) ($data['membership_active'] ?? true);
        $this->membershipGuard->ensureCanRemoveLastActiveAdmin($company, $user, $role, $active);

        $emailTaken = User::query()->where('email', $data['email'])->whereKeyNot($user->getKey())->exists();
        if ($emailTaken) {
            throw ValidationException::withMessages(['email' => 'Este e-mail já está em uso.']);
        }

        return DB::transaction(function () use ($company, $user, $data, $role, $active): User {
            $payload = ['name' => $data['name'], 'email' => $data['email']];
            if (filled($data['password'] ?? null)) {
                $payload['password'] = $data['password'];
            }
            $user->update($payload);
            $company->users()->updateExistingPivot($user->getKey(), [
                'role' => $role->value,
                'is_active' => $active,
                'permissions' => $this->permissionsPayload($role, $data),
            ]);

            return $user->refresh();
        });
    }

    /** @param array<string, mixed> $data @return list<string>|null */
    protected function permissionsPayload(CompanyRole $role, array $data): ?array
    {
        if ($data['use_role_defaults'] ?? true) {
            return null;
        }

        $permissions = array_values(array_unique($data['permissions'] ?? []));

        if ($role === CompanyRole::CompanyAdmin && ! in_array(CompanyPermission::ManagePermissions->value, $permissions, true)) {
            $permissions[] = CompanyPermission::ManagePermissions->value;
        }

        return $permissions;
    }
}
