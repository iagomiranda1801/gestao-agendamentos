<?php

namespace Tests\Feature\Sales;

use App\Enums\CompanyModule;
use App\Enums\SaleItemType;
use App\Filament\App\Resources\Sales\Pages\ListSales;
use App\Models\Sale;
use App\Models\SaleFiscalDocument;
use App\Models\SaleItem;
use App\Services\Asaas\CompanyAsaasSettingService;
use App\Services\Asaas\SaleFiscalService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class SaleFiscalIntegrationTest extends TestCase
{
    public function test_mixed_sale_issues_each_note_for_its_share_and_uses_the_company_sandbox_keys(): void
    {
        $company = $this->createCompany([
            'enabled_modules' => [CompanyModule::Finance->value, CompanyModule::Sales->value],
        ]);
        $sale = Sale::factory()->forCompany($company)->create([
            'gross_amount' => '100.00',
            'discount_amount' => '10.01',
            'final_amount' => '89.99',
        ]);
        $sale->client->update(['document' => '52998224725']);
        $product = SaleItem::factory()->forCompany($company)->create(['sale_id' => $sale->id]);
        SaleItem::factory()->forCompany($company)->create([
            'sale_id' => $sale->id,
            'item_type' => SaleItemType::Service,
            'product_id' => null,
        ]);
        $setting = app(CompanyAsaasSettingService::class)->getOrCreate($company);
        $setting->update([
            'environment' => 'production',
            'api_key' => 'production-key',
            'fiscal_sandbox_api_key' => 'service-sandbox-key',
            'base_sandbox_api_key' => 'product-sandbox-key',
        ]);
        $orderReference = null;
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use (&$orderReference) {
            return match ([$request->method(), parse_url($request->url(), PHP_URL_PATH)]) {
                ['GET', '/v3/customers'] => Http::response(['data' => [['id' => 'cus_sandbox']]]),
                ['POST', '/v3/invoices'] => Http::response(['id' => 'inv_sandbox', 'status' => 'SCHEDULED']),
                ['POST', '/api/v2/salesOrders'] => (function () use ($request, &$orderReference) {
                    $orderReference = $request['externalReference'];

                    return Http::response(['id' => 101], 202);
                })(),
                ['GET', '/api/v2/salesOrders/101'] => Http::response(['externalReference' => $orderReference, 'orderValue' => 45]),
                ['POST', '/api/v2/salesOrders/101/invoice'] => Http::response(['invoiceId' => 102], 202),
                default => $this->fail('Unexpected request '.$request->url()),
            };
        });

        $this->authenticateForAppTenant($this->createCompanyUser($company), $company);
        Livewire::test(ListSales::class)
            ->callAction(TestAction::make('issue_nfse')->table($sale), $this->serviceData())
            ->assertHasNoActionErrors();

        Livewire::test(ListSales::class)
            ->callAction(TestAction::make('issue_nfe')->table($sale), [
                'baseCustomer' => '201', 'baseBank' => '301', 'basePaymentType' => 'PIX', 'dueDate' => '2026-11-06',
                'product_'.$product->id => '401',
            ])
            ->assertHasNoActionErrors();
        $nfe = $sale->fiscalDocuments()->where('type', SaleFiscalDocument::PRODUCT)->sole();

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://api-sandbox.asaas.com/v3/invoices'
            && $request->hasHeader('access_token', 'service-sandbox-key')
            && $request['value'] === 44.99);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://api-sandbox.baseerp.com.br/api/v2/salesOrders'
            && $request->hasHeader('access_token', 'product-sandbox-key')
            && $request['orderItems'][0]['productId'] === 401
            && $request['discountValue'] == 5);
        $this->assertSame('45.00', $nfe->amount);
        $this->assertSame('pending', $nfe->status);
        $this->assertSame('102', $nfe->external_invoice_id);

        $route = route('webhooks.asaas.fiscal-sandbox', ['company' => $company->slug]);
        $headers = ['asaas-access-token' => $setting->webhook_token];
        $this->postJson($route, [
            'event' => 'INVOICE_NFE_AUTHORIZED',
            'invoiceNfe' => ['invoiceId' => 102, 'salesOrderId' => 101, 'number' => '42', 'xmlUrl' => 'https://api-sandbox.baseerp.com.br/download/xml'],
        ], $headers)->assertOk();
        $this->postJson($route, [
            'event' => 'INVOICE_NFE_CREATED',
            'invoiceNfe' => ['invoiceId' => 102, 'salesOrderId' => 101],
        ], $headers)->assertOk();

        $this->assertSame('authorized', $nfe->fresh()->status);
        $this->assertSame('https://api-sandbox.baseerp.com.br/download/xml', $nfe->fresh()->xml_url);
        $this->assertSame(2, $sale->fiscalDocuments()->count());
    }

    public function test_uncertain_service_submission_is_reconciled_without_sending_a_second_note(): void
    {
        $company = $this->createCompany();
        $sale = Sale::factory()->forCompany($company)->create(['final_amount' => '50.00']);
        $sale->client->update(['document' => '52998224725']);
        SaleItem::factory()->forCompany($company)->create([
            'sale_id' => $sale->id,
            'item_type' => SaleItemType::Service,
            'product_id' => null,
        ]);
        app(CompanyAsaasSettingService::class)->getOrCreate($company)->update(['fiscal_sandbox_api_key' => 'sandbox-key']);
        Http::preventStrayRequests();
        Http::fake([
            'api-sandbox.asaas.com/v3/customers*' => Http::response(['data' => [['id' => 'cus_sandbox']]]),
            'api-sandbox.asaas.com/v3/invoices' => Http::failedConnection(),
            'api-sandbox.asaas.com/v3/invoices?*' => Http::response(['data' => [['id' => 'inv_recovered', 'status' => 'AUTHORIZED']]]),
        ]);

        $fiscal = app(SaleFiscalService::class);
        $document = $fiscal->issue($sale, SaleFiscalDocument::SERVICE, $this->serviceData());
        $this->assertSame('unknown', $document->status);

        try {
            $fiscal->issue($sale, SaleFiscalDocument::SERVICE, $this->serviceData());
            $this->fail('An uncertain invoice must not be resubmitted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('fiscal', $exception->errors());
        }

        $fiscal->refresh($document, $company);
        $this->assertSame('authorized', $document->fresh()->status);
        $this->assertSame('inv_recovered', $document->fresh()->external_invoice_id);
        Http::assertSentCount(3);
    }

    private function serviceData(): array
    {
        return [
            'serviceCode' => '1.01', 'serviceName' => 'Serviço de teste',
            'iss' => 0, 'pis' => 0, 'cofins' => 0, 'csll' => 0, 'inss' => 0, 'ir' => 0,
        ];
    }
}
