<?php

namespace Tests\Feature\Finance;

use App\Enums\CompanyRole;
use App\Enums\ExpenseCategoryType;
use App\Filament\App\Resources\ExpenseCategories\ExpenseCategoryResource;
use App\Filament\App\Resources\ExpenseCategories\Pages\ListExpenseCategories;
use App\Models\ExpenseCategory;
use App\Policies\ExpenseCategoryPolicy;
use App\Services\Financial\ExpenseCategoryService;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class ExpenseCategoryTest extends TestCase
{
    public function test_company_admin_can_create_category(): void
    {
        $company = $this->createCompany(['slug' => 'estudio-ana']);
        $admin = $this->createCompanyUser($company);

        $category = app(ExpenseCategoryService::class)->create($company, [
            'name' => 'Aluguel',
            'type' => ExpenseCategoryType::Administrative->value,
            'is_active' => true,
        ]);

        $this->assertSame('Aluguel', $category->name);
        $this->assertSame($company->id, $category->company_id);
    }

    public function test_manager_can_create_category_when_authorized(): void
    {
        $company = $this->createCompany(['slug' => 'estudio-ana']);
        $manager = $this->createCompanyUser($company, role: CompanyRole::Manager);

        $this->authenticateForAppTenant($manager, $company);

        Livewire::test(ListExpenseCategories::class)->assertSuccessful();

        $category = app(ExpenseCategoryService::class)->create($company, [
            'name' => 'Marketing',
            'type' => ExpenseCategoryType::Marketing->value,
        ]);

        $this->assertSame('Marketing', $category->name);
    }

    public function test_employee_cannot_access_expense_categories(): void
    {
        $company = $this->createCompany(['slug' => 'estudio-ana']);
        $employee = $this->createCompanyUser($company, role: CompanyRole::Employee);

        $this->authenticateForAppTenant($employee, $company);

        Livewire::test(ListExpenseCategories::class)->assertForbidden();
    }

    public function test_created_category_receives_current_company_id(): void
    {
        $company = $this->createCompany(['slug' => 'estudio-ana']);

        $category = app(ExpenseCategoryService::class)->create($company, [
            'name' => 'Energia',
            'type' => ExpenseCategoryType::Operational->value,
        ]);

        $this->assertSame($company->id, $category->company_id);
    }

    public function test_category_from_other_company_is_not_listed(): void
    {
        $company = $this->createCompany(['slug' => 'estudio-ana']);
        $otherCompany = $this->createCompany(['slug' => 'outra']);
        $admin = $this->createCompanyUser($company);

        $visible = ExpenseCategory::factory()->forCompany($company)->create(['name' => 'Visivel']);
        $hidden = ExpenseCategory::factory()->forCompany($otherCompany)->create(['name' => 'Oculto']);

        $this->authenticateForAppTenant($admin, $company);

        $records = ExpenseCategoryResource::getEloquentQuery()->get();

        $this->assertTrue($records->contains('id', $visible->id));
        $this->assertFalse($records->contains('id', $hidden->id));
    }

    public function test_duplicate_name_in_company_is_prevented(): void
    {
        $company = $this->createCompany(['slug' => 'estudio-ana']);

        app(ExpenseCategoryService::class)->create($company, [
            'name' => 'Internet',
            'type' => ExpenseCategoryType::Operational->value,
        ]);

        $this->expectException(ValidationException::class);

        app(ExpenseCategoryService::class)->create($company, [
            'name' => 'Internet',
            'type' => ExpenseCategoryType::Administrative->value,
        ]);
    }

    public function test_same_name_can_exist_in_different_companies(): void
    {
        $companyA = $this->createCompany(['slug' => 'empresa-a']);
        $companyB = $this->createCompany(['slug' => 'empresa-b']);

        $categoryA = app(ExpenseCategoryService::class)->create($companyA, [
            'name' => 'Telefone',
            'type' => ExpenseCategoryType::Operational->value,
        ]);

        $categoryB = app(ExpenseCategoryService::class)->create($companyB, [
            'name' => 'Telefone',
            'type' => ExpenseCategoryType::Operational->value,
        ]);

        $this->assertNotSame($categoryA->id, $categoryB->id);
        $this->assertSame('Telefone', $categoryA->name);
        $this->assertSame('Telefone', $categoryB->name);
    }

    public function test_parent_category_must_belong_to_same_company(): void
    {
        $company = $this->createCompany(['slug' => 'estudio-ana']);
        $otherCompany = $this->createCompany(['slug' => 'outra']);
        $foreignParent = ExpenseCategory::factory()->forCompany($otherCompany)->create();

        $this->expectException(ValidationException::class);

        app(ExpenseCategoryService::class)->create($company, [
            'name' => 'Filha',
            'type' => ExpenseCategoryType::Operational->value,
            'parent_id' => $foreignParent->id,
        ]);
    }

    public function test_category_hierarchy_cycle_is_prevented(): void
    {
        $company = $this->createCompany(['slug' => 'estudio-ana']);

        $parent = app(ExpenseCategoryService::class)->create($company, [
            'name' => 'Pai',
            'type' => ExpenseCategoryType::Operational->value,
        ]);

        $child = app(ExpenseCategoryService::class)->create($company, [
            'name' => 'Filho',
            'type' => ExpenseCategoryType::Operational->value,
            'parent_id' => $parent->id,
        ]);

        $this->expectException(ValidationException::class);

        app(ExpenseCategoryService::class)->update($company, $parent, [
            'parent_id' => $child->id,
        ]);
    }

    public function test_system_category_cannot_be_deleted(): void
    {
        $company = $this->createCompany(['slug' => 'estudio-ana']);
        $admin = $this->createCompanyUser($company);
        $category = ExpenseCategory::factory()->forCompany($company)->system()->create([
            'name' => 'Taxas de pagamentos',
            'code' => 'payment_fees',
            'type' => ExpenseCategoryType::Financial,
        ]);

        $policy = app(ExpenseCategoryPolicy::class);

        $this->assertFalse($policy->delete($admin, $category));

        $this->expectException(ValidationException::class);

        app(ExpenseCategoryService::class)->deactivate($company, $category);
    }

    public function test_stock_purchase_category_does_not_affect_managerial_result(): void
    {
        $company = $this->createCompany(['slug' => 'estudio-ana']);

        $category = app(ExpenseCategoryService::class)->create($company, [
            'name' => 'Compra de estoque',
            'type' => ExpenseCategoryType::StockPurchase->value,
            'affects_managerial_result' => true,
        ]);

        $this->assertFalse($category->affects_managerial_result);
        $this->assertSame(ExpenseCategoryType::StockPurchase, $category->type);
    }
}
