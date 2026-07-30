<?php

namespace App\Services\Sales;

use App\DataTransferObjects\Financial\PaymentData;
use App\Enums\SaleItemType;
use App\Enums\SaleOrigin;
use App\Enums\SaleStatus;
use App\Enums\StockDocumentType;
use App\Enums\ProductType;
use App\Models\Company;
use App\Models\FinancialAccount;
use App\Models\Product;
use App\Models\Receivable;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Service;
use App\Models\StockDocument;
use App\Models\User;
use App\Services\Financial\CashSessionService;
use App\Services\Financial\CompanyFinancialSettingService;
use App\Services\Financial\ReceivableService;
use App\Services\Stock\StockDocumentPostingService;
use App\Services\Stock\StockDocumentService;
use App\Support\DecimalMoney;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaleService
{
    public function __construct(
        protected CompanyFinancialSettingService $financialSettingService,
        protected ReceivableService $receivableService,
        protected StockDocumentService $stockDocumentService,
        protected StockDocumentPostingService $stockDocumentPostingService,
        protected CashSessionService $cashSessionService,
    ) {}

    /**
     * @param  array{
     *     client_id?: int|null,
     *     origin?: string|null,
     *     sold_at?: CarbonInterface|string|null,
     *     discount_amount?: string|int|float|null,
     *     notes?: string|null,
     *     items: list<array<string, mixed>>,
     *     payments?: list<PaymentData>
     * }  $data
     */
    public function complete(Company $company, User $user, array $data): Sale
    {
        $soldAt = isset($data['sold_at']) && $data['sold_at'] !== null
            ? Carbon::parse($data['sold_at'])
            : now();

        $validatedItems = $this->validateItems($company, $data['items'] ?? []);
        $grossAmount = $this->calculateGrossAmount($validatedItems);
        $discountAmount = $this->normalizeNonNegativeMoney($data['discount_amount'] ?? '0', 'discount_amount');
        $finalAmount = DecimalMoney::round(bcsub($grossAmount, $discountAmount, 4));

        if (bccomp($finalAmount, '0.00', 2) <= 0) {
            throw ValidationException::withMessages([
                'final_amount' => 'O valor final da venda deve ser maior que zero.',
            ]);
        }

        $payments = $data['payments'] ?? [];
        $this->validatePayments($company, $payments, $finalAmount);
        $this->validateStockAvailability($validatedItems);

        return DB::transaction(function () use (
            $company,
            $user,
            $data,
            $soldAt,
            $validatedItems,
            $grossAmount,
            $discountAmount,
            $finalAmount,
            $payments,
        ): Sale {
            $sale = new Sale([
                'status' => SaleStatus::Completed,
                'origin' => SaleOrigin::tryFrom((string) ($data['origin'] ?? SaleOrigin::Pos->value)) ?? SaleOrigin::Pos,
                'gross_amount' => $grossAmount,
                'discount_amount' => $discountAmount,
                'final_amount' => $finalAmount,
                'paid_amount' => '0.00',
                'outstanding_amount' => $finalAmount,
                'notes' => $data['notes'] ?? null,
                'sold_at' => $soldAt,
                'client_id' => $data['client_id'] ?? null,
            ]);
            $sale->company()->associate($company);
            $sale->seller()->associate($user);
            $sale->save();

            $this->createItems($company, $sale, $validatedItems);
            $stockDocument = $this->createAndPostStockDocument($company, $user, $sale, $validatedItems, $soldAt);
            $receivable = $this->receivableService->createForSale($company, $sale, $user, $soldAt->copy()->startOfDay());

            foreach ($payments as $payment) {
                $this->attachCashSessionIfNeeded($company, $sale, $payment);
                $this->receivableService->registerPayment($company, $receivable, $payment, $user);
            }

            $this->refreshSaleAmounts($sale->refresh(), $receivable->refresh());

            return $sale->refresh()->load([
                'items.product',
                'receivable.payments',
                'payments',
                'stockDocument',
            ]);
        });
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array{type: SaleItemType, product?: Product, service?: Service, name: string, quantity: string, unit_price: string, unit_cost: string, discount_amount: string, line_total: string, tracks_stock: bool}>
     */
    protected function validateItems(Company $company, array $items): array
    {
        if ($items === []) {
            throw ValidationException::withMessages([
                'items' => 'Adicione ao menos um item à venda.',
            ]);
        }

        $validated = [];

        foreach ($items as $index => $item) {
            $type = SaleItemType::tryFrom((string) ($item['item_type'] ?? SaleItemType::Product->value));

            if (! in_array($type, [SaleItemType::Product, SaleItemType::Service, SaleItemType::Custom], true)) {
                throw ValidationException::withMessages([
                    "items.{$index}.item_type" => 'Tipo de item inválido para venda rápida.',
                ]);
            }

            $product = null;
            $service = null;
            $name = trim((string) ($item['name'] ?? ''));
            $unitCost = '0.000000';
            $tracksStock = false;

            if ($type === SaleItemType::Product) {
                $product = $this->resolveProductItem($company, $item, $index);
                $name = $product->name;
                $unitCost = $product->getCurrentUnitCost();
                $tracksStock = (bool) $product->tracks_stock;
            }

            if ($type === SaleItemType::Service) {
                $service = $this->resolveServiceItem($company, $item, $index);
                $name = $service->name;
                $unitCost = $service->getEstimatedMaterialCost();
                $tracksStock = $service->consumptions->contains(fn ($consumption): bool => (bool) $consumption->product?->tracks_stock);
            }

            if ($type === SaleItemType::Custom && $name === '') {
                throw ValidationException::withMessages([
                    "items.{$index}.name" => 'Informe a descrição do item avulso.',
                ]);
            }

            $quantity = $this->normalizePositiveQuantity($item['quantity'] ?? '1', "items.{$index}.quantity");
            $unitPrice = $this->normalizeNonNegativeMoney(
                $item['unit_price'] ?? $product?->sale_price ?? $service?->price ?? '0.00',
                "items.{$index}.unit_price",
            );
            $discountAmount = $this->normalizeNonNegativeMoney($item['discount_amount'] ?? '0', "items.{$index}.discount_amount");
            $lineGross = DecimalMoney::round(bcmul($quantity, $unitPrice, 4));
            $lineTotal = DecimalMoney::round(bcsub($lineGross, $discountAmount, 4));

            if (bccomp($lineTotal, '0.00', 2) < 0) {
                throw ValidationException::withMessages([
                    "items.{$index}.discount_amount" => 'O desconto do item não pode ser maior que o total bruto.',
                ]);
            }

            $validated[] = [
                'type' => $type,
                'product' => $product,
                'service' => $service,
                'name' => $name,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'unit_cost' => $unitCost,
                'discount_amount' => $discountAmount,
                'line_total' => $lineTotal,
                'tracks_stock' => $tracksStock,
            ];
        }

        return $validated;
    }

    /**
     * @param  list<array{line_total: string}>  $items
     */
    protected function calculateGrossAmount(array $items): string
    {
        $total = '0.00';

        foreach ($items as $item) {
            $total = bcadd($total, $item['line_total'], 2);
        }

        return DecimalMoney::round($total);
    }

    /**
     * @param  list<array{type: SaleItemType, product?: Product, service?: Service, quantity: string}>  $items
     */
    protected function validateStockAvailability(array $items): void
    {
        foreach ($this->buildStockItems($items) as $stockItem) {
            $product = $stockItem['product'];

            if (bccomp($product->getCurrentStockQuantity(), $stockItem['quantity'], 4) < 0) {
                throw ValidationException::withMessages([
                    'items' => "Saldo insuficiente para o produto {$product->name}.",
                ]);
            }
        }
    }

    /**
     * @param  list<PaymentData>  $payments
     */
    protected function validatePayments(Company $company, array $payments, string $finalAmount): void
    {
        $settings = $this->financialSettingService->getOrCreate($company);

        if ($payments === []) {
            if (! $settings->allow_unpaid_completion) {
                throw ValidationException::withMessages([
                    'payments' => 'É necessário registrar ao menos um pagamento para finalizar a venda.',
                ]);
            }

            return;
        }

        $totalNet = '0.00';

        foreach ($payments as $payment) {
            $totalNet = bcadd($totalNet, bcsub($payment->amount, $payment->feeAmount, 2), 2);
        }

        if (bccomp($totalNet, $finalAmount, 2) > 0) {
            throw ValidationException::withMessages([
                'payments' => 'A soma dos pagamentos não pode ser maior que o valor final da venda.',
            ]);
        }

        if (bccomp($totalNet, $finalAmount, 2) < 0 && ! $settings->allow_partial_payments) {
            throw ValidationException::withMessages([
                'payments' => 'Pagamentos parciais não são permitidos para esta empresa.',
            ]);
        }
    }

    /**
     * @param  list<array{type: SaleItemType, product?: Product, service?: Service, name: string, quantity: string, unit_price: string, unit_cost: string, discount_amount: string, line_total: string, tracks_stock: bool}>  $items
     */
    protected function createItems(Company $company, Sale $sale, array $items): void
    {
        foreach ($items as $item) {
            $product = $item['product'] ?? null;
            $service = $item['service'] ?? null;

            $saleItem = new SaleItem([
                'item_type' => $item['type'],
                'product_id' => $product?->getKey(),
                'service_id' => $service?->getKey(),
                'name_snapshot' => $item['name'],
                'quantity' => $item['quantity'],
                'unit_price_snapshot' => $item['unit_price'],
                'unit_cost_snapshot' => $item['unit_cost'],
                'discount_amount' => $item['discount_amount'],
                'line_total' => $item['line_total'],
                'tracks_stock_snapshot' => $item['tracks_stock'],
            ]);
            $saleItem->company()->associate($company);
            $saleItem->sale()->associate($sale);
            $saleItem->save();
        }
    }

    /**
     * @param  list<array{type: SaleItemType, product?: Product, service?: Service, quantity: string}>  $items
     */
    protected function createAndPostStockDocument(
        Company $company,
        User $user,
        Sale $sale,
        array $items,
        CarbonInterface $soldAt,
    ): ?StockDocument {
        $stockItems = collect($this->buildStockItems($items))
            ->map(fn (array $item): array => [
                'product_id' => $item['product']->getKey(),
                'quantity' => $item['quantity'],
                'notes' => $item['notes'],
            ])
            ->values()
            ->all();

        if ($stockItems === []) {
            return null;
        }

        $document = $this->stockDocumentService->createDraft(
            $company,
            StockDocumentType::ProductSale,
            [
                'sale_id' => $sale->getKey(),
                'reference_key' => "sale:{$sale->getKey()}:product-sale",
                'occurred_at' => $soldAt,
                'notes' => "Venda PDV #{$sale->getKey()}",
            ],
            $stockItems,
            $user,
        );

        return $this->stockDocumentPostingService->post($company, $document, $user);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    protected function resolveProductItem(Company $company, array $item, int $index): Product
    {
        $product = Product::query()
            ->whereKey((int) ($item['product_id'] ?? 0))
            ->where('company_id', $company->getKey())
            ->first();

        if (! $product) {
            throw ValidationException::withMessages([
                "items.{$index}.product_id" => 'Produto inválido para esta empresa.',
            ]);
        }

        if (! $product->is_active || $product->type !== ProductType::Sale) {
            throw ValidationException::withMessages([
                "items.{$index}.product_id" => 'O produto precisa estar ativo e com tipo Produto de venda.',
            ]);
        }

        return $product;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    protected function resolveServiceItem(Company $company, array $item, int $index): Service
    {
        $service = Service::query()
            ->with('consumptions.product')
            ->whereKey((int) ($item['service_id'] ?? 0))
            ->where('company_id', $company->getKey())
            ->first();

        if (! $service) {
            throw ValidationException::withMessages([
                "items.{$index}.service_id" => 'Serviço inválido para esta empresa.',
            ]);
        }

        if (! $service->is_active || ! $service->is_sellable) {
            throw ValidationException::withMessages([
                "items.{$index}.service_id" => 'O serviço precisa estar ativo e disponível para venda.',
            ]);
        }

        return $service;
    }

    /**
     * @param  list<array{type: SaleItemType, product?: Product, service?: Service, quantity: string}>  $items
     * @return list<array{product: Product, quantity: string, notes: string}>
     */
    protected function buildStockItems(array $items): array
    {
        $stockItems = [];

        foreach ($items as $item) {
            if ($item['type'] === SaleItemType::Product) {
                $product = $item['product'] ?? null;

                if ($product?->tracks_stock) {
                    $this->addStockItem($stockItems, $product, $item['quantity'], 'Produto vendido');
                }

                continue;
            }

            if ($item['type'] !== SaleItemType::Service || ! isset($item['service'])) {
                continue;
            }

            foreach ($item['service']->consumptions as $consumption) {
                $product = $consumption->product;

                if (! $product?->tracks_stock) {
                    continue;
                }

                $quantity = DecimalMoney::round(bcmul((string) $consumption->quantity, $item['quantity'], 4), 4);
                $this->addStockItem($stockItems, $product, $quantity, "Consumo do serviço {$item['service']->name}");
            }
        }

        return array_values($stockItems);
    }

    /**
     * @param  array<int, array{product: Product, quantity: string, notes: string}>  $stockItems
     */
    protected function addStockItem(array &$stockItems, Product $product, string $quantity, string $notes): void
    {
        $productId = $product->getKey();

        if (! isset($stockItems[$productId])) {
            $stockItems[$productId] = [
                'product' => $product,
                'quantity' => $quantity,
                'notes' => $notes,
            ];

            return;
        }

        $stockItems[$productId]['quantity'] = DecimalMoney::round(
            bcadd($stockItems[$productId]['quantity'], $quantity, 4),
            4,
        );
        $stockItems[$productId]['notes'] = 'Venda PDV';
    }

    protected function attachCashSessionIfNeeded(Company $company, Sale $sale, PaymentData $payment): void
    {
        if ($sale->cash_session_id !== null) {
            return;
        }

        $account = FinancialAccount::query()
            ->whereKey($payment->financialAccountId)
            ->where('company_id', $company->getKey())
            ->first();

        if ($account === null) {
            return;
        }

        $cashSessionId = $this->cashSessionService->resolveCashSessionIdForTransaction($company, $account, $payment->method);

        if ($cashSessionId !== null) {
            $sale->forceFill(['cash_session_id' => $cashSessionId])->save();
        }
    }

    protected function refreshSaleAmounts(Sale $sale, Receivable $receivable): void
    {
        $status = match (true) {
            $receivable->isSettled() => SaleStatus::Paid,
            bccomp((string) $receivable->paid_amount, '0.00', 2) > 0 => SaleStatus::Partial,
            default => SaleStatus::Completed,
        };

        $sale->forceFill([
            'status' => $status,
            'paid_amount' => $receivable->paid_amount,
            'outstanding_amount' => $receivable->outstanding_amount,
        ])->save();
    }

    protected function normalizePositiveQuantity(mixed $quantity, string $field): string
    {
        if (bccomp((string) $quantity, '0', 4) <= 0) {
            throw ValidationException::withMessages([
                $field => 'A quantidade deve ser maior que zero.',
            ]);
        }

        return (string) $quantity;
    }

    protected function normalizeNonNegativeMoney(mixed $amount, string $field): string
    {
        $normalized = DecimalMoney::round((string) $amount);

        if (bccomp($normalized, '0.00', 2) < 0) {
            throw ValidationException::withMessages([
                $field => 'O valor não pode ser negativo.',
            ]);
        }

        return $normalized;
    }
}
