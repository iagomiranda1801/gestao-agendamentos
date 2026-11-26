<?php

namespace App\Services\Orders;

use App\Enums\CompanyModule;
use App\Enums\OrderChannel;
use App\Enums\OrderFulfillment;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Events\OrderCreated;
use App\Events\OrderStatusChanged;
use App\Models\Company;
use App\Models\DiningTable;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\User;
use App\Services\Company\CompanyModuleService;
use App\Support\PublicBookingTextSanitizer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FloorOrderService
{
    public function __construct(
        protected CompanyModuleService $modules,
        protected OrderCatalogService $catalog,
        protected OrderPublicCodeGenerator $codes,
        protected OrderService $orders,
        protected OrderSaleService $orderSales,
    ) {}

    public function open(Company $company, DiningTable $table, User $user, ?string $guestName = null, ?string $notes = null): Order
    {
        $this->assertCompanyCanUseFloor($company);

        return DB::transaction(function () use ($company, $table, $user, $guestName, $notes): Order {
            /** @var DiningTable $lockedTable */
            $lockedTable = DiningTable::query()->whereKey($table->getKey())->lockForUpdate()->firstOrFail();
            $this->assertTableCanOpen($company, $lockedTable);

            $label = $lockedTable->displayLabel();
            $guest = PublicBookingTextSanitizer::clientName($guestName);

            $order = new Order([
                'number' => $this->nextNumber($company),
                'public_code' => $this->codes->generate($company),
                'status' => OrderStatus::Received,
                'fulfillment' => OrderFulfillment::DineIn,
                'channel' => OrderChannel::Floor,
                'customer_name' => $guest ?? $label,
                'customer_phone' => null,
                'customer_phone_normalized' => null,
                'subtotal_cents' => 0,
                'delivery_fee_cents' => 0,
                'total_cents' => 0,
                'notes' => PublicBookingTextSanitizer::clientNotes($notes),
                'received_at' => now(),
                'table_id' => $lockedTable->getKey(),
            ]);
            $order->company()->associate($company);
            $order->openedBy()->associate($user);
            $order->save();

            $this->recordHistory($company, $order, null, OrderStatus::Received, $user);

            DB::afterCommit(fn () => event(new OrderCreated($order->fresh(['items', 'company', 'diningTable']) ?? $order)));

            return $order->fresh(['items', 'diningTable']) ?? $order;
        });
    }

    public function addItem(
        Company $company,
        Order $order,
        int $productId,
        int $quantity,
        ?string $notes = null,
        ?int $variantId = null,
        ?User $user = null,
    ): Order {
        $this->assertCompanyCanUseFloor($company);

        return DB::transaction(function () use ($company, $order, $productId, $quantity, $notes, $variantId, $user): Order {
            $locked = $this->lockOpenFloorOrder($company, $order);
            $snapshot = $this->snapshotItem($company, $productId, $quantity, $notes, $variantId);
            $this->assertNotADoubleSubmit($locked, $snapshot);

            $item = new OrderItem($snapshot);
            $item->order()->associate($locked);
            $item->save();

            $this->recalculateTotals($locked);
            $this->returnToKitchen($company, $locked, $user);

            return $locked->fresh(['items', 'diningTable']) ?? $locked;
        });
    }

    public function removeItem(Company $company, Order $order, OrderItem $item): Order
    {
        $this->assertCompanyCanUseFloor($company);

        return DB::transaction(function () use ($company, $order, $item): Order {
            $locked = $this->lockOpenFloorOrder($company, $order);

            if ((int) $item->order_id !== (int) $locked->getKey()) {
                abort(404);
            }

            $item->delete();
            $this->recalculateTotals($locked);

            return $locked->fresh(['items', 'diningTable']) ?? $locked;
        });
    }

    public function close(Company $company, Order $order, PaymentMethod|string $method, User $user): Order
    {
        $this->assertCompanyCanUseFloor($company);

        $paymentMethod = $method instanceof PaymentMethod
            ? $method
            : PaymentMethod::tryFrom((string) $method);

        if ($paymentMethod === null) {
            throw ValidationException::withMessages([
                'payment_method' => 'Escolha a forma de pagamento.',
            ]);
        }

        return DB::transaction(function () use ($company, $order, $paymentMethod, $user): Order {
            $locked = $this->lockOpenFloorOrder($company, $order);
            $locked->loadCount('items');

            if ((int) $locked->items_count < 1) {
                throw ValidationException::withMessages([
                    'items' => 'Adicione ao menos um item antes de fechar a conta.',
                ]);
            }

            $from = $locked->status;
            $locked->payment_method = $paymentMethod;
            $locked->status = OrderStatus::Completed;
            $locked->completed_at = now();
            $locked->save();

            $this->recordHistory($company, $locked, $from, OrderStatus::Completed, $user);
            $this->orderSales->syncFromCompletedOrder($company, $locked->fresh(['items.product', 'diningTable']) ?? $locked, $user);

            DB::afterCommit(fn () => event(new OrderStatusChanged(
                $locked->fresh(['items', 'company', 'diningTable']) ?? $locked,
                $from,
                OrderStatus::Completed,
            )));

            return $locked->fresh(['items', 'diningTable', 'sale']) ?? $locked;
        });
    }

    public function cancel(Company $company, Order $order, ?string $reason, User $user): Order
    {
        $this->assertCompanyCanUseFloor($company);
        $this->assertOpenFloorOrder($company, $order);

        $order->loadCount('items');
        $cleanReason = PublicBookingTextSanitizer::cancellationReason($reason);

        if ((int) $order->items_count > 0 && blank($cleanReason)) {
            throw ValidationException::withMessages([
                'cancel_reason' => 'Informe o motivo para cancelar uma comanda com itens.',
            ]);
        }

        return $this->orders->cancel(
            $company,
            $order,
            $cleanReason ?? 'Comanda encerrada sem consumo',
            $user,
        );
    }

    public function assertCompanyCanUseFloor(Company $company): void
    {
        if (! $company->is_active) {
            abort(404);
        }

        if (! $this->modules->hasModule($company, CompanyModule::Orders)) {
            abort(404);
        }
    }

    protected function assertTableCanOpen(Company $company, DiningTable $table): void
    {
        $this->assertTableBelongsToCompany($company, $table);

        if (! $table->is_active) {
            throw ValidationException::withMessages([
                'table' => 'Esta mesa está inativa.',
            ]);
        }

        $open = Order::query()
            ->where('company_id', $company->getKey())
            ->where('table_id', $table->getKey())
            ->open()
            ->lockForUpdate()
            ->exists();

        if ($open) {
            throw ValidationException::withMessages([
                'table' => 'Esta mesa já tem uma comanda aberta.',
            ]);
        }
    }

    protected function assertTableBelongsToCompany(Company $company, DiningTable $table): void
    {
        if ((int) $table->company_id !== (int) $company->getKey()) {
            abort(404);
        }
    }

    protected function assertOpenFloorOrder(Company $company, Order $order): void
    {
        $this->orders->ensureBelongsToCompany($company, $order);

        if (! $order->isFloorOrder()) {
            throw ValidationException::withMessages([
                'order' => 'Só é possível alterar itens de uma comanda aberta no salão.',
            ]);
        }

        if (! $order->isOpen()) {
            throw ValidationException::withMessages([
                'order' => 'Esta comanda já foi encerrada.',
            ]);
        }
    }

    protected function lockOpenFloorOrder(Company $company, Order $order): Order
    {
        /** @var Order $locked */
        $locked = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
        $this->assertOpenFloorOrder($company, $locked);

        return $locked;
    }

    /**
     * @return array{product_id: int, product_variant_id: int|null, name: string, variant_name: string|null, unit_price_cents: int, quantity: int, line_total_cents: int, notes: string|null}
     */
    protected function snapshotItem(Company $company, int $productId, int $quantity, ?string $notes, ?int $variantId): array
    {
        if ($productId < 1 || $quantity < 1) {
            throw ValidationException::withMessages([
                'items' => 'Escolha o produto e a quantidade.',
            ]);
        }

        if ($quantity > 99) {
            throw ValidationException::withMessages([
                'quantity' => 'Quantidade máxima por item é 99.',
            ]);
        }

        $product = $this->catalog->findSellable($company, $productId);

        if ($product === null) {
            throw ValidationException::withMessages([
                'product_id' => 'Este produto não está disponível para venda nesta empresa.',
            ]);
        }

        $variant = null;

        if ($product->hasActiveVariants()) {
            if ($variantId === null || $variantId < 1) {
                throw ValidationException::withMessages([
                    'variant_id' => "Escolha o tamanho de {$product->name}.",
                ]);
            }

            $variant = $this->catalog->findActiveVariant($product, $variantId);

            if ($variant === null) {
                throw ValidationException::withMessages([
                    'variant_id' => "Tamanho inválido para {$product->name}.",
                ]);
            }
        }

        $unitPrice = $this->catalog->unitPriceCents($product, $variant);

        if ($unitPrice < 1) {
            throw ValidationException::withMessages([
                'product_id' => "{$product->name} está sem preço de venda.",
            ]);
        }

        return [
            'product_id' => (int) $product->getKey(),
            'product_variant_id' => $variant !== null ? (int) $variant->getKey() : null,
            'name' => $this->catalog->snapshotName($product, $variant),
            'variant_name' => $variant?->name,
            'unit_price_cents' => $unitPrice,
            'quantity' => $quantity,
            'line_total_cents' => $unitPrice * $quantity,
            'notes' => PublicBookingTextSanitizer::sanitize($notes, 240),
        ];
    }

    /**
     * @param  array{product_id: int, product_variant_id: int|null, quantity: int, notes: string|null}  $snapshot
     */
    protected function assertNotADoubleSubmit(Order $order, array $snapshot): void
    {
        $fingerprint = sha1(implode('|', [
            $order->getKey(),
            $snapshot['product_id'],
            $snapshot['product_variant_id'] ?? 0,
            $snapshot['quantity'],
            (string) ($snapshot['notes'] ?? ''),
        ]));

        if (! Cache::add('floor-order-item:'.$fingerprint, 1, now()->addSeconds(2))) {
            throw ValidationException::withMessages([
                'items' => 'Este item acabou de ser lançado. Aguarde um instante para lançar de novo.',
            ]);
        }
    }

    protected function recalculateTotals(Order $order): void
    {
        $subtotal = (int) $order->items()->sum('line_total_cents');
        $order->subtotal_cents = $subtotal;
        $order->delivery_fee_cents = 0;
        $order->total_cents = $subtotal;
        $order->save();
    }

    protected function returnToKitchen(Company $company, Order $order, ?User $user): void
    {
        if (! in_array($order->status, [OrderStatus::Preparing, OrderStatus::Ready, OrderStatus::OutForDelivery], true)) {
            return;
        }

        $from = $order->status;
        $order->status = OrderStatus::Received;
        $order->save();
        $this->recordHistory($company, $order, $from, OrderStatus::Received, $user);

        DB::afterCommit(fn () => event(new OrderStatusChanged(
            $order->fresh(['items', 'company', 'diningTable']) ?? $order,
            $from,
            OrderStatus::Received,
        )));
    }

    protected function nextNumber(Company $company): int
    {
        $max = Order::query()
            ->where('company_id', $company->getKey())
            ->lockForUpdate()
            ->max('number');

        return ((int) $max) + 1;
    }

    protected function recordHistory(
        Company $company,
        Order $order,
        ?OrderStatus $from,
        OrderStatus $to,
        ?User $user,
    ): void {
        $history = new OrderStatusHistory([
            'from_status' => $from,
            'to_status' => $to,
        ]);
        $history->company()->associate($company);
        $history->order()->associate($order);

        if ($user !== null) {
            $history->user()->associate($user);
        }

        $history->save();
    }
}
