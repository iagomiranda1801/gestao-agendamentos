<?php

namespace Tests\Feature\Orders;

use App\Enums\CompanyRole;
use App\Enums\OrderFulfillment;
use App\Enums\OrderStatus;
use App\Filament\App\Pages\KitchenDisplayPage;
use App\Filament\App\Resources\Orders\Pages\ListOrders;
use App\Models\DiningTable;
use App\Models\Order;
use App\Services\Orders\CompanyOrderSettingService;
use App\Services\Orders\OrderService;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Concerns\CreatesOrderFixtures;
use Tests\TestCase;

class KitchenDisplayTest extends TestCase
{
    use CreatesOrderFixtures;

    public function test_kitchen_lists_received_order_and_advances_status(): void
    {
        $setup = $this->createRestaurantSetup();
        $admin = $this->createCompanyUser($setup['company']);
        $order = app(OrderService::class)->createPublic($setup['company'], [
            'items' => [['product_id' => $setup['burger']->id, 'quantity' => 1]],
            'fulfillment' => OrderFulfillment::Pickup,
            'customer_name' => 'Maria Cliente',
            'customer_phone' => '34988887777',
        ]);

        $this->authenticateForAppTenant($admin, $setup['company']);

        Livewire::test(KitchenDisplayPage::class)
            ->assertSuccessful()
            ->assertSee('Maria Cliente', false)
            ->assertSee('X-Burguer', false)
            ->call('advanceOrder', $order->id)
            ->assertSuccessful();

        $this->assertSame(OrderStatus::Preparing, $order->fresh()->status);
    }

    public function test_kitchen_keeps_order_in_ready_column_after_advance(): void
    {
        $setup = $this->createRestaurantSetup();
        $admin = $this->createCompanyUser($setup['company']);
        $order = app(OrderService::class)->createPublic($setup['company'], [
            'items' => [['product_id' => $setup['burger']->id, 'quantity' => 1]],
            'fulfillment' => OrderFulfillment::Pickup,
            'customer_name' => 'Maria Pronto',
            'customer_phone' => '34988887777',
        ]);

        $this->authenticateForAppTenant($admin, $setup['company']);

        $page = Livewire::test(KitchenDisplayPage::class)
            ->assertSuccessful()
            ->call('advanceOrder', $order->id)
            ->assertSuccessful()
            ->call('advanceOrder', $order->id)
            ->assertSuccessful()
            ->assertSee('Maria Pronto', false);

        $this->assertSame(OrderStatus::Ready, $order->fresh()->status);

        $readyOrders = $page->instance()->kitchenOrders()->get(OrderStatus::Ready->value);

        $this->assertNotNull($readyOrders);
        $this->assertTrue(
            $readyOrders->contains(fn (Order $listed): bool => (int) $listed->getKey() === (int) $order->getKey()),
        );
        $this->assertSame(
            route('public.orders.show', ['company' => $setup['company']->slug]),
            $page->instance()->publicOrderingUrl(),
        );

        Filament::setTenant(null, isQuiet: true);

        $this->assertTrue(
            $page->instance()->kitchenOrders()->get(OrderStatus::Ready->value)
                ->contains(fn (Order $listed): bool => (int) $listed->getKey() === (int) $order->getKey()),
        );

        $this->withoutVite();
        $this->get(KitchenDisplayPage::getUrl(['tenant' => $setup['company']]))
            ->assertOk()
            ->assertSee('Maria Pronto', false);
    }

    public function test_kitchen_advance_to_ready_stays_ok_when_online_ordering_is_disabled(): void
    {
        $setup = $this->createRestaurantSetup();
        $admin = $this->createCompanyUser($setup['company']);
        $order = app(OrderService::class)->createPublic($setup['company'], [
            'items' => [['product_id' => $setup['burger']->id, 'quantity' => 1]],
            'fulfillment' => OrderFulfillment::Pickup,
            'customer_name' => 'Ana Cozinha',
            'customer_phone' => '34988887777',
        ]);

        app(CompanyOrderSettingService::class)->update($setup['company'], [
            'online_ordering_enabled' => false,
        ]);
        $setup['company']->unsetRelation('orderSetting');

        $this->authenticateForAppTenant($admin, $setup['company']->fresh(['orderSetting']));

        $page = Livewire::test(KitchenDisplayPage::class)
            ->assertSuccessful()
            ->call('advanceOrder', $order->id)
            ->assertSuccessful()
            ->call('advanceOrder', $order->id)
            ->assertSuccessful()
            ->assertSee('Ana Cozinha', false);

        $this->assertSame(OrderStatus::Ready, $order->fresh()->status);
        $this->assertNull($page->instance()->publicOrderingUrl());
        $this->assertTrue(
            $page->instance()->kitchenOrders()->get(OrderStatus::Ready->value)
                ->contains(fn (Order $listed): bool => (int) $listed->getKey() === (int) $order->getKey()),
        );

        $this->withoutVite();
        $this->get(route('public.orders.show', ['company' => $setup['company']->slug]))
            ->assertNotFound();

        $this->get(KitchenDisplayPage::getUrl(['tenant' => $setup['company']]))
            ->assertOk()
            ->assertSee('Ana Cozinha', false);
    }

    public function test_public_ordering_url_is_not_generated_without_tenant(): void
    {
        $setup = $this->createRestaurantSetup();
        $admin = $this->createCompanyUser($setup['company']);
        $this->authenticateForAppTenant($admin, $setup['company']);

        $page = Livewire::test(KitchenDisplayPage::class)->assertSuccessful();

        Filament::setTenant(null, isQuiet: true);

        $this->assertNull($page->instance()->publicOrderingUrl());
    }

    public function test_kitchen_can_complete_delivery_path(): void
    {
        $setup = $this->createRestaurantSetup();
        $admin = $this->createCompanyUser($setup['company']);
        $service = app(OrderService::class);
        $order = $service->createPublic($setup['company'], [
            'items' => [['product_id' => $setup['burger']->id, 'quantity' => 1]],
            'fulfillment' => OrderFulfillment::Delivery,
            'customer_name' => 'Pedro Entrega',
            'customer_phone' => '34988887777',
            'delivery_address' => 'Rua A, 10',
        ]);

        $this->authenticateForAppTenant($admin, $setup['company']);

        $page = Livewire::test(KitchenDisplayPage::class);
        $page->call('advanceOrder', $order->id);
        $page->call('advanceOrder', $order->id);
        $page->call('advanceOrder', $order->id);
        $this->assertSame(OrderStatus::OutForDelivery, $order->fresh()->status);
        $page->call('advanceOrder', $order->id);

        $this->assertSame(OrderStatus::Completed, $order->fresh()->status);
    }

    public function test_kitchen_can_complete_dine_in_path_and_shows_label(): void
    {
        $setup = $this->createRestaurantSetup();
        $admin = $this->createCompanyUser($setup['company']);
        $service = app(OrderService::class);
        $order = $service->createPublic($setup['company'], [
            'items' => [['product_id' => $setup['burger']->id, 'quantity' => 1]],
            'fulfillment' => OrderFulfillment::DineIn,
            'customer_name' => 'Ana Local',
            'customer_phone' => '34988887777',
        ]);

        $this->authenticateForAppTenant($admin, $setup['company']);

        $page = Livewire::test(KitchenDisplayPage::class)
            ->assertSuccessful()
            ->assertSee('Ana Local', false)
            ->assertSee('Comer no local', false);

        $page->call('advanceOrder', $order->id);
        $page->call('advanceOrder', $order->id);
        $this->assertSame(OrderStatus::Ready, $order->fresh()->status);

        $page->call('advanceOrder', $order->id);
        $this->assertSame(OrderStatus::Completed, $order->fresh()->status);
        $this->assertNull($order->fresh()->out_for_delivery_at);
    }

    public function test_kitchen_cancel_requires_reason(): void
    {
        $setup = $this->createRestaurantSetup();
        $admin = $this->createCompanyUser($setup['company']);
        $order = app(OrderService::class)->createPublic($setup['company'], [
            'items' => [['product_id' => $setup['burger']->id, 'quantity' => 1]],
            'fulfillment' => OrderFulfillment::Pickup,
            'customer_name' => 'Maria',
            'customer_phone' => '34988887777',
        ]);

        $this->authenticateForAppTenant($admin, $setup['company']);

        Livewire::test(KitchenDisplayPage::class)
            ->call('startCancel', $order->id)
            ->set('cancelReason', 'Acabou o pão')
            ->call('confirmCancel')
            ->assertHasNoErrors();

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
        $this->assertSame('Acabou o pão', $order->fresh()->cancel_reason);
    }

    public function test_order_history_lists_orders(): void
    {
        $setup = $this->createRestaurantSetup();
        $admin = $this->createCompanyUser($setup['company']);
        app(OrderService::class)->createPublic($setup['company'], [
            'items' => [['product_id' => $setup['burger']->id, 'quantity' => 1]],
            'fulfillment' => OrderFulfillment::Pickup,
            'customer_name' => 'Maria Histórico',
            'customer_phone' => '34988887777',
        ]);

        $this->authenticateForAppTenant($admin, $setup['company']);

        Livewire::test(ListOrders::class)
            ->assertSuccessful()
            ->assertSee('Maria Histórico', false);
    }

    public function test_order_history_shows_dine_in_label(): void
    {
        $setup = $this->createRestaurantSetup();
        $admin = $this->createCompanyUser($setup['company']);
        app(OrderService::class)->createPublic($setup['company'], [
            'items' => [['product_id' => $setup['burger']->id, 'quantity' => 1]],
            'fulfillment' => OrderFulfillment::DineIn,
            'customer_name' => 'Ana Histórico',
            'customer_phone' => '34988887777',
        ]);

        $this->authenticateForAppTenant($admin, $setup['company']);

        Livewire::test(ListOrders::class)
            ->assertSuccessful()
            ->assertSee('Ana Histórico', false)
            ->assertSee('Comer no local', false);
    }

    public function test_employee_cannot_cancel_from_kitchen(): void
    {
        $setup = $this->createRestaurantSetup();
        $employee = $this->createCompanyUser($setup['company'], role: CompanyRole::Employee);
        $order = app(OrderService::class)->createPublic($setup['company'], [
            'items' => [['product_id' => $setup['burger']->id, 'quantity' => 1]],
            'fulfillment' => OrderFulfillment::Pickup,
            'customer_name' => 'Maria',
            'customer_phone' => '34988887777',
        ]);

        $this->authenticateForAppTenant($employee, $setup['company']);

        Livewire::test(KitchenDisplayPage::class)
            ->call('startCancel', $order->id)
            ->assertForbidden();
    }

    public function test_kitchen_tabs_count_active_orders_and_filter_by_fulfillment(): void
    {
        $setup = $this->createRestaurantSetup();
        $admin = $this->createCompanyUser($setup['company']);
        $service = app(OrderService::class);

        $pickup = $service->createPublic($setup['company'], [
            'items' => [['product_id' => $setup['burger']->id, 'quantity' => 1]],
            'fulfillment' => OrderFulfillment::Pickup,
            'customer_name' => 'Cliente Retirada',
            'customer_phone' => '34988887771',
        ]);
        $delivery = $service->createPublic($setup['company'], [
            'items' => [['product_id' => $setup['burger']->id, 'quantity' => 1]],
            'fulfillment' => OrderFulfillment::Delivery,
            'customer_name' => 'Cliente Entrega',
            'customer_phone' => '34988887772',
            'delivery_address' => 'Rua das Flores, 100',
            'delivery_neighborhood' => 'Centro',
            'delivery_city' => 'Uberlândia',
        ]);
        $dineIn = $service->createPublic($setup['company'], [
            'items' => [['product_id' => $setup['burger']->id, 'quantity' => 1]],
            'fulfillment' => OrderFulfillment::DineIn,
            'customer_name' => 'Cliente Local',
            'customer_phone' => '34988887773',
        ]);
        $table = DiningTable::factory()->forCompany($setup['company'])->create([
            'number' => 4,
            'name' => null,
        ]);
        $dineIn->forceFill(['table_id' => $table->getKey()])->save();

        $completed = $service->createPublic($setup['company'], [
            'items' => [['product_id' => $setup['burger']->id, 'quantity' => 1]],
            'fulfillment' => OrderFulfillment::Pickup,
            'customer_name' => 'Cliente Concluído',
            'customer_phone' => '34988887774',
        ]);
        $completed->forceFill([
            'status' => OrderStatus::Completed,
            'completed_at' => now(),
        ])->save();

        $this->assertSame(OrderStatus::Completed, $completed->fresh()->status);

        $this->authenticateForAppTenant($admin, $setup['company']);

        $page = Livewire::test(KitchenDisplayPage::class)
            ->assertSuccessful()
            ->assertSet('fulfillmentFilter', KitchenDisplayPage::FULFILLMENT_FILTER_ALL)
            ->assertSee('Todos', false)
            ->assertSee('Cliente Retirada', false)
            ->assertSee('Cliente Entrega', false)
            ->assertSee('Cliente Local', false)
            ->assertDontSee('Cliente Concluído', false)
            ->assertSee('Saiu para entrega', false)
            ->assertSee('Rua das Flores, 100', false)
            ->assertSee('Mesa 4', false);

        $this->assertSame([
            ['value' => 'todos', 'label' => 'Todos', 'count' => 3],
            ['value' => 'delivery', 'label' => 'Entrega', 'count' => 1],
            ['value' => 'pickup', 'label' => 'Retirada', 'count' => 1],
            ['value' => 'dine_in', 'label' => 'Comer no local', 'count' => 1],
        ], $page->instance()->fulfillmentTabs());

        $page->call('setFulfillmentFilter', OrderFulfillment::Pickup->value)
            ->assertSet('fulfillmentFilter', 'pickup')
            ->assertSee('Cliente Retirada', false)
            ->assertDontSee('Cliente Entrega', false)
            ->assertDontSee('Cliente Local', false)
            ->assertDontSee('Saiu para entrega', false);

        $this->assertSame(
            [OrderStatus::Received, OrderStatus::Preparing, OrderStatus::Ready],
            $page->instance()->visibleKitchenColumns(),
        );
        $this->assertArrayNotHasKey(
            OrderStatus::OutForDelivery->value,
            $page->instance()->kitchenOrders()->all(),
        );

        $page->call('setFulfillmentFilter', OrderFulfillment::Delivery->value)
            ->assertSet('fulfillmentFilter', 'delivery')
            ->assertSee('Cliente Entrega', false)
            ->assertSee('Saiu para entrega', false)
            ->assertDontSee('Cliente Retirada', false)
            ->assertDontSee('Cliente Local', false);

        $page->call('setFulfillmentFilter', OrderFulfillment::DineIn->value)
            ->assertSet('fulfillmentFilter', 'dine_in')
            ->assertSee('Cliente Local', false)
            ->assertSee('Mesa 4', false)
            ->assertDontSee('Saiu para entrega', false)
            ->assertDontSee('Cliente Entrega', false);
    }

    public function test_kitchen_fulfillment_filter_is_restored_from_query_string(): void
    {
        $setup = $this->createRestaurantSetup();
        $admin = $this->createCompanyUser($setup['company']);
        $service = app(OrderService::class);

        $service->createPublic($setup['company'], [
            'items' => [['product_id' => $setup['burger']->id, 'quantity' => 1]],
            'fulfillment' => OrderFulfillment::Pickup,
            'customer_name' => 'Só Retirada',
            'customer_phone' => '34988887775',
        ]);
        $service->createPublic($setup['company'], [
            'items' => [['product_id' => $setup['burger']->id, 'quantity' => 1]],
            'fulfillment' => OrderFulfillment::Delivery,
            'customer_name' => 'Só Entrega',
            'customer_phone' => '34988887776',
            'delivery_address' => 'Av. Brasil, 50',
        ]);

        $this->authenticateForAppTenant($admin, $setup['company']);

        Livewire::withQueryParams(['tipo' => 'pickup'])
            ->test(KitchenDisplayPage::class)
            ->assertSuccessful()
            ->assertSet('fulfillmentFilter', 'pickup')
            ->assertSee('Só Retirada', false)
            ->assertDontSee('Só Entrega', false)
            ->assertDontSee('Saiu para entrega', false);

        Livewire::withQueryParams(['tipo' => 'invalido'])
            ->test(KitchenDisplayPage::class)
            ->assertSuccessful()
            ->assertSet('fulfillmentFilter', KitchenDisplayPage::FULFILLMENT_FILTER_ALL)
            ->assertSee('Só Retirada', false)
            ->assertSee('Só Entrega', false);
    }
}
