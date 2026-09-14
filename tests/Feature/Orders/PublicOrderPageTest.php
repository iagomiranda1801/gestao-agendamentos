<?php

namespace Tests\Feature\Orders;

use App\Enums\CompanyModule;
use App\Enums\OrderFulfillment;
use App\Enums\OrderStatus;
use App\Livewire\PublicOrders\OrderWizard;
use App\Models\Order;
use App\Services\Orders\CompanyOrderSettingService;
use Livewire\Livewire;
use Tests\Concerns\CreatesOrderFixtures;
use Tests\TestCase;

class PublicOrderPageTest extends TestCase
{
    use CreatesOrderFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_returns_404_when_online_ordering_is_disabled(): void
    {
        $setup = $this->createRestaurantSetup(settingAttributes: [
            'online_ordering_enabled' => false,
        ]);

        $this->get(route('public.orders.show', ['company' => $setup['company']->slug]))
            ->assertNotFound();
    }

    public function test_returns_404_when_company_lacks_orders_module(): void
    {
        $setup = $this->createRestaurantSetup([
            'enabled_modules' => [CompanyModule::Scheduling->value],
        ]);

        $this->get(route('public.orders.show', ['company' => $setup['company']->slug]))
            ->assertNotFound();
    }

    public function test_returns_404_for_inactive_company(): void
    {
        $setup = $this->createRestaurantSetup(['is_active' => false]);

        $this->get(route('public.orders.show', ['company' => $setup['company']->slug]))
            ->assertNotFound();
    }

    public function test_returns_200_when_online_ordering_is_enabled(): void
    {
        $setup = $this->createRestaurantSetup();

        $this->get(route('public.orders.show', ['company' => $setup['company']->slug]))
            ->assertOk()
            ->assertSee('X-Burguer', false)
            ->assertDontSee('<script>', false);
    }

    public function test_page_is_accessible_without_authentication(): void
    {
        $setup = $this->createRestaurantSetup();

        $this->assertGuest();

        $this->get(route('public.orders.show', ['company' => $setup['company']->slug]))
            ->assertOk()
            ->assertSee('Cardápio', false);
    }

    public function test_custom_page_content_is_escaped(): void
    {
        $setup = $this->createRestaurantSetup();
        app(CompanyOrderSettingService::class)->update($setup['company'], [
            'page_title' => '<script>alert("xss")</script>Pedir',
            'page_description' => '<img src=x onerror=alert(1)>Descrição',
        ]);

        $response = $this->get(route('public.orders.show', ['company' => $setup['company']->slug]));

        $response->assertOk();
        $response->assertDontSee('<script>alert("xss")</script>', false);
        $response->assertDontSee('onerror=alert(1)', false);
        $response->assertSee('Pedir', false);
        $response->assertSee('Descrição', false);
    }

    public function test_livewire_wizard_completes_pickup_order(): void
    {
        $setup = $this->createRestaurantSetup();

        Livewire::test(OrderWizard::class, ['company' => $setup['company']])
            ->assertSet('step', OrderWizard::STEP_MENU)
            ->call('addToCart', $setup['burger']->id)
            ->call('goToFulfillment')
            ->assertSet('step', OrderWizard::STEP_FULFILLMENT)
            ->call('selectFulfillment', OrderFulfillment::Pickup->value)
            ->assertSet('step', OrderWizard::STEP_CUSTOMER)
            ->set('customerName', 'Maria Cliente')
            ->set('customerPhone', '(34) 99999-1111')
            ->call('goToReview')
            ->assertSet('step', OrderWizard::STEP_REVIEW)
            ->call('submit')
            ->assertHasNoErrors()
            ->assertSet('step', OrderWizard::STEP_CONFIRMATION);

        $order = Order::query()->where('company_id', $setup['company']->id)->first();

        $this->assertNotNull($order);
        $this->assertSame(OrderFulfillment::Pickup, $order->fulfillment);
        $this->assertSame(OrderStatus::Received, $order->status);
        $this->assertSame('Maria Cliente', $order->customer_name);
        $this->assertSame(2500, $order->total_cents);
    }

    public function test_double_submit_with_same_idempotency_key_does_not_fail(): void
    {
        $setup = $this->createRestaurantSetup();

        $component = Livewire::test(OrderWizard::class, ['company' => $setup['company']])
            ->call('addToCart', $setup['burger']->id)
            ->call('goToFulfillment')
            ->call('selectFulfillment', OrderFulfillment::Pickup->value)
            ->set('customerName', 'Maria Cliente')
            ->set('customerPhone', '(34) 99999-1111')
            ->call('goToReview')
            ->call('submit')
            ->assertHasNoErrors()
            ->assertSet('step', OrderWizard::STEP_CONFIRMATION);

        $component
            ->set('isSubmitting', false)
            ->call('submit')
            ->assertHasNoErrors()
            ->assertSet('step', OrderWizard::STEP_CONFIRMATION)
            ->assertSet('errorMessage', null);

        $this->assertSame(1, Order::query()->where('company_id', $setup['company']->id)->count());
    }

    public function test_livewire_wizard_completes_delivery_order(): void
    {
        $setup = $this->createRestaurantSetup();

        Livewire::test(OrderWizard::class, ['company' => $setup['company']])
            ->call('addToCart', $setup['burger']->id)
            ->call('incrementItem', $setup['soda']->id)
            ->call('goToFulfillment')
            ->call('selectFulfillment', OrderFulfillment::Delivery->value)
            ->set('customerName', 'Pedro Entrega')
            ->set('customerPhone', '34988887777')
            ->set('deliveryAddress', 'Rua das Flores, 100')
            ->set('deliveryCity', 'Uberlândia')
            ->call('goToReview')
            ->call('submit')
            ->assertHasNoErrors()
            ->assertSet('step', OrderWizard::STEP_CONFIRMATION);

        $order = Order::query()->where('company_id', $setup['company']->id)->first();

        $this->assertNotNull($order);
        $this->assertSame(OrderFulfillment::Delivery, $order->fulfillment);
        $this->assertSame(2500 + 800 + 800, $order->total_cents);
        $this->assertSame('Rua das Flores, 100', $order->delivery_address);
    }

    public function test_fulfillment_step_shows_dine_in_when_enabled(): void
    {
        $setup = $this->createRestaurantSetup();

        Livewire::test(OrderWizard::class, ['company' => $setup['company']])
            ->call('addToCart', $setup['burger']->id)
            ->call('goToFulfillment')
            ->assertSee('Retirada', false)
            ->assertSee('Entrega', false)
            ->assertSee('Comer no local', false);
    }

    public function test_fulfillment_step_hides_dine_in_when_disabled(): void
    {
        $setup = $this->createRestaurantSetup(settingAttributes: [
            'dine_in_enabled' => false,
        ]);

        Livewire::test(OrderWizard::class, ['company' => $setup['company']])
            ->call('addToCart', $setup['burger']->id)
            ->call('goToFulfillment')
            ->assertSee('Retirada', false)
            ->assertSee('Entrega', false)
            ->assertDontSee('Comer no local', false)
            ->call('selectFulfillment', OrderFulfillment::DineIn->value)
            ->assertSet('step', OrderWizard::STEP_FULFILLMENT)
            ->assertSet('errorMessage', 'Escolha uma opção disponível.');
    }

    public function test_livewire_wizard_completes_dine_in_order_without_address(): void
    {
        $setup = $this->createRestaurantSetup();

        Livewire::test(OrderWizard::class, ['company' => $setup['company']])
            ->call('addToCart', $setup['burger']->id)
            ->call('goToFulfillment')
            ->call('selectFulfillment', OrderFulfillment::DineIn->value)
            ->assertSet('step', OrderWizard::STEP_CUSTOMER)
            ->assertDontSee('Endereço', false)
            ->set('customerName', 'Ana Local')
            ->set('customerPhone', '34988887777')
            ->call('goToReview')
            ->assertSee('Comer no local', false)
            ->assertSee('Pague no local.', false)
            ->call('submit')
            ->assertHasNoErrors()
            ->assertSet('step', OrderWizard::STEP_CONFIRMATION);

        $order = Order::query()->where('company_id', $setup['company']->id)->first();

        $this->assertNotNull($order);
        $this->assertSame(OrderFulfillment::DineIn, $order->fulfillment);
        $this->assertSame(OrderStatus::Received, $order->status);
        $this->assertSame('Ana Local', $order->customer_name);
        $this->assertNull($order->table_id);
        $this->assertNull($order->delivery_address);
        $this->assertSame(0, $order->delivery_fee_cents);
        $this->assertSame(2500, $order->total_cents);
    }
}
