<?php

namespace Tests\Feature\Clinical;

use App\Enums\CompanyProfile;
use App\Models\ClinicalAttachment;
use App\Services\Client\ClientService;
use App\Services\Clinical\ClinicalStorageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class S3ClinicalStorageTest extends TestCase
{
    public function test_it_stores_patient_photos_under_the_company_and_patient_prefix(): void
    {
        Storage::fake('s3');
        config(['filesystems.clinical_disk' => 's3']);
        $company = $this->createCompany(['business_profile' => CompanyProfile::DentalClinic]);
        $patient = app(ClientService::class)->create($company, ['name' => 'Paciente', 'phone' => '34999990001']);
        $file = UploadedFile::fake()->image('foto.jpg');

        $path = app(ClinicalStorageService::class)->store($company, $patient, $file, 'photo');

        $this->assertNotFalse($path);
        $this->assertStringStartsWith("agendaqui/{$company->slug}/pacientes/P".str_pad((string) $patient->getKey(), 6, '0', STR_PAD_LEFT).'/fotos/', $path);
        Storage::disk('s3')->assertExists($path);
    }

    public function test_it_separates_documents_from_photos_and_from_other_companies(): void
    {
        Storage::fake('s3');
        config(['filesystems.clinical_disk' => 's3']);
        $company = $this->createCompany(['business_profile' => CompanyProfile::DentalClinic]);
        $otherCompany = $this->createCompany(['business_profile' => CompanyProfile::DentalClinic]);
        $patient = app(ClientService::class)->create($company, ['name' => 'Paciente', 'phone' => '34999990002']);
        $otherPatient = app(ClientService::class)->create($otherCompany, ['name' => 'Outro paciente', 'phone' => '34999990003']);
        $service = app(ClinicalStorageService::class);

        $documentPath = $service->store($company, $patient, UploadedFile::fake()->create('exame.pdf', 50, 'application/pdf'), 'exam');
        $otherPath = $service->store($otherCompany, $otherPatient, UploadedFile::fake()->image('foto.png'), 'photo');

        $this->assertStringContainsString('/documentos/', (string) $documentPath);
        $this->assertStringContainsString("agendaqui/{$company->slug}/", (string) $documentPath);
        $this->assertStringContainsString("agendaqui/{$otherCompany->slug}/", (string) $otherPath);
        $this->assertStringNotContainsString($company->slug, (string) $otherPath);
    }

    public function test_it_generates_a_standard_s3_path_when_migrating_local_attachment(): void
    {
        $company = $this->createCompany(['business_profile' => CompanyProfile::DentalClinic]);
        $patient = app(ClientService::class)->create($company, ['name' => 'Paciente', 'phone' => '34999990004']);
        $attachment = new ClinicalAttachment([
            'type' => 'photo',
            'path' => 'clinical/1/1/arquivo-antigo.jpg',
            'original_name' => 'foto original.jpg',
        ]);

        $path = app(ClinicalStorageService::class)->pathForAttachment($company, $patient, $attachment);

        $this->assertStringContainsString("agendaqui/{$company->slug}/pacientes/P", $path);
        $this->assertStringContainsString('/fotos/arquivo-antigo.jpg', $path);
    }
}
