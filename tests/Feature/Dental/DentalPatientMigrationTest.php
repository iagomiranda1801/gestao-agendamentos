<?php

namespace Tests\Feature\Dental;

use App\Enums\CompanyProfile;
use App\Models\Client;
use App\Services\Client\DentalPatientMigrationService;
use Tests\TestCase;

class DentalPatientMigrationTest extends TestCase
{
    public function test_it_prepares_existing_clients_idempotently(): void
    {
        $company = $this->createCompany(['business_profile' => CompanyProfile::DentalClinic]);
        $first = Client::factory()->forCompany($company)->create();
        $second = Client::factory()->forCompany($company)->create();
        $service = app(DentalPatientMigrationService::class);

        $firstRun = $service->prepareExistingClients($company);
        $secondRun = $service->prepareExistingClients($company);

        $this->assertSame(['analyzed' => 2, 'converted' => 2, 'already_prepared' => 0], $firstRun);
        $this->assertSame(['analyzed' => 2, 'converted' => 0, 'already_prepared' => 2], $secondRun);
        $this->assertDatabaseCount('dental_patient_profiles', 2);
        $this->assertDatabaseHas('dental_patient_profiles', ['client_id' => $first->getKey()]);
        $this->assertDatabaseHas('dental_patient_profiles', ['client_id' => $second->getKey()]);
    }

    public function test_it_never_prepares_clients_from_another_company(): void
    {
        $company = $this->createCompany(['business_profile' => CompanyProfile::DentalClinic]);
        $other = $this->createCompany(['business_profile' => CompanyProfile::DentalClinic]);
        Client::factory()->forCompany($company)->create();
        $foreign = Client::factory()->forCompany($other)->create();

        app(DentalPatientMigrationService::class)->prepareExistingClients($company);

        $this->assertDatabaseMissing('dental_patient_profiles', ['client_id' => $foreign->getKey()]);
    }
}
