<?php

namespace App\Filament\App\Pages;

use App\Enums\CompanyModule;
use App\Enums\OrderStatus;
use App\Filament\App\Concerns\RequiresCompanyModule;
use App\Models\Company;
use App\Models\Order;
use App\Policies\OrderPolicy;
use App\Services\Orders\OrderService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use UnitEnum;

class KitchenDisplayPage extends Page
{
    use RequiresCompanyModule;

    protected static ?string $slug = 'cozinha';

    protected static ?string $navigationLabel = 'Cozinha';

    protected static ?string $title = 'Cozinha';

    protected static string|UnitEnum|null $navigationGroup = 'Pedidos';

    protected static ?int $navigationSort = 10;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFire;

    protected string $view = 'filament.app.pages.kitchen-display';

    public ?int $cancelingOrderId = null;

    public string $cancelReason = '';

    #[Locked]
    public ?int $kitchenTenantId = null;

    public static function canAccess(): bool
    {
        if (! static::tenantHasRequiredModule()) {
            return false;
        }

        $user = auth()->user();

        return $user !== null && (new OrderPolicy)->viewKitchen($user);
    }

    public function boot(): void
    {
        $this->restoreFilamentTenantFromComponentState();
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->kitchenTenantId = $this->tenantCompany()->getKey();
    }

    /**
     * @return Collection<string, Collection<int, Order>>
     */
    public function kitchenOrders(): Collection
    {
        $company = $this->tenantCompany();

        $orders = Order::query()
            ->where('company_id', $company->getKey())
            ->whereIn('status', array_map(
                fn (OrderStatus $status): string => $status->value,
                OrderStatus::kitchenColumns(),
            ))
            ->with('items')
            ->orderBy('created_at')
            ->get();

        return collect(OrderStatus::kitchenColumns())
            ->mapWithKeys(fn (OrderStatus $status): array => [
                $status->value => $orders
                    ->filter(fn (Order $order): bool => $this->orderMatchesKitchenStatus($order, $status))
                    ->values(),
            ]);
    }

    public function advanceOrder(int $orderId): void
    {
        $order = $this->findTenantOrder($orderId);
        $user = auth()->user();

        abort_unless($user !== null && $user->can('advance', $order), 403);

        app(OrderService::class)->advance($this->tenantCompany(), $order, $user);

        Notification::make()
            ->success()
            ->title('Pedido atualizado')
            ->body($order->fresh()?->displayNumber().' agora está '.$order->fresh()?->status?->label())
            ->send();
    }

    public function startCancel(int $orderId): void
    {
        $order = $this->findTenantOrder($orderId);
        abort_unless(auth()->user()?->can('cancel', $order) ?? false, 403);

        $this->cancelingOrderId = $orderId;
        $this->cancelReason = '';
    }

    public function confirmCancel(): void
    {
        if ($this->cancelingOrderId === null) {
            return;
        }

        $this->validate([
            'cancelReason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $order = $this->findTenantOrder($this->cancelingOrderId);
        $user = auth()->user();

        abort_unless($user !== null && $user->can('cancel', $order), 403);

        app(OrderService::class)->cancel($this->tenantCompany(), $order, $this->cancelReason, $user);

        $this->cancelingOrderId = null;
        $this->cancelReason = '';

        Notification::make()
            ->success()
            ->title('Pedido cancelado')
            ->send();
    }

    public function dismissCancel(): void
    {
        $this->cancelingOrderId = null;
        $this->cancelReason = '';
    }

    protected function findTenantOrder(int $orderId): Order
    {
        $company = $this->tenantCompany();

        return Order::query()
            ->where('company_id', $company->getKey())
            ->whereKey($orderId)
            ->firstOrFail();
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        $this->restoreFilamentTenantFromComponentState();

        $url = $this->publicOrderingUrl();

        if ($url === null) {
            return [];
        }

        return [
            Action::make('openPublicLink')
                ->label('Link público')
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->url($url)
                ->openUrlInNewTab()
                ->color('gray'),
        ];
    }

    public function publicOrderingUrl(): ?string
    {
        $tenant = Filament::getTenant();

        if (! $tenant instanceof Company || blank($tenant->slug)) {
            return null;
        }

        if (! $tenant->orderSetting?->online_ordering_enabled) {
            return null;
        }

        return route('public.orders.show', ['company' => $tenant->slug]);
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
            $this->kitchenTenantId = (int) $current->getKey();

            return;
        }

        if ($this->kitchenTenantId === null) {
            return;
        }

        $user = auth()->user();
        $company = Company::query()->find($this->kitchenTenantId);

        if (! $company instanceof Company || $user === null || ! $user->canAccessTenant($company)) {
            return;
        }

        Filament::setTenant($company, isQuiet: true);
    }

    protected function orderMatchesKitchenStatus(Order $order, OrderStatus $status): bool
    {
        $current = $order->status;

        if ($current === $status) {
            return true;
        }

        $currentValue = $current instanceof OrderStatus
            ? $current->value
            : (is_string($current) ? $current : null);

        return $currentValue === $status->value;
    }

    protected static function requiredCompanyModule(): CompanyModule
    {
        return CompanyModule::Orders;
    }
}
