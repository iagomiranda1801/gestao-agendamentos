<?php

namespace Tests\Feature\Filament;

use App\Enums\CompanyRole;
use App\Filament\App\Pages\RegisterExpensePage;
use Livewire\Livewire;
use Tests\Support\CreatesFinanceFixtures;
use Tests\TestCase;

class RegisterExpensePageTest extends TestCase
{
    use CreatesFinanceFixtures;

    public function test_company_admin_can_access_register_expense_page(): void
    {
        $company = $this->createCompany();
        $admin = $this->createCompanyUser($company, [], CompanyRole::CompanyAdmin);
        $this->createOperationalCategory($company);

        $this->authenticateForAppTenant($admin, $company);

        $this->get(route('filament.app.pages.registrar-despesa', ['tenant' => $company]))
            ->assertOk();
    }

    public function test_employee_cannot_access_register_expense_page(): void
    {
        $company = $this->createCompany();
        $employee = $this->createCompanyUser($company, [], CompanyRole::Employee);

        $this->authenticateForAppTenant($employee, $company);

        $this->get(route('filament.app.pages.registrar-despesa', ['tenant' => $company]))
            ->assertForbidden();
    }

    public function test_register_expense_page_renders_form_for_admin(): void
    {
        $company = $this->createCompany();
        $admin = $this->createCompanyUser($company, [], CompanyRole::CompanyAdmin);
        $this->createOperationalCategory($company);

        $this->authenticateForAppTenant($admin, $company);

        Livewire::test(RegisterExpensePage::class)
            ->assertSuccessful()
            ->assertSee('Descrição')
            ->assertSee('Registrar despesa');
    }
}
