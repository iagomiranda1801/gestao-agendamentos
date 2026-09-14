<?php

namespace App\Livewire\PublicOrders;

use App\Enums\OrderFulfillment;
use App\Models\Company;
use App\Models\CompanyBusinessHour;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Orders\CompanyOrderSettingService;
use App\Services\Orders\OrderCatalogService;
use App\Services\Orders\OrderService;
use App\Support\CompanyDateTime;
use App\Support\Money;
use App\Support\PhoneNormalizer;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Throwable;

#[Layout('layouts.public-booking')]
class OrderWizard extends Component
{
    public const STEP_MENU = 'menu';

    public const STEP_FULFILLMENT = 'fulfillment';

    public const STEP_CUSTOMER = 'customer';

    public const STEP_REVIEW = 'review';

    public const STEP_CONFIRMATION = 'confirmation';

    public Company $company;

    public string $step = self::STEP_MENU;

    public string $idempotencyUuid;

    public int $formStartedAt;

    public string $website_url = '';

    /** @var array<string, array{product_id: int, variant_id: int|null, quantity: int, notes: string}> */
    public array $cart = [];

    /** @var array<int|string, int|string|null> */
    public array $selectedVariant = [];

    public string $fulfillment = '';

    public string $customerName = '';

    public string $customerPhone = '';

    public ?string $customerEmail = null;

    public ?string $deliveryAddress = null;

    public ?string $deliveryComplement = null;

    public ?string $deliveryNeighborhood = null;

    public ?string $deliveryCity = null;

    public ?string $notes = null;

    public ?string $confirmationCode = null;

    public ?string $confirmationNumber = null;

    public ?string $confirmationMessage = null;

    public ?string $errorMessage = null;

    public bool $isSubmitting = false;

    public function mount(Company $company): void
    {
        app(OrderService::class)->assertCompanyCanReceiveOrders($company);

        $this->company = $company->load(['orderSetting', 'businessHours']);
        $this->idempotencyUuid = (string) Str::uuid();
        $this->formStartedAt = time();
        $this->hydrateSelectedVariants();
    }

    /**
     * @return array<string, mixed>
     */
    protected function bookingLayoutData(): array
    {
        $settings = $this->company->orderSetting;

        return [
            'primaryColor' => filled($settings?->primary_color)
                ? $settings->primary_color
                : '#c2410c',
            'company' => $this->company,
            'tagline' => 'Pedidos online',
        ];
    }

    public function addToCart(int $productId, ?int $variantId = null): void
    {
        $this->errorMessage = null;
        $catalog = app(OrderCatalogService::class);
        $product = $catalog->findAvailable($this->company, $productId);

        if ($product === null) {
            $this->errorMessage = 'Item indisponível.';

            return;
        }

        $variant = null;

        if ($product->hasActiveVariants()) {
            $resolvedId = $variantId ?? (int) ($this->selectedVariant[$productId] ?? 0);

            if ($resolvedId < 1) {
                $resolvedId = (int) ($catalog->defaultVariant($product)?->getKey() ?? 0);
            }

            if ($resolvedId < 1) {
                $this->errorMessage = 'Escolha o tamanho.';

                return;
            }

            $variant = $catalog->findActiveVariant($product, $resolvedId);

            if ($variant === null) {
                $this->errorMessage = 'Tamanho inválido.';

                return;
            }

            $this->selectedVariant[$productId] = (int) $variant->getKey();
        }

        $cartKey = $this->cartKey($productId, $variant !== null ? (int) $variant->getKey() : null);

        if (isset($this->cart[$cartKey])) {
            $this->cart[$cartKey]['quantity'] = min(99, $this->cart[$cartKey]['quantity'] + 1);

            return;
        }

        $this->cart[$cartKey] = [
            'product_id' => $productId,
            'variant_id' => $variant !== null ? (int) $variant->getKey() : null,
            'quantity' => 1,
            'notes' => '',
        ];
    }

    public function incrementItem(int|string $key): void
    {
        $cartKey = $this->resolveCartKey($key);

        if (! isset($this->cart[$cartKey])) {
            $this->addToCartFromKey($key);

            return;
        }

        $this->cart[$cartKey]['quantity'] = min(99, $this->cart[$cartKey]['quantity'] + 1);
    }

    public function decrementItem(int|string $key): void
    {
        $cartKey = $this->resolveCartKey($key);

        if (! isset($this->cart[$cartKey])) {
            return;
        }

        $this->cart[$cartKey]['quantity']--;

        if ($this->cart[$cartKey]['quantity'] < 1) {
            unset($this->cart[$cartKey]);
        }
    }

    public function removeItem(int|string $key): void
    {
        unset($this->cart[$this->resolveCartKey($key)]);
    }

    public function goToFulfillment(): void
    {
        $this->errorMessage = null;

        if ($this->cart === []) {
            $this->errorMessage = 'Adicione ao menos um item ao pedido.';

            return;
        }

        $this->step = self::STEP_FULFILLMENT;
    }

    public function selectFulfillment(string $fulfillment): void
    {
        $this->errorMessage = null;
        $this->fulfillment = $fulfillment;
        $this->step = self::STEP_CUSTOMER;
    }

    public function goToReview(): void
    {
        $this->errorMessage = null;

        try {
            $this->validate($this->customerRules());
        } catch (ValidationException $exception) {
            $this->errorMessage = collect($exception->errors())->flatten()->first();

            throw $exception;
        }

        $this->step = self::STEP_REVIEW;
    }

    public function backToMenu(): void
    {
        $this->step = self::STEP_MENU;
    }

    public function backToFulfillment(): void
    {
        $this->step = self::STEP_FULFILLMENT;
    }

    public function backToCustomer(): void
    {
        $this->step = self::STEP_CUSTOMER;
    }

    public function submit(): void
    {
        $this->errorMessage = null;

        if ($this->isSubmitting) {
            return;
        }

        if (filled($this->website_url) || (! app()->environment('testing') && (time() - $this->formStartedAt) < 2)) {
            $this->errorMessage = 'Não foi possível concluir o pedido. Tente novamente.';

            return;
        }

        $this->isSubmitting = true;

        try {
            $this->validate($this->customerRules());

            $order = app(OrderService::class)->createPublic($this->company, [
                'items' => collect($this->cart)
                    ->map(fn (array $row): array => [
                        'product_id' => (int) $row['product_id'],
                        'variant_id' => ((int) ($row['variant_id'] ?? 0)) > 0 ? (int) $row['variant_id'] : null,
                        'quantity' => (int) $row['quantity'],
                        'notes' => $row['notes'] ?? null,
                    ])
                    ->values()
                    ->all(),
                'fulfillment' => $this->fulfillment,
                'customer_name' => $this->customerName,
                'customer_phone' => $this->customerPhone,
                'customer_email' => $this->customerEmail,
                'delivery_address' => $this->deliveryAddress,
                'delivery_complement' => $this->deliveryComplement,
                'delivery_neighborhood' => $this->deliveryNeighborhood,
                'delivery_city' => $this->deliveryCity,
                'notes' => $this->notes,
                'idempotency_key' => $this->idempotencyUuid,
            ], request()->ip());

            $settings = $this->company->orderSetting;
            $this->confirmationCode = $order->public_code;
            $this->confirmationNumber = $order->displayNumber();
            $this->confirmationMessage = filled($settings?->confirmation_message)
                ? $settings->confirmation_message
                : 'Pedido enviado. Pague na '.$order->fulfillment->label().'.';
            $this->step = self::STEP_CONFIRMATION;
        } catch (ValidationException $exception) {
            $this->errorMessage = collect($exception->errors())->flatten()->first();
            $this->isSubmitting = false;

            throw $exception;
        } catch (Throwable $exception) {
            report($exception);
            $this->errorMessage = 'Não foi possível concluir o pedido. Tente novamente.';
            $this->isSubmitting = false;
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function customerRules(): array
    {
        $rules = [
            'customerName' => ['required', 'string', 'max:255'],
            'customerPhone' => ['required', 'string', 'min:10', 'max:40'],
            'customerEmail' => ['nullable', 'email', 'max:255'],
            'notes' => ['nullable', 'string', 'max:500'],
            'fulfillment' => ['required', 'in:pickup,delivery'],
        ];

        if ($this->fulfillment === OrderFulfillment::Delivery->value) {
            $rules['deliveryAddress'] = ['required', 'string', 'max:255'];
            $rules['deliveryComplement'] = ['nullable', 'string', 'max:120'];
            $rules['deliveryNeighborhood'] = ['nullable', 'string', 'max:120'];
            $rules['deliveryCity'] = ['nullable', 'string', 'max:120'];
        }

        return $rules;
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    protected function visibleSteps(): array
    {
        $steps = [
            ['key' => self::STEP_MENU, 'label' => 'Cardápio'],
            ['key' => self::STEP_FULFILLMENT, 'label' => 'Retirada ou entrega'],
            ['key' => self::STEP_CUSTOMER, 'label' => 'Seus dados'],
            ['key' => self::STEP_REVIEW, 'label' => 'Revisão'],
        ];

        if ($this->step === self::STEP_CONFIRMATION) {
            $steps[] = ['key' => self::STEP_CONFIRMATION, 'label' => 'Confirmação'];
        }

        return $steps;
    }

    public function cartSubtotalCents(): int
    {
        $catalog = app(OrderCatalogService::class);
        $total = 0;

        foreach ($this->cart as $item) {
            $product = $catalog->findAvailable($this->company, (int) $item['product_id']);

            if ($product === null) {
                continue;
            }

            $variant = $this->resolveCartVariant($catalog, $product, $item);
            $total += $catalog->unitPriceCents($product, $variant) * (int) $item['quantity'];
        }

        return $total;
    }

    public function deliveryFeeCents(): int
    {
        $settings = $this->company->orderSetting;

        if ($this->fulfillment !== OrderFulfillment::Delivery->value) {
            return 0;
        }

        return (int) ($settings?->delivery_fee_cents ?? 0);
    }

    public function cartTotalCents(): int
    {
        return $this->cartSubtotalCents() + $this->deliveryFeeCents();
    }

    public function formatMoneyCents(int $cents): string
    {
        return Money::formatCents($cents);
    }

    /**
     * @return Collection<int, array{product: Product, variant: ProductVariant|null, label: string, quantity: int, notes: string, line_total_cents: int}>
     */
    public function cartLines(): Collection
    {
        $catalog = app(OrderCatalogService::class);

        return collect($this->cart)
            ->map(function (array $item) use ($catalog): ?array {
                $product = $catalog->findAvailable($this->company, (int) $item['product_id']);

                if ($product === null) {
                    return null;
                }

                $variant = $this->resolveCartVariant($catalog, $product, $item);

                return [
                    'product' => $product,
                    'variant' => $variant,
                    'label' => $catalog->snapshotName($product, $variant),
                    'quantity' => (int) $item['quantity'],
                    'notes' => (string) ($item['notes'] ?? ''),
                    'line_total_cents' => $catalog->unitPriceCents($product, $variant) * (int) $item['quantity'],
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * @return list<string>
     */
    public function businessHoursSummary(): array
    {
        return $this->company->businessHours
            ->where('is_active', true)
            ->sortBy('weekday')
            ->map(function (CompanyBusinessHour $hour): string {
                $weekday = $hour->weekdayEnum()->label();
                $start = substr((string) $hour->start_time, 0, 5);
                $end = substr((string) $hour->end_time, 0, 5);

                return "{$weekday}: {$start}–{$end}";
            })
            ->values()
            ->all();
    }

    protected function hydrateSelectedVariants(): void
    {
        $catalog = app(OrderCatalogService::class);
        $grouped = $catalog->groupedByCategory($this->company);

        foreach ($grouped as $products) {
            foreach ($products as $product) {
                if (! $product->hasActiveVariants()) {
                    continue;
                }

                if (! empty($this->selectedVariant[$product->id])) {
                    continue;
                }

                $default = $catalog->defaultVariant($product);
                $this->selectedVariant[$product->id] = $default !== null ? (int) $default->getKey() : null;
            }
        }
    }

    protected function cartKey(int $productId, ?int $variantId): string
    {
        return $productId.':'.((int) $variantId);
    }

    protected function resolveCartKey(int|string $key): string
    {
        if (is_string($key) && str_contains($key, ':')) {
            return $key;
        }

        $productId = (int) $key;
        $zero = $this->cartKey($productId, null);

        if (isset($this->cart[$zero])) {
            return $zero;
        }

        foreach (array_keys($this->cart) as $candidate) {
            if (str_starts_with((string) $candidate, $productId.':')) {
                return (string) $candidate;
            }
        }

        return $zero;
    }

    protected function addToCartFromKey(int|string $key): void
    {
        if (is_string($key) && str_contains($key, ':')) {
            [$productId, $variantId] = array_map('intval', explode(':', $key, 2));
            $this->addToCart($productId, $variantId > 0 ? $variantId : null);

            return;
        }

        $this->addToCart((int) $key);
    }

    /**
     * @param  array{product_id?: mixed, variant_id?: mixed}  $item
     */
    protected function resolveCartVariant(OrderCatalogService $catalog, Product $product, array $item): ?ProductVariant
    {
        $variantId = (int) ($item['variant_id'] ?? 0);

        if ($variantId < 1) {
            return null;
        }

        return $catalog->findActiveVariant($product, $variantId);
    }

    public function render(OrderCatalogService $catalog, CompanyOrderSettingService $settings)
    {
        $setting = $this->company->orderSetting ?? $settings->getOrCreate($this->company);
        $grouped = $catalog->groupedByCategory($this->company);
        $pageTitle = filled($setting->page_title)
            ? $setting->page_title
            : 'Pedir — '.$this->company->name;

        return view('livewire.public-orders.order-wizard', [
            'settings' => $setting,
            'groupedProducts' => $grouped,
            'cartLines' => $this->cartLines(),
            'pageTitle' => $pageTitle,
            'steps' => $this->visibleSteps(),
            'pickupEnabled' => (bool) $setting->pickup_enabled,
            'deliveryEnabled' => (bool) $setting->delivery_enabled,
            'hours' => $this->businessHoursSummary(),
            'nowLocal' => CompanyDateTime::nowLocal($this->company)->format('H:i'),
            'phoneNormalized' => PhoneNormalizer::normalize($this->customerPhone),
        ])->layoutData($this->bookingLayoutData());
    }
}
