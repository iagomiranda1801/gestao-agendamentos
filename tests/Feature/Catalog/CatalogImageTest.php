<?php

namespace Tests\Feature\Catalog;

use App\Enums\ProductType;
use App\Livewire\PublicBooking\BookingWizard;
use App\Livewire\PublicOrders\OrderWizard;
use App\Models\MeasurementUnit;
use App\Models\Product;
use App\Models\Service;
use App\Services\Product\ProductService;
use App\Services\Service\ServiceCatalogService;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\CreatesOrderFixtures;
use Tests\Concerns\CreatesPublicBookingFixtures;
use Tests\Concerns\CreatesSchedulingFixtures;
use Tests\TestCase;

class CatalogImageTest extends TestCase
{
    use CreatesOrderFixtures;
    use CreatesPublicBookingFixtures;
    use CreatesSchedulingFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->withoutVite();
    }

    public function test_product_image_url_is_null_when_there_is_no_photo(): void
    {
        $product = Product::factory()->create(['image_path' => null]);

        $this->assertNull($product->imageUrl());
    }

    public function test_product_image_url_points_to_public_storage(): void
    {
        $company = $this->createCompany();
        $path = Product::publicImageDirectory((int) $company->getKey()).'/espetinho.jpg';
        Storage::disk('public')->put($path, 'fake-image');

        $product = Product::factory()->forCompany($company)->create([
            'image_path' => $path,
        ]);

        $this->assertSame(Storage::disk('public')->url($path), $product->imageUrl());
    }

    public function test_product_image_url_ignores_unsafe_paths(): void
    {
        $product = Product::factory()->create([
            'image_path' => '../.env',
        ]);

        $this->assertNull($product->imageUrl());
    }

    public function test_product_service_persists_image_path(): void
    {
        $company = $this->createCompany();
        $unit = MeasurementUnit::factory()->create(['is_active' => true]);
        $path = Product::publicImageDirectory((int) $company->getKey()).'/porcao.jpg';
        Storage::disk('public')->put($path, 'fake-image');

        $product = app(ProductService::class)->create($company, [
            'name' => 'Porção de mandioca',
            'measurement_unit_id' => $unit->getKey(),
            'type' => ProductType::Sale->value,
            'reference_unit_cost' => 5,
            'sale_price' => 18,
            'minimum_stock' => 0,
            'tracks_stock' => false,
            'is_active' => true,
            'image_path' => $path,
        ]);

        $this->assertSame($path, $product->image_path);
        $this->assertSame(Storage::disk('public')->url($path), $product->imageUrl());
    }

    public function test_replacing_product_image_deletes_the_old_file(): void
    {
        $company = $this->createCompany();
        $old = Product::publicImageDirectory((int) $company->getKey()).'/antiga.jpg';
        $new = Product::publicImageDirectory((int) $company->getKey()).'/nova.jpg';
        Storage::disk('public')->put($old, 'old');
        Storage::disk('public')->put($new, 'new');

        $product = Product::factory()->forCompany($company)->sellable()->create([
            'image_path' => $old,
        ]);

        app(ProductService::class)->update($company, $product, [
            'name' => $product->name,
            'measurement_unit_id' => $product->measurement_unit_id,
            'type' => $product->type->value,
            'reference_unit_cost' => $product->reference_unit_cost,
            'sale_price' => $product->sale_price,
            'minimum_stock' => $product->minimum_stock,
            'tracks_stock' => $product->tracks_stock,
            'is_active' => true,
            'image_path' => $new,
        ]);

        Storage::disk('public')->assertMissing($old);
        Storage::disk('public')->assertExists($new);
        $this->assertSame($new, $product->fresh()->image_path);
    }

    public function test_removing_product_image_deletes_the_file(): void
    {
        $company = $this->createCompany();
        $old = Product::publicImageDirectory((int) $company->getKey()).'/remover.jpg';
        Storage::disk('public')->put($old, 'old');

        $product = Product::factory()->forCompany($company)->sellable()->create([
            'image_path' => $old,
        ]);

        app(ProductService::class)->update($company, $product, [
            'name' => $product->name,
            'measurement_unit_id' => $product->measurement_unit_id,
            'type' => $product->type->value,
            'reference_unit_cost' => $product->reference_unit_cost,
            'sale_price' => $product->sale_price,
            'minimum_stock' => $product->minimum_stock,
            'tracks_stock' => $product->tracks_stock,
            'is_active' => true,
            'image_path' => null,
        ]);

        Storage::disk('public')->assertMissing($old);
        $this->assertNull($product->fresh()->image_path);
    }

    public function test_service_service_persists_image_path(): void
    {
        $company = $this->createCompany();
        $path = Service::publicImageDirectory((int) $company->getKey()).'/design.jpg';
        Storage::disk('public')->put($path, 'fake-image');

        $service = app(ServiceCatalogService::class)->create($company, [
            'name' => 'Design simples',
            'slug' => 'design-simples',
            'price' => 35,
            'duration_minutes' => 30,
            'sort_order' => 1,
            'is_bookable' => true,
            'is_online_booking_enabled' => true,
            'is_active' => true,
            'image_path' => $path,
        ]);

        $this->assertSame($path, $service->image_path);
        $this->assertSame(Storage::disk('public')->url($path), $service->imageUrl());
    }

    public function test_replacing_service_image_deletes_the_old_file(): void
    {
        $company = $this->createCompany();
        $old = Service::publicImageDirectory((int) $company->getKey()).'/antiga.jpg';
        $new = Service::publicImageDirectory((int) $company->getKey()).'/nova.jpg';
        Storage::disk('public')->put($old, 'old');
        Storage::disk('public')->put($new, 'new');

        $service = Service::factory()->forCompany($company)->create([
            'image_path' => $old,
        ]);

        app(ServiceCatalogService::class)->update($company, $service, [
            'name' => $service->name,
            'slug' => $service->slug,
            'price' => $service->price,
            'duration_minutes' => $service->duration_minutes,
            'sort_order' => $service->sort_order,
            'is_bookable' => $service->is_bookable,
            'is_online_booking_enabled' => $service->is_online_booking_enabled,
            'is_active' => true,
            'image_path' => $new,
        ]);

        Storage::disk('public')->assertMissing($old);
        Storage::disk('public')->assertExists($new);
        $this->assertSame($new, $service->fresh()->image_path);
    }

    public function test_public_menu_shows_product_photo_and_hides_it_when_missing(): void
    {
        $setup = $this->createRestaurantSetup();
        $path = Product::publicImageDirectory((int) $setup['company']->getKey()).'/x-burguer.jpg';
        Storage::disk('public')->put($path, 'fake-image');
        $setup['burger']->update(['image_path' => $path]);

        $url = Storage::disk('public')->url($path);

        $this->get(route('public.orders.show', ['company' => $setup['company']->slug]))
            ->assertOk()
            ->assertSee('X-Burguer', false)
            ->assertSee($url, false)
            ->assertSee('class="booking-option__photo"', false);

        $setup['burger']->update(['image_path' => null]);

        $this->get(route('public.orders.show', ['company' => $setup['company']->slug]))
            ->assertOk()
            ->assertSee('X-Burguer', false)
            ->assertDontSee($url, false)
            ->assertDontSee('class="booking-option__photo"', false);
    }

    public function test_public_booking_shows_service_photo_and_hides_it_when_missing(): void
    {
        $setup = $this->createBookableSetup();
        $this->enablePublicBooking($setup['company']);
        $path = Service::publicImageDirectory((int) $setup['company']->getKey()).'/servico.jpg';
        Storage::disk('public')->put($path, 'fake-image');
        $setup['service']->update(['image_path' => $path]);

        $url = Storage::disk('public')->url($path);

        Livewire::test(BookingWizard::class, ['company' => $setup['company']])
            ->assertOk()
            ->assertSee($setup['service']->name, false)
            ->assertSee($url, false)
            ->assertSee('class="booking-service-card__photo"', false);

        $setup['service']->update(['image_path' => null]);

        Livewire::test(BookingWizard::class, ['company' => $setup['company']])
            ->assertOk()
            ->assertSee($setup['service']->name, false)
            ->assertDontSee($url, false)
            ->assertDontSee('class="booking-service-card__photo"', false);
    }

    public function test_order_wizard_still_mounts_when_product_has_no_photo(): void
    {
        $setup = $this->createRestaurantSetup();

        Livewire::test(OrderWizard::class, ['company' => $setup['company']])
            ->assertOk()
            ->assertSet('step', OrderWizard::STEP_MENU)
            ->assertSee('X-Burguer', false)
            ->assertDontSee('class="booking-option__photo"', false);
    }
}
