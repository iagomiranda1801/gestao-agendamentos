<?php

namespace Tests\Feature\Suppliers;

use App\Enums\CompanyRole;
use App\Filament\App\Resources\Suppliers\Pages\ListSuppliers;
use App\Filament\App\Resources\Suppliers\SupplierResource;
use App\Models\Supplier;
use App\Policies\SupplierPolicy;
use App\Services\Supplier\SupplierService;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class SupplierResourceTest extends TestCase
{
    public function test_company_admin_can_list_suppliers(): void
    {
        $company = $this->createCompany();
        $admin = $this->createCompanyUser($company);
        Supplier::factory()->forCompany($company)->create(['name' => 'Fornecedor A']);

        $this->authenticateForAppTenant($admin, $company);

        Livewire::test(ListSuppliers::class)->assertSuccessful();
    }

    public function test_manager_can_list_suppliers(): void
    {
        $company = $this->createCompany();
        $manager = $this->createCompanyUser($company, role: CompanyRole::Manager);

        $this->authenticateForAppTenant($manager, $company);

        Livewire::test(ListSuppliers::class)->assertSuccessful();
    }

    public function test_employee_cannot_access_supplier_resource(): void
    {
        $company = $this->createCompany();
        $employee = $this->createCompanyUser($company, role: CompanyRole::Employee);

        $this->authenticateForAppTenant($employee, $company);

        Livewire::test(ListSuppliers::class)->assertForbidden();
    }

    public function test_supplier_receives_current_company_id(): void
    {
        $company = $this->createCompany();

        $supplier = app(SupplierService::class)->create($company, [
            'name' => 'Fornecedor A',
            'is_active' => true,
        ]);

        $this->assertSame($company->id, $supplier->company_id);
    }

    public function test_manipulated_company_id_is_ignored_on_create(): void
    {
        $company = $this->createCompany();
        $other = $this->createCompany();

        $supplier = app(SupplierService::class)->create($company, [
            'name' => 'Fornecedor A',
            'company_id' => $other->id,
            'is_active' => true,
        ]);

        $this->assertSame($company->id, $supplier->company_id);
    }

    public function test_supplier_from_other_company_is_not_listed(): void
    {
        $company = $this->createCompany();
        $other = $this->createCompany();
        $admin = $this->createCompanyUser($company);

        $visible = Supplier::factory()->forCompany($company)->create();
        Supplier::factory()->forCompany($other)->create();

        $this->authenticateForAppTenant($admin, $company);

        $records = SupplierResource::getEloquentQuery()->get();

        $this->assertTrue($records->contains('id', $visible->id));
        $this->assertSame(1, $records->count());
    }

    public function test_supplier_from_other_company_cannot_be_edited(): void
    {
        $company = $this->createCompany();
        $other = $this->createCompany();
        $admin = $this->createCompanyUser($company);
        $foreign = Supplier::factory()->forCompany($other)->create();

        $this->actingAs($admin)
            ->get(SupplierResource::getUrl('edit', ['tenant' => $company, 'record' => $foreign], panel: 'app', tenant: $company))
            ->assertNotFound();
    }

    public function test_duplicate_document_in_same_company_is_prevented(): void
    {
        $company = $this->createCompany();
        Supplier::factory()->forCompany($company)->create(['document' => '123']);

        $this->expectException(ValidationException::class);

        app(SupplierService::class)->create($company, [
            'name' => 'Outro',
            'document' => '123',
            'is_active' => true,
        ]);
    }

    public function test_same_document_can_exist_in_different_companies(): void
    {
        $a = $this->createCompany();
        $b = $this->createCompany();

        app(SupplierService::class)->create($a, ['name' => 'A', 'document' => '123', 'is_active' => true]);
        $supplierB = app(SupplierService::class)->create($b, ['name' => 'B', 'document' => '123', 'is_active' => true]);

        $this->assertSame('123', $supplierB->document);
    }

    public function test_supplier_can_be_deactivated(): void
    {
        $company = $this->createCompany();
        $supplier = Supplier::factory()->forCompany($company)->create(['is_active' => true]);

        app(SupplierService::class)->changeStatus($company, $supplier, false);

        $this->assertFalse($supplier->fresh()->is_active);
    }

    public function test_supplier_resource_has_no_delete_action(): void
    {
        $this->assertFalse((new SupplierPolicy)->delete(
            $this->createCompanyUser($this->createCompany()),
            Supplier::factory()->make(),
        ));
    }
}
