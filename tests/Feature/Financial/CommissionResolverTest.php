<?php

namespace Tests\Feature\Financial;

use App\Enums\CommissionType;
use App\Models\Company;
use App\Models\Professional;
use App\Models\Service;
use App\Services\Financial\CommissionResolver;
use App\Services\Financial\CompanyFinancialSettingService;
use App\Services\Service\ServiceProfessionalAssignmentService;
use Database\Factories\ProfessionalServiceFactory;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesSchedulingFixtures;
use Tests\TestCase;

class CommissionResolverTest extends TestCase
{
    use CreatesSchedulingFixtures;

    protected Company $company;

    protected Professional $professional;

    protected Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = $this->createSchedulingCompany();
        $this->professional = Professional::factory()->forCompany($this->company)->active()->create();
        $this->service = Service::factory()->forCompany($this->company)->active()->create();

        ProfessionalServiceFactory::new()
            ->forCompany($this->company)
            ->create([
                'professional_id' => $this->professional->getKey(),
                'service_id' => $this->service->getKey(),
            ]);

        app(CompanyFinancialSettingService::class)->update($this->company, [
            'default_commission_type' => CommissionType::Percentage->value,
            'default_commission_value' => '15',
            'materials_reserve_percentage' => '10',
            'business_reserve_percentage' => '10',
        ]);
    }

    public function test_uses_default_commission(): void
    {
        $result = app(CommissionResolver::class)->resolve(
            $this->company,
            $this->professional,
            $this->service,
            '100.00',
        );

        $this->assertSame(CommissionType::Percentage, $result->type);
        $this->assertSame('15.0000', $result->configuredValue);
        $this->assertSame('15.00', $result->calculatedAmount);
        $this->assertSame('default', $result->source);
    }

    public function test_uses_custom_commission(): void
    {
        app(ServiceProfessionalAssignmentService::class)->update($this->company, $this->service, $this->professional, [
            'commission_type' => CommissionType::Percentage->value,
            'commission_value' => '20',
        ]);

        $result = app(CommissionResolver::class)->resolve(
            $this->company,
            $this->professional,
            $this->service,
            '100.00',
        );

        $this->assertSame('20.0000', $result->configuredValue);
        $this->assertSame('20.00', $result->calculatedAmount);
        $this->assertSame('custom', $result->source);
    }

    public function test_fixed_commission_works(): void
    {
        app(ServiceProfessionalAssignmentService::class)->update($this->company, $this->service, $this->professional, [
            'commission_type' => CommissionType::Fixed->value,
            'commission_value' => '25',
        ]);

        $result = app(CommissionResolver::class)->resolve(
            $this->company,
            $this->professional,
            $this->service,
            '100.00',
        );

        $this->assertSame(CommissionType::Fixed, $result->type);
        $this->assertSame('25.00', $result->calculatedAmount);
        $this->assertSame('25.0000', $result->equivalentPercentage);
    }

    public function test_none_commission_works(): void
    {
        app(ServiceProfessionalAssignmentService::class)->update($this->company, $this->service, $this->professional, [
            'commission_type' => CommissionType::None->value,
        ]);

        $result = app(CommissionResolver::class)->resolve(
            $this->company,
            $this->professional,
            $this->service,
            '100.00',
        );

        $this->assertSame(CommissionType::None, $result->type);
        $this->assertSame('0.00', $result->calculatedAmount);
    }

    public function test_fixed_commission_greater_than_final_amount_is_rejected(): void
    {
        app(ServiceProfessionalAssignmentService::class)->update($this->company, $this->service, $this->professional, [
            'commission_type' => CommissionType::Fixed->value,
            'commission_value' => '150',
        ]);

        $this->expectException(ValidationException::class);

        app(CommissionResolver::class)->resolve(
            $this->company,
            $this->professional,
            $this->service,
            '100.00',
        );
    }

    public function test_percentage_commission_above_100_is_rejected_on_assignment(): void
    {
        $this->expectException(ValidationException::class);

        app(ServiceProfessionalAssignmentService::class)->update($this->company, $this->service, $this->professional, [
            'commission_type' => CommissionType::Percentage->value,
            'commission_value' => '95',
        ]);
    }

    public function test_cross_company_assignment_is_rejected(): void
    {
        $otherCompany = $this->createSchedulingCompany();
        $otherProfessional = Professional::factory()->forCompany($otherCompany)->active()->create();

        $this->expectException(ValidationException::class);

        app(ServiceProfessionalAssignmentService::class)->attach($this->company, $this->service, [
            'professional_id' => $otherProfessional->getKey(),
        ]);
    }

    public function test_future_setting_changes_do_not_modify_resolved_snapshot_values(): void
    {
        $result = app(CommissionResolver::class)->resolve(
            $this->company,
            $this->professional,
            $this->service,
            '100.00',
        );

        app(CompanyFinancialSettingService::class)->update($this->company, [
            'default_commission_value' => '50',
        ]);

        $this->assertSame('15.00', $result->calculatedAmount);
        $this->assertSame('15.0000', $result->configuredValue);
    }
}
