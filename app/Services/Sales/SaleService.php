<?php

namespace App\Services\Sales;

use App\DataTransferObjects\Financial\PaymentData;
use App\Enums\SaleItemType;
use App\Enums\SaleOrigin;
use App\Enums\SaleStatus;
use App\Enums\StockDocumentType;
use App\Models\Company;
use App\Models\FinancialAccount;
use App\Models\Product;
use App\Models\Receivable;
use App\Models\Sale;
use App\Models\SaleItem;
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
     * @return list<array{type: SaleItemType, product: Product, quantity: string, unit_price: string, discount_amount: string, line_total: string}>
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

            if ($type !== SaleItemType::Product) {
                throw ValidationException::withMessages([
                    "items.{$index}.item_type" => 'Neste momento o PDV finaliza apenas produtos.',
                ]);
            }

            $product = Product::query()
                ->whereKey((int) ($item['product_id'] ?? 0))
                ->where('company_id', $company->getKey())
                ->first();

            if (! $product) {
                throw ValidationException::withMessages([
                    "items.{$index}.product_id" => 'Produto inválido para esta empresa.',
                ]);
            }

            if (! $product->is_active || ! $product->is_sellable) {
                throw ValidationException::withMessages([
                    "items.{$index}.product_id" => 'O produto precisa estar ativo e disponível para venda.',
                ]);
            }

            $quantity = $this->normalizePositiveQuantity($item['quantity'] ?? '1', "items.{$index}.quantity");
            $unitPrice = $this->normalizeNonNegativeMoney(
                $item['unit_price'] ?? $product->sale_price,
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
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'discount_amount' => $discountAmount,
                'line_total' => $lineTotal,
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
     * @param  list<array{product: Product, quantity: string}>  $items
     */
    protected function validateStockAvailability(array $items): void
    {
        foreach ($items as $item) {
            $product = $item['product'];

            if (! $product->tracks_stock) {
                continue;
            }

            if (bccomp($product->getCurrentStockQuantity(), $item['quantity'], 4) < 0) {
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
     * @param  list<array{type: SaleItemType, product: Product, quantity: string, unit_price: string, discount_amount: string, line_total: string}>  $items
     */
    protected function createItems(Company $company, Sale $sale, array $items): void
    {
        foreach ($items as $item) {
            $product = $item['product'];

            $saleItem = new SaleItem([
                'item_type' => $item['type'],
                'product_id' => $product->getKey(),
                'name_snapshot' => $product->name,
                'quantity' => $item['quantity'],
                'unit_price_snapshot' => $item['unit_price'],
                'unit_cost_snapshot' => $product->getCurrentUnitCost(),
                'discount_amount' => $item['discount_amount'],
                'line_total' => $item['line_total'],
                'tracks_stock_snapshot' => $product->tracks_stock,
            ]);
            $saleItem->company()->associate($company);
            $saleItem->sale()->associate($sale);
            $saleItem->save();
        }
    }

    /**
     * @param  list<array{product: Product, quantity: string}>  $items
     */
    protected function createAndPostStockDocument(
        Company $company,
        User $user,
        Sale $sale,
        array $items,
        CarbonInterface $soldAt,
    ): ?StockDocument {
        $stockItems = [];

        foreach ($items as $item) {
            $product = $item['product'];

            if (! $product->tracks_stock) {
                continue;
            }

            $stockItems[] = [
                'product_id' => $product->getKey(),
                'quantity' => $item['quantity'],
                'notes' => "Venda #{$sale->getKey()}",
            ];
        }

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
                'notes' => "Venda de produtos #{$sale->getKey()}",
            ],
            $stockItems,
            $user,
        );

        return $this->stockDocumentPostingService->post($company, $document, $user);
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
