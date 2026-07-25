<?php

namespace Database\Seeders;

use App\Enums\StockDocumentType;
use App\Models\Company;
use App\Models\Product;
use App\Models\StockDocument;
use App\Models\User;
use App\Services\Stock\StockDocumentPostingService;
use App\Services\Stock\StockDocumentService;
use Illuminate\Database\Seeder;
use RuntimeException;

class EstudioAnaOpeningInventorySeeder extends Seeder
{
    public const REFERENCE_KEY = 'seed:estudio-ana:opening-inventory-v1';

    public function run(): void
    {
        $company = Company::query()->where('slug', 'estudio-ana')->first();

        if (! $company) {
            return;
        }

        $existing = StockDocument::query()
            ->where('company_id', $company->getKey())
            ->where('reference_key', self::REFERENCE_KEY)
            ->first();

        if ($existing?->isPosted()) {
            return;
        }

        $user = User::query()->where('email', 'ana@estudioana.test')->first()
            ?? User::query()->where('email', 'superadmin@imsolucoes.test')->first();

        if (! $user) {
            throw new RuntimeException('Usuário seed não encontrado para lançar saldo inicial.');
        }

        $items = $this->openingItems();

        $productMap = $this->resolveProducts($company, array_keys($items));

        $payloadItems = [];

        foreach ($items as $name => $data) {
            $product = $productMap[$name];
            $product->update(['tracks_stock' => true]);

            $payloadItems[] = [
                'product_id' => $product->getKey(),
                'quantity' => (string) $data['quantity'],
                'unit_cost' => (string) $data['unit_cost'],
            ];
        }

        $documentService = app(StockDocumentService::class);
        $postingService = app(StockDocumentPostingService::class);

        if ($existing?->isDraft()) {
            $document = $documentService->updateDraft($company, $existing, [
                'occurred_at' => now(),
                'reference_key' => self::REFERENCE_KEY,
                'notes' => 'Saldo inicial importado da planilha da cliente piloto.',
            ], $payloadItems);
        } else {
            $document = $documentService->createDraft($company, StockDocumentType::OpeningBalance, [
                'occurred_at' => now(),
                'reference_key' => self::REFERENCE_KEY,
                'notes' => 'Saldo inicial importado da planilha da cliente piloto.',
            ], $payloadItems, $user);
        }

        $postingService->post($company, $document, $user);
    }

    /**
     * @param  list<string>  $names
     * @return array<string, Product>
     */
    protected function resolveProducts(Company $company, array $names): array
    {
        $products = Product::query()
            ->where('company_id', $company->getKey())
            ->whereIn('name', $names)
            ->get()
            ->keyBy('name');

        $missing = array_diff($names, $products->keys()->all());

        if ($missing !== []) {
            throw new RuntimeException(
                'Produtos não encontrados para saldo inicial: '.implode(', ', $missing)
            );
        }

        return $products->all();
    }

    /**
     * @return array<string, array{quantity: float|int, unit_cost: string}>
     */
    protected function openingItems(): array
    {
        return [
            'Algodão Prensado' => ['quantity' => 973, 'unit_cost' => '0.014635'],
            'Aplicador' => ['quantity' => 315, 'unit_cost' => '0.034476'],
            'Bandejas' => ['quantity' => 2, 'unit_cost' => '20.740000'],
            'Boquinha de Colágeno' => ['quantity' => 46, 'unit_cost' => '1.000000'],
            'Carrinho' => ['quantity' => 1, 'unit_cost' => '93.550000'],
            'Cartucho' => ['quantity' => 30, 'unit_cost' => '1.666667'],
            'Escovinha' => ['quantity' => 303, 'unit_cost' => '0.035842'],
            'Esfoliante Facial' => ['quantity' => 46, 'unit_cost' => '0.356957'],
            'Forro da Maca' => ['quantity' => 1, 'unit_cost' => '43.000000'],
            'Esfoliante Tulipa' => ['quantity' => 15, 'unit_cost' => '5.133333'],
            'Folhas de Cera' => ['quantity' => 18, 'unit_cost' => '2.581667'],
            'Gel Relaxante' => ['quantity' => 32, 'unit_cost' => '1.000000'],
            'Henna' => ['quantity' => 25, 'unit_cost' => '1.876000'],
            'Linha' => ['quantity' => 47, 'unit_cost' => '0.319149'],
            'Organizador Acrílico' => ['quantity' => 1, 'unit_cost' => '39.820000'],
            'Organizador de Esfera' => ['quantity' => 1, 'unit_cost' => '33.500000'],
            'Loção Adstringente' => ['quantity' => 133, 'unit_cost' => '0.149774'],
            'Pinça' => ['quantity' => 4, 'unit_cost' => '5.497500'],
            'Porta Lenço' => ['quantity' => 1, 'unit_cost' => '48.830000'],
            'Pump de Sabonete' => ['quantity' => 1, 'unit_cost' => '16.420000'],
            'Quadro para Certificados' => ['quantity' => 5, 'unit_cost' => '5.938000'],
            'Manipulados' => ['quantity' => 15, 'unit_cost' => '6.333333'],
            'Suporte de Pinças' => ['quantity' => 1, 'unit_cost' => '19.000000'],
            'Tesoura Reta' => ['quantity' => 1, 'unit_cost' => '60.640000'],
            'Vidros Saboneteira' => ['quantity' => 5, 'unit_cost' => '11.378000'],
            'Microbrush' => ['quantity' => 337, 'unit_cost' => '0.032226'],
            'Palitos' => ['quantity' => 211, 'unit_cost' => '0.101801'],
            'Papel Toalha' => ['quantity' => 85, 'unit_cost' => '0.357882'],
            'Removedor de Henna' => ['quantity' => 30, 'unit_cost' => '1.014000'],
        ];
    }
}
