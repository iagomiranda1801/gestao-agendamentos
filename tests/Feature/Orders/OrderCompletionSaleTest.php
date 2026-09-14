<?php

namespace Tests\Feature\Orders;

use App\Enums\CompanyModule;
use App\Enums\OrderFulfillment;
use App\Enums\OrderStatus;
use App\Enums\ReceivableStatus;
use App\Enums\SaleItemType;
use App\Enums\SaleOrigin;
use App\Enums\SaleStatus;
use App\Models\Company;
use App\Models\Order;
use App\Models\Product;
use App\Models\Sale;
use App\Services\Financial\CompanyFinancialSettingService;
use App\Services\Orders\OrderSaleService;
use App\Services\Orders\OrderService;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesOrderFixtures;
use Tests\TestCase;

class OrderCompletionSaleTest extends TestCase
{
    use CreatesOrderFixtures;

    public function test_completing_pickup_order_creates_unpaid_sale_when_sales_module_is_enabled(): void
    {
        $setup = $this->createRestaurantSetupWithSales();
        $user = $this->createCompanyUser($setup['company']);
        $order = $this->completeOrder($setup, $user, [
            'items' => [['product_id' => $setup['burger']->id, 'quantity' => 1]],
            'fulfillment' => OrderFulfillment::Pickup,
        ]);

        $this->assertSame(OrderStatus::Completed, $order->status);
        $this->assertNotNull($order->sale_id);

        $sale = $order->sale()->with(['items', 'receivable', 'payments'])->first();

        $this->assertNotNull($sale);
        $this->assertSame(SaleOrigin::OnlineOrder, $sale->origin);
        $this->assertSame(SaleStatus::Completed, $sale->status);
        $this->assertSame(OrderSaleService::referenceKey($order), $sale->reference_key);
        $this->assertSame('25.00', $sale->final_amount);
        $this->assertSame('0.00', $sale->paid_amount);
        $this->assertSame('25.00', $sale->outstanding_amount);
        $this->assertSame(0, $sale->payments->count());
        $this->assertSame(1, $sale->items->count());
        $this->assertSame(SaleItemType::Product, $sale->items->first()->item_type);
        $this->assertSame($setup['burger']->id, $sale->items->first()->product_id);
        $this->assertSame('25.00', $sale->items->first()->line_total);
        $this->assertSame(ReceivableStatus::Open, $sale->receivable?->status);
        $this->assertSame('25.00', $sale->receivable?->outstanding_amount);
        $this->assertStringContainsString('Pedido online', (string) $sale->notes);
        $this->assertStringContainsString('Maria', (string) $sale->notes);
    }

    public function test_completing_delivery_order_includes_delivery_fee_on_the_sale(): void
    {
        $setup = $this->createRestaurantSetupWithSales();
        $user = $this->createCompanyUser($setup['company']);
        $order = $this->completeOrder($setup, $user, [
            'items' => [['product_id' => $setup['burger']->id, 'quantity' => 1]],
            'fulfillment' => OrderFulfillment::Delivery,
            'delivery_address' => 'Rua das Flores, 100',
            'delivery_neighborhood' => 'Centro',
            'delivery_city' => 'Uberlândia',
        ]);

        $sale = $order->sale()->with('items')->first();

        $this->assertNotNull($sale);
        $this->assertSame(SaleOrigin::OnlineOrder, $sale->origin);
        $this->assertSame('33.00', $sale->final_amount);
        $this->assertSame(2, $sale->items->count());
        $fee = $sale->items->firstWhere('name_snapshot', 'Taxa de entrega');
        $this->assertNotNull($fee);
        $this->assertSame(SaleItemType::Custom, $fee->item_type);
        $this->assertSame('8.00', (string) $fee->line_total);
        $this->assertSame(3300, $order->total_cents);
    }

    public function test_completing_without_sales_module_does_not_create_a_sale(): void
    {
        $setup = $this->createRestaurantSetup();
        $user = $this->createCompanyUser($setup['company']);
        $order = $this->completeOrder($setup, $user, [
            'items' => [['product_id' => $setup['burger']->id, 'quantity' => 1]],
            'fulfillment' => OrderFulfillment::Pickup,
        ]);

        $this->assertSame(OrderStatus::Completed, $order->status);
        $this->assertNull($order->sale_id);
        $this->assertSame(0, Sale::query()->where('company_id', $setup['company']->id)->count());
    }

    public function test_second_complete_is_idempotent_and_does_not_duplicate_sales(): void
    {
        $setup = $this->createRestaurantSetupWithSales();
        $user = $this->createCompanyUser($setup['company']);
        $service = app(OrderService::class);
        $order = $this->completeOrder($setup, $user, [
            'items' => [['product_id' => $setup['burger']->id, 'quantity' => 1]],
            'fulfillment' => OrderFulfillment::Pickup,
        ]);

        $saleId = $order->sale_id;

        $again = $service->transition($setup['company'], $order, OrderStatus::Completed, $user);

        $this->assertSame($saleId, $again->sale_id);
        $this->assertSame(1, Sale::query()->where('company_id', $setup['company']->id)->count());
    }

    public function test_completed_order_cannot_be_cancelled_and_sale_remains(): void
    {
        $setup = $this->createRestaurantSetupWithSales();
        $user = $this->createCompanyUser($setup['company']);
        $order = $this->completeOrder($setup, $user, [
            'items' => [['product_id' => $setup['burger']->id, 'quantity' => 1]],
            'fulfillment' => OrderFulfillment::Pickup,
        ]);

        try {
            app(OrderService::class)->cancel($setup['company'], $order, 'Cliente desistiu', $user);
            $this->fail('Expected validation exception');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
        }

        $fresh = $order->fresh();
        $this->assertSame(OrderStatus::Completed, $fresh->status);
        $this->assertNotNull($fresh->sale_id);
        $this->assertSame(SaleStatus::Completed, $fresh->sale?->status);
    }

    public function test_online_order_sale_is_unpaid_even_when_pos_requires_payment(): void
    {
        $setup = $this->createRestaurantSetupWithSales();
        $settings = app(CompanyFinancialSettingService::class)->getOrCreate($setup['company']);
        $settings->forceFill(['allow_unpaid_completion' => false])->save();

        $user = $this->createCompanyUser($setup['company']);
        $order = $this->completeOrder($setup, $user, [
            'items' => [['product_id' => $setup['burger']->id, 'quantity' => 1]],
            'fulfillment' => OrderFulfillment::Pickup,
        ]);

        $this->assertNotNull($order->sale_id);
        $this->assertSame(SaleStatus::Completed, $order->sale?->status);
        $this->assertSame('25.00', $order->sale?->outstanding_amount);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function completeOrder(array $setup, mixed $user, array $payload): Order
    {
        $service = app(OrderService::class);
        $order = $service->createPublic($setup['company'], array_merge([
            'customer_name' => 'Maria',
            'customer_phone' => '34988887777',
        ], $payload));

        while ($order->canAdvance()) {
            $order = $service->advance($setup['company'], $order, $user);
        }

        return $order->fresh(['items', 'sale']) ?? $order;
    }

    /**
     * @param  array<string, mixed>  $companyAttributes
     * @return array{company: Company, burger: Product, soda: Product}
     */
    protected function createRestaurantSetupWithSales(array $companyAttributes = []): array
    {
        return $this->createRestaurantSetup(array_merge([
            'enabled_modules' => [
                CompanyModule::Orders->value,
                CompanyModule::WhatsApp->value,
                CompanyModule::Sales->value,
            ],
            'slug' => 'cantina-com-vendas',
        ], $companyAttributes));
    }
}
