<?php

namespace Tests\Feature\Company;

use App\Enums\CompanyModule;
use App\Enums\CompanyProfile;
use App\Filament\App\Resources\Clients\ClientResource;
use App\Filament\App\Resources\Professionals\Pages\CreateProfessional;
use App\Filament\App\Resources\Services\Pages\CreateService;
use App\Filament\App\Resources\Services\ServiceResource;
use App\Services\Company\CompanyProvisioningService;
use App\Services\Company\PersonalTrainerSetupService;
use App\Services\Professional\ProfessionalService;
use App\Services\Scheduling\CompanySchedulingSettingService;
use App\Services\Scheduling\ProfessionalWorkingHoursService;
use App\Services\Service\ServiceCatalogService;
use App\Services\Service\ServiceProfessionalSyncService;
use App\Services\WhatsApp\Bot\WhatsAppBookingBotMessageBuilder;
use App\Support\CompanyTerminology;
use Livewire\Livewire;
use Tests\TestCase;

class PersonalTrainerProfileTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_profile_defaults_and_terms(): void
    {
        $this->assertSame('Personal trainer', CompanyProfile::PersonalTrainer->label());
        $this->assertSame(
            [CompanyModule::Scheduling, CompanyModule::WhatsApp, CompanyModule::Finance],
            CompanyProfile::PersonalTrainer->defaultModules(),
        );

        $company = $this->createCompany(['business_profile' => CompanyProfile::PersonalTrainer]);
        $this->authenticateForAppTenant($this->createCompanyUser($company), $company);

        $this->assertSame('Aluno', CompanyTerminology::client($company));
        $this->assertSame('Alunos', ClientResource::getNavigationLabel());
        $this->assertSame('Personal trainers', CompanyTerminology::professional($company, plural: true));
        $this->assertFalse($company->usesClinicalChart());
        $this->assertStringContainsString(
            '*personal trainer*',
            app(WhatsAppBookingBotMessageBuilder::class)->professionalMenu(collect(), false, $company),
        );
    }

    public function test_setup_tracks_individual_booking_readiness_without_creating_records(): void
    {
        $result = app(CompanyProvisioningService::class)->provision([
            'name' => 'Treinos da Ana',
            'business_profile' => CompanyProfile::PersonalTrainer,
            'admin_name' => 'Ana',
            'admin_email' => 'ana@treinos.test',
            'admin_password' => 'Password123!',
        ]);
        $company = $result['company'];
        $admin = $result['user'];
        $this->authenticateForAppTenant($admin, $company);

        $setup = app(PersonalTrainerSetupService::class);
        $initial = $setup->summary($company);
        $this->assertSame([false, false, false, false], array_column($initial['steps'], 'complete'));
        $this->assertNull($initial['booking_url']);
        $this->assertSame(0, $company->professionals()->count());

        $professional = app(ProfessionalService::class)->create($company, [
            'name' => 'Ana', 'user_id' => $admin->getKey(), 'is_active' => true, 'is_bookable' => true,
        ]);
        app(ProfessionalWorkingHoursService::class)->create($company, $professional, [
            'weekday' => 1, 'start_time' => '09:00', 'end_time' => '18:00', 'is_active' => true,
        ]);

        foreach (['Avaliação inicial', 'Treino individual'] as $name) {
            $service = app(ServiceCatalogService::class)->create($company, [
                'name' => $name,
                'price' => 100,
                'duration_minutes' => 60,
                'is_bookable' => true,
                'is_online_booking_enabled' => true,
                'is_active' => true,
            ]);
            app(ServiceProfessionalSyncService::class)->sync($company, $service, [$professional->getKey()]);
        }

        $this->assertNull($setup->summary($company)['booking_url']);

        app(CompanySchedulingSettingService::class)->update($company, ['public_booking_enabled' => true]);
        $company->refresh();

        $ready = $setup->summary($company);
        $this->assertSame([true, true, true, true], array_column($ready['steps'], 'complete'));
        $this->assertNotNull($ready['booking_url']);
        $this->assertSame(1, $company->professionals()->count());
    }

    public function test_dashboard_and_creation_forms_guide_the_first_setup(): void
    {
        $company = $this->createCompany([
            'business_profile' => CompanyProfile::PersonalTrainer,
            'enabled_modules' => ['scheduling', 'whatsapp'],
        ]);
        $admin = $this->createCompanyUser($company, ['name' => 'Ana Personal']);
        $this->authenticateForAppTenant($admin, $company);

        $this->get(route('filament.app.pages.dashboard', ['tenant' => $company]))
            ->assertOk()
            ->assertSee('Configure seus atendimentos')
            ->assertSee('Criar Avaliação inicial')
            ->assertDontSee('Abrir link de agendamento');

        Livewire::test(CreateProfessional::class)
            ->assertFormSet(['name' => 'Ana Personal', 'user_id' => $admin->getKey()]);

        $this->get(ServiceResource::getUrl('create').'?personal_template=initial_assessment')
            ->assertOk()->assertSee('avaliacao-inicial');

        Livewire::test(CreateService::class)->assertFormSet(['price' => null, 'duration_minutes' => null]);
    }
}
