<?php

namespace Tests\Feature\Services;

use App\Models\Professional;
use App\Models\Service;
use App\Services\Service\ServiceProfessionalAssignmentService;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ServiceProfessionalAssignmentTest extends TestCase
{
    public function test_professional_from_same_company_can_be_linked(): void
    {
        $company = $this->createCompany();
        $service = Service::factory()->forCompany($company)->create();
        $professional = Professional::factory()->forCompany($company)->create();

        app(ServiceProfessionalAssignmentService::class)->attach($company, $service, [
            'professional_id' => $professional->getKey(),
        ]);

        $this->assertTrue(
            $service->professionals()->where('professionals.id', $professional->getKey())->exists()
        );
    }

    public function test_professional_from_other_company_cannot_be_linked(): void
    {
        $company = $this->createCompany();
        $otherCompany = $this->createCompany();
        $service = Service::factory()->forCompany($company)->create();
        $professional = Professional::factory()->forCompany($otherCompany)->create();

        $this->expectException(ValidationException::class);

        app(ServiceProfessionalAssignmentService::class)->attach($company, $service, [
            'professional_id' => $professional->getKey(),
        ]);
    }

    public function test_inactive_professional_cannot_be_linked(): void
    {
        $company = $this->createCompany();
        $service = Service::factory()->forCompany($company)->create();
        $professional = Professional::factory()->forCompany($company)->inactive()->create();

        $this->expectException(ValidationException::class);

        app(ServiceProfessionalAssignmentService::class)->attach($company, $service, [
            'professional_id' => $professional->getKey(),
        ]);
    }

    public function test_duplicate_association_is_prevented(): void
    {
        $company = $this->createCompany();
        $service = Service::factory()->forCompany($company)->create();
        $professional = Professional::factory()->forCompany($company)->create();

        app(ServiceProfessionalAssignmentService::class)->attach($company, $service, [
            'professional_id' => $professional->getKey(),
        ]);

        $this->expectException(ValidationException::class);

        app(ServiceProfessionalAssignmentService::class)->attach($company, $service, [
            'professional_id' => $professional->getKey(),
        ]);
    }

    public function test_negative_custom_price_is_prevented(): void
    {
        $company = $this->createCompany();
        $service = Service::factory()->forCompany($company)->create();
        $professional = Professional::factory()->forCompany($company)->create();

        app(ServiceProfessionalAssignmentService::class)->attach($company, $service, [
            'professional_id' => $professional->getKey(),
        ]);

        $this->expectException(ValidationException::class);

        app(ServiceProfessionalAssignmentService::class)->update($company, $service, $professional, [
            'custom_price' => -10,
        ]);
    }

    public function test_zero_custom_duration_is_prevented(): void
    {
        $company = $this->createCompany();
        $service = Service::factory()->forCompany($company)->create();
        $professional = Professional::factory()->forCompany($company)->create();

        app(ServiceProfessionalAssignmentService::class)->attach($company, $service, [
            'professional_id' => $professional->getKey(),
        ]);

        $this->expectException(ValidationException::class);

        app(ServiceProfessionalAssignmentService::class)->update($company, $service, $professional, [
            'custom_duration_minutes' => 0,
        ]);
    }

    public function test_link_can_be_deactivated(): void
    {
        $company = $this->createCompany();
        $service = Service::factory()->forCompany($company)->create();
        $professional = Professional::factory()->forCompany($company)->create();

        app(ServiceProfessionalAssignmentService::class)->attach($company, $service, [
            'professional_id' => $professional->getKey(),
            'is_active' => true,
        ]);

        app(ServiceProfessionalAssignmentService::class)->update($company, $service, $professional, [
            'is_active' => false,
        ]);

        $pivot = $service->professionals()->where('professionals.id', $professional->getKey())->first()->pivot;

        $this->assertFalse((bool) $pivot->is_active);
    }

    public function test_manipulated_ids_do_not_allow_cross_company_linking(): void
    {
        $company = $this->createCompany();
        $otherCompany = $this->createCompany();
        $service = Service::factory()->forCompany($company)->create();
        $professional = Professional::factory()->forCompany($otherCompany)->create();

        $this->expectException(ValidationException::class);

        app(ServiceProfessionalAssignmentService::class)->attach($company, $service, [
            'professional_id' => $professional->getKey(),
        ]);
    }
}
