<?php

namespace App\Services\Orders;

use App\Enums\CompanyModule;
use App\Enums\OrderFulfillment;
use App\Enums\OrderStatus;
use App\Events\OrderCreated;
use App\Events\OrderStatusChanged;
use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\User;
use App\Services\Company\CompanyModuleService;
use App\Support\PhoneNormalizer;
use App\Support\PublicBookingTextSanitizer;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderService
{
    public function __construct(
        protected CompanyOrderSettingService $settings,
        protected OrderCatalogService $catalog,
        protected OrderPublicCodeGenerator $codes,
        protected CompanyModuleService $modules,
        protected PublicOrderRateLimiter $rateLimiter,
        protected OrderSaleService $orderSales,
    ) {}

    public function ensureBelongsToCompany(Company $company, Order $order): void
    {
        if ((int) $order->company_id !== (int) $company->getKey()) {
            abort(404);
        }
    }

    /**
     * @param  array{
     *     items: list<array{product_id: int, variant_id?: int|null, quantity: int, notes?: string|null}>,
     *     fulfillment: OrderFulfillment|string,
     *     customer_name: string,
     *     customer_phone: string,
     *     customer_email?: string|null,
     *     delivery_address?: string|null,
     *     delivery_complement?: string|null,
     *     delivery_neighborhood?: string|null,
     *     delivery_city?: string|null,
     *     notes?: string|null,
     *     idempotency_key?: string|null,
     * }  $data
     */
    public function createPublic(Company $company, array $data, ?string $ip = null): Order
    {
        $this->assertCompanyCanReceiveOrders($company);

        $setting = $this->settings->getOrCreate($company);
        $fulfillment = $data['fulfillment'] instanceof OrderFulfillment
            ? $data['fulfillment']
            : OrderFulfillment::tryFrom((string) $data['fulfillment']);

        if ($fulfillment === null || $fulfillment === OrderFulfillment::DineIn) {
            throw ValidationException::withMessages([
                'fulfillment' => 'Escolha retirada ou entrega.',
            ]);
        }

        if ($fulfillment === OrderFulfillment::Pickup && ! $setting->pickup_enabled) {
            throw ValidationException::withMessages([
                'fulfillment' => 'Retirada não está disponível no momento.',
            ]);
        }

        if ($fulfillment === OrderFulfillment::Delivery && ! $setting->delivery_enabled) {
            throw ValidationException::withMessages([
                'fulfillment' => 'Entrega não está disponível no momento.',
            ]);
        }

        $phone = PublicBookingTextSanitizer::sanitize((string) ($data['customer_phone'] ?? ''), 40);
        $phoneNormalized = PhoneNormalizer::normalize($phone);

        if ($ip !== null) {
            $this->rateLimiter->assertCreateAttemptAllowed((int) $company->getKey(), $ip, $phoneNormalized);
        }

        $customerName = PublicBookingTextSanitizer::clientName($data['customer_name'] ?? null);

        if (blank($customerName)) {
            throw ValidationException::withMessages([
                'customer_name' => 'Informe o nome para o pedido.',
            ]);
        }

        if (blank($phoneNormalized) || strlen($phoneNormalized) < 10) {
            throw ValidationException::withMessages([
                'customer_phone' => 'Informe um telefone válido com DDD.',
            ]);
        }

        $items = $this->snapshotItems($company, $data['items'] ?? []);
        $subtotalCents = collect($items)->sum(fn (array $item): int => $item['line_total_cents']);

        if ($setting->min_order_cents > 0 && $subtotalCents < $setting->min_order_cents) {
            throw ValidationException::withMessages([
                'items' => 'O pedido mínimo é de '.$this->formatCents($setting->min_order_cents).'.',
            ]);
        }

        $deliveryFeeCents = $fulfillment === OrderFulfillment::Delivery
            ? (int) $setting->delivery_fee_cents
            : 0;

        if ($fulfillment === OrderFulfillment::Delivery) {
            $address = PublicBookingTextSanitizer::sanitize($data['delivery_address'] ?? null, 255);

            if (blank($address)) {
                throw ValidationException::withMessages([
                    'delivery_address' => 'Informe o endereço de entrega.',
                ]);
            }
        }

        $idempotencyKey = filled($data['idempotency_key'] ?? null)
            ? (string) $data['idempotency_key']
            : null;

        $persist = fn (): Order => $this->persistPublicOrder(
            $company,
            $fulfillment,
            $customerName,
            $phone,
            $phoneNormalized,
            $data,
            $items,
            $subtotalCents,
            $deliveryFeeCents,
            $idempotencyKey,
        );

        if ($idempotencyKey === null) {
            return $persist();
        }

        $existing = $this->findByIdempotencyKey($company, $idempotencyKey);

        if ($existing !== null) {
            return $existing->load('items');
        }

        $lock = Cache::lock("public-order:{$company->getKey()}:{$idempotencyKey}", 10);

        try {
            $lock->block(5);

            return $persist();
        } finally {
            optional($lock)->release();
        }
    }

    public function advance(Company $company, Order $order, ?User $user = null): Order
    {
        $this->ensureBelongsToCompany($company, $order);

        $next = $order->nextStatus();

        if ($next === null) {
            throw ValidationException::withMessages([
                'status' => 'Este pedido não pode avançar de status.',
            ]);
        }

        return $this->transition($company, $order, $next, $user);
    }

    public function transition(Company $company, Order $order, OrderStatus $to, ?User $user = null, ?string $cancelReason = null): Order
    {
        $this->ensureBelongsToCompany($company, $order);

        return DB::transaction(function () use ($company, $order, $to, $user, $cancelReason): Order {
            /** @var Order $locked */
            $locked = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            $from = $locked->status;

            if ($to === OrderStatus::Completed && $from === OrderStatus::Completed) {
                $this->orderSales->syncFromCompletedOrder($company, $locked, $user);

                return $locked->refresh()->load('items');
            }

            if ($to === OrderStatus::Cancelled) {
                if (! $locked->canCancel()) {
                    throw ValidationException::withMessages([
                        'status' => 'Este pedido não pode ser cancelado.',
                    ]);
                }

                $reason = PublicBookingTextSanitizer::cancellationReason($cancelReason);

                if (blank($reason)) {
                    throw ValidationException::withMessages([
                        'cancel_reason' => 'Informe o motivo do cancelamento.',
                    ]);
                }

                $locked->cancel_reason = $reason;
            } elseif (! $from->canTransitionTo($to, $locked->fulfillment)) {
                throw ValidationException::withMessages([
                    'status' => 'Transição de status inválida para este pedido.',
                ]);
            }

            $locked->status = $to;
            $column = $to->timestampColumn();
            $locked->{$column} = now();
            $locked->save();

            $this->recordHistory($company, $locked, $from, $to, $user);

            if ($to === OrderStatus::Completed) {
                $this->orderSales->syncFromCompletedOrder($company, $locked, $user);
            }

            DB::afterCommit(fn () => event(new OrderStatusChanged(
                $locked->fresh(['items', 'company']) ?? $locked,
                $from,
                $to,
            )));

            return $locked->refresh()->load('items');
        });
    }

    public function cancel(Company $company, Order $order, string $reason, ?User $user = null): Order
    {
        return $this->transition($company, $order, OrderStatus::Cancelled, $user, $reason);
    }

    public function assertCompanyCanReceiveOrders(Company $company): void
    {
        if (! $company->is_active) {
            abort(404);
        }

        if (! $this->modules->hasModule($company, CompanyModule::Orders)) {
            abort(404);
        }

        $setting = $this->settings->getOrCreate($company);

        if (! $setting->online_ordering_enabled) {
            abort(404);
        }
    }

    /**
     * @param  list<array{product_id?: mixed, variant_id?: mixed, quantity?: mixed, notes?: mixed}>  $rawItems
     * @return list<array{product_id: int, product_variant_id: int|null, name: string, variant_name: string|null, unit_price_cents: int, quantity: int, line_total_cents: int, notes: string|null}>
     */
    protected function snapshotItems(Company $company, array $rawItems): array
    {
        if ($rawItems === []) {
            throw ValidationException::withMessages([
                'items' => 'Adicione ao menos um item ao pedido.',
            ]);
        }

        $snapshots = [];

        foreach ($rawItems as $index => $raw) {
            $productId = (int) ($raw['product_id'] ?? 0);
            $quantity = (int) ($raw['quantity'] ?? 0);

            if ($productId < 1 || $quantity < 1) {
                throw ValidationException::withMessages([
                    "items.{$index}" => 'Item inválido.',
                ]);
            }

            if ($quantity > 99) {
                throw ValidationException::withMessages([
                    "items.{$index}" => 'Quantidade máxima por item é 99.',
                ]);
            }

            $product = $this->catalog->findAvailable($company, $productId);

            if ($product === null) {
                throw ValidationException::withMessages([
                    "items.{$index}" => 'Um dos itens não está mais disponível no cardápio.',
                ]);
            }

            $variant = null;

            if ($product->hasActiveVariants()) {
                $variantId = (int) ($raw['variant_id'] ?? 0);

                if ($variantId < 1) {
                    throw ValidationException::withMessages([
                        "items.{$index}" => "Escolha o tamanho de {$product->name}.",
                    ]);
                }

                $variant = $this->catalog->findActiveVariant($product, $variantId);

                if ($variant === null) {
                    throw ValidationException::withMessages([
                        "items.{$index}" => "Tamanho inválido para {$product->name}.",
                    ]);
                }
            }

            $unitPrice = $this->catalog->unitPriceCents($product, $variant);

            if ($unitPrice < 1) {
                throw ValidationException::withMessages([
                    "items.{$index}" => "{$product->name} está sem preço de venda.",
                ]);
            }

            $snapshots[] = [
                'product_id' => (int) $product->getKey(),
                'product_variant_id' => $variant !== null ? (int) $variant->getKey() : null,
                'name' => $this->catalog->snapshotName($product, $variant),
                'variant_name' => $variant?->name,
                'unit_price_cents' => $unitPrice,
                'quantity' => $quantity,
                'line_total_cents' => $unitPrice * $quantity,
                'notes' => PublicBookingTextSanitizer::sanitize(
                    isset($raw['notes']) ? (string) $raw['notes'] : null,
                    240,
                ),
            ];
        }

        return $snapshots;
    }

    /**
     * @param  array{
     *     customer_email?: string|null,
     *     delivery_address?: string|null,
     *     delivery_complement?: string|null,
     *     delivery_neighborhood?: string|null,
     *     delivery_city?: string|null,
     *     notes?: string|null,
     * }  $data
     * @param  list<array{product_id: int, product_variant_id: int|null, name: string, variant_name: string|null, unit_price_cents: int, quantity: int, line_total_cents: int, notes: string|null}>  $items
     */
    protected function persistPublicOrder(
        Company $company,
        OrderFulfillment $fulfillment,
        string $customerName,
        string $phone,
        string $phoneNormalized,
        array $data,
        array $items,
        int $subtotalCents,
        int $deliveryFeeCents,
        ?string $idempotencyKey,
    ): Order {
        $attempts = 0;
        $maxAttempts = 3;

        while ($attempts < $maxAttempts) {
            $attempts++;

            try {
                return DB::transaction(function () use (
                    $company,
                    $fulfillment,
                    $customerName,
                    $phone,
                    $phoneNormalized,
                    $data,
                    $items,
                    $subtotalCents,
                    $deliveryFeeCents,
                    $idempotencyKey,
                ): Order {
                    if ($idempotencyKey !== null) {
                        $existing = $this->findByIdempotencyKey($company, $idempotencyKey);

                        if ($existing !== null) {
                            return $existing->load('items');
                        }
                    }

                    $order = new Order([
                        'number' => $this->nextNumber($company),
                        'public_code' => $this->codes->generate($company),
                        'idempotency_key' => $idempotencyKey,
                        'status' => OrderStatus::Received,
                        'fulfillment' => $fulfillment,
                        'customer_name' => $customerName,
                        'customer_phone' => $phone,
                        'customer_phone_normalized' => $phoneNormalized,
                        'customer_email' => PublicBookingTextSanitizer::sanitize($data['customer_email'] ?? null, 255),
                        'delivery_address' => $fulfillment === OrderFulfillment::Delivery
                            ? PublicBookingTextSanitizer::sanitize($data['delivery_address'] ?? null, 255)
                            : null,
                        'delivery_complement' => $fulfillment === OrderFulfillment::Delivery
                            ? PublicBookingTextSanitizer::sanitize($data['delivery_complement'] ?? null, 120)
                            : null,
                        'delivery_neighborhood' => $fulfillment === OrderFulfillment::Delivery
                            ? PublicBookingTextSanitizer::sanitize($data['delivery_neighborhood'] ?? null, 120)
                            : null,
                        'delivery_city' => $fulfillment === OrderFulfillment::Delivery
                            ? PublicBookingTextSanitizer::sanitize($data['delivery_city'] ?? null, 120)
                            : null,
                        'subtotal_cents' => $subtotalCents,
                        'delivery_fee_cents' => $deliveryFeeCents,
                        'total_cents' => $subtotalCents + $deliveryFeeCents,
                        'notes' => PublicBookingTextSanitizer::clientNotes($data['notes'] ?? null),
                        'received_at' => now(),
                    ]);
                    $order->company()->associate($company);
                    $order->save();

                    foreach ($items as $item) {
                        $orderItem = new OrderItem($item);
                        $orderItem->order()->associate($order);
                        $orderItem->save();
                    }

                    $this->recordHistory($company, $order, null, OrderStatus::Received, null);

                    DB::afterCommit(fn () => event(new OrderCreated($order->fresh(['items', 'company']))));

                    return $order->fresh(['items']) ?? $order;
                });
            } catch (UniqueConstraintViolationException $exception) {
                if ($idempotencyKey !== null) {
                    $existing = $this->findByIdempotencyKey($company, $idempotencyKey);

                    if ($existing !== null) {
                        return $existing->load('items');
                    }
                }

                if ($attempts >= $maxAttempts) {
                    throw $exception;
                }
            }
        }

        throw new \RuntimeException('Não foi possível gravar o pedido. Tente novamente.');
    }

    protected function findByIdempotencyKey(Company $company, string $idempotencyKey): ?Order
    {
        return Order::query()
            ->where('company_id', $company->getKey())
            ->where('idempotency_key', $idempotencyKey)
            ->first();
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

    protected function formatCents(int $cents): string
    {
        return 'R$ '.number_format($cents / 100, 2, ',', '.');
    }
}
