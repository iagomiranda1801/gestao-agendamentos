<?php

namespace Tests\Feature\MultiTenancy;

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Tests\TestCase;

class CompanyValidationTest extends TestCase
{
    public function test_duplicate_company_slug_is_prevented(): void
    {
        $this->createCompany(['slug' => 'estudio-ana']);

        $validator = Validator::make(
            ['slug' => 'estudio-ana'],
            ['slug' => ['required', Rule::unique('companies', 'slug')]],
        );

        $this->assertTrue($validator->fails());
    }

    public function test_duplicate_company_user_association_is_prevented(): void
    {
        $company = $this->createCompany(['slug' => 'estudio-ana']);
        $user = User::factory()->create();

        $company->users()->attach($user, [
            'role' => CompanyRole::CompanyAdmin->value,
            'is_active' => true,
        ]);

        $this->expectException(QueryException::class);

        $company->users()->attach($user, [
            'role' => CompanyRole::Employee->value,
            'is_active' => true,
        ]);
    }
}
