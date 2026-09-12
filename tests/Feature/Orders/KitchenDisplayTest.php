<?php

namespace Tests\Feature\Orders;

use App\Enums\CompanyRole;
use App\Enums\OrderFulfillment;
use App\Enums\OrderStatus;
use App\Filament\App\Pages\KitchenDisplayPage;
use App\Filament\App\Resources\Orders\Pages\ListOrders;
use App\Services\Orders\OrderService;
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
