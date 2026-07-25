<?php

namespace Tests\Feature\Professionals;

use App\Enums\CompanyRole;
use App\Filament\App\Resources\Professionals\Pages\ListProfessionals;
use App\Filament\App\Resources\Professionals\ProfessionalResource;
use App\Models\Professional;
use App\Models\User;
use App\Policies\ProfessionalPolicy;
use App\Services\Professional\ProfessionalService;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class ProfessionalResourceTest extends TestCase
{
    public function test_company_admin_can_list_professionals(): void
    {
        $company = $this->createCompany(['slug' => 'estudio-ana']);
        $admin = $this->createCompanyUser($company);
        Professional::factory()->forCompany($company)->create(['name' => 'Ana']);

        $this->authenticateForAppTenant($admin, $company);

        Livewire::test(ListProfessionals::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords(Professional::where('company_id', $company->id)->get());
    }

    public function test_manager_can_list_professionals(): void
    {
        $company = $this->createCompany(['slug' => 'estudio-ana']);
        $manager = $this->createCompanyUser($company, role: CompanyRole::Manager);

        $this->authenticateForAppTenant($manager, $company);

        Livewire::test(ListProfessionals::class)->assertSuccessful();
    }

    public function test_employee_cannot_access_professional_resource(): void
    {
        $company = $this->createCompany(['slug' => 'estudio-ana']);
        $employee = $this->createCompanyUser($company, role: CompanyRole::Employee);

        $this->authenticateForAppTenant($employee, $company);

        Livewire::test(ListProfessionals::class)->assertForbidden();
    }

    public function test_created_professional_receives_current_company_id(): void
    {
        $company = $this->createCompany(['slug' => 'estudio-ana']);

        $professional = app(ProfessionalService::class)->create($company, [
            'name' => 'Ana',
            'is_active' => true,
            'is_bookable' => true,
            'sort_order' => 1,
        ]);

        $this->assertSame($company->id, $professional->company_id);
    }

    public function test_manipulated_company_id_is_ignored_on_create(): void
    {
        $company = $this->createCompany(['slug' => 'estudio-ana']);
        $otherCompany = $this->createCompany(['slug' => 'outra']);

        $professional = app(ProfessionalService::class)->create($company, [
            'name' => 'Ana',
            'company_id' => $otherCompany->id,
            'is_active' => true,
            'is_bookable' => true,
            'sort_order' => 1,
        ]);

        $this->assertSame($company->id, $professional->company_id);
    }

    public function test_professional_from_other_company_is_not_listed(): void
    {
        $company = $this->createCompany(['slug' => 'estudio-ana']);
        $otherCompany = $this->createCompany(['slug' => 'outra']);
        $admin = $this->createCompanyUser($company);

        $visible = Professional::factory()->forCompany($company)->create(['name' => 'Visivel']);
        $hidden = Professional::factory()->forCompany($otherCompany)->create(['name' => 'Oculto']);

        $this->authenticateForAppTenant($admin, $company);

        Livewire::test(ListProfessionals::class)->assertSuccessful();

        $records = ProfessionalResource::getEloquentQuery()->get();

        $this->assertTrue($records->contains('id', $visible->id));
        $this->assertFalse($records->contains('id', $hidden->id));
    }

    public function test_professional_from_other_company_cannot_be_edited_by_url(): void
    {
        $company = $this->createCompany(['slug' => 'estudio-ana']);
        $otherCompany = $this->createCompany(['slug' => 'outra']);
        $admin = $this->createCompanyUser($company);
        $foreign = Professional::factory()->forCompany($otherCompany)->create();

        $this->actingAs($admin)
            ->get(ProfessionalResource::getUrl('edit', [
                'tenant' => $company,
                'record' => $foreign,
            ], panel: 'app', tenant: $company))
            ->assertNotFound();
    }

    public function test_linked_user_must_belong_to_company(): void
    {
        $company = $this->createCompany(['slug' => 'estudio-ana']);
        $foreignUser = User::factory()->create();

        $this->expectException(ValidationException::class);

        app(ProfessionalService::class)->create($company, [
            'name' => 'Profissional',
            'user_id' => $foreignUser->id,
            'is_active' => true,
            'is_bookable' => true,
            'sort_order' => 1,
        ]);
    }

    public function test_user_with_inactive_membership_cannot_be_linked(): void
    {
        $company = $this->createCompany(['slug' => 'estudio-ana']);
        $user = $this->createCompanyUser($company, isActive: false);

        $this->expectException(ValidationException::class);

        app(ProfessionalService::class)->create($company, [
            'name' => 'Profissional',
            'user_id' => $user->id,
            'is_active' => true,
            'is_bookable' => true,
            'sort_order' => 1,
        ]);
    }

    public function test_user_from_other_company_cannot_be_linked(): void
    {
        $company = $this->createCompany(['slug' => 'estudio-ana']);
        $otherCompany = $this->createCompany(['slug' => 'outra']);
        $foreignUser = $this->createCompanyUser($otherCompany);

        $this->expectException(ValidationException::class);

        app(ProfessionalService::class)->create($company, [
            'name' => 'Profissional',
            'user_id' => $foreignUser->id,
            'is_active' => true,
            'is_bookable' => true,
            'sort_order' => 1,
        ]);
    }

    public function test_same_user_cannot_be_linked_to_two_professionals_in_same_company(): void
    {
        $company = $this->createCompany(['slug' => 'estudio-ana']);
        $user = $this->createCompanyUser($company);

        Professional::factory()->forCompany($company)->linkedToUser($user)->create(['name' => 'Ana']);

        $this->expectException(ValidationException::class);

        app(ProfessionalService::class)->create($company, [
            'name' => 'Outro',
            'user_id' => $user->id,
            'is_active' => true,
            'is_bookable' => true,
            'sort_order' => 2,
        ]);
    }

    public function test_same_user_can_be_professional_in_different_companies(): void
    {
        $companyA = $this->createCompany(['slug' => 'empresa-a']);
        $companyB = $this->createCompany(['slug' => 'empresa-b']);
        $user = User::factory()->create();

        $companyA->users()->attach($user, ['role' => CompanyRole::Employee->value, 'is_active' => true]);
        $companyB->users()->attach($user, ['role' => CompanyRole::Employee->value, 'is_active' => true]);

        app(ProfessionalService::class)->create($companyA, [
            'name' => 'Prof A',
            'user_id' => $user->id,
            'is_active' => true,
            'is_bookable' => true,
            'sort_order' => 1,
        ]);

        $professionalB = app(ProfessionalService::class)->create($companyB, [
            'name' => 'Prof B',
            'user_id' => $user->id,
            'is_active' => true,
            'is_bookable' => true,
            'sort_order' => 1,
        ]);

        $this->assertSame($companyB->id, $professionalB->company_id);
    }

    public function test_professional_can_be_deactivated(): void
    {
        $company = $this->createCompany();
        $professional = Professional::factory()->forCompany($company)->create(['is_active' => true]);

        app(ProfessionalService::class)->changeStatus($company, $professional, false);

        $this->assertFalse($professional->fresh()->is_active);
    }

    public function test_professional_can_be_marked_as_not_bookable(): void
    {
        $company = $this->createCompany();
        $professional = Professional::factory()->forCompany($company)->bookable()->create();

        app(ProfessionalService::class)->changeBookableStatus($company, $professional, false);

        $this->assertFalse($professional->fresh()->is_bookable);
    }

    public function test_phone_is_normalized_on_create(): void
    {
        $company = $this->createCompany();

        $professional = app(ProfessionalService::class)->create($company, [
            'name' => 'Ana',
            'phone' => '(34) 99999-0001',
            'is_active' => true,
            'is_bookable' => true,
            'sort_order' => 1,
        ]);

        $this->assertSame('34999990001', $professional->phone_normalized);
    }

    public function test_search_does_not_return_professionals_from_other_company(): void
    {
        $company = $this->createCompany(['slug' => 'estudio-ana']);
        $otherCompany = $this->createCompany(['slug' => 'outra']);
        $admin = $this->createCompanyUser($company);

        Professional::factory()->forCompany($company)->create(['name' => 'Ana Visivel']);
        Professional::factory()->forCompany($otherCompany)->create(['name' => 'Ana Oculta']);

        $this->authenticateForAppTenant($admin, $company);

        Livewire::test(ListProfessionals::class)
            ->searchTable('Ana')
            ->assertSuccessful()
            ->assertCanSeeTableRecords(
                Professional::query()->where('company_id', $company->id)->get()
            );

        $this->assertFalse(
            ProfessionalResource::getEloquentQuery()
                ->where('company_id', $otherCompany->id)
                ->where('name', 'like', '%Ana%')
                ->exists()
        );
    }

    public function test_professional_resource_has_no_delete_policy(): void
    {
        $this->assertFalse((new ProfessionalPolicy)->delete(
            $this->createCompanyUser($this->createCompany()),
            Professional::factory()->make(),
        ));
    }
}
