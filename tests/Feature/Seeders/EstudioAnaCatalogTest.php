<?php

namespace Tests\Feature\Seeders;

use App\Models\Company;
use App\Models\Professional;
use App\Models\Service;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\EstudioAnaCatalogSeeder;
use Database\Seeders\MeasurementUnitSeeder;
use Database\Seeders\TenantFoundationSeeder;
use Tests\TestCase;

class EstudioAnaCatalogTest extends TestCase
{
    protected function seedEstudioAnaCatalog(): Company
    {
        $this->seed(TenantFoundationSeeder::class);
        $this->seed(MeasurementUnitSeeder::class);
        $this->seed(DemoDataSeeder::class);
        $this->seed(EstudioAnaCatalogSeeder::class);

        return Company::query()->where('slug', 'estudio-ana')->firstOrFail();
    }

    /**
     * @return array<string, float>
     */
    protected function expectedCosts(): array
    {
        return [
            'Combo Completo' => 12.99,
            'Design + Henna' => 11.72,
            'Design Henna + Spa Labial' => 10.74,
            'Design Simples' => 10.02,
            'Design Simples + Spa Labial' => 11.11,
            'Henna Avulsa' => 2.01,
            'Spa dos Lábios' => 1.20,
            'Hidra Gloss' => 21.41,
        ];
    }

    public function test_estudio_ana_services_have_expected_material_costs(): void
    {
        $company = $this->seedEstudioAnaCatalog();

        foreach ($this->expectedCosts() as $serviceName => $expectedCost) {
            $service = Service::query()
                ->where('company_id', $company->getKey())
                ->where('name', $serviceName)
                ->firstOrFail();

            $service->load('consumptions.product');

            $actual = round((float) $service->getEstimatedMaterialCost(), 2);

            $this->assertSame(
                $expectedCost,
                $actual,
                "Custo estimado incorreto para {$serviceName}. Esperado: {$expectedCost}, obtido: {$actual}"
            );
        }
    }

    public function test_ana_is_linked_to_all_eight_estudio_ana_services(): void
    {
        $company = $this->seedEstudioAnaCatalog();

        $ana = Professional::query()
            ->where('company_id', $company->getKey())
            ->where('name', 'Ana')
            ->firstOrFail();

        $serviceCount = Service::query()->where('company_id', $company->getKey())->count();
        $linkedCount = $ana->services()->count();

        $this->assertSame(8, $serviceCount);
        $this->assertSame(8, $linkedCount);
    }
}
