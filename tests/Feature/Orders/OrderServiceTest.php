<?php

namespace Tests\Feature\Orders;

use App\Enums\OrderFulfillment;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\Orders\CompanyOrderSettingService;
use App\Services\Orders\OrderPublicCodeGenerator;
use App\Services\Orders\OrderService;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesOrderFixtures;
use Tests\TestCase;

class OrderServiceTest extends TestCase
{
    use CreatesOrderFixtures;

    public function test_creates_pickup_order_with_snapshots_and_sequential_number(): void
    {
        $setup = $this->createRestaurantSetup();
        $service = app(OrderService::class);

        $first = $service->createPublic($setup['company'], [
            'items' => [
                ['product_id' => $setup['burger']->id, 'quantity' => 2],
            ],
            'fulfillment' => OrderFulfillment::Pickup,
            'customer_name' => 'Maria Cliente',
            'customer_phone' => '(34) 99999-1111',
        ]);

        $second = $service->createPublic($setup['company'], [
            'items' => [
                ['product_id' => $setup['soda']->id, 'quantity' => 1],
            ],
            'fulfillment' => OrderFulfillment::Pickup,
            'customer_name' => 'João Cliente',
            'customer_phone' => '(34) 99999-2222',
        ]);

        $this->assertSame(1, $first->number);
        $this->assertSame(2, $second->number);
        $this->assertSame(OrderStatus::Received, $first->status);
        $this->assertSame(OrderFulfillment::Pickup, $first->fulfillment);
        $this->assertSame(5000, $first->subtotal_cents);
        $this->assertSame(0, $first->delivery_fee_cents);
        $this->assertSame(5000, $first->total_cents);
        $this->assertSame('X-Burguer', $first->items->first()->name);
        $this->assertSame(2, $first->items->first()->quantity);
        $this->assertSame(2500, $first->items->first()->unit_price_cents);
        $this->assertNotEmpty($first->public_code);
        $this->assertSame(1, $first->statusHistories()->count());
        $this->assertNull($first->table_id);
        $this->assertNull($first->sale_id);
    }

    public function test_creates_delivery_order_with_fee_and_address(): void
    {
        $setup = $this->createRestaurantSetup();

        $order = app(OrderService::class)->createPublic($setup['company'], [
            'items' => [
                ['product_id' => $setup['burger']->id, 'quantity' => 1],
            ],
            'fulfillment' => OrderFulfillment::Delivery,
            'customer_name' => 'Pedro Entrega',
            'customer_phone' => '34988887777',
            'delivery_address' => 'Rua das Flores, 100',
            'delivery_neighborhood' => 'Centro',
            'delivery_city' => 'Uberlândia',
        ]);

        $this->assertSame(OrderFulfillment::Delivery, $order->fulfillment);
        $this->assertSame(2500, $order->subtotal_cents);
        $this->assertSame(800, $order->delivery_fee_cents);
        $this->assertSame(3300, $order->total_cents);
        $this->assertSame('Rua das Flores, 100', $order->delivery_address);
    }

    public function test_rejects_delivery_without_address(): void
    {
        $setup = $this->createRestaurantSetup();

        $this->expectException(ValidationException::class);

        app(OrderService::class)->createPublic($setup['company'], [
            'items' => [['product_id' => $setup['burger']->id, 'quantity' => 1]],
            'fulfillment' => OrderFulfillment::Delivery,
            'customer_name' => 'Sem Endereço',
            'customer_phone' => '34988887777',
        ]);
    }

    public function test_rejects_order_below_minimum(): void
    {
        $setup = $this->createRestaurantSetup(settingAttributes: [
            'min_order_cents' => 4000,
        ]);

        $this->expectException(ValidationException::class);

        app(OrderService::class)->createPublic($setup['company'], [
            'items' => [['product_id' => $setup['soda']->id, 'quantity' => 1]],
            'fulfillment' => OrderFulfillment::Pickup,
            'customer_name' => 'Pedido Pequeno',
            'customer_phone' => '34988887777',
        ]);
    }

    public function test_rejects_item_not_on_online_menu(): void
    {
        $setup = $this->createRestaurantSetup();
        $setup['burger']->update(['available_for_online_order' => false]);

        $this->expectException(ValidationException::class);

        app(OrderService::class)->createPublic($setup['company'], [
            'items' => [['product_id' => $setup['burger']->id, 'quantity' => 1]],
            'fulfillment' => OrderFulfillment::Pickup,
            'customer_name' => 'Maria',
            'customer_phone' => '34988887777',
        ]);
    }

    public function test_reuses_existing_order_for_same_idempotency_key(): void
    {
        $setup = $this->createRestaurantSetup();
        $payload = [
            'items' => [['product_id' => $setup['burger']->id, 'quantity' => 1]],
            'fulfillment' => OrderFulfillment::Pickup,
            'customer_name' => 'Maria',
            'customer_phone' => '34988887777',
            'idempotency_key' => 'same-key-123',
        ];

        $first = app(OrderService::class)->createPublic($setup['company'], $payload);
        $second = app(OrderService::class)->createPublic($setup['company'], $payload);

        $this->assertTrue($first->is($second));
        $this->assertSame(1, Order::query()->where('company_id', $setup['company']->id)->count());
    }

    public function test_retries_when_unique_constraint_collides_on_public_code(): void
    {
        $setup = $this->createRestaurantSetup();
        Order::factory()->forCompany($setup['company'])->create([
            'number' => 1,
            'public_code' => 'ABC-1111',
        ]);

        $this->mock(OrderPublicCodeGenerator::class, function ($mock): void {
            $mock->shouldReceive('generate')->andReturn('ABC-1111', 'ABC-2222');
        });

        $result = app(OrderService::class)->createPublic($setup['company'], [
            'items' => [['product_id' => $setup['burger']->id, 'quantity' => 1]],
            'fulfillment' => OrderFulfillment::Pickup,
            'customer_name' => 'Maria',
            'customer_phone' => '34988887777',
        ]);

        $this->assertSame('ABC-2222', $result->public_code);
        $this->assertSame(2, $result->number);
        $this->assertSame(2, Order::query()->where('company_id', $setup['company']->id)->count());
    }

    public function test_pickup_status_path_reaches_completed(): void
    {
        $setup = $this->createRestaurantSetup();
        $user = $this->createCompanyUser($setup['company']);
        $service = app(OrderService::class);
        $order = $service->createPublic($setup['company'], [
            'items' => [['product_id' => $setup['burger']->id, 'quantity' => 1]],
            'fulfillment' => OrderFulfillment::Pickup,
            'customer_name' => 'Maria',
            'customer_phone' => '34988887777',
        ]);

        $order = $service->advance($setup['company'], $order, $user);
        $this->assertSame(OrderStatus::Preparing, $order->status);
        $this->assertNotNull($order->preparing_at);

        $order = $service->advance($setup['company'], $order, $user);
        $this->assertSame(OrderStatus::Ready, $order->status);

        $order = $service->advance($setup['company'], $order, $user);
        $this->assertSame(OrderStatus::Completed, $order->status);
        $this->assertNotNull($order->completed_at);
        $this->assertFalse($order->canAdvance());
        $this->assertSame(4, $order->statusHistories()->count());
    }

    public function test_delivery_status_path_includes_out_for_delivery(): void
    {
        $setup = $this->createRestaurantSetup();
        $user = $this->createCompanyUser($setup['company']);
        $service = app(OrderService::class);
        $order = $service->createPublic($setup['company'], [
            'items' => [['product_id' => $setup['burger']->id, 'quantity' => 1]],
            'fulfillment' => OrderFulfillment::Delivery,
            'customer_name' => 'Pedro',
            'customer_phone' => '34988887777',
            'delivery_address' => 'Rua A, 10',
        ]);

        $order = $service->advance($setup['company'], $order, $user);
        $order = $service->advance($setup['company'], $order, $user);
        $this->assertSame(OrderStatus::Ready, $order->status);

        $order = $service->advance($setup['company'], $order, $user);
        $this->assertSame(OrderStatus::OutForDelivery, $order->status);

        $order = $service->advance($setup['company'], $order, $user);
        $this->assertSame(OrderStatus::Completed, $order->status);
    }

    public function test_cancel_requires_reason_and_is_terminal(): void
    {
        $setup = $this->createRestaurantSetup();
        $user = $this->createCompanyUser($setup['company']);
        $service = app(OrderService::class);
        $order = $service->createPublic($setup['company'], [
            'items' => [['product_id' => $setup['burger']->id, 'quantity' => 1]],
            'fulfillment' => OrderFulfillment::Pickup,
            'customer_name' => 'Maria',
            'customer_phone' => '34988887777',
        ]);

        try {
            $service->cancel($setup['company'], $order, '', $user);
            $this->fail('Expected validation exception');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('cancel_reason', $exception->errors());
        }

        $cancelled = $service->cancel($setup['company'], $order, 'Cliente desistiu', $user);

        $this->assertSame(OrderStatus::Cancelled, $cancelled->status);
        $this->assertSame('Cliente desistiu', $cancelled->cancel_reason);
        $this->assertFalse($cancelled->canAdvance());

        $this->expectException(ValidationException::class);
        $service->advance($setup['company'], $cancelled, $user);
    }

    public function test_rejects_invalid_status_jump(): void
    {
        $setup = $this->createRestaurantSetup();
        $order = app(OrderService::class)->createPublic($setup['company'], [
            'items' => [['product_id' => $setup['burger']->id, 'quantity' => 1]],
            'fulfillment' => OrderFulfillment::Pickup,
            'customer_name' => 'Maria',
            'customer_phone' => '34988887777',
        ]);

        $this->expectException(ValidationException::class);

        app(OrderService::class)->transition($setup['company'], $order, OrderStatus::Completed);
    }

    public function test_settings_require_at_least_one_fulfillment(): void
    {
        $setup = $this->createRestaurantSetup();

        $this->expectException(ValidationException::class);

        app(CompanyOrderSettingService::class)->update($setup['company'], [
            'pickup_enabled' => false,
            'delivery_enabled' => false,
        ]);
    }
}
