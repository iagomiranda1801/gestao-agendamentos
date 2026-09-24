<?php

namespace Tests\Feature\Clinical;

use App\Enums\ClinicalSpecialty;
use App\Enums\CompanyModule;
use App\Enums\CompanyProfile;
use App\Enums\CompanyRole;
use App\Filament\App\Resources\Odontograms\OdontogramResource;
use App\Filament\App\Resources\TreatmentPlans\TreatmentPlanResource;
use App\Services\Client\ClientService;
use App\Services\Clinical\DentalAnamnesisService;
use App\Services\Company\CompanyModuleService;
use App\Services\Company\CompanyProvisioningService;
use App\Support\ClinicalAnamnesisQuestionnaire;
use App\Support\CompanyTerminology;
use App\Support\DentalAnamnesisQuestionnaire;
use Tests\TestCase;

class PsychiatristProfileTest extends TestCase
{
    public function test_psychiatrist_profile_defaults_to_chart_modules(): void
    {
        $this->assertSame('Psiquiatra', CompanyProfile::Psychiatrist->label());
        $this->assertContains(CompanyModule::Scheduling, CompanyProfile::Psychiatrist->defaultModules());
        $this->assertContains(CompanyModule::ClinicalRecords, CompanyProfile::Psychiatrist->defaultModules());
        $this->assertContains(CompanyModule::WhatsApp, CompanyProfile::Psychiatrist->defaultModules());
        $this->assertContains(CompanyModule::Finance, CompanyProfile::Psychiatrist->defaultModules());
        $this->assertNotContains(CompanyModule::Sales, CompanyProfile::Psychiatrist->defaultModules());
        $this->assertNotContains(CompanyModule::Stock, CompanyProfile::Psychiatrist->defaultModules());
        $this->assertNotContains(CompanyModule::Orders, CompanyProfile::Psychiatrist->defaultModules());
        $this->assertArrayHasKey(CompanyProfile::Psychiatrist->value, CompanyProfile::options());
    }

    public function test_provisioning_psychiatrist_restricts_chart_and_uses_psychiatrist_labels(): void
    {
        $result = app(CompanyProvisioningService::class)->provision([
            'name' => 'Consultório Dra. Lima',
            'slug' => 'consultorio-dra-lima',
            'business_profile' => CompanyProfile::Psychiatrist,
            'admin_name' => 'Dra. Lima',
            'admin_email' => 'lima@psiquiatra.test',
            'admin_password' => 'Password123!',
        ]);

        $company = $result['company'];
        $modules = app(CompanyModuleService::class);

        $this->assertSame(CompanyProfile::Psychiatrist, $company->business_profile);
        $this->assertTrue($company->isPsychiatrist());
        $this->assertTrue($company->usesClinicalChart());
        $this->assertTrue($modules->hasModule($company, CompanyModule::ClinicalRecords));
        $this->assertTrue($modules->hasModule($company, CompanyModule::Scheduling));
        $this->assertTrue($modules->hasModule($company, CompanyModule::WhatsApp));
        $this->assertTrue($modules->hasModule($company, CompanyModule::Finance));
        $this->assertSame('related', $company->dentalClinicSetting?->professional_record_scope);
        $this->assertSame('Paciente', CompanyTerminology::client($company));
        $this->assertSame('Psiquiatra', CompanyTerminology::professional($company));
        $this->assertSame('Psiquiatra', CompanyRole::Dentist->label($company));
        $this->assertSame(ClinicalSpecialty::Psychiatry, ClinicalAnamnesisQuestionnaire::resolve($company));
    }

    public function test_psychiatry_anamnesis_hides_odontogram(): void
    {
        $result = app(CompanyProvisioningService::class)->provision([
            'name' => 'Consultório Dr. Nunes',
            'slug' => 'consultorio-dr-nunes',
            'business_profile' => CompanyProfile::Psychiatrist,
            'admin_name' => 'Dr. Nunes',
            'admin_email' => 'nunes@psiquiatra.test',
            'admin_password' => 'Password123!',
        ]);

        $company = $result['company'];
        $admin = $result['user'];
        $patient = app(ClientService::class)->create($company, [
            'name' => 'Paciente Psiquiatria',
            'phone' => '34988881111',
        ]);

        $anamnesis = app(DentalAnamnesisService::class)->createDraft($company, $patient, $admin);
        $keys = collect($anamnesis->questionnaire_snapshot)->pluck('key')->all();

        $this->assertContains('psychiatric_history', $keys);
        $this->assertContains('risk_self_harm', $keys);
        $this->assertContains('substance_use', $keys);
        $this->assertNotContains('oral_hygiene', $keys);
        $this->assertNotContains('bruxism', $keys);
        $this->assertNotSame(
            collect(DentalAnamnesisQuestionnaire::questions())->pluck('key')->all(),
            $keys,
        );

        $this->authenticateForAppTenant($admin, $company);

        $this->assertFalse(OdontogramResource::canViewAny());
        $this->assertFalse(OdontogramResource::shouldRegisterNavigation());
        $this->assertFalse(TreatmentPlanResource::canViewAny());
    }
}
