<?php

namespace Tests\Feature\Orders;

use App\Enums\CompanyModule;
use App\Enums\OrderChannel;
use App\Enums\OrderFulfillment;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\SaleOrigin;
use App\Enums\SaleStatus;
use App\Filament\App\Pages\FloorServicePage;
use App\Filament\App\Pages\KitchenDisplayPage;
use App\Models\DiningTable;
use App\Models\FinancialAccount;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Sale;
use App\Services\Orders\CompanyOrderSettingService;
use App\Services\Orders\FloorOrderService;
use App\Services\Orders\OrderService;
use Filament\Facades\Filament;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Concerns\CreatesOrderFixtures;
use Tests\TestCase;

class FloorServiceTest extends TestCase
{
    use CreatesOrderFixtures;

    public function test_opens_tab_adds_and_removes_items_then_closes_and_frees_the_table(): void
    {
        $setup = $this->createRestaurantSetup();
        $user = $this->createCompanyUser($setup['company']);
        $table = DiningTable::factory()->forCompany($setup['company'])->create([
            'number' => 1,
            'name' => 'Janela',
        ]);
        $floor = app(FloorOrderService::class);

        $order = $floor->open($setup['company'], $table, $user, 'Ana');

        $this->assertSame(OrderChannel::Floor, $order->channel);
        $this->assertSame(OrderFulfillment::DineIn, $order->fulfillment);
        $this->assertSame(OrderStatus::Received, $order->status);
        $this->assertSame('Ana', $order->customer_name);
        $this->assertNull($order->customer_phone);
        $this->assertSame($table->id, $order->table_id);
        $this->assertNotNull($table->fresh()->currentOrder());
        $this->assertSame('Mesa 1 — Janela', $order->diningTableLabel());

        $order = $floor->addItem($setup['company'], $order, $setup['burger']->id, 2, 'sem gelo', user: $user);

        $this->assertCount(1, $order->items);
        $this->assertSame(5000, $order->total_cents);
        $this->assertSame('sem gelo', $order->items->first()->notes);
        $this->assertSame('X-Burguer', $order->items->first()->name);

        $item = $order->items->first();
        $this->assertNotNull($item);
        $order = $floor->removeItem($setup['company'], $order, $item);

        $this->assertCount(0, $order->items);
        $this->assertSame(0, $order->total_cents);
        $this->assertNotNull($table->fresh()->currentOrder());

        $order = $floor->addItem($setup['company'], $order, $setup['soda']->id, 1, user: $user);

        try {
            $floor->close($setup['company'], $order->fresh(), 'nope', $user);
            $this->fail('Forma de pagamento inválida deveria falhar.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('payment_method', $exception->errors());
        }

        $closed = $floor->close($setup['company'], $order->fresh(), PaymentMethod::Pix, $user);

        $this->assertSame(OrderStatus::Completed, $closed->status);
        $this->assertSame(PaymentMethod::Pix, $closed->payment_method);
        $this->assertNull($closed->sale_id);
        $this->assertSame(0, Sale::query()->where('company_id', $setup['company']->id)->count());
        $this->assertNull($table->fresh()->currentOrder());

        $again = $floor->open($setup['company'], $table->fresh(), $user);
        $this->assertTrue($again->isOpen());
        $this->assertNotSame($closed->id, $again->id);
    }

    public function test_floor_page_operates_the_tab_and_the_kitchen_shows_the_table(): void
    {
        $setup = $this->createRestaurantSetup();
        $user = $this->createCompanyUser($setup['company']);
        $table = DiningTable::factory()->forCompany($setup['company'])->create([
            'number' => 3,
            'name' => null,
        ]);

        $this->authenticateForAppTenant($user, $setup['company']);

        $page = Livewire::test(FloorServicePage::class)
            ->assertSuccessful()
            ->assertSee('Mesa 3', false)
            ->assertSee('Livre', false)
            ->call('selectTable', $table->id)
            ->call('openTab')
            ->assertSee('Ocupada', false)
            ->set('productId', (string) $setup['burger']->id)
            ->set('quantity', 1)
            ->set('itemNotes', 'ponto da carne')
            ->call('addItem')
            ->assertSee('X-Burguer', false)
            ->assertSee('ponto da carne', false);

        $order = Order::query()->where('table_id', $table->id)->first();
        $this->assertNotNull($order);
        $itemId = $order->items()->value('id');

        $page->call('removeItem', $itemId)
            ->assertSee('Nenhum item ainda', false)
            ->set('productId', (string) $setup['burger']->id)
            ->call('addItem')
            ->set('paymentMethod', PaymentMethod::Cash->value)
            ->call('closeTab')
            ->assertSee('Livre', false);

        $this->assertSame(OrderStatus::Completed, $order->fresh()->status);
        $this->assertNull($table->fresh()->currentOrder());

        $open = app(FloorOrderService::class)->open($setup['company'], $table->fresh(), $user);
        app(FloorOrderService::class)->addItem($setup['company'], $open, $setup['burger']->id, 1, user: $user);

        Livewire::test(KitchenDisplayPage::class)
            ->assertSuccessful()
            ->assertSee('Mesa 3', false)
            ->assertSee('X-Burguer', false);
    }

    public function test_kitchen_stops_before_completion_and_a_new_item_returns_the_tab_to_received(): void
    {
        $setup = $this->createRestaurantSetup();
        $user = $this->createCompanyUser($setup['company']);
        $table = DiningTable::factory()->forCompany($setup['company'])->create(['number' => 2]);
        $floor = app(FloorOrderService::class);
        $orders = app(OrderService::class);

        $order = $floor->open($setup['company'], $table, $user);
        $order = $floor->addItem($setup['company'], $order, $setup['burger']->id, 1, user: $user);
        $order = $orders->advance($setup['company'], $order, $user);
        $order = $orders->advance($setup['company'], $order, $user);

        $this->assertSame(OrderStatus::Ready, $order->status);
        $this->assertNull($order->nextStatus());

        try {
            $orders->advance($setup['company'], $order, $user);
            $this->fail('A cozinha não fecha a comanda.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
        }

        $reopened = $floor->addItem($setup['company'], $order->fresh(), $setup['soda']->id, 1, user: $user);

        $this->assertSame(OrderStatus::Received, $reopened->status);
        $this->assertSame(3300, $reopened->total_cents);
        $this->assertCount(2, $reopened->items);
    }

    public function test_closing_with_sales_module_creates_a_sale_and_pays_it_on_the_default_account(): void
    {
        $setup = $this->createRestaurantSetup([
            'enabled_modules' => [
                CompanyModule::Orders->value,
                CompanyModule::Sales->value,
                CompanyModule::Finance->value,
            ],
            'slug' => 'bar-com-venda',
        ]);
        $user = $this->createCompanyUser($setup['company']);
        FinancialAccount::factory()->forCompany($setup['company'])->create([
            'name' => 'Caixa do bar',
            'is_default_receipt_account' => true,
            'allow_negative_balance' => true,
        ]);
        $table = DiningTable::factory()->forCompany($setup['company'])->create(['number' => 8]);
        $floor = app(FloorOrderService::class);

        $order = $floor->open($setup['company'], $table, $user);
        $order = $floor->addItem($setup['company'], $order, $setup['burger']->id, 1, user: $user);
        $closed = $floor->close($setup['company'], $order, PaymentMethod::CreditCard, $user);

        $sale = $closed->sale()->with(['items', 'payments'])->first();

        $this->assertNotNull($sale);
        $this->assertSame(SaleOrigin::FloorOrder, $sale->origin);
        $this->assertSame(SaleStatus::Paid, $sale->status);
        $this->assertSame('25.00', $sale->final_amount);
        $this->assertSame('25.00', $sale->paid_amount);
        $this->assertSame('0.00', $sale->outstanding_amount);
        $this->assertSame(PaymentMethod::CreditCard, $sale->payments->first()?->method);
        $this->assertStringContainsString('Comanda', (string) $sale->notes);
        $this->assertStringContainsString('Mesa 8', (string) $sale->notes);
        $this->assertNull($table->fresh()->currentOrder());
    }

    public function test_companies_do_not_share_tables_tabs_or_receipts(): void
    {
        $setup = $this->createRestaurantSetup(['document' => '12345678000195']);
        $other = $this->createRestaurantSetup([
            'slug' => 'outro-bar',
            'name' => 'Outro Bar',
        ]);
        $user = $this->createCompanyUser($setup['company']);
        $intruder = $this->createCompanyUser($other['company']);
        $table = DiningTable::factory()->forCompany($setup['company'])->create([
            'number' => 5,
            'name' => 'Exclusiva',
        ]);
        $foreignTable = DiningTable::factory()->forCompany($other['company'])->create([
            'number' => 9,
            'name' => 'Vizinha',
        ]);
        $floor = app(FloorOrderService::class);
        $order = $floor->open($setup['company'], $table, $user, 'Cliente A');
        $order = $floor->addItem($setup['company'], $order, $setup['burger']->id, 1, 'bem passado', user: $user);

        try {
            $floor->open($other['company'], $table, $intruder);
            $this->fail('Mesa de outra empresa não pode ser aberta.');
        } catch (NotFoundHttpException) {
            $this->assertTrue(true);
        }

        try {
            $floor->addItem($other['company'], $order, $other['burger']->id, 1, user: $intruder);
            $this->fail('Comanda de outra empresa não pode receber item.');
        } catch (NotFoundHttpException) {
            $this->assertTrue(true);
        }

        try {
            $floor->addItem($setup['company'], $order, $other['burger']->id, 1, user: $user);
            $this->fail('Produto de outra empresa não entra na comanda.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('product_id', $exception->errors());
        }

        $this->authenticateForAppTenant($intruder, $other['company']);

        Livewire::test(FloorServicePage::class)
            ->assertSuccessful()
            ->assertSee('Mesa 9 — Vizinha', false)
            ->assertDontSee('Exclusiva', false);

        Filament::setTenant(null, isQuiet: true);

        $this->actingAs($intruder)
            ->get(route('orders.receipt', ['company' => $setup['company'], 'order' => $order]))
            ->assertForbidden();

        $foreignOrder = $floor->open($other['company'], $foreignTable, $intruder);
        $foreignOrder = $floor->addItem($other['company'], $foreignOrder, $other['soda']->id, 1, user: $intruder);

        $this->actingAs($user)
            ->get(route('orders.receipt', ['company' => $setup['company'], 'order' => $foreignOrder]))
            ->assertNotFound();

        $this->actingAs($user)
            ->get(route('orders.receipt', ['company' => $setup['company'], 'order' => $order]))
            ->assertOk()
            ->assertSee('Cupom não fiscal')
            ->assertSee($setup['company']->name)
            ->assertSee('12.345.678/0001-95')
            ->assertSee('Mesa 5 — Exclusiva')
            ->assertSee('X-Burguer')
            ->assertSee('bem passado')
            ->assertSee('R$ 25,00')
            ->assertSee('Conferência da conta')
            ->assertSee('ainda não paga')
            ->assertSee('Este documento não tem valor fiscal.');

        $closed = $floor->close($setup['company'], $order->fresh(), PaymentMethod::Pix, $user);

        $this->actingAs($user)
            ->get(route('orders.receipt', ['company' => $setup['company'], 'order' => $closed]))
            ->assertOk()
            ->assertSee('Pagamento: PIX')
            ->assertDontSee('ainda não paga');
    }

    public function test_receipt_width_follows_the_company_setting(): void
    {
        $setup = $this->createRestaurantSetup();
        $user = $this->createCompanyUser($setup['company']);
        $table = DiningTable::factory()->forCompany($setup['company'])->create(['number' => 1]);
        $order = app(FloorOrderService::class)->open($setup['company'], $table, $user);

        app(CompanyOrderSettingService::class)->update($setup['company'], [
            'receipt_paper_mm' => 58,
        ]);

        $this->actingAs($user)
            ->get(route('orders.receipt', ['company' => $setup['company'], 'order' => $order]))
            ->assertOk()
            ->assertSee('size: 58mm', false);

        app(CompanyOrderSettingService::class)->update($setup['company'], [
            'receipt_paper_mm' => 80,
        ]);

        $this->actingAs($user)
            ->get(route('orders.receipt', ['company' => $setup['company'], 'order' => $order->fresh()]))
            ->assertOk()
            ->assertSee('size: 80mm', false);
    }

    public function test_inactive_table_empty_tab_and_public_orders_cannot_be_misused(): void
    {
        $setup = $this->createRestaurantSetup();
        $user = $this->createCompanyUser($setup['company']);
        $floor = app(FloorOrderService::class);
        $inactive = DiningTable::factory()->forCompany($setup['company'])->inactive()->create(['number' => 6]);

        try {
            $floor->open($setup['company'], $inactive, $user);
            $this->fail('Mesa inativa não abre comanda.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('table', $exception->errors());
        }

        $table = DiningTable::factory()->forCompany($setup['company'])->create(['number' => 7]);
        $empty = $floor->open($setup['company'], $table, $user);

        try {
            $floor->close($setup['company'], $empty, PaymentMethod::Pix, $user);
            $this->fail('Comanda vazia não fecha.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('items', $exception->errors());
        }

        $this->assertTrue($empty->fresh()->isOpen());

        $public = app(OrderService::class)->createPublic($setup['company'], [
            'items' => [['product_id' => $setup['burger']->id, 'quantity' => 1]],
            'fulfillment' => OrderFulfillment::DineIn,
            'customer_name' => 'Maria',
            'customer_phone' => '34988887777',
        ]);
        $publicItem = $public->items->first();
        $this->assertInstanceOf(OrderItem::class, $publicItem);

        try {
            $floor->removeItem($setup['company'], $public, $publicItem);
            $this->fail('Pedido online não pode ter item removido.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('order', $exception->errors());
        }

        $this->assertSame(1, $public->fresh()->items()->count());
    }

    public function test_floor_tab_accepts_a_sellable_product_outside_the_online_menu(): void
    {
        $setup = $this->createRestaurantSetup();
        $user = $this->createCompanyUser($setup['company']);
        $water = Product::factory()->forCompany($setup['company'])->sellable()->create([
            'name' => 'Água',
            'sale_price' => 5,
            'available_for_online_order' => false,
            'tracks_stock' => false,
        ]);
        $table = DiningTable::factory()->forCompany($setup['company'])->create(['number' => 4]);
        $floor = app(FloorOrderService::class);

        $order = $floor->open($setup['company'], $table, $user);
        $order = $floor->addItem($setup['company'], $order, $water->id, 3, user: $user);

        $this->assertSame('Água', $order->items->first()->name);
        $this->assertSame(1500, $order->total_cents);
    }

    public function test_floor_page_is_forbidden_without_the_orders_module(): void
    {
        $company = $this->createCompany([
            'enabled_modules' => [CompanyModule::Scheduling->value],
            'slug' => 'sem-pedidos',
        ]);
        $user = $this->createCompanyUser($company);

        $this->authenticateForAppTenant($user, $company);

        Livewire::test(FloorServicePage::class)->assertForbidden();
    }
}
