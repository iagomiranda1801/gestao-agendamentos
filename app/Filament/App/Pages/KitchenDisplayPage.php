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

    public static function canAccess(): bool
    {
        if (! static::tenantHasRequiredModule()) {
            return false;
        }

        $user = auth()->user();

        return $user !== null && (new OrderPolicy)->viewAny($user);
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    /**
     * @return Collection<string, Collection<int, Order>>
     */
    public function kitchenOrders(): Collection
    {
        /** @var Company $company */
        $company = Filament::getTenant();

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
                $status->value => $orders->where('status', $status)->values(),
            ]);
    }

    public function advanceOrder(int $orderId): void
    {
        $order = $this->findTenantOrder($orderId);
        $user = auth()->user();

        abort_unless($user !== null && $user->can('advance', $order), 403);

        app(OrderService::class)->advance(Filament::getTenant(), $order, $user);

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

        app(OrderService::class)->cancel(Filament::getTenant(), $order, $this->cancelReason, $user);

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
        /** @var Company $company */
        $company = Filament::getTenant();

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
        return [
            Action::make('openPublicLink')
                ->label('Link público')
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->url(fn (): string => route('public.orders.show', ['company' => Filament::getTenant()?->slug]))
                ->openUrlInNewTab()
                ->color('gray'),
        ];
    }

    protected static function requiredCompanyModule(): CompanyModule
    {
        return CompanyModule::Orders;
    }
}
