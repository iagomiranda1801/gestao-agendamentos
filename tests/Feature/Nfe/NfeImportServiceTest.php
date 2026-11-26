<?php

namespace Tests\Feature\Nfe;

use App\Enums\CompanyRole;
use App\Enums\PayableStatus;
use App\Models\Company;
use App\Models\InventoryBalance;
use App\Models\Payable;
use App\Models\Product;
use App\Models\StockDocument;
use App\Models\Supplier;
use App\Models\SupplierProductMapping;
use App\Models\User;
use App\Services\Nfe\NfeImportService;
use App\Services\Nfe\NfeProductMatcher;
use App\Services\Nfe\NfeXmlParser;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;
use Tests\Support\CreatesFinanceFixtures;
use Tests\Support\CreatesStockFixtures;
use Tests\TestCase;

class NfeImportServiceTest extends TestCase
{
    use CreatesFinanceFixtures;
    use CreatesStockFixtures;

    protected function invoice(string $name = 'nfe_proc_cerveja'): array
    {
        return app(NfeXmlParser::class)->parse((string) file_get_contents(base_path("tests/fixtures/nfe/{$name}.xml")));
    }

    protected function makeCompany(array $attributes = []): Company
    {
        return $this->createCompany(array_merge([
            'document' => '45.723.174/0001-10',
            'enabled_modules' => ['stock', 'finance'],
        ], $attributes));
    }

    protected function admin(Company $company): User
    {
        return $this->createCompanyUser($company, [], CompanyRole::CompanyAdmin);
    }

    protected function balance(Product $product): InventoryBalance
    {
        return InventoryBalance::query()->where('product_id', $product->getKey())->firstOrFail();
    }

    public function test_matcher_uses_barcode_mapping_and_ignores_sem_gtin(): void
    {
        $company = $this->makeCompany();
        $supplier = Supplier::factory()->forCompany($company)->create(['document' => '11.222.333/0001-81']);
        $byTaxBarcode = $this->createTrackedProduct($company, ['name' => 'Lata Pilsen', 'barcode' => '7891234567895']);
        $mapped = $this->createTrackedProduct($company, ['name' => 'Cola Dois Litros', 'barcode' => null]);

        $mapping = new SupplierProductMapping;
        $mapping->forceFill([
            'company_id' => $company->getKey(),
            'supplier_id' => $supplier->getKey(),
            'supplier_code' => 'REFRI002',
            'product_id' => $mapped->getKey(),
            'conversion_factor' => '1',
        ])->save();

        $invoice = $this->invoice();
        $matches = app(NfeProductMatcher::class)->matchAll($company, $supplier, $invoice['items']);

        $this->assertSame(NfeProductMatcher::SOURCE_BARCODE, $matches[1]['source']);
        $this->assertSame($byTaxBarcode->getKey(), $matches[1]['product_id']);
        $this->assertSame('12', $matches[1]['factor']);
        $this->assertSame(NfeProductMatcher::SOURCE_MAPPING, $matches[2]['source']);
        $this->assertSame($mapped->getKey(), $matches[2]['product_id']);
    }

    public function test_sem_gtin_never_matches_products_without_barcode(): void
    {
        $company = $this->makeCompany();
        $this->createTrackedProduct($company, ['name' => 'Produto sem código', 'barcode' => null]);

        $matches = app(NfeProductMatcher::class)->matchAll($company, null, $this->invoice()['items']);

        $this->assertSame(NfeProductMatcher::SOURCE_NONE, $matches[2]['source']);
    }

    public function test_matcher_suggests_by_similar_name_only_when_close(): void
    {
        $company = $this->makeCompany();
        $close = $this->createTrackedProduct($company, ['name' => 'Refrigerante Cola 2L', 'barcode' => null]);
        $this->createTrackedProduct($company, ['name' => 'Cerveja Skol', 'barcode' => null]);

        $matches = app(NfeProductMatcher::class)->matchAll($company, null, $this->invoice()['items']);

        $this->assertSame(NfeProductMatcher::SOURCE_NAME, $matches[2]['source']);
        $this->assertSame($close->getKey(), $matches[2]['product_id']);
        $this->assertSame(NfeProductMatcher::SOURCE_NONE, $matches[1]['source']);
    }

    public function test_import_posts_stock_with_conversion_creates_supplier_payables_and_mappings(): void
    {
        $company = $this->makeCompany();
        $user = $this->admin($company);
        $beer = $this->createTrackedProduct($company, ['name' => 'Lata Pilsen', 'barcode' => '7891234567895']);
        $cola = $this->createTrackedProduct($company, ['name' => 'Cola Dois Litros', 'barcode' => null]);
        $invoice = $this->invoice();

        $result = app(NfeImportService::class)->import($company, $user, $invoice, [
            1 => ['action' => 'match', 'product_id' => $beer->getKey(), 'factor' => '12', 'remember' => true],
            2 => ['action' => 'match', 'product_id' => $cola->getKey(), 'factor' => '1', 'remember' => true],
        ], ['create_payable' => true]);

        $this->assertSame($invoice['access_key'], $result->document->nfe_access_key);
        $this->assertTrue($result->document->isPosted());
        $this->assertSame('1234/1', $result->document->document_number);

        // 10 caixas x 12 = 120 latas; custo 380,00 / 120
        $this->assertSame('120.0000', (string) $this->balance($beer)->quantity_on_hand);
        $this->assertSame('3.166667', (string) $this->balance($beer)->average_unit_cost);
        $this->assertSame('6.0000', (string) $this->balance($cola)->quantity_on_hand);
        $this->assertSame('6.666667', (string) $this->balance($cola)->average_unit_cost);

        $supplier = $result->supplier;
        $this->assertNotNull($supplier);
        $this->assertSame($company->getKey(), $supplier->company_id);
        $this->assertSame('11.222.333/0001-81', $supplier->document);
        $this->assertSame($supplier->getKey(), $result->document->supplier_id);

        $payable = $result->payable;
        $this->assertInstanceOf(Payable::class, $payable);
        $this->assertSame('420.00', (string) $payable->total_amount);
        $this->assertSame($supplier->getKey(), $payable->supplier_id);
        $this->assertSame($result->document->getKey(), $payable->stock_document_id);
        $installments = $payable->installments()->orderBy('installment_number')->get();
        $this->assertCount(2, $installments);
        $this->assertSame('2026-12-20', $installments[0]->due_date->toDateString());
        $this->assertSame('210.00', (string) $installments[1]->original_amount);
        $this->assertNotSame(PayableStatus::Draft, $payable->status);

        $this->assertSame(2, $result->mappingsSaved);
        $mapping = SupplierProductMapping::query()->where('supplier_code', 'CERV001')->firstOrFail();
        $this->assertSame($company->getKey(), $mapping->company_id);
        $this->assertSame($beer->getKey(), $mapping->product_id);
        $this->assertSame('12.0000', (string) $mapping->conversion_factor);
    }

    public function test_saved_mapping_is_used_on_next_invoice_with_its_factor(): void
    {
        $company = $this->makeCompany();
        $user = $this->admin($company);
        $beer = $this->createTrackedProduct($company, ['name' => 'Lata Pilsen', 'barcode' => null]);
        $invoice = $this->invoice();

        app(NfeImportService::class)->import($company, $user, $invoice, [
            1 => ['action' => 'match', 'product_id' => $beer->getKey(), 'factor' => '12', 'remember' => true],
        ]);

        $next = $this->invoice();
        $matches = app(NfeProductMatcher::class)->matchAll(
            $company,
            app(NfeImportService::class)->findSupplier($company, '11222333000181'),
            $next['items'],
        );

        $this->assertSame(NfeProductMatcher::SOURCE_MAPPING, $matches[1]['source']);
        $this->assertSame($beer->getKey(), $matches[1]['product_id']);
        $this->assertSame('12', $matches[1]['factor']);
    }

    public function test_remember_false_does_not_save_mapping(): void
    {
        $company = $this->makeCompany();
        $user = $this->admin($company);
        $beer = $this->createTrackedProduct($company, ['name' => 'Lata Pilsen']);

        $result = app(NfeImportService::class)->import($company, $user, $this->invoice(), [
            1 => ['action' => 'match', 'product_id' => $beer->getKey(), 'factor' => '1', 'remember' => false],
        ]);

        $this->assertSame(0, $result->mappingsSaved);
        $this->assertSame(0, SupplierProductMapping::query()->count());
        $this->assertSame(1, $result->ignoredItems);
    }

    public function test_same_key_cannot_be_imported_twice_in_the_same_company(): void
    {
        $company = $this->makeCompany();
        $user = $this->admin($company);
        $beer = $this->createTrackedProduct($company, ['name' => 'Lata Pilsen']);
        $decisions = [1 => ['action' => 'match', 'product_id' => $beer->getKey(), 'factor' => '12']];
        $service = app(NfeImportService::class);

        $service->import($company, $user, $this->invoice(), $decisions);

        try {
            $service->import($company, $user, $this->invoice(), $decisions);
            $this->fail('A segunda importação deveria falhar.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('já foi importada', $exception->errors()['xml'][0]);
        }

        $this->assertSame('120.0000', (string) $this->balance($beer)->quantity_on_hand);
        $this->assertSame(1, StockDocument::query()->whereNotNull('nfe_access_key')->count());
    }

    public function test_database_blocks_duplicate_key_per_company(): void
    {
        $company = $this->makeCompany();
        $user = $this->admin($company);
        $product = $this->createTrackedProduct($company);
        $key = $this->invoice()['access_key'];

        $this->postPurchase($company, $user, [['product_id' => $product->getKey(), 'quantity' => '1', 'unit_cost' => '1']], ['nfe_access_key' => $key]);

        $this->expectException(UniqueConstraintViolationException::class);

        $this->postPurchase($company, $user, [['product_id' => $product->getKey(), 'quantity' => '1', 'unit_cost' => '1']], ['nfe_access_key' => $key]);
    }

    public function test_tenants_are_isolated_for_duplicates_products_suppliers_and_mappings(): void
    {
        $companyA = $this->makeCompany();
        $companyB = $this->makeCompany(['document' => '45.723.174/0001-10']);
        $userA = $this->admin($companyA);
        $userB = $this->admin($companyB);
        $productA = $this->createTrackedProduct($companyA, ['name' => 'Lata Pilsen', 'barcode' => '7891234567895']);
        $productB = $this->createTrackedProduct($companyB, ['name' => 'Lata Pilsen', 'barcode' => '7891234567895']);
        $service = app(NfeImportService::class);

        $service->import($companyA, $userA, $this->invoice(), [
            1 => ['action' => 'match', 'product_id' => $productA->getKey(), 'factor' => '12'],
        ]);

        // A mesma chave em outra empresa é permitida.
        $this->assertNull($service->findDuplicate($companyB, $this->invoice()['access_key']));
        $matches = app(NfeProductMatcher::class)->matchAll($companyB, null, $this->invoice()['items']);
        $this->assertSame($productB->getKey(), $matches[1]['product_id']);

        // Produto de outra empresa não pode ser escolhido.
        try {
            $service->import($companyB, $userB, $this->invoice(), [
                1 => ['action' => 'match', 'product_id' => $productA->getKey(), 'factor' => '12'],
            ]);
            $this->fail('Deveria recusar produto de outra empresa.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(0, StockDocument::query()->where('company_id', $companyB->getKey())->count());

        $result = $service->import($companyB, $userB, $this->invoice(), [
            1 => ['action' => 'match', 'product_id' => $productB->getKey(), 'factor' => '12'],
        ]);

        $this->assertSame($companyB->getKey(), $result->document->company_id);
        $this->assertSame(2, Supplier::query()->count());
        $this->assertSame(1, Supplier::query()->where('company_id', $companyB->getKey())->count());
        $this->assertSame('120.0000', (string) $this->balance($productA)->quantity_on_hand);
        $this->assertSame('120.0000', (string) $this->balance($productB)->quantity_on_hand);
    }

    public function test_creates_new_product_inline_with_cost_and_optional_sale_price(): void
    {
        $company = $this->makeCompany();
        $user = $this->admin($company);
        $this->createTrackedProduct($company, ['name' => 'Outro produto']);

        $result = app(NfeImportService::class)->import($company, $user, $this->invoice(), [
            2 => [
                'action' => 'create',
                'factor' => '1',
                'new' => ['name' => 'Cola 2L', 'barcode' => '7890001112223', 'sale_price' => '12,50'],
            ],
        ]);

        $product = Product::query()->where('company_id', $company->getKey())->where('name', 'Cola 2L')->firstOrFail();

        $this->assertSame(1, $result->createdProducts);
        $this->assertSame('7890001112223', $product->barcode);
        $this->assertTrue($product->tracks_stock);
        $this->assertSame('12.50', (string) $product->sale_price);
        $this->assertSame('6.666667', (string) $product->reference_unit_cost);
        $this->assertSame('6.0000', (string) $this->balance($product)->quantity_on_hand);
        $this->assertSame(1, SupplierProductMapping::query()->where('product_id', $product->getKey())->count());
    }

    public function test_duplicate_product_name_on_create_is_reported_with_item_label(): void
    {
        $company = $this->makeCompany();
        $user = $this->admin($company);
        $this->createTrackedProduct($company, ['name' => 'Cola 2L']);

        try {
            app(NfeImportService::class)->import($company, $user, $this->invoice(), [
                2 => ['action' => 'create', 'factor' => '1', 'new' => ['name' => 'Cola 2L']],
            ]);
            $this->fail('Deveria recusar nome duplicado.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Item 2', $exception->errors()['items'][0]);
        }

        $this->assertSame(0, StockDocument::query()->count());
    }

    public function test_two_items_for_the_same_product_are_merged_with_weighted_cost(): void
    {
        $company = $this->makeCompany();
        $user = $this->admin($company);
        $product = $this->createTrackedProduct($company, ['name' => 'Bebida']);

        app(NfeImportService::class)->import($company, $user, $this->invoice(), [
            1 => ['action' => 'match', 'product_id' => $product->getKey(), 'factor' => '12'],
            2 => ['action' => 'match', 'product_id' => $product->getKey(), 'factor' => '1'],
        ]);

        // 120 + 6 unidades, custo total 380 + 40 = 420
        $this->assertSame('126.0000', (string) $this->balance($product)->quantity_on_hand);
        $this->assertSame('3.333333', (string) $this->balance($product)->average_unit_cost);
    }

    public function test_invalid_factor_and_empty_selection_are_rejected(): void
    {
        $company = $this->makeCompany();
        $user = $this->admin($company);
        $product = $this->createTrackedProduct($company);
        $service = app(NfeImportService::class);

        foreach ([['factor' => '0'], ['factor' => 'abc'], ['factor' => '-2']] as $extra) {
            try {
                $service->import($company, $user, $this->invoice(), [1 => ['action' => 'match', 'product_id' => $product->getKey()] + $extra]);
                $this->fail('Fator inválido deveria falhar.');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }

        try {
            $service->import($company, $user, $this->invoice(), []);
            $this->fail('Sem itens deveria falhar.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('items', $exception->errors());
        }

        $this->assertSame(0, StockDocument::query()->count());
    }

    public function test_without_installments_creates_single_payable_due_today(): void
    {
        $company = $this->makeCompany();
        $user = $this->admin($company);
        $product = $this->createTrackedProduct($company, ['barcode' => '7890000000017']);

        $result = app(NfeImportService::class)->import($company, $user, $this->invoice('nfe_simples'), [
            1 => ['action' => 'match', 'product_id' => $product->getKey(), 'factor' => '1'],
        ], ['create_payable' => true, 'acknowledge_recipient_mismatch' => true]);

        $installments = $result->payable->installments;
        $this->assertCount(1, $installments);
        $this->assertSame(now()->toDateString(), $installments[0]->due_date->toDateString());
        $this->assertSame('102.00', (string) $result->payable->total_amount);
    }

    public function test_finance_module_off_skips_payable(): void
    {
        $company = $this->makeCompany(['enabled_modules' => ['stock']]);
        $user = $this->admin($company);
        $product = $this->createTrackedProduct($company, ['name' => 'Lata']);

        $result = app(NfeImportService::class)->import($company, $user, $this->invoice(), [
            1 => ['action' => 'match', 'product_id' => $product->getKey(), 'factor' => '12'],
        ], ['create_payable' => true]);

        $this->assertNull($result->payable);
        $this->assertSame(0, Payable::query()->count());
        $this->assertTrue($result->document->isPosted());
    }

    public function test_payable_is_optional(): void
    {
        $company = $this->makeCompany();
        $user = $this->admin($company);
        $product = $this->createTrackedProduct($company);

        $result = app(NfeImportService::class)->import($company, $user, $this->invoice(), [
            1 => ['action' => 'match', 'product_id' => $product->getKey(), 'factor' => '12'],
        ], ['create_payable' => false]);

        $this->assertNull($result->payable);
    }

    public function test_recipient_mismatch_needs_confirmation_and_own_issuer_is_rejected(): void
    {
        $company = $this->makeCompany(['document' => '12.345.678/0001-95']);
        $user = $this->admin($company);
        $product = $this->createTrackedProduct($company);
        $service = app(NfeImportService::class);
        $decisions = [1 => ['action' => 'match', 'product_id' => $product->getKey(), 'factor' => '12']];

        $this->assertSame(NfeImportService::RECIPIENT_MISMATCH, $service->recipientStatus($company, $this->invoice()));

        try {
            $service->import($company, $user, $this->invoice(), $decisions);
            $this->fail('Deveria exigir confirmação.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('destinatário', $exception->errors()['xml'][0]);
        }

        $result = $service->import($company, $user, $this->invoice(), $decisions, ['acknowledge_recipient_mismatch' => true]);
        $this->assertTrue($result->document->isPosted());

        $issuerCompany = $this->makeCompany(['document' => '11.222.333/0001-81']);
        $this->assertSame(NfeImportService::RECIPIENT_OWN_ISSUER, $service->recipientStatus($issuerCompany, $this->invoice()));
    }

    public function test_company_without_document_does_not_block_import(): void
    {
        $company = $this->makeCompany(['document' => null]);
        $user = $this->admin($company);
        $product = $this->createTrackedProduct($company);

        $this->assertSame(NfeImportService::RECIPIENT_UNKNOWN, app(NfeImportService::class)->recipientStatus($company, $this->invoice()));

        $result = app(NfeImportService::class)->import($company, $user, $this->invoice(), [
            1 => ['action' => 'match', 'product_id' => $product->getKey(), 'factor' => '12'],
        ]);

        $this->assertTrue($result->document->isPosted());
    }

    public function test_existing_supplier_is_found_by_cnpj_regardless_of_formatting(): void
    {
        $company = $this->makeCompany();
        $user = $this->admin($company);
        $product = $this->createTrackedProduct($company);
        $supplier = Supplier::factory()->forCompany($company)->create(['name' => 'Sol', 'document' => '11222333000181']);

        $result = app(NfeImportService::class)->import($company, $user, $this->invoice(), [
            1 => ['action' => 'match', 'product_id' => $product->getKey(), 'factor' => '12'],
        ]);

        $this->assertSame($supplier->getKey(), $result->supplier->getKey());
        $this->assertSame(1, Supplier::query()->count());
    }

    public function test_ignored_items_do_not_enter_stock(): void
    {
        $company = $this->makeCompany();
        $user = $this->admin($company);
        $beer = $this->createTrackedProduct($company, ['name' => 'Lata']);
        $cola = $this->createTrackedProduct($company, ['name' => 'Cola']);

        app(NfeImportService::class)->import($company, $user, $this->invoice(), [
            1 => ['action' => 'match', 'product_id' => $beer->getKey(), 'factor' => '12'],
            2 => ['action' => 'ignore'],
        ]);

        $this->assertNull(InventoryBalance::query()->where('product_id', $cola->getKey())->first());
    }
}
