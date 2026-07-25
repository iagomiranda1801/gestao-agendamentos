<?php

namespace Tests\Feature\Clients;

use App\Enums\CompanyRole;
use App\Filament\App\Resources\Clients\ClientResource;
use App\Filament\App\Resources\Clients\Pages\ListClients;
use App\Models\Client;
use App\Policies\ClientPolicy;
use App\Services\Client\ClientService;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ClientResourceTest extends TestCase
{
    public function test_company_admin_can_list_clients(): void
    {
        $company = $this->createCompany(['slug' => 'estudio-ana']);
        $admin = $this->createCompanyUser($company);
        Client::factory()->forCompany($company)->create(['name' => 'Maria']);

        $this->authenticateForAppTenant($admin, $company);

        Livewire::test(ListClients::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords(Client::where('company_id', $company->id)->get());
    }

    public function test_manager_can_list_clients(): void
    {
        $company = $this->createCompany(['slug' => 'estudio-ana']);
        $manager = $this->createCompanyUser($company, role: CompanyRole::Manager);

        $this->authenticateForAppTenant($manager, $company);

        Livewire::test(ListClients::class)->assertSuccessful();
    }

    public function test_employee_cannot_access_client_resource(): void
    {
        $company = $this->createCompany(['slug' => 'estudio-ana']);
        $employee = $this->createCompanyUser($company, role: CompanyRole::Employee);

        $this->authenticateForAppTenant($employee, $company);

        Livewire::test(ListClients::class)->assertForbidden();
    }

    public function test_created_client_receives_current_company_id(): void
    {
        $company = $this->createCompany(['slug' => 'estudio-ana']);

        $client = app(ClientService::class)->create($company, [
            'name' => 'Maria',
            'phone' => '(34) 99999-0001',
            'is_active' => true,
        ]);

        $this->assertSame($company->id, $client->company_id);
    }

    public function test_manipulated_company_id_is_ignored_on_create(): void
    {
        $company = $this->createCompany(['slug' => 'estudio-ana']);
        $otherCompany = $this->createCompany(['slug' => 'outra']);

        $client = app(ClientService::class)->create($company, [
            'name' => 'Maria',
            'phone' => '(34) 99999-0001',
            'company_id' => $otherCompany->id,
            'is_active' => true,
        ]);

        $this->assertSame($company->id, $client->company_id);
    }

    public function test_client_from_other_company_is_not_listed(): void
    {
        $company = $this->createCompany(['slug' => 'estudio-ana']);
        $otherCompany = $this->createCompany(['slug' => 'outra']);
        $admin = $this->createCompanyUser($company);

        $visible = Client::factory()->forCompany($company)->create(['name' => 'Visivel']);
        $hidden = Client::factory()->forCompany($otherCompany)->create(['name' => 'Oculto']);

        $this->authenticateForAppTenant($admin, $company);

        Livewire::test(ListClients::class)->assertSuccessful();

        $records = ClientResource::getEloquentQuery()->get();

        $this->assertTrue($records->contains('id', $visible->id));
        $this->assertFalse($records->contains('id', $hidden->id));
    }

    public function test_client_from_other_company_cannot_be_opened_for_edit(): void
    {
        $company = $this->createCompany(['slug' => 'estudio-ana']);
        $otherCompany = $this->createCompany(['slug' => 'outra']);
        $admin = $this->createCompanyUser($company);
        $foreignClient = Client::factory()->forCompany($otherCompany)->create();

        $this->actingAs($admin)
            ->get(ClientResource::getUrl('edit', [
                'tenant' => $company,
                'record' => $foreignClient,
            ], panel: 'app', tenant: $company))
            ->assertNotFound();
    }

    public function test_client_from_other_company_cannot_be_updated(): void
    {
        $company = $this->createCompany(['slug' => 'estudio-ana']);
        $otherCompany = $this->createCompany(['slug' => 'outra']);
        $foreignClient = Client::factory()->forCompany($otherCompany)->create(['name' => 'Original']);

        $this->expectException(HttpException::class);

        app(ClientService::class)->update($company, $foreignClient, [
            'name' => 'Alterado',
            'phone' => '(34) 99999-0001',
        ]);
    }

    public function test_phone_is_normalized_on_create(): void
    {
        $company = $this->createCompany();

        $client = app(ClientService::class)->create($company, [
            'name' => 'Maria',
            'phone' => '(34) 99999-0001',
            'is_active' => true,
        ]);

        $this->assertSame('34999990001', $client->phone_normalized);
    }

    public function test_duplicate_document_in_same_company_is_prevented(): void
    {
        $company = $this->createCompany();

        Client::factory()->forCompany($company)->create(['document' => '12345678900']);

        $this->expectException(ValidationException::class);

        app(ClientService::class)->create($company, [
            'name' => 'Outro Cliente',
            'phone' => '(34) 99999-0002',
            'document' => '12345678900',
            'is_active' => true,
        ]);
    }

    public function test_same_document_can_exist_in_different_companies(): void
    {
        $companyA = $this->createCompany();
        $companyB = $this->createCompany();

        app(ClientService::class)->create($companyA, [
            'name' => 'Cliente A',
            'phone' => '(34) 99999-0001',
            'document' => '12345678900',
            'is_active' => true,
        ]);

        $clientB = app(ClientService::class)->create($companyB, [
            'name' => 'Cliente B',
            'phone' => '(34) 99999-0002',
            'document' => '12345678900',
            'is_active' => true,
        ]);

        $this->assertSame('12345678900', $clientB->document);
    }

    public function test_client_can_be_deactivated(): void
    {
        $company = $this->createCompany();
        $client = Client::factory()->forCompany($company)->create(['is_active' => true]);

        app(ClientService::class)->changeStatus($company, $client, false);

        $this->assertFalse($client->fresh()->is_active);
    }

    public function test_client_resource_has_no_delete_action(): void
    {
        $this->assertFalse((new ClientPolicy)->delete(
            $this->createCompanyUser($this->createCompany()),
            Client::factory()->make(),
        ));
    }

    public function test_inactive_company_prevents_client_resource_access(): void
    {
        $company = $this->createCompany(['slug' => 'inativa', 'is_active' => false]);
        $admin = $this->createCompanyUser($company);

        $this->actingAs($admin)
            ->get('/app/empresa/'.$company->slug.'/clientes')
            ->assertForbidden();
    }

    public function test_inactive_membership_prevents_client_resource_access(): void
    {
        $company = $this->createCompany(['slug' => 'estudio-ana']);
        $admin = $this->createCompanyUser($company, isActive: false);

        $this->actingAs($admin)
            ->get('/app/empresa/'.$company->slug.'/clientes')
            ->assertForbidden();
    }

    public function test_search_does_not_return_clients_from_other_company(): void
    {
        $company = $this->createCompany(['slug' => 'estudio-ana']);
        $otherCompany = $this->createCompany(['slug' => 'outra']);
        $admin = $this->createCompanyUser($company);

        Client::factory()->forCompany($company)->create(['name' => 'Maria Visivel']);
        Client::factory()->forCompany($otherCompany)->create(['name' => 'Maria Oculta']);

        $this->authenticateForAppTenant($admin, $company);

        Livewire::test(ListClients::class)
            ->searchTable('Maria')
            ->assertSuccessful()
            ->assertCanSeeTableRecords(
                Client::query()->where('company_id', $company->id)->where('name', 'Maria Visivel')->get()
            );

        $this->assertFalse(
            ClientResource::getEloquentQuery()
                ->where('company_id', $otherCompany->id)
                ->where('name', 'like', '%Maria%')
                ->exists()
        );
    }
}
