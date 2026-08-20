<?php

namespace Tests\Feature\Dental;

use App\Enums\CompanyModule;
use App\Enums\CompanyProfile;
use App\Enums\CompanyRole;
use App\Filament\App\Resources\Anamneses\Pages\CreateAnamnesis;
use App\Filament\App\Resources\Clients\ClientResource;
use App\Filament\App\Resources\ClinicalAttachments\Pages\CreateClinicalAttachment;
use App\Filament\App\Resources\ClinicalEntries\Pages\CreateClinicalEntry;
use App\Filament\App\Resources\ClinicalEntries\Pages\ListClinicalEntries;
use App\Filament\App\Resources\Odontograms\Pages\CreateOdontogram;
use App\Filament\App\Resources\TeamMembers\Pages\ListTeamMembers;
use App\Filament\App\Resources\TreatmentPlans\Pages\CreateTreatmentPlan;
use App\Models\Client;
use App\Models\ClinicalAuditEvent;
use App\Models\Company;
use App\Models\DentalClinicalEntryAddendum;
use App\Models\PatientClinicalAlert;
use App\Models\Professional;
use App\Models\User;
use App\Policies\ClientPolicy;
use App\Services\Client\ClientService;
use App\Services\Clinical\DentalAnamnesisService;
use App\Services\Clinical\DentalClinicalEntryService;
use App\Services\Clinical\DentalOdontogramService;
use App\Services\Clinical\DentalTreatmentPlanService;
use App\Services\Company\CompanyTeamService;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class DentalClinicModuleTest extends TestCase
{
    public function test_dental_profile_provisions_clinical_module_by_default(): void
    {
        $this->assertContains(CompanyModule::ClinicalRecords, CompanyProfile::DentalClinic->defaultModules());
    }

    public function test_dental_client_receives_patient_profile_and_record_number(): void
    {
        $company = $this->dentalCompany();
        $patient = app(ClientService::class)->create($company, [
            'name' => 'Maria Paciente', 'phone' => '(34) 99999-0001', 'birth_date' => '1990-01-10',
            'dental_profile' => ['social_name' => 'Maria', 'city' => 'Uberlândia', 'state' => 'MG'],
            'guardians' => [['name' => 'Responsável', 'is_legal_guardian' => true]],
            'insurances' => [['provider' => 'Plano Dental', 'card_number' => '123']],
        ]);

        $this->assertSame('P'.str_pad((string) $patient->id, 6, '0', STR_PAD_LEFT), $patient->dentalProfile->record_number);
        $this->assertSame('Maria', $patient->dentalProfile->social_name);
        $this->assertCount(1, $patient->guardians);
        $this->assertCount(1, $patient->insurances);
    }

    public function test_receptionist_can_manage_patients_but_cannot_open_clinical_records(): void
    {
        $company = $this->dentalCompany();
        $receptionist = $this->createCompanyUser($company, role: CompanyRole::Receptionist);
        $this->authenticateForAppTenant($receptionist, $company);
        $patient = Client::factory()->forCompany($company)->create();

        $this->assertTrue((new ClientPolicy)->view($receptionist, $patient));
        $this->expectException(HttpException::class);
        app(DentalAnamnesisService::class)->createDraft($company, $patient, $receptionist);
    }

    public function test_completed_anamnesis_is_versioned_and_generates_alerts_and_audit(): void
    {
        [$company, $dentist, $professional, $patient] = $this->clinicalSetup();
        $service = app(DentalAnamnesisService::class);
        $anamnesis = $service->createDraft($company, $patient, $dentist, [
            'allergies' => ['answer' => 'yes', 'details' => 'Dipirona'],
            'diabetes' => ['answer' => 'no'],
        ]);
        $service->complete($company, $anamnesis, $professional, $dentist);

        $this->assertSame('completed', $anamnesis->fresh()->status);
        $this->assertTrue(PatientClinicalAlert::query()->where('client_id', $patient->id)->where('type', 'allergy')->where('is_active', true)->exists());
        $this->assertTrue(ClinicalAuditEvent::query()->where('action', 'anamnesis.completed')->exists());

        $second = $service->createDraft($company, $patient, $dentist);
        $service->complete($company, $second, $professional, $dentist);
        $this->assertSame(2, $second->version);
        $this->assertSame('superseded', $anamnesis->fresh()->status);
    }

    public function test_finalized_clinical_entry_is_immutable_and_accepts_addendum(): void
    {
        [$company, $dentist, $professional, $patient] = $this->clinicalSetup();
        $service = app(DentalClinicalEntryService::class);
        $entry = $service->createDraft($company, $patient, $professional, $dentist, [
            'clinical_assessment' => 'Cárie no elemento 16', 'procedure_performed' => 'Restauração', 'teeth' => ['16'],
        ]);
        $service->finalize($company, $entry, $dentist);
        $addendum = $service->addAddendum($company, $entry, $dentist, 'Complemento necessário', 'Paciente orientado sobre retorno.');

        $this->assertInstanceOf(DentalClinicalEntryAddendum::class, $addendum);
        $this->assertSame('finalized', $entry->fresh()->status);

        $this->expectException(ValidationException::class);
        $service->updateDraft($company, $entry, $dentist, ['clinical_assessment' => 'Sobrescrito']);
    }

    public function test_treatment_plan_approval_preserves_totals(): void
    {
        [$company, $dentist, $professional, $patient] = $this->clinicalSetup();
        $service = app(DentalTreatmentPlanService::class);
        $plan = $service->create($company, $patient, $professional, $dentist, [
            'title' => 'Plano inicial', 'discount_amount' => 20, 'is_primary' => true,
        ], [
            ['description' => 'Restauração', 'tooth' => '16', 'quantity' => 2, 'unit_price' => 100, 'discount_amount' => 10],
        ]);
        $service->approve($company, $plan, $dentist);

        $this->assertSame('190.00', $plan->fresh()->subtotal);
        $this->assertSame('170.00', $plan->fresh()->total_amount);
        $this->assertNotNull($plan->fresh()->approved_at);

        $this->expectException(HttpException::class);
        $service->update($company, $plan, $dentist, ['title' => 'Alterado'], []);
    }

    public function test_odontogram_uses_fdi_and_finalized_version_is_immutable(): void
    {
        [$company, $dentist, $professional, $patient] = $this->clinicalSetup();
        $service = app(DentalOdontogramService::class);
        $odontogram = $service->createDraft($company, $patient, $professional, $dentist, [[
            'tooth' => '11', 'surfaces' => ['V'], 'condition' => 'caries', 'stage' => 'existing',
        ]]);
        $service->finalize($company, $odontogram, $dentist);

        $this->assertSame('finalized', $odontogram->fresh()->status);
        $this->expectException(ValidationException::class);
        $service->updateDraft($company, $odontogram, $dentist, []);
    }

    public function test_company_admin_can_create_receptionist_with_default_permissions(): void
    {
        $company = $this->dentalCompany();
        $receptionist = app(CompanyTeamService::class)->create($company, [
            'name' => 'Secretária', 'email' => 'secretaria@example.test', 'password' => 'password123',
            'role' => CompanyRole::Receptionist->value, 'membership_active' => true, 'use_role_defaults' => true,
        ]);

        $this->assertTrue($receptionist->hasActiveRoleInCompany($company, CompanyRole::Receptionist));
        $this->assertNull($company->users()->whereKey($receptionist)->first()->pivot->permissions);
    }

    public function test_dentist_can_open_clinical_menu_and_receptionist_cannot(): void
    {
        [$company, $dentist] = $this->clinicalSetup();
        $this->authenticateForAppTenant($dentist, $company);
        Livewire::test(ListClinicalEntries::class)->assertSuccessful();

        $receptionist = $this->createCompanyUser($company, role: CompanyRole::Receptionist);
        $this->authenticateForAppTenant($receptionist, $company);
        Livewire::test(ListClinicalEntries::class)->assertForbidden();
    }

    public function test_dental_navigation_uses_patient_language_and_admin_can_manage_team(): void
    {
        $company = $this->dentalCompany();
        $admin = $this->createCompanyUser($company, role: CompanyRole::CompanyAdmin);
        $this->authenticateForAppTenant($admin, $company);

        $this->assertSame('Pacientes', ClientResource::getNavigationLabel());
        Livewire::test(ListTeamMembers::class)->assertSuccessful();
    }

    public function test_dentist_can_open_all_clinical_creation_forms(): void
    {
        [$company, $dentist] = $this->clinicalSetup();
        $this->authenticateForAppTenant($dentist, $company);

        Livewire::test(CreateAnamnesis::class)->assertSuccessful();
        Livewire::test(CreateClinicalEntry::class)->assertSuccessful();
        Livewire::test(CreateTreatmentPlan::class)->assertSuccessful();
        Livewire::test(CreateOdontogram::class)->assertSuccessful();
        Livewire::test(CreateClinicalAttachment::class)->assertSuccessful();
    }

    public function test_patient_record_and_printable_treatment_plan_are_access_controlled(): void
    {
        [$company, $dentist, $professional, $patient] = $this->clinicalSetup();
        $this->authenticateForAppTenant($dentist, $company);
        $plan = app(DentalTreatmentPlanService::class)->create($company, $patient, $professional, $dentist, [
            'title' => 'Plano para impressão',
        ], [['description' => 'Profilaxia', 'quantity' => 1, 'unit_price' => 150]]);

        $this->get(ClientResource::getUrl('view', ['record' => $patient], tenant: $company))->assertSuccessful();
        $this->get(route('dental.treatment-plan.print', ['company' => $company, 'plan' => $plan]))
            ->assertSuccessful()
            ->assertSee('Plano para impressão');
    }

    /** @return array{Company, User, Professional, Client} */
    protected function clinicalSetup(): array
    {
        $company = $this->dentalCompany();
        $dentist = $this->createCompanyUser($company, role: CompanyRole::Dentist);
        $professional = Professional::factory()->forCompany($company)->linkedToUser($dentist)->active()->create(['name' => 'Dra. Ana']);
        $patient = app(ClientService::class)->create($company, ['name' => 'Paciente', 'phone' => '34999990001']);

        return [$company, $dentist, $professional, $patient];
    }

    protected function dentalCompany(): Company
    {
        return $this->createCompany([
            'business_profile' => CompanyProfile::DentalClinic,
            'enabled_modules' => [CompanyModule::Scheduling->value, CompanyModule::ClinicalRecords->value, CompanyModule::Finance->value],
        ]);
    }
}
