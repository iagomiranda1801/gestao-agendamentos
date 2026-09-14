<?php

namespace Tests\Feature\Orders;

use App\Enums\CompanyModule;
use App\Enums\CompanyRole;
use App\Filament\App\Resources\MenuCategories\MenuCategoryResource;
use App\Filament\App\Resources\MenuCategories\Pages\ListMenuCategories;
use App\Livewire\PublicOrders\OrderWizard;
use App\Models\MenuCategory;
use App\Models\Product;
use App\Policies\MenuCategoryPolicy;
use App\Services\Orders\MenuCategoryService;
use App\Services\Orders\OrderCatalogService;
use App\Services\Product\ProductService;
use Filament\Facades\Filament;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Concerns\CreatesOrderFixtures;
use Tests\TestCase;

class MenuCategoryTest extends TestCase
{
    use CreatesOrderFixtures;

    public function test_ensure_defaults_seeds_pt_br_categories_once(): void
    {
        $company = $this->createCompany([
            'enabled_modules' => [CompanyModule::Orders->value],
        ]);

        $service = app(MenuCategoryService::class);
        $service->ensureDefaults($company);
        $service->ensureDefaults($company);

        $names = MenuCategory::query()
            ->where('company_id', $company->id)
            ->orderBy('sort_order')
            ->pluck('name')
            ->all();

        $this->assertSame(['Lanches', 'Bebidas', 'Sobremesas', 'Combos', 'Outros'], $names);
    }

    public function test_ensure_defaults_skips_when_company_already_has_categories(): void
    {
        $company = $this->createCompany([
            'enabled_modules' => [CompanyModule::Orders->value],
        ]);

        app(MenuCategoryService::class)->create($company, [
            'name' => 'Porções',
            'sort_order' => 5,
        ]);
        app(MenuCategoryService::class)->ensureDefaults($company);

        $this->assertSame(1, MenuCategory::query()->where('company_id', $company->id)->count());
        $this->assertSame('Porções', MenuCategory::query()->where('company_id', $company->id)->value('name'));
    }

    public function test_rejects_duplicate_normalized_name_in_the_same_company(): void
    {
        $company = $this->createCompany([
            'enabled_modules' => [CompanyModule::Orders->value],
        ]);
        $service = app(MenuCategoryService::class);
        $service->create($company, ['name' => 'Lanches']);

        $this->expectException(ValidationException::class);

        $service->create($company, ['name' => 'lanches']);
    }

    public function test_product_service_links_selected_category_and_keeps_string_compat(): void
    {
        $setup = $this->createRestaurantSetup();
        $category = MenuCategory::query()
            ->where('company_id', $setup['company']->id)
            ->where('name', 'Bebidas')
            ->first();

        $this->assertNotNull($category);

        $unit = $setup['burger']->measurement_unit_id;
        $product = app(ProductService::class)->create($setup['company'], [
            'name' => 'Suco de laranja',
            'type' => 'sale',
            'measurement_unit_id' => $unit,
            'reference_unit_cost' => 0,
            'sale_price' => 9.5,
            'minimum_stock' => 0,
            'tracks_stock' => false,
            'available_for_online_order' => true,
            'menu_category_id' => $category->id,
        ]);

        $this->assertSame($category->id, $product->menu_category_id);
        $this->assertSame('Bebidas', $product->online_order_category);
    }

    public function test_public_catalog_groups_by_sort_order(): void
    {
        $setup = $this->createRestaurantSetup();
        $company = $setup['company'];

        $setup['burger']->update(['menu_category_id' => null, 'online_order_category' => null]);
        $setup['soda']->update([
            'menu_category_id' => null,
            'online_order_category' => null,
            'available_for_online_order' => false,
        ]);
        MenuCategory::query()->where('company_id', $company->id)->delete();

        $sobremesas = app(MenuCategoryService::class)->create($company, [
            'name' => 'Sobremesas',
            'sort_order' => 30,
        ]);
        $lanches = app(MenuCategoryService::class)->create($company, [
            'name' => 'Lanches',
            'sort_order' => 10,
        ]);

        $setup['burger']->update(['menu_category_id' => $lanches->id, 'online_order_category' => 'Lanches']);
        Product::factory()->forCompany($company)->onlineMenu('Sobremesas')->create([
            'name' => 'Pudim',
            'sale_price' => 12,
            'menu_category_id' => $sobremesas->id,
            'online_order_category' => 'Sobremesas',
        ]);

        $grouped = app(OrderCatalogService::class)->groupedByCategory($company);

        $this->assertSame(['Lanches', 'Sobremesas'], $grouped->keys()->all());
        $this->assertTrue($grouped['Lanches']->contains(fn (Product $product): bool => $product->is($setup['burger']->fresh())));
    }

    public function test_public_page_renders_categories_in_stable_order(): void
    {
        $this->withoutVite();
        $setup = $this->createRestaurantSetup();

        Livewire::test(OrderWizard::class, ['company' => $setup['company']])
            ->assertSeeInOrder(['Lanches', 'Bebidas']);
    }

    public function test_manager_can_open_categories_and_employee_cannot(): void
    {
        $setup = $this->createRestaurantSetup();
        $manager = $this->createCompanyUser($setup['company'], role: CompanyRole::Manager);
        $employee = $this->createCompanyUser($setup['company'], role: CompanyRole::Employee);

        $this->authenticateForAppTenant($manager, $setup['company']);
        Filament::setCurrentPanel('app');

        $this->assertTrue(MenuCategoryResource::canViewAny());
        Livewire::test(ListMenuCategories::class)->assertSuccessful();

        $this->authenticateForAppTenant($employee, $setup['company']);
        Filament::setCurrentPanel('app');

        $this->assertFalse(MenuCategoryResource::canViewAny());
        $this->assertFalse((new MenuCategoryPolicy)->viewAny($employee));
        Livewire::test(ListMenuCategories::class)->assertForbidden();
    }

    public function test_visiting_categories_seeds_defaults_when_empty(): void
    {
        $company = $this->createCompany([
            'enabled_modules' => [CompanyModule::Orders->value],
            'slug' => 'cantina-vazia',
        ]);
        $admin = $this->createCompanyUser($company);

        $this->assertSame(0, MenuCategory::query()->where('company_id', $company->id)->count());

        $this->authenticateForAppTenant($admin, $company);
        Filament::setCurrentPanel('app');

        Livewire::test(ListMenuCategories::class)->assertSuccessful();

        $this->assertSame(5, MenuCategory::query()->where('company_id', $company->id)->count());
    }
}
