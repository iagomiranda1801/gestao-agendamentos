<?php

namespace Database\Seeders;

use App\Enums\ProductType;
use App\Models\Company;
use App\Models\MeasurementUnit;
use App\Models\Product;
use App\Models\Professional;
use App\Models\Service;
use App\Services\Service\ServiceCompositionService;
use App\Services\Service\ServiceProfessionalAssignmentService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

class EstudioAnaCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::query()->where('slug', 'estudio-ana')->first();

        if (! $company) {
            return;
        }

        $units = MeasurementUnit::query()
            ->whereIn('code', ['unit', 'use', 'milliliter', 'gram', 'sheet'])
            ->get()
            ->keyBy('code');

        if ($units->count() < 5) {
            $this->call(MeasurementUnitSeeder::class);

            $units = MeasurementUnit::query()
                ->whereIn('code', ['unit', 'use', 'milliliter', 'gram', 'sheet'])
                ->get()
                ->keyBy('code');
        }

        $products = $this->seedProducts($company, $units);
        $services = $this->seedServices($company);
        $this->seedCompositions($company, $services, $products);
        $this->seedProfessionalLinks($company, $services);
    }

    /**
     * @param  Collection<string, MeasurementUnit>  $units
     * @return array<string, Product>
     */
    protected function seedProducts(Company $company, $units): array
    {
        $catalog = [
            ['name' => 'Algodão Prensado', 'unit' => 'unit', 'cost' => '0.014635', 'type' => ProductType::Consumable],
            ['name' => 'Aplicador', 'unit' => 'use', 'cost' => '0.034476', 'type' => ProductType::Consumable],
            ['name' => 'Boquinha de Colágeno', 'unit' => 'unit', 'cost' => '1.000000', 'type' => ProductType::Consumable],
            ['name' => 'Cartucho', 'unit' => 'unit', 'cost' => '1.666667', 'type' => ProductType::Consumable],
            ['name' => 'Escovinha', 'unit' => 'unit', 'cost' => '0.035842', 'type' => ProductType::Consumable],
            ['name' => 'Esfoliante Facial', 'unit' => 'gram', 'cost' => '0.356957', 'type' => ProductType::Consumable],
            ['name' => 'Esfoliante Tulipa', 'unit' => 'gram', 'cost' => '5.133333', 'type' => ProductType::Consumable],
            ['name' => 'Folhas de Cera', 'unit' => 'sheet', 'cost' => '2.581667', 'type' => ProductType::Consumable],
            ['name' => 'Gel Relaxante', 'unit' => 'milliliter', 'cost' => '1.000000', 'type' => ProductType::Consumable],
            ['name' => 'Henna', 'unit' => 'use', 'cost' => '1.876000', 'type' => ProductType::Consumable],
            ['name' => 'Linha', 'unit' => 'use', 'cost' => '0.319149', 'type' => ProductType::Consumable],
            ['name' => 'Loção Adstringente', 'unit' => 'milliliter', 'cost' => '0.149774', 'type' => ProductType::Consumable],
            ['name' => 'Manipulados', 'unit' => 'milliliter', 'cost' => '6.333333', 'type' => ProductType::Consumable],
            ['name' => 'Microbrush', 'unit' => 'use', 'cost' => '0.032226', 'type' => ProductType::Consumable],
            ['name' => 'Palitos', 'unit' => 'unit', 'cost' => '0.101801', 'type' => ProductType::Consumable],
            ['name' => 'Papel Toalha', 'unit' => 'unit', 'cost' => '0.357882', 'type' => ProductType::Consumable],
            ['name' => 'Removedor de Henna', 'unit' => 'milliliter', 'cost' => '1.014000', 'type' => ProductType::Consumable],
            ['name' => 'Bandejas', 'unit' => 'unit', 'cost' => '0', 'type' => ProductType::Asset],
            ['name' => 'Carrinho', 'unit' => 'unit', 'cost' => '0', 'type' => ProductType::Asset],
            ['name' => 'Forro da Maca', 'unit' => 'unit', 'cost' => '0', 'type' => ProductType::Asset],
            ['name' => 'Organizador Acrílico', 'unit' => 'unit', 'cost' => '0', 'type' => ProductType::Asset],
            ['name' => 'Organizador de Esfera', 'unit' => 'unit', 'cost' => '0', 'type' => ProductType::Asset],
            ['name' => 'Pinça', 'unit' => 'unit', 'cost' => '0', 'type' => ProductType::Asset],
            ['name' => 'Porta Lenço', 'unit' => 'unit', 'cost' => '0', 'type' => ProductType::Asset],
            ['name' => 'Pump de Sabonete', 'unit' => 'unit', 'cost' => '0', 'type' => ProductType::Asset],
            ['name' => 'Quadro para Certificados', 'unit' => 'unit', 'cost' => '0', 'type' => ProductType::Asset],
            ['name' => 'Suporte de Pinças', 'unit' => 'unit', 'cost' => '0', 'type' => ProductType::Asset],
            ['name' => 'Tesoura Reta', 'unit' => 'unit', 'cost' => '0', 'type' => ProductType::Asset],
            ['name' => 'Vidros Saboneteira', 'unit' => 'unit', 'cost' => '0', 'type' => ProductType::Asset],
        ];

        $products = [];

        foreach ($catalog as $item) {
            $products[$item['name']] = Product::query()->updateOrCreate(
                [
                    'company_id' => $company->getKey(),
                    'name' => $item['name'],
                ],
                [
                    'measurement_unit_id' => $units[$item['unit']]->getKey(),
                    'type' => $item['type'],
                    'reference_unit_cost' => $item['cost'],
                    'tracks_stock' => $item['type'] === ProductType::Consumable,
                    'is_active' => true,
                ],
            );
        }

        return $products;
    }

    /**
     * @return array<string, Service>
     */
    protected function seedServices(Company $company): array
    {
        $catalog = [
            ['name' => 'Combo Completo', 'price' => '85.00', 'duration' => 60, 'sort' => 1],
            ['name' => 'Design + Henna', 'price' => '50.00', 'duration' => 45, 'sort' => 2],
            ['name' => 'Design Henna + Spa Labial', 'price' => '60.00', 'duration' => 50, 'sort' => 3],
            ['name' => 'Design Simples', 'price' => '35.00', 'duration' => 30, 'sort' => 4],
            ['name' => 'Design Simples + Spa Labial', 'price' => '50.00', 'duration' => 45, 'sort' => 5],
            ['name' => 'Henna Avulsa', 'price' => '25.00', 'duration' => 25, 'sort' => 6],
            ['name' => 'Spa dos Lábios', 'price' => '45.00', 'duration' => 30, 'sort' => 7],
            ['name' => 'Hidra Gloss', 'price' => '119.90', 'duration' => 60, 'sort' => 8],
        ];

        $services = [];

        foreach ($catalog as $item) {
            $slug = Service::generateUniqueSlug($item['name'], $company->getKey());

            $services[$item['name']] = Service::query()->updateOrCreate(
                [
                    'company_id' => $company->getKey(),
                    'name' => $item['name'],
                ],
                [
                    'slug' => $slug,
                    'price' => $item['price'],
                    'duration_minutes' => $item['duration'],
                    'sort_order' => $item['sort'],
                    'is_bookable' => true,
                    'is_online_booking_enabled' => true,
                    'is_active' => true,
                ],
            );
        }

        return $services;
    }

    /**
     * @param  array<string, Service>  $services
     * @param  array<string, Product>  $products
     */
    protected function seedCompositions(Company $company, array $services, array $products): void
    {
        $compositions = [
            'Combo Completo' => [
                'Algodão Prensado' => 4,
                'Aplicador' => 2,
                'Boquinha de Colágeno' => 1,
                'Escovinha' => 1,
                'Esfoliante Facial' => 1,
                'Folhas de Cera' => 3,
                'Gel Relaxante' => 1,
                'Henna' => 1,
                'Microbrush' => 1,
                'Palitos' => 1,
                'Papel Toalha' => 2,
            ],
            'Design + Henna' => [
                'Algodão Prensado' => 2,
                'Aplicador' => 1,
                'Escovinha' => 1,
                'Folhas de Cera' => 3,
                'Gel Relaxante' => 1,
                'Henna' => 1,
                'Loção Adstringente' => 1,
                'Microbrush' => 1,
                'Palitos' => 1,
                'Papel Toalha' => 2,
            ],
            'Design Henna + Spa Labial' => [
                'Algodão Prensado' => 4,
                'Aplicador' => 2,
                'Boquinha de Colágeno' => 1,
                'Escovinha' => 1,
                'Esfoliante Facial' => 1,
                'Folhas de Cera' => 2,
                'Gel Relaxante' => 1,
                'Henna' => 1,
                'Loção Adstringente' => 2,
                'Microbrush' => 2,
                'Palitos' => 1,
                'Papel Toalha' => 2,
            ],
            'Design Simples' => [
                'Algodão Prensado' => 4,
                'Escovinha' => 1,
                'Folhas de Cera' => 3,
                'Gel Relaxante' => 1,
                'Linha' => 1,
                'Loção Adstringente' => 1,
                'Papel Toalha' => 2,
            ],
            'Design Simples + Spa Labial' => [
                'Algodão Prensado' => 3,
                'Aplicador' => 2,
                'Boquinha de Colágeno' => 1,
                'Escovinha' => 1,
                'Esfoliante Facial' => 1,
                'Folhas de Cera' => 3,
                'Gel Relaxante' => 1,
                'Linha' => 1,
                'Loção Adstringente' => 1,
                'Microbrush' => 1,
                'Papel Toalha' => 1,
            ],
            'Henna Avulsa' => [
                'Algodão Prensado' => 2,
                'Henna' => 1,
                'Palitos' => 1,
            ],
            'Spa dos Lábios' => [
                'Algodão Prensado' => 1,
                'Aplicador' => 1,
                'Boquinha de Colágeno' => 1,
                'Loção Adstringente' => 1,
            ],
            'Hidra Gloss' => [
                'Loção Adstringente' => 1,
                'Algodão Prensado' => 3,
                'Aplicador' => 1,
                'Boquinha de Colágeno' => 1,
                'Manipulados' => 2,
                'Esfoliante Tulipa' => 1,
                'Cartucho' => 1,
                'Papel Toalha' => 2,
            ],
        ];

        $compositionService = app(ServiceCompositionService::class);

        foreach ($compositions as $serviceName => $items) {
            $service = $services[$serviceName];

            $payload = [];

            foreach ($items as $productName => $quantity) {
                $payload[] = [
                    'product_id' => $products[$productName]->getKey(),
                    'quantity' => (string) $quantity,
                ];
            }

            $compositionService->sync($company, $service, $payload);
        }
    }

    /**
     * @param  array<string, Service>  $services
     */
    protected function seedProfessionalLinks(Company $company, array $services): void
    {
        $ana = Professional::query()
            ->where('company_id', $company->getKey())
            ->where('name', 'Ana')
            ->first();

        if (! $ana) {
            return;
        }

        $assignmentService = app(ServiceProfessionalAssignmentService::class);

        foreach ($services as $service) {
            $alreadyLinked = $service->professionals()
                ->where('professionals.id', $ana->getKey())
                ->exists();

            if ($alreadyLinked) {
                continue;
            }

            $assignmentService->attach($company, $service, [
                'professional_id' => $ana->getKey(),
                'is_active' => true,
            ]);
        }
    }
}
