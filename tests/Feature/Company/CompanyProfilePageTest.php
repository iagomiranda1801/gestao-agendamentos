<?php

namespace Tests\Feature\Company;

use App\Enums\CompanyModule;
use App\Enums\CompanyProfile;
use App\Enums\CompanyRole;
use App\Filament\App\Pages\CompanyProfilePage;
use App\Services\Scheduling\CompanySchedulingSettingService;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function test_restaurant_company_profile_page_shows_public_orders_link_and_pedidos_copy(): void
    {
        $company = $this->createCompany([
            'name' => 'Petiscaria do Tio Wesley',
            'slug' => 'petiscaria-do-tio-wesley',
            'business_profile' => CompanyProfile::Restaurant,
            'enabled_modules' => [CompanyModule::Orders->value, CompanyModule::WhatsApp->value],
        ]);
        $admin = $this->createCompanyUser($company, [], CompanyRole::CompanyAdmin);

        $this->authenticateForAppTenant($admin, $company);

        $this->get(route('filament.app.pages.minha-empresa', ['tenant' => $company]))
            ->assertOk()
            ->assertSee('Pedidos online')
            ->assertSee('página pública de cardápio e pedidos online')
            ->assertSee('/pedir/petiscaria-do-tio-wesley')
            ->assertSee(route('public.orders.show', ['company' => $company->slug]))
            ->assertSee('Abrir configurações de pedidos')
            ->assertDontSee('Agendamento público')
            ->assertDontSee('/agendar/petiscaria-do-tio-wesley')
            ->assertDontSee('página pública de agendamento');
    }

    public function test_restaurant_with_scheduling_module_still_shows_public_orders_link(): void
    {
        $company = $this->createCompany([
            'slug' => 'cantina-mista',
            'business_profile' => CompanyProfile::Restaurant,
            'enabled_modules' => [
                CompanyModule::Orders->value,
                CompanyModule::Scheduling->value,
                CompanyModule::WhatsApp->value,
            ],
        ]);
        $admin = $this->createCompanyUser($company, [], CompanyRole::CompanyAdmin);

        $this->authenticateForAppTenant($admin, $company);

        $this->get(route('filament.app.pages.minha-empresa', ['tenant' => $company]))
            ->assertOk()
            ->assertSee('Pedidos online')
            ->assertSee('/pedir/cantina-mista')
            ->assertDontSee('/agendar/cantina-mista');
    }

    #[DataProvider('schedulingCompanyProfiles')]
    public function test_scheduling_company_profile_page_shows_public_booking_link(
        CompanyProfile $profile,
        string $slug,
    ): void {
        $company = $this->createCompany([
            'slug' => $slug,
            'business_profile' => $profile,
            'enabled_modules' => [CompanyModule::Scheduling->value, CompanyModule::WhatsApp->value],
        ]);
        $admin = $this->createCompanyUser($company, [], CompanyRole::CompanyAdmin);

        $this->authenticateForAppTenant($admin, $company);

        $this->get(route('filament.app.pages.minha-empresa', ['tenant' => $company]))
            ->assertOk()
            ->assertSee('Agendamento público')
            ->assertSee('página pública de agendamento')
            ->assertSee('/agendar/'.$slug)
            ->assertSee(route('public.booking.show', ['company' => $company->slug]))
            ->assertDontSee('Pedidos online')
            ->assertDontSee('/pedir/'.$slug)
            ->assertDontSee('página pública de cardápio e pedidos online');
    }

    /**
     * @return array<string, array{0: CompanyProfile, 1: string}>
     */
    public static function schedulingCompanyProfiles(): array
    {
        return [
            'salon' => [CompanyProfile::Salon, 'salao-beleza'],
            'clinic' => [CompanyProfile::Clinic, 'clinica-vida'],
        ];
    }
}
