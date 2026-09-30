<?php

namespace App\Services\Professional;

use App\Enums\CompanyPermission;
use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\Professional;
use App\Models\User;
use App\Services\Company\CompanyPermissionService;
use App\Services\Company\CompanyTeamService;
use App\Support\PhoneNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class ProfessionalService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Company $company, array $data): Professional
    {
        return DB::transaction(function () use ($company, $data): Professional {
            if ($data['create_access'] ?? false) {
                $actor = auth()->user();
                abort_unless($actor instanceof User && app(CompanyPermissionService::class)
                    ->allows($actor, $company, CompanyPermission::ManagePermissions), 403);

                validator($data, [
                    'name' => ['required', 'string', 'max:255'],
                    'email' => ['required', 'email', 'max:255', 'unique:users,email'],
                    'access_role' => ['required', 'in:'.implode(',', array_column(CompanyRole::cases(), 'value'))],
                    'access_password' => ['required', 'confirmed', Password::defaults()],
                ])->validate();

                $user = app(CompanyTeamService::class)->create($company, [
                    'name' => $data['name'],
                    'email' => $data['email'],
                    'password' => $data['access_password'],
                    'role' => $data['access_role'],
                ]);
                $data['user_id'] = $user->getKey();
            }

            $payload = $this->preparePayload($data);

            $this->assertUserCanBeLinked($company, $payload['user_id'] ?? null);

            $professional = new Professional($payload);
            $professional->company()->associate($company);
            $professional->save();

            return $professional->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Company $company, Professional $professional, array $data): Professional
    {
        return DB::transaction(function () use ($company, $professional, $data): Professional {
            $this->ensureBelongsToCompany($company, $professional);

            $payload = $this->preparePayload($data);

            $this->assertUserCanBeLinked(
                $company,
                $payload['user_id'] ?? null,
                $professional,
            );

            $professional->fill($payload);
            $professional->save();

            return $professional->refresh();
        });
    }

    public function changeStatus(Company $company, Professional $professional, bool $isActive): Professional
    {
        $this->ensureBelongsToCompany($company, $professional);

        $professional->update(['is_active' => $isActive]);

        return $professional->refresh();
    }

    public function changeBookableStatus(Company $company, Professional $professional, bool $isBookable): Professional
    {
        $this->ensureBelongsToCompany($company, $professional);

        $professional->update(['is_bookable' => $isBookable]);

        return $professional->refresh();
    }

    public function ensureBelongsToCompany(Company $company, Professional $professional): void
    {
        if ((int) $professional->company_id !== (int) $company->getKey()) {
            abort(404);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function preparePayload(array $data): array
    {
        unset($data['company_id'], $data['create_access'], $data['access_role'], $data['access_password'], $data['access_password_confirmation']);

        if (array_key_exists('phone', $data)) {
            $data['phone_normalized'] = filled($data['phone'])
                ? PhoneNormalizer::normalize($data['phone'])
                : null;
        }

        return $data;
    }

    protected function assertUserCanBeLinked(
        Company $company,
        ?int $userId,
        ?Professional $ignore = null,
    ): void {
        if ($userId === null) {
            return;
        }

        $user = User::query()->find($userId);

        if (! $user || ! $user->is_active) {
            throw ValidationException::withMessages([
                'user_id' => 'Selecione um usuário ativo vinculado à empresa.',
            ]);
        }

        if ($user->is_super_admin) {
            throw ValidationException::withMessages([
                'user_id' => 'Superadministradores não podem ser vinculados como profissionais.',
            ]);
        }

        if (! $user->hasActiveCompanyMembershipWith($company)) {
            throw ValidationException::withMessages([
                'user_id' => 'O usuário selecionado não possui vínculo ativo com esta empresa.',
            ]);
        }

        $exists = Professional::query()
            ->where('company_id', $company->getKey())
            ->where('user_id', $userId)
            ->when($ignore, fn ($query) => $query->whereKeyNot($ignore->getKey()))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'user_id' => 'Este usuário já está vinculado a outro profissional desta empresa.',
            ]);
        }
    }
}
