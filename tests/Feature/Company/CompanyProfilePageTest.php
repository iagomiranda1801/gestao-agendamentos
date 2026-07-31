<?php

namespace Tests\Feature\Company;

use App\Enums\CompanyRole;
use App\Filament\App\Pages\CompanyProfilePage;
use App\Services\Scheduling\CompanySchedulingSettingService;
use Livewire\Livewire;
use Tests\TestCase;

class CompanyProfilePageTest extends TestCase
{
    public function test_company_admin_can_render_company_profile_page(): void
    {
        $company = $this->createCompany();
        $admin = $this->createCompanyUser($company, [], CompanyRole::CompanyAdmin);

        $this->authenticateForAppTenant($admin, $company);

        $this->get(route('filament.app.pages.minha-empresa', ['tenant' => $company]))
            ->assertOk()
            ->assertSee('Minha empresa')
            ->assertSee('Logo da empresa');
    }

    public function test_employee_cannot_access_company_profile_page(): void
    {
        $company = $this->createCompany();
        $employee = $this->createCompanyUser($company, [], CompanyRole::Employee);

        $this->authenticateForAppTenant($employee, $company);

        $this->get(route('filament.app.pages.minha-empresa', ['tenant' => $company]))
            ->assertForbidden();
    }

    public function test_company_admin_can_update_company_profile(): void
    {
        $company = $this->createCompany();
        $admin = $this->createCompanyUser($company, [], CompanyRole::CompanyAdmin);

        $this->authenticateForAppTenant($admin, $company);

        Livewire::test(CompanyProfilePage::class)
            ->fillForm([
                'name' => 'Clínica Sol',
                'document' => '12.345.678/0001-90',
                'phone' => '(11) 99999-0000',
                'email' => 'contato@clinicasol.test',
                'logo_path' => ['company-logos/1/logo.png'],
                'timezone' => 'America/Sao_Paulo',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $company->refresh();

        $this->assertSame('Clínica Sol', $company->name);
        $this->assertSame('company-logos/1/logo.png', $company->logo_path);
    }

    public function test_manager_cannot_update_company_profile(): void
    {
        $company = $this->createCompany(['name' => 'Original']);
        $manager = $this->createCompanyUser($company, [], CompanyRole::Manager);

        $this->authenticateForAppTenant($manager, $company);

        Livewire::test(CompanyProfilePage::class)
            ->fillForm([
                'name' => 'Alterada',
                'document' => null,
                'phone' => null,
                'email' => null,
                'logo_path' => [],
                'timezone' => 'America/Sao_Paulo',
            ])
            ->call('save')
            ->assertForbidden();

        $this->assertSame('Original', $company->refresh()->name);
    }

    public function test_company_logo_path_is_resolved_to_public_storage_url(): void
    {
        $company = $this->createCompany([
            'logo_path' => 'company-logos/1/logo.png',
        ]);

        $this->assertSame(url('/storage/company-logos/1/logo.png'), $company->logoUrl());
    }

    public function test_public_booking_uses_company_logo_when_available(): void
    {
        $company = $this->createCompany([
            'name' => 'Estúdio com Logo',
            'logo_path' => 'company-logos/1/logo.png',
        ]);
        app(CompanySchedulingSettingService::class)->update($company, [
            'public_booking_enabled' => true,
        ]);

        $this->get(route('public.booking.show', ['company' => $company->slug]))
            ->assertOk()
            ->assertSee('storage/company-logos/1/logo.png', false)
            ->assertDontSee('booking-brand__logo--platform', false);
    }
}
