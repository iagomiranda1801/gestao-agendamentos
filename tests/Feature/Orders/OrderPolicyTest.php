<?php

namespace Tests\Feature\Orders;

use App\Enums\CompanyRole;
use App\Enums\OrderFulfillment;
use App\Filament\App\Pages\KitchenDisplayPage;
use App\Filament\App\Resources\Orders\Pages\ListOrders;
use App\Filament\App\Resources\Orders\Pages\ViewOrder;
use App\Models\Order;
use App\Policies\OrderPolicy;
use App\Services\Orders\OrderService;
use Livewire\Livewire;
use Tests\Concerns\CreatesOrderFixtures;
use Tests\TestCase;

class OrderPolicyTest extends TestCase
{
    use CreatesOrderFixtures;

    public function test_employee_can_use_kitchen_but_cannot_view_order_history_or_pii(): void
    {
        $setup = $this->createRestaurantSetup();
        $employee = $this->createCompanyUser($setup['company'], role: CompanyRole::Employee);
        $order = app(OrderService::class)->createPublic($setup['company'], [
            'items' => [['product_id' => $setup['burger']->id, 'quantity' => 1]],
            'fulfillment' => OrderFulfillment::Pickup,
            'customer_name' => 'Maria Cliente',
            'customer_phone' => '34988887777',
            'customer_email' => 'maria@example.com',
        ]);

        $this->authenticateForAppTenant($employee, $setup['company']);

        $policy = new OrderPolicy;

        $this->assertTrue($policy->viewKitchen($employee));
        $this->assertTrue($employee->can('advance', $order));
        $this->assertTrue($employee->can('update', $order));
        $this->assertFalse($policy->viewAny($employee));
        $this->assertFalse($employee->can('viewAny', Order::class));
        $this->assertFalse($employee->can('view', $order));
        $this->assertFalse($employee->can('cancel', $order));
        $this->assertTrue(KitchenDisplayPage::canAccess());

        Livewire::test(KitchenDisplayPage::class)
            ->assertSuccessful()
            ->assertSee('Maria Cliente', false)
            ->assertDontSee('34988887777', false)
            ->assertDontSee('maria@example.com', false)
            ->call('advanceOrder', $order->id)
            ->assertSuccessful();

        Livewire::test(ListOrders::class)->assertForbidden();
        Livewire::test(ViewOrder::class, ['record' => $order->getKey()])->assertForbidden();
    }

    public function test_user_with_view_orders_can_list_and_view_history(): void
    {
        $setup = $this->createRestaurantSetup();
        $receptionist = $this->createCompanyUser($setup['company'], role: CompanyRole::Receptionist);
        $order = app(OrderService::class)->createPublic($setup['company'], [
            'items' => [['product_id' => $setup['burger']->id, 'quantity' => 1]],
            'fulfillment' => OrderFulfillment::Pickup,
            'customer_name' => 'Maria Cliente',
            'customer_phone' => '34988887777',
            'customer_email' => 'maria@example.com',
        ]);

        $this->authenticateForAppTenant($receptionist, $setup['company']);

        $this->assertTrue($receptionist->can('viewAny', Order::class));
        $this->assertTrue($receptionist->can('view', $order));
        $this->assertTrue($receptionist->can('advance', $order));

        Livewire::test(ListOrders::class)
            ->assertSuccessful()
            ->assertSee('Maria Cliente', false);

        Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
            ->assertSuccessful()
            ->assertSee('maria@example.com', false)
            ->assertSee('34988887777', false);
    }
}
