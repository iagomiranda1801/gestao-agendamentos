<?php

namespace App\Filament\App\Pages;

use App\Enums\CompanyModule;
use App\Enums\PaymentMethod;
use App\Filament\App\Concerns\RequiresCompanyModule;
use App\Filament\App\Resources\DiningTables\DiningTableResource;
use App\Filament\Concerns\NotifiesValidationErrors;
use App\Models\Company;
use App\Models\DiningTable;
use App\Models\Order;
use App\Models\OrderItem;
use App\Policies\OrderPolicy;
use App\Services\Orders\FloorOrderService;
use App\Services\Orders\OrderCatalogService;
use App\Support\Money;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use UnitEnum;

class FloorServicePage extends Page
{
    use NotifiesValidationErrors;
    use RequiresCompanyModule;

    protected static ?string $slug = 'comandas';

    protected static ?string $navigationLabel = 'Comandas';

    protected static ?string $title = 'Comandas';

    protected static string|UnitEnum|null $navigationGroup = 'Pedidos';

    protected static ?int $navigationSort = 9;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected string $view = 'filament.app.pages.floor-service';

    #[Locked]
    public ?int $floorTenantId = null;

    public ?int $selectedTableId = null;

    public string $guestName = '';

    public string $productId = '';

    public string $variantId = '';

    public int $quantity = 1;

    public string $itemNotes = '';

    public string $paymentMethod = 'pix';

    public string $cancelReason = '';

    public static function canAccess(): bool
    {
        if (! static::tenantHasRequiredModule()) {
            return false;
        }

        $user = auth()->user();

        return $user !== null && (new OrderPolicy)->operateFloor($user);
    }

    public function boot(): void
    {
        $this->restoreFilamentTenantFromComponentState();
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->floorTenantId = $this->tenantCompany()->getKey();
    }

    public function selectTable(int $tableId): void
    {
        $table = $this->findTable($tableId);
        $this->selectedTableId = (int) $table->getKey();
        $this->guestName = '';
        $this->cancelReason = '';
        $this->resetItemForm();
    }

    public function openTab(): void
    {
        $this->hasNotifiedValidationError = false;
        $table = $this->selectedTable();
        $user = auth()->user();

        if ($table === null || $user === null) {
            return;
        }

        abort_unless($user->can('operateFloor', Order::class), 403);

        try {
            app(FloorOrderService::class)->open(
                $this->tenantCompany(),
                $table,
                $user,
                $this->guestName,
            );
        } catch (ValidationException $exception) {
            $this->notifyValidationException($exception);

            return;
        }

        $this->guestName = '';

        Notification::make()
            ->success()
            ->title('Comanda aberta')
            ->body($table->displayLabel())
            ->send();
    }

    public function addItem(): void
    {
        $this->hasNotifiedValidationError = false;
        $order = $this->selectedOrder();
        $user = auth()->user();

        if ($order === null || $user === null) {
            return;
        }

        abort_unless($user->can('manageFloor', $order), 403);

        try {
            app(FloorOrderService::class)->addItem(
                $this->tenantCompany(),
                $order,
                (int) $this->productId,
                max(1, (int) $this->quantity),
                $this->itemNotes,
                $this->variantId !== '' ? (int) $this->variantId : null,
                $user,
            );
        } catch (ValidationException $exception) {
            $this->notifyValidationException($exception);

            return;
        }

        $this->resetItemForm();

        Notification::make()->success()->title('Item lançado')->send();
    }

    public function removeItem(int $itemId): void
    {
        $this->hasNotifiedValidationError = false;
        $order = $this->selectedOrder();
        $user = auth()->user();

        if ($order === null || $user === null) {
            return;
        }

        abort_unless($user->can('manageFloor', $order), 403);

        $item = OrderItem::query()
            ->whereKey($itemId)
            ->where('order_id', $order->getKey())
            ->firstOrFail();

        try {
            app(FloorOrderService::class)->removeItem($this->tenantCompany(), $order, $item);
        } catch (ValidationException $exception) {
            $this->notifyValidationException($exception);

            return;
        }

        Notification::make()->success()->title('Item removido')->send();
    }

    public function closeTab(): void
    {
        $this->hasNotifiedValidationError = false;
        $order = $this->selectedOrder();
        $user = auth()->user();

        if ($order === null || $user === null) {
            return;
        }

        abort_unless($user->can('manageFloor', $order), 403);

        try {
            $closed = app(FloorOrderService::class)->close(
                $this->tenantCompany(),
                $order,
                $this->paymentMethod,
                $user,
            );
        } catch (ValidationException $exception) {
            $this->notifyValidationException($exception);

            return;
        }

        $url = $this->receiptUrl($closed, true);

        if ($url !== null) {
            $this->js('window.open('.json_encode($url).', "_blank", "noopener")');
        }

        Notification::make()
            ->success()
            ->title('Conta fechada')
            ->body('A mesa foi liberada.')
            ->send();
    }

    public function cancelTab(): void
    {
        $this->hasNotifiedValidationError = false;
        $order = $this->selectedOrder();
        $user = auth()->user();

        if ($order === null || $user === null) {
            return;
        }

        abort_unless($user->can('cancelFloor', $order), 403);

        try {
            app(FloorOrderService::class)->cancel(
                $this->tenantCompany(),
                $order,
                $this->cancelReason,
                $user,
            );
        } catch (ValidationException $exception) {
            $this->notifyValidationException($exception);

            return;
        }

        $this->cancelReason = '';

        Notification::make()->success()->title('Comanda cancelada')->body('A mesa foi liberada.')->send();
    }

    /**
     * @return Collection<int, DiningTable>
     */
    public function floorTables(): Collection
    {
        return DiningTable::query()
            ->where('company_id', $this->tenantCompany()->getKey())
            ->active()
            ->with(['orders' => fn ($query) => $query->open()->with('items')->orderByDesc('id')])
            ->orderBy('sort_order')
            ->orderBy('number')
            ->get();
    }

    public function selectedTable(): ?DiningTable
    {
        if ($this->selectedTableId === null) {
            return null;
        }

        return DiningTable::query()
            ->where('company_id', $this->tenantCompany()->getKey())
            ->with(['orders' => fn ($query) => $query->open()->with('items')->orderByDesc('id')])
            ->find($this->selectedTableId);
    }

    public function selectedOrder(): ?Order
    {
        return $this->selectedTable()?->currentOrder();
    }

    /**
     * @return list<array{id: int, name: string, price: string, variants: list<array{id: int, name: string, price: string}>}>
     */
    public function catalog(): array
    {
        return app(OrderCatalogService::class)
            ->sellableProducts($this->tenantCompany())
            ->map(function ($product): array {
                return [
                    'id' => (int) $product->getKey(),
                    'name' => (string) $product->name,
                    'price' => Money::formatCents(app(OrderCatalogService::class)->displayPriceCents($product)),
                    'variants' => $product->activeVariants->map(fn ($variant): array => [
                        'id' => (int) $variant->getKey(),
                        'name' => (string) $variant->name,
                        'price' => Money::formatCents(app(OrderCatalogService::class)->unitPriceCents($product, $variant)),
                    ])->values()->all(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public function paymentOptions(): array
    {
        return PaymentMethod::options();
    }

    public function receiptUrl(?Order $order = null, bool $autoPrint = false): ?string
    {
        $order ??= $this->selectedOrder();

        if ($order === null) {
            return null;
        }

        $url = route('orders.receipt', [
            'company' => $this->tenantCompany(),
            'order' => $order,
        ]);

        return $autoPrint ? $url.'?imprimir=1' : $url;
    }

    public function tablesIndexUrl(): ?string
    {
        if (! DiningTableResource::canViewAny()) {
            return null;
        }

        return DiningTableResource::getUrl('index');
    }

    protected function findTable(int $tableId): DiningTable
    {
        return DiningTable::query()
            ->where('company_id', $this->tenantCompany()->getKey())
            ->whereKey($tableId)
            ->firstOrFail();
    }

    protected function resetItemForm(): void
    {
        $this->productId = '';
        $this->variantId = '';
        $this->quantity = 1;
        $this->itemNotes = '';
    }

    protected function tenantCompany(): Company
    {
        $this->restoreFilamentTenantFromComponentState();

        $tenant = Filament::getTenant();

        abort_unless($tenant instanceof Company, 403);

        return $tenant;
    }

    protected function restoreFilamentTenantFromComponentState(): void
    {
        $current = Filament::getTenant();

        if ($current instanceof Company) {
            $this->floorTenantId = (int) $current->getKey();

            return;
        }

        if ($this->floorTenantId === null) {
            return;
        }

        $user = auth()->user();
        $company = Company::query()->find($this->floorTenantId);

        if (! $company instanceof Company || $user === null || ! $user->canAccessTenant($company)) {
            return;
        }

        Filament::setTenant($company, isQuiet: true);
    }

    protected static function requiredCompanyModule(): CompanyModule
    {
        return CompanyModule::Orders;
    }
}
