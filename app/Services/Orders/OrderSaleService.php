<?php

namespace App\Services\Orders;

use App\DataTransferObjects\Financial\PaymentData;
use App\Enums\CompanyModule;
use App\Enums\PaymentMethod;
use App\Enums\ProductType;
use App\Enums\SaleItemType;
use App\Enums\SaleOrigin;
use App\Models\Company;
use App\Models\FinancialAccount;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Sale;
use App\Models\User;
use App\Services\Company\CompanyModuleService;
use App\Services\Sales\SaleService;
use App\Support\Money;

class OrderSaleService
{
    public function __construct(
        protected CompanyModuleService $modules,
        protected SaleService $sales,
    ) {}

    public static function referenceKey(Order $order): string
    {
        return 'order:'.$order->getKey();
    }

    /**
     * Creates the PDV sale (and unpaid receivable) for a completed kitchen order
     * when the Sales module is enabled. Idempotent via orders.sale_id and sales.reference_key.
     */
    public function syncFromCompletedOrder(Company $company, Order $order, ?User $user): ?Sale
    {
        if ($order->sale_id !== null) {
            $existing = $order->sale ?? Sale::query()->whereKey($order->sale_id)->first();

            return $existing;
        }

        if (! $this->modules->hasModule($company, CompanyModule::Sales)) {
            return null;
        }

        if ($user === null) {
            return null;
        }

        $referenceKey = self::referenceKey($order);

        $existing = Sale::query()
            ->where('company_id', $company->getKey())
            ->where('reference_key', $referenceKey)
            ->first();

        if ($existing) {
            $order->forceFill(['sale_id' => $existing->getKey()])->save();

            return $existing;
        }

        $order->loadMissing(['items.product', 'diningTable']);

        $payments = $order->isFloorOrder() ? $this->floorPayments($company, $order) : [];

        $sale = $this->sales->complete($company, $user, [
            'origin' => $order->isFloorOrder() ? SaleOrigin::FloorOrder->value : SaleOrigin::OnlineOrder->value,
            'reference_key' => $referenceKey,
            'allow_unpaid' => $payments === [],
            'sold_at' => $order->completed_at ?? now(),
            'notes' => $this->saleNotes($order),
            'items' => $this->saleItems($order),
            'payments' => $payments,
        ]);

        $order->forceFill(['sale_id' => $sale->getKey()])->save();

        return $sale;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function saleItems(Order $order): array
    {
        $items = $order->items
            ->map(function (OrderItem $item) use ($order): array {
                $product = $item->product;
                $unitPrice = Money::fromCents((int) $item->unit_price_cents);
                $quantity = (string) $item->quantity;

                if (
                    $product !== null
                    && (int) $product->company_id === (int) $order->company_id
                    && $product->is_active
                    && $product->type === ProductType::Sale
                ) {
                    return [
                        'item_type' => SaleItemType::Product->value,
                        'product_id' => $product->getKey(),
                        'name' => (string) $item->name,
                        'quantity' => $quantity,
                        'unit_price' => $unitPrice,
                    ];
                }

                return [
                    'item_type' => SaleItemType::Custom->value,
                    'name' => (string) $item->name,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                ];
            })
            ->values()
            ->all();

        if ((int) $order->delivery_fee_cents > 0) {
            $items[] = [
                'item_type' => SaleItemType::Custom->value,
                'name' => 'Taxa de entrega',
                'quantity' => '1',
                'unit_price' => Money::fromCents((int) $order->delivery_fee_cents),
            ];
        }

        return $items;
    }

    /**
     * @return list<PaymentData>
     */
    protected function floorPayments(Company $company, Order $order): array
    {
        $method = $order->payment_method instanceof PaymentMethod
            ? $order->payment_method
            : PaymentMethod::tryFrom((string) $order->payment_method);

        if ($method === null || (int) $order->total_cents < 1) {
            return [];
        }

        $account = FinancialAccount::query()
            ->where('company_id', $company->getKey())
            ->where('is_active', true)
            ->where('is_default_receipt_account', true)
            ->first();

        if ($account === null) {
            return [];
        }

        return [
            new PaymentData(
                amount: Money::fromCents((int) $order->total_cents),
                feeAmount: '0.00',
                method: $method,
                paidAt: $order->completed_at ?? now(),
                financialAccountId: (int) $account->getKey(),
                notes: 'Comanda '.$order->displayNumber(),
            ),
        ];
    }

    protected function saleNotes(Order $order): string
    {
        if ($order->isFloorOrder()) {
            $lines = array_filter([
                'Comanda '.$order->displayNumber().' ('.$order->public_code.')',
                $order->diningTableLabel(),
                $order->customer_name,
                $order->payment_method?->label(),
            ]);

            if (filled($order->notes)) {
                $lines[] = (string) $order->notes;
            }

            return implode("\n", $lines);
        }

        $lines = [
            'Pedido online '.$order->displayNumber().' ('.$order->public_code.')',
            $order->fulfillment?->label().' — '.$order->customer_name.' — '.$order->customer_phone,
        ];

        $address = $order->formattedDeliveryAddress();

        if (filled($address)) {
            $lines[] = $address;
        }

        if (filled($order->notes)) {
            $lines[] = (string) $order->notes;
        }

        return implode("\n", array_filter($lines));
    }
}
