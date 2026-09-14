<?php

namespace Tests\Feature\Orders;

use App\Enums\CompanyModule;
use App\Enums\OrderFulfillment;
use App\Enums\SaleItemType;
use App\Filament\App\Pages\KitchenDisplayPage;
use App\Livewire\PublicOrders\OrderWizard;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Orders\OrderService;
use App\Services\Product\ProductService;
use App\Services\Product\ProductVariantService;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Concerns\CreatesOrderFixtures;
use Tests\TestCase;

class ProductVariantOrderTest extends TestCase
{
    use CreatesOrderFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_order_without_variants_still_uses_product_sale_price(): void
    {
        $setup = $this->createRestaurantSetup();

        $order = app(OrderService::class)->createPublic($setup['company'], [
            'items' => [['product_id' => $setup['burger']->id, 'quantity' => 1]],
            'fulfillment' => OrderFulfillment::Pickup,
            'customer_name' => 'Maria',
            'customer_phone' => '34988887777',
        ]);

        $item = $order->items->first();

        $this->assertSame('X-Burguer', $item->name);
        $this->assertNull($item->product_variant_id);
        $this->assertNull($item->variant_name);
        $this->assertSame(2500, $item->unit_price_cents);
        $this->assertSame(2500, $order->total_cents);
    }

    public function test_variant_is_required_when_product_has_active_sizes(): void
    {
        $setup = $this->createRestaurantSetup();
        $this->attachSizes($setup['burger']);

        $this->expectException(ValidationException::class);

        app(OrderService::class)->createPublic($setup['company'], [
            'items' => [['product_id' => $setup['burger']->id, 'quantity' => 1]],
            'fulfillment' => OrderFulfillment::Pickup,
            'customer_name' => 'Maria',
            'customer_phone' => '34988887777',
        ]);
    }

    public function test_order_item_snapshots_selected_size_name_and_price(): void
    {
        $setup = $this->createRestaurantSetup();
        $grande = $this->attachSizes($setup['burger'])->firstWhere('name', 'Grande');

        $order = app(OrderService::class)->createPublic($setup['company'], [
            'items' => [[
                'product_id' => $setup['burger']->id,
                'variant_id' => $grande->id,
                'quantity' => 2,
            ]],
            'fulfillment' => OrderFulfillment::Pickup,
            'customer_name' => 'Maria',
            'customer_phone' => '34988887777',
        ]);

        $item = $order->items->first();

        $this->assertSame('X-Burguer — Grande', $item->name);
        $this->assertSame($grande->id, $item->product_variant_id);
        $this->assertSame('Grande', $item->variant_name);
        $this->assertSame(3200, $item->unit_price_cents);
        $this->assertSame(6400, $item->line_total_cents);
        $this->assertSame(6400, $order->total_cents);
    }

    public function test_wizard_adds_default_size_and_can_switch_to_grande(): void
    {
        $setup = $this->createRestaurantSetup();
        $variants = $this->attachSizes($setup['burger']);
        $grande = $variants->firstWhere('name', 'Grande');

        Livewire::test(OrderWizard::class, ['company' => $setup['company']])
            ->call('addToCart', $setup['burger']->id)
            ->call('addToCart', $setup['burger']->id, $grande->id)
            ->call('goToFulfillment')
            ->call('selectFulfillment', OrderFulfillment::Pickup->value)
            ->set('customerName', 'Maria Cliente')
            ->set('customerPhone', '(34) 99999-1111')
            ->call('goToReview')
            ->assertSee('X-Burguer — Média', false)
            ->assertSee('X-Burguer — Grande', false)
            ->call('submit')
            ->assertHasNoErrors()
            ->assertSet('step', OrderWizard::STEP_CONFIRMATION);

        $order = Order::query()->where('company_id', $setup['company']->id)->first();
        $names = $order->items->pluck('name')->sort()->values()->all();

        $this->assertSame(['X-Burguer — Grande', 'X-Burguer — Média'], $names);
        $this->assertSame(2800 + 3200, $order->total_cents);
    }

    public function test_kitchen_shows_size_in_the_item_line(): void
    {
        $setup = $this->createRestaurantSetup();
        $grande = $this->attachSizes($setup['burger'])->firstWhere('name', 'Grande');
        $admin = $this->createCompanyUser($setup['company']);

        $order = app(OrderService::class)->createPublic($setup['company'], [
            'items' => [[
                'product_id' => $setup['burger']->id,
                'variant_id' => $grande->id,
                'quantity' => 1,
            ]],
            'fulfillment' => OrderFulfillment::Pickup,
            'customer_name' => 'Maria Cliente',
            'customer_phone' => '34988887777',
        ]);

        $this->authenticateForAppTenant($admin, $setup['company']);

        Livewire::test(KitchenDisplayPage::class)
            ->assertSuccessful()
            ->assertSee('X-Burguer — Grande', false)
            ->assertSee($order->displayNumber(), false);
    }

    public function test_completed_sale_uses_variant_price_and_size_name(): void
    {
        $setup = $this->createRestaurantSetup([
            'enabled_modules' => [
                CompanyModule::Orders->value,
                CompanyModule::WhatsApp->value,
                CompanyModule::Sales->value,
            ],
        ]);
        $grande = $this->attachSizes($setup['burger'])->firstWhere('name', 'Grande');
        $user = $this->createCompanyUser($setup['company']);
        $service = app(OrderService::class);

        $order = $service->createPublic($setup['company'], [
            'items' => [[
                'product_id' => $setup['burger']->id,
                'variant_id' => $grande->id,
                'quantity' => 1,
            ]],
            'fulfillment' => OrderFulfillment::Pickup,
            'customer_name' => 'Maria',
            'customer_phone' => '34988887777',
        ]);

        while ($order->canAdvance()) {
            $order = $service->advance($setup['company'], $order, $user);
        }

        $completed = $order->fresh(['items', 'sale']) ?? $order;

        $sale = $completed->sale()->with('items')->first();

        $this->assertNotNull($sale);
        $this->assertSame('32.00', $sale->final_amount);
        $this->assertSame(SaleItemType::Product, $sale->items->first()->item_type);
        $this->assertSame('X-Burguer — Grande', $sale->items->first()->name_snapshot);
        $this->assertSame('32.00', (string) $sale->items->first()->line_total);
    }

    public function test_product_service_syncs_variants_on_save(): void
    {
        $setup = $this->createRestaurantSetup();

        $product = app(ProductService::class)->update($setup['company'], $setup['soda'], [
            'name' => $setup['soda']->name,
            'type' => $setup['soda']->type->value,
            'measurement_unit_id' => $setup['soda']->measurement_unit_id,
            'reference_unit_cost' => $setup['soda']->reference_unit_cost,
            'sale_price' => $setup['soda']->sale_price,
            'minimum_stock' => 0,
            'tracks_stock' => false,
            'available_for_online_order' => true,
            'variants' => [
                ['name' => 'Lata', 'price' => 7, 'is_default' => true, 'is_active' => true],
                ['name' => '1L', 'price' => 12, 'is_default' => false, 'is_active' => true],
            ],
        ]);

        $this->assertCount(2, $product->variants);
        $this->assertSame('Lata', $product->variants->firstWhere('is_default', true)?->name);
    }

    /**
     * @return Collection<int, ProductVariant>
     */
    protected function attachSizes(Product $product): Collection
    {
        app(ProductVariantService::class)->sync($product, [
            ['name' => 'Média', 'price' => 28, 'is_default' => true, 'is_active' => true],
            ['name' => 'Grande', 'price' => 32, 'is_default' => false, 'is_active' => true],
        ]);

        return $product->fresh()->activeVariants;
    }
}
