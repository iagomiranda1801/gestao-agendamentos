<?php

namespace Database\Seeders;

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Seeder;

class TenantFoundationSeeder extends Seeder
{
    public function run(): void
    {
        $superAdmin = User::query()->updateOrCreate(
            ['email' => 'superadmin@imsolucoes.test'],
            [
                'name' => 'Iago Super Admin',
                'password' => 'password',
                'is_super_admin' => true,
                'is_active' => true,
            ],
        );

        $company = Company::query()->updateOrCreate(
            ['slug' => 'estudio-ana'],
            [
                'name' => 'Estúdio Ana',
                'email' => 'contato@estudioana.test',
                'timezone' => 'America/Sao_Paulo',
                'is_active' => true,
            ],
        );

        $companyAdmin = User::query()->updateOrCreate(
            ['email' => 'ana@estudioana.test'],
            [
                'name' => 'Ana Admin',
                'password' => 'password',
                'is_super_admin' => false,
                'is_active' => true,
            ],
        );

        $company->users()->syncWithoutDetaching([
            $companyAdmin->id => [
                'role' => CompanyRole::CompanyAdmin->value,
                'is_active' => true,
            ],
        ]);

        unset($superAdmin);
    }
}
