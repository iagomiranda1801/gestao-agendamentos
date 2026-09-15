<?php

namespace Tests\Feature\Orders;

use App\Enums\CompanyRole;
use App\Enums\OrderFulfillment;
use App\Enums\OrderStatus;
use App\Filament\App\Pages\KitchenDisplayPage;
use App\Filament\App\Resources\Orders\Pages\ListOrders;
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
}
