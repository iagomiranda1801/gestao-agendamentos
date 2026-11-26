<?php

namespace Tests\Feature\Nfe;

use App\Enums\CompanyRole;
use App\Filament\App\Pages\ImportNfePage;
use App\Filament\App\Resources\Purchases\Pages\ListPurchases;
use App\Models\Company;
use App\Models\InventoryBalance;
use App\Models\Product;
use App\Models\StockDocument;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\Support\CreatesFinanceFixtures;
use Tests\Support\CreatesStockFixtures;
use Tests\TestCase;

class ImportNfePageTest extends TestCase
{
    use CreatesFinanceFixtures;
    use CreatesStockFixtures;

    protected function xmlFile(string $name = 'nfe_proc_cerveja'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            'nota.xml',
            (string) file_get_contents(base_path("tests/fixtures/nfe/{$name}.xml")),
        );
    }

    /**
     * @return array{0: Company, 1: User}
     */
    protected function setupTenant(array $companyAttributes = [], CompanyRole $role = CompanyRole::CompanyAdmin): array
    {
        $company = $this->createCompany(array_merge([
            'document' => '45.723.174/0001-10',
            'enabled_modules' => ['stock', 'finance'],
        ], $companyAttributes));
        $user = $this->createCompanyUser($company, [], $role);
        $this->authenticateForAppTenant($user, $company);

        return [$company, $user];
    }

    public function test_purchases_list_shows_import_button(): void
    {
        $this->setupTenant();

        Livewire::test(ListPurchases::class)
            ->assertActionVisible('importNfe');
    }

    public function test_page_requires_stock_module(): void
    {
        $this->setupTenant(['enabled_modules' => ['finance']]);

        $this->assertFalse(ImportNfePage::canAccess());
    }

    public function test_page_is_denied_for_roles_that_cannot_create_purchases(): void
    {
        $this->setupTenant(role: CompanyRole::Employee);

        $this->assertFalse(ImportNfePage::canAccess());
    }

    public function test_upload_review_and_confirm_creates_purchase(): void
    {
        [$company] = $this->setupTenant();
        $beer = $this->createTrackedProduct($company, ['name' => 'Lata Pilsen', 'barcode' => '7891234567895']);

        $page = Livewire::test(ImportNfePage::class)
            ->fillForm(['xml' => $this->xmlFile()])
            ->call('readXml')
            ->assertHasNoErrors()
            ->assertSet('step', 'review')
            ->assertSet('rows.1.action', 'match')
            ->assertSet('rows.1.product_id', $beer->getKey())
            ->assertSet('rows.1.factor', '12')
            ->assertSet('rows.2.action', 'create')
            ->assertSee('Lata Pilsen')
            ->assertSee('CERVEJA PILSEN LATA 350ML CX 12');

        $page->set('rows.2.new_name', 'Refrigerante Cola 2L')
            ->call('confirmImport')
            ->assertHasNoErrors()
            ->assertRedirect();

        $document = StockDocument::query()->where('company_id', $company->getKey())->firstOrFail();

        $this->assertSame('35261111222333000181550010000012341000012345', $document->nfe_access_key);
        $this->assertTrue($document->isPosted());
        $this->assertSame('120.0000', (string) InventoryBalance::query()->where('product_id', $beer->getKey())->value('quantity_on_hand'));
        $this->assertNotNull(Product::query()->where('company_id', $company->getKey())->where('name', 'Refrigerante Cola 2L')->first());
        $this->assertSame(2, $document->items()->count());
    }

    public function test_page_blocks_already_imported_key(): void
    {
        [$company] = $this->setupTenant();
        $this->createTrackedProduct($company, ['name' => 'Lata Pilsen', 'barcode' => '7891234567895']);

        Livewire::test(ImportNfePage::class)
            ->fillForm(['xml' => $this->xmlFile()])
            ->call('readXml')
            ->call('confirmImport')
            ->assertRedirect();

        Livewire::test(ImportNfePage::class)
            ->fillForm(['xml' => $this->xmlFile()])
            ->call('readXml')
            ->assertHasErrors(['data.xml'])
            ->assertSet('step', 'upload');

        $this->assertSame(1, StockDocument::query()->whereNotNull('nfe_access_key')->count());
    }

    public function test_page_rejects_malicious_xml(): void
    {
        $this->setupTenant();

        Livewire::test(ImportNfePage::class)
            ->fillForm(['xml' => $this->xmlFile('nfe_doctype')])
            ->call('readXml')
            ->assertHasErrors(['data.xml'])
            ->assertSet('step', 'upload');
    }

    public function test_recipient_mismatch_must_be_acknowledged_on_page(): void
    {
        [$company] = $this->setupTenant(['document' => '12.345.678/0001-95']);
        $this->createTrackedProduct($company, ['name' => 'Lata Pilsen', 'barcode' => '7891234567895']);

        $page = Livewire::test(ImportNfePage::class)
            ->fillForm(['xml' => $this->xmlFile()])
            ->call('readXml')
            ->assertSet('step', 'review')
            ->assertSee('não é o CNPJ/CPF desta empresa');

        $page->set('rows.2.action', 'ignore')
            ->call('confirmImport')
            ->assertHasErrors(['import'])
            ->assertNoRedirect();

        $page->set('acknowledgeMismatch', true)
            ->call('confirmImport')
            ->assertRedirect();
    }
}
