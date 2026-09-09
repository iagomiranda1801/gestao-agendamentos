<?php

namespace Tests\Feature\Clinical;

use App\Enums\ClinicalSpecialty;
use App\Enums\CompanyModule;
use App\Enums\CompanyProfile;
use App\Enums\CompanyRole;
use App\Filament\App\Resources\Clients\ClientResource;
use App\Filament\App\Resources\Clients\Pages\ViewPatientRecord;
use App\Filament\App\Resources\Odontograms\OdontogramResource;
use App\Models\Client;
use App\Models\Professional;
use App\Services\Client\ClientService;
use App\Services\Clinical\DentalAnamnesisService;
use App\Services\Clinical\DentalClinicalEntryService;
use App\Support\ClinicalAnamnesisQuestionnaire;
use App\Support\ClinicalAttachmentTypes;
use App\Support\DentalAnamnesisQuestionnaire;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ClinicalSpecialtyChartTest extends TestCase
{
    public function test_clinic_with_clinical_module_opens_patient_record(): void
    {
        [$company, $admin, $patient] = $this->clinicSetup();

        $this->authenticateForAppTenant($admin, $company);

        $this->assertSame('Pacientes', ClientResource::getNavigationLabel());
        $this->assertNotNull($patient->dentalProfile?->record_number);

        Livewire::test(ViewPatientRecord::class, ['record' => $patient->getKey()])
            ->assertSuccessful()
            ->assertSee('Resumo do paciente')
            ->assertSee('Prontuário')
            ->assertDontSee('Novo odontograma');
    }

    public function test_professional_from_another_company_cannot_read_clinical_records(): void
    {
        [$company, $admin, $patient] = $this->clinicSetup();
        $other = $this->createCompany([
            'business_profile' => CompanyProfile::Clinic,
            'enabled_modules' => [CompanyModule::Scheduling->value, CompanyModule::ClinicalRecords->value],
        ]);
        $intruder = $this->createCompanyUser($other, role: CompanyRole::CompanyAdmin);

        $this->expectException(HttpException::class);
        app(DentalAnamnesisService::class)->createDraft($company, $patient, $intruder);
    }

    public function test_nutrition_anamnesis_uses_nutrition_questionnaire_not_dental(): void
    {
        [$company, $admin, $patient] = $this->clinicSetup();
        $nutritionist = $this->createCompanyUser($company, role: CompanyRole::Dentist);
        Professional::factory()->forCompany($company)->linkedToUser($nutritionist)->active()->create([
            'name' => 'Nutri Ana',
            'clinical_specialty' => ClinicalSpecialty::Nutrition,
        ]);

        $anamnesis = app(DentalAnamnesisService::class)->createDraft(
            $company,
            $patient,
            $nutritionist,
            ['food_routine' => 'Café da manhã irregular'],
        );

        $keys = collect($anamnesis->questionnaire_snapshot)->pluck('key')->all();

        $this->assertContains('food_routine', $keys);
        $this->assertContains('goals', $keys);
        $this->assertNotContains('oral_hygiene', $keys);
        $this->assertNotContains('bruxism', $keys);
        $this->assertNotSame(
            collect(DentalAnamnesisQuestionnaire::questions())->pluck('key')->all(),
            $keys,
        );
    }

    public function test_clinic_evolution_does_not_require_teeth(): void
    {
        [$company, $admin, $patient] = $this->clinicSetup();
        $professionalUser = $this->createCompanyUser($company, role: CompanyRole::Dentist);
        $professional = Professional::factory()->forCompany($company)->linkedToUser($professionalUser)->active()->create([
            'clinical_specialty' => ClinicalSpecialty::Psychology,
        ]);

        $entry = app(DentalClinicalEntryService::class)->createDraft(
            $company,
            $patient,
            $professional,
            $professionalUser,
            [
                'chief_complaint' => 'Ansiedade',
                'clinical_assessment' => 'Sessão inicial',
                'procedure_performed' => 'Acolhimento',
            ],
        );

        $this->assertEmpty($entry->teeth);
        $this->assertNull($entry->anesthetic);
        $this->assertSame('draft', $entry->status);
    }

    public function test_meal_plan_is_a_clinical_attachment_type(): void
    {
        $this->assertArrayHasKey('meal_plan', ClinicalAttachmentTypes::options());
        $this->assertSame('Plano alimentar', ClinicalAttachmentTypes::label('meal_plan'));
    }

    public function test_odontogram_is_hidden_for_non_dental_clinic(): void
    {
        [$company, $admin] = $this->clinicSetup();
        $this->authenticateForAppTenant($admin, $company);

        $this->assertFalse(OdontogramResource::canViewAny());
        $this->assertFalse(OdontogramResource::shouldRegisterNavigation());
    }

    public function test_psychology_questionnaire_includes_risk_item(): void
    {
        $keys = collect(ClinicalAnamnesisQuestionnaire::questions(ClinicalSpecialty::Psychology))->pluck('key')->all();

        $this->assertContains('risk_self_harm', $keys);
        $this->assertContains('previous_therapy', $keys);
        $this->assertNotContains('oral_hygiene', $keys);
    }

    /**
     * @return array{0: \App\Models\Company, 1: \App\Models\User, 2: Client}
     */
    protected function clinicSetup(): array
    {
        $company = $this->createCompany([
            'business_profile' => CompanyProfile::Clinic,
            'enabled_modules' => [
                CompanyModule::Scheduling->value,
                CompanyModule::ClinicalRecords->value,
                CompanyModule::Finance->value,
            ],
        ]);
        $admin = $this->createCompanyUser($company, role: CompanyRole::CompanyAdmin);
        $patient = app(ClientService::class)->create($company, [
            'name' => 'Paciente Clínica',
            'phone' => '34988880001',
        ]);

        return [$company, $admin, $patient];
    }
}
