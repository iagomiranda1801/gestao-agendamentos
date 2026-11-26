<?php

namespace Tests\Feature\Orders;

use App\Enums\OrderStatus;
use App\Filament\App\Pages\FloorServicePage;
use App\Models\DiningTable;
use App\Models\Order;
use App\Services\Orders\FloorOrderService;
use App\Services\Product\ProductVariantService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Concerns\CreatesOrderFixtures;
use Tests\TestCase;

class FloorBoardLayoutTest extends TestCase
{
    use CreatesOrderFixtures;

    public function test_change_item_quantity_updates_totals_and_sends_the_tab_back_to_the_kitchen(): void
    {
        $setup = $this->createRestaurantSetup();
        $user = $this->createCompanyUser($setup['company']);
        $table = DiningTable::factory()->forCompany($setup['company'])->create(['number' => 1]);
        $floor = app(FloorOrderService::class);
        $order = $floor->open($setup['company'], $table, $user);
        $order = $floor->addItem($setup['company'], $order, $setup['burger']->id, 1, user: $user);
        $item = $order->items->first();

        Order::query()->whereKey($order->id)->update(['status' => OrderStatus::Ready->value]);

        $order = $floor->changeItemQuantity($setup['company'], $order->fresh(), $item, 3, $user);

        $this->assertSame(3, $order->items->first()->quantity);
        $this->assertSame(7500, $order->items->first()->line_total_cents);
        $this->assertSame(7500, $order->total_cents);
        $this->assertSame(OrderStatus::Received, $order->status);

        Order::query()->whereKey($order->id)->update(['status' => OrderStatus::Ready->value]);
        $order = $floor->changeItemQuantity($setup['company'], $order->fresh(), $item->fresh(), 2, $user);

        $this->assertSame(5000, $order->total_cents);
        $this->assertSame(OrderStatus::Ready, $order->status, 'Diminuir a quantidade não reabre a cozinha.');
    }

    public function test_change_item_quantity_rejects_invalid_values_and_foreign_items(): void
    {
        $setup = $this->createRestaurantSetup();
        $other = $this->createRestaurantSetup(['slug' => 'outra-casa']);
        $user = $this->createCompanyUser($setup['company']);
        $otherUser = $this->createCompanyUser($other['company']);
        $floor = app(FloorOrderService::class);

        $order = $floor->open($setup['company'], DiningTable::factory()->forCompany($setup['company'])->create(['number' => 1]), $user);
        $order = $floor->addItem($setup['company'], $order, $setup['burger']->id, 1, user: $user);
        $item = $order->items->first();

        foreach ([0, -1, 100] as $quantity) {
            try {
                $floor->changeItemQuantity($setup['company'], $order, $item, $quantity, $user);
                $this->fail("Quantidade {$quantity} deveria falhar.");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('quantity', $exception->errors());
            }
        }

        $foreign = $floor->open($other['company'], DiningTable::factory()->forCompany($other['company'])->create(['number' => 1]), $otherUser);
        $foreign = $floor->addItem($other['company'], $foreign, $other['burger']->id, 1, user: $otherUser);

        try {
            $floor->changeItemQuantity($setup['company'], $order, $foreign->items->first(), 5, $user);
            $this->fail('Item de outra comanda não pode ser alterado.');
        } catch (ModelNotFoundException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(1, $foreign->fresh('items')->items->first()->quantity);
        $this->assertSame(1, $order->fresh('items')->items->first()->quantity);
    }

    public function test_board_shows_summary_chips_area_tabs_and_tile_details(): void
    {
        $setup = $this->createRestaurantSetup();
        $user = $this->createCompanyUser($setup['company']);
        $floor = app(FloorOrderService::class);
        $varanda = DiningTable::factory()->forCompany($setup['company'])->create(['number' => 1, 'sort_order' => 1, 'name' => 'Varanda']);
        DiningTable::factory()->forCompany($setup['company'])->create(['number' => 2, 'sort_order' => 2, 'name' => 'VARANDA']);
        DiningTable::factory()->forCompany($setup['company'])->create(['number' => 3, 'sort_order' => 3, 'name' => 'Salão']);
        $order = $floor->open($setup['company'], $varanda, $user, 'Carla');
        $floor->addItem($setup['company'], $order, $setup['burger']->id, 2, user: $user);

        $this->authenticateForAppTenant($user, $setup['company']);

        $page = Livewire::test(FloorServicePage::class)
            ->assertSuccessful()
            ->assertSee('Livres', false)
            ->assertSee('Ocupadas', false)
            ->assertSee('R$ 50,00', false)
            ->assertSee('Carla', false)
            ->assertSee('2 itens', false)
            ->assertSee('Salão', false)
            ->assertSee('Mesa 3', false)
            ->assertDontSeeHtml('role="dialog"');

        $areas = $page->instance()->areas($page->instance()->floorTables());
        $this->assertSame(['varanda' => 'Varanda', 'salão' => 'Salão'], $areas, 'Varanda e VARANDA formam uma área só.');

        $page->call('setArea', 'salão')
            ->assertSee('Mesa 3', false)
            ->assertDontSee('Mesa 1 — Varanda', false)
            ->call('setArea', 'todas')
            ->assertSee('Mesa 1 — Varanda', false);
    }

    public function test_tapping_a_free_table_opens_the_drawer_and_closing_it_keeps_the_tab(): void
    {
        $setup = $this->createRestaurantSetup();
        $user = $this->createCompanyUser($setup['company']);
        $table = DiningTable::factory()->forCompany($setup['company'])->create(['number' => 7, 'name' => 'Janela']);

        $this->authenticateForAppTenant($user, $setup['company']);

        Livewire::test(FloorServicePage::class)
            ->call('selectTable', $table->id)
            ->assertSet('selectedTableId', $table->id)
            ->assertSee('Nome do cliente (opcional)', false)
            ->assertSee('Abrir comanda', false)
            ->set('guestName', 'Bruno')
            ->call('openTab')
            ->assertSee('Bruno', false)
            ->assertSee('Itens da comanda', false)
            ->call('closeDrawer')
            ->assertSet('selectedTableId', null)
            ->assertDontSee('Itens da comanda', false)
            ->assertSee('Bruno', false);

        $this->assertNotNull($table->fresh()->currentOrder());
    }

    public function test_quick_add_merges_equal_lines_and_stepper_changes_quantity(): void
    {
        $setup = $this->createRestaurantSetup();
        $user = $this->createCompanyUser($setup['company']);
        $table = DiningTable::factory()->forCompany($setup['company'])->create(['number' => 1]);

        $this->authenticateForAppTenant($user, $setup['company']);

        $page = Livewire::test(FloorServicePage::class)
            ->call('selectTable', $table->id)
            ->call('openTab')
            ->call('pickProduct', $setup['burger']->id)
            ->call('pickProduct', $setup['burger']->id)
            ->call('pickProduct', $setup['burger']->id);

        $order = Order::query()->where('table_id', $table->id)->firstOrFail();
        $this->assertCount(1, $order->items, 'Toques repetidos somam na mesma linha.');
        $this->assertSame(3, $order->items->first()->quantity);
        $this->assertSame(7500, $order->fresh()->total_cents);

        $itemId = $order->items->first()->id;

        $page->call('decrementItem', $itemId)->call('decrementItem', $itemId)->call('decrementItem', $itemId);
        $this->assertSame(1, $order->items()->first()->quantity, 'O menos para em 1; remover é pela lixeira.');

        $page->call('incrementItem', $itemId);
        $this->assertSame(2, $order->items()->first()->quantity);
        $this->assertSame(5000, $order->fresh()->total_cents);

        $page->set('itemNotes', 'sem cebola')->call('pickProduct', $setup['burger']->id);
        $this->assertSame(2, $order->items()->count(), 'Com observação vira uma linha própria.');
        $this->assertSame('', $page->get('itemNotes'));
        $this->assertSame(7500, $order->fresh()->total_cents);

        $page->call('removeItem', $itemId);
        $this->assertSame(1, $order->items()->count());
    }

    public function test_product_with_sizes_asks_for_the_size_before_adding(): void
    {
        $setup = $this->createRestaurantSetup();
        $user = $this->createCompanyUser($setup['company']);
        $table = DiningTable::factory()->forCompany($setup['company'])->create(['number' => 1]);
        app(ProductVariantService::class)->sync($setup['burger'], [
            ['name' => 'Média', 'price' => 28, 'is_default' => true, 'is_active' => true],
            ['name' => 'Grande', 'price' => 32, 'is_default' => false, 'is_active' => true],
        ]);
        $grande = $setup['burger']->fresh()->activeVariants->firstWhere('name', 'Grande');

        $this->authenticateForAppTenant($user, $setup['company']);

        $page = Livewire::test(FloorServicePage::class)
            ->call('selectTable', $table->id)
            ->call('openTab')
            ->call('pickProduct', $setup['burger']->id)
            ->assertSet('productId', (string) $setup['burger']->id)
            ->assertSee('escolha o tamanho', false)
            ->assertSee('Grande', false);

        $order = Order::query()->where('table_id', $table->id)->firstOrFail();
        $this->assertSame(0, $order->items()->count());

        $page->call('quickAdd', $setup['burger']->id, $grande->id)
            ->assertSet('productId', '');

        $this->assertSame(1, $order->items()->count());
        $this->assertSame(3200, $order->fresh()->total_cents);

        $page->call('pickProduct', $setup['burger']->id)->call('quickAdd', $setup['burger']->id, $grande->id);
        $this->assertSame(1, $order->items()->count());
        $this->assertSame(2, $order->items()->first()->quantity);
    }

    public function test_search_and_category_filter_the_product_grid(): void
    {
        $setup = $this->createRestaurantSetup();
        $user = $this->createCompanyUser($setup['company']);
        $table = DiningTable::factory()->forCompany($setup['company'])->create(['number' => 1]);

        $this->authenticateForAppTenant($user, $setup['company']);

        Livewire::test(FloorServicePage::class)
            ->call('selectTable', $table->id)
            ->call('openTab')
            ->assertSee('X-Burguer', false)
            ->assertSee('Refrigerante', false)
            ->set('search', 'refri')
            ->assertSee('Refrigerante', false)
            ->assertDontSee('X-Burguer', false)
            ->set('search', 'zzz')
            ->assertSee('Nenhum produto encontrado', false)
            ->set('search', '')
            ->call('setCategory', 'Lanches')
            ->assertSee('X-Burguer', false)
            ->assertDontSee('Refrigerante', false)
            ->call('setCategory', 'Lanches')
            ->assertSee('Refrigerante', false);
    }

    public function test_closing_or_cancelling_the_tab_closes_the_drawer(): void
    {
        $setup = $this->createRestaurantSetup();
        $user = $this->createCompanyUser($setup['company']);
        $first = DiningTable::factory()->forCompany($setup['company'])->create(['number' => 1]);
        $second = DiningTable::factory()->forCompany($setup['company'])->create(['number' => 2]);

        $this->authenticateForAppTenant($user, $setup['company']);

        Livewire::test(FloorServicePage::class)
            ->call('selectTable', $first->id)
            ->call('openTab')
            ->call('pickProduct', $setup['soda']->id)
            ->call('closeTab')
            ->assertSet('selectedTableId', null)
            ->call('selectTable', $second->id)
            ->call('openTab')
            ->call('cancelTab')
            ->assertSet('selectedTableId', null);

        $this->assertNull($first->fresh()->currentOrder());
        $this->assertNull($second->fresh()->currentOrder());
    }

    public function test_new_table_uses_next_free_number_and_area(): void
    {
        $setup = $this->createRestaurantSetup();
        $user = $this->createCompanyUser($setup['company']);
        DiningTable::factory()->forCompany($setup['company'])->create(['number' => 4]);

        $this->authenticateForAppTenant($user, $setup['company']);

        Livewire::test(FloorServicePage::class)
            ->set('newTableArea', 'Varanda')
            ->call('createTable')
            ->assertSet('newTableNumber', '')
            ->assertSee('Mesa 5 — Varanda', false)
            ->set('newTableNumber', '5')
            ->call('createTable')
            ->assertSet('newTableNumber', '5');

        $this->assertSame(1, DiningTable::query()->where('company_id', $setup['company']->id)->where('number', 5)->count());
    }

    public function test_empty_state_invites_to_register_tables(): void
    {
        $setup = $this->createRestaurantSetup();
        $user = $this->createCompanyUser($setup['company']);

        $this->authenticateForAppTenant($user, $setup['company']);

        Livewire::test(FloorServicePage::class)
            ->assertSuccessful()
            ->assertSee('Cadastre suas mesas', false)
            ->assertDontSee('Livres', false);
    }
}
