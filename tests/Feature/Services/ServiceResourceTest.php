<?php

namespace Tests\Feature\Services;

use App\Enums\CompanyRole;
use App\Filament\App\Resources\Services\Pages\ListServices;
use App\Filament\App\Resources\Services\ServiceResource;
use App\Models\Professional;
use App\Models\ProfessionalWorkingHour;
use App\Models\Service;
use App\Policies\ServicePolicy;
use App\Services\PublicBooking\OnlineBookingCatalogService;
use App\Services\Service\ServiceCatalogService;
use App\Services\Service\ServiceProfessionalSyncService;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ServiceResourceTest extends TestCase
{
    public function test_company_admin_can_list_services(): void
    {
        $company = $this->createCompany(['slug' => 'estudio-ana']);
        $admin = $this->createCompanyUser($company);
        Service::factory()->forCompany($company)->create(['name' => 'Design Simples']);

        $this->authenticateForAppTenant($admin, $company);

        Livewire::test(ListServices::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords(Service::where('company_id', $company->id)->get());
    }

    public function test_manager_can_list_services(): void
    {
        $company = $this->createCompany(['slug' => 'estudio-ana']);
        $manager = $this->createCompanyUser($company, role: CompanyRole::Manager);

        $this->authenticateForAppTenant($manager, $company);

        Livewire::test(ListServices::class)->assertSuccessful();
    }

    public function test_employee_cannot_access_service_resource(): void
    {
        $company = $this->createCompany(['slug' => 'estudio-ana']);
        $employee = $this->createCompanyUser($company, role: CompanyRole::Employee);

        $this->authenticateForAppTenant($employee, $company);

        Livewire::test(ListServices::class)->assertForbidden();
    }

    public function test_service_receives_current_company_id(): void
    {
        $company = $this->createCompany();

        $service = app(ServiceCatalogService::class)->create($company, [
            'name' => 'Design Simples',
            'slug' => 'design-simples',
            'price' => 35,
            'duration_minutes' => 30,
            'sort_order' => 1,
            'is_bookable' => true,
            'is_online_booking_enabled' => true,
            'is_active' => true,
        ]);

        $this->assertSame($company->id, $service->company_id);
    }

    public function test_service_linked_to_professional_appears_in_online_booking_catalog(): void
    {
        $company = $this->createCompany();
        $professional = Professional::factory()->forCompany($company)->create([
            'is_active' => true,
            'is_bookable' => true,
        ]);
        ProfessionalWorkingHour::factory()->forCompany($company)->create([
            'professional_id' => $professional->getKey(),
        ]);

        $service = app(ServiceCatalogService::class)->create($company, [
            'name' => 'Design Simples',
            'slug' => 'design-simples',
            'price' => 35,
            'duration_minutes' => 30,
            'sort_order' => 1,
            'is_bookable' => true,
            'is_online_booking_enabled' => true,
            'is_active' => true,
        ]);

        app(ServiceProfessionalSyncService::class)->sync($company, $service, [$professional->getKey()]);

        $services = app(OnlineBookingCatalogService::class)->getEligibleServices($company);

        $this->assertTrue($services->contains('id', $service->getKey()));
    }

    public function test_service_professional_sync_preserves_existing_custom_pivot_values(): void
    {
        $company = $this->createCompany();
        $professional = Professional::factory()->forCompany($company)->create();
        $service = Service::factory()->forCompany($company)->create();

        $service->professionals()->attach($professional->getKey(), [
            'company_id' => $company->getKey(),
            'custom_price' => 75,
            'custom_duration_minutes' => 45,
            'is_active' => true,
        ]);

        app(ServiceProfessionalSyncService::class)->sync($company, $service, [$professional->getKey()]);

        $link = $service->professionals()->whereKey($professional->getKey())->firstOrFail()->pivot;

        $this->assertSame('75.00', (string) $link->custom_price);
        $this->assertSame(45, (int) $link->custom_duration_minutes);
    }

    public function test_service_from_other_company_is_not_listed(): void
    {
        $company = $this->createCompany(['slug' => 'estudio-ana']);
        $otherCompany = $this->createCompany(['slug' => 'outra']);
        $admin = $this->createCompanyUser($company);

        $visible = Service::factory()->forCompany($company)->create(['name' => 'Visivel']);
        $hidden = Service::factory()->forCompany($otherCompany)->create(['name' => 'Oculto']);

        $this->authenticateForAppTenant($admin, $company);

        $records = ServiceResource::getEloquentQuery()->get();

        $this->assertTrue($records->contains('id', $visible->id));
        $this->assertFalse($records->contains('id', $hidden->id));
    }

    public function test_service_from_other_company_cannot_be_edited(): void
    {
        $company = $this->createCompany(['slug' => 'estudio-ana']);
        $otherCompany = $this->createCompany(['slug' => 'outra']);
        $admin = $this->createCompanyUser($company);
        $foreign = Service::factory()->forCompany($otherCompany)->create();

        $this->actingAs($admin)
            ->get(ServiceResource::getUrl('edit', [
                'tenant' => $company,
                'record' => $foreign,
            ], panel: 'app', tenant: $company))
            ->assertNotFound();
    }

    public function test_slug_is_unique_within_company(): void
    {
        $company = $this->createCompany();

        Service::factory()->forCompany($company)->create(['slug' => 'design-simples']);

        $this->expectException(ValidationException::class);

        app(ServiceCatalogService::class)->create($company, [
            'name' => 'Outro Serviço',
            'slug' => 'design-simples',
            'price' => 50,
            'duration_minutes' => 45,
            'sort_order' => 2,
            'is_bookable' => true,
            'is_online_booking_enabled' => true,
            'is_active' => true,
        ]);
    }

    public function test_same_slug_can_exist_in_different_companies(): void
    {
        $companyA = $this->createCompany();
        $companyB = $this->createCompany();

        app(ServiceCatalogService::class)->create($companyA, [
            'name' => 'Design Simples',
            'slug' => 'design-simples',
            'price' => 35,
            'duration_minutes' => 30,
            'sort_order' => 1,
            'is_bookable' => true,
            'is_online_booking_enabled' => true,
            'is_active' => true,
        ]);

        $serviceB = app(ServiceCatalogService::class)->create($companyB, [
            'name' => 'Design Simples',
            'slug' => 'design-simples',
            'price' => 40,
            'duration_minutes' => 30,
            'sort_order' => 1,
            'is_bookable' => true,
            'is_online_booking_enabled' => true,
            'is_active' => true,
        ]);

        $this->assertSame('design-simples', $serviceB->slug);
    }

    public function test_negative_price_is_prevented(): void
    {
        $company = $this->createCompany();

        $this->expectException(ValidationException::class);

        app(ServiceCatalogService::class)->create($company, [
            'name' => 'Serviço Inválido',
            'slug' => 'servico-invalido',
            'price' => -10,
            'duration_minutes' => 30,
            'sort_order' => 1,
            'is_bookable' => true,
            'is_online_booking_enabled' => true,
            'is_active' => true,
        ]);
    }

    public function test_zero_duration_is_prevented(): void
    {
        $company = $this->createCompany();

        $this->expectException(ValidationException::class);

        app(ServiceCatalogService::class)->create($company, [
            'name' => 'Serviço Inválido',
            'slug' => 'servico-invalido',
            'price' => 50,
            'duration_minutes' => 0,
            'sort_order' => 1,
            'is_bookable' => true,
            'is_online_booking_enabled' => true,
            'is_active' => true,
        ]);
    }

    public function test_service_can_be_deactivated(): void
    {
        $company = $this->createCompany();
        $service = Service::factory()->forCompany($company)->create(['is_active' => true]);

        app(ServiceCatalogService::class)->changeStatus($company, $service, false);

        $this->assertFalse($service->fresh()->is_active);
    }

    public function test_service_resource_has_no_delete_action(): void
    {
        $this->assertFalse((new ServicePolicy)->delete(
            $this->createCompanyUser($this->createCompany()),
            Service::factory()->make(),
        ));
    }

    public function test_online_booking_requires_bookable_service(): void
    {
        $company = $this->createCompany();

        $this->expectException(ValidationException::class);

        app(ServiceCatalogService::class)->create($company, [
            'name' => 'Serviço Online',
            'slug' => 'servico-online',
            'price' => 50,
            'duration_minutes' => 30,
            'sort_order' => 1,
            'is_bookable' => false,
            'is_online_booking_enabled' => true,
            'is_active' => true,
        ]);
    }

    public function test_service_from_other_company_cannot_be_updated(): void
    {
        $company = $this->createCompany();
        $otherCompany = $this->createCompany();
        $foreign = Service::factory()->forCompany($otherCompany)->create(['name' => 'Original']);

        $this->expectException(HttpException::class);

        app(ServiceCatalogService::class)->update($company, $foreign, [
            'name' => 'Alterado',
            'slug' => 'alterado',
            'price' => 50,
            'duration_minutes' => 30,
            'sort_order' => 1,
            'is_bookable' => true,
            'is_online_booking_enabled' => true,
            'is_active' => true,
        ]);
    }
}
