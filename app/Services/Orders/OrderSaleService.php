<?php

namespace App\Services\Orders;

use App\Enums\CompanyModule;
use App\Enums\ProductType;
use App\Enums\SaleItemType;
use App\Enums\SaleOrigin;
use App\Models\Company;
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

        $order->loadMissing(['items.product']);

        $sale = $this->sales->complete($company, $user, [
            'origin' => SaleOrigin::OnlineOrder->value,
            'reference_key' => $referenceKey,
            'allow_unpaid' => true,
            'sold_at' => $order->completed_at ?? now(),
            'notes' => $this->saleNotes($order),
            'items' => $this->saleItems($order),
            'payments' => [],
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

    protected function saleNotes(Order $order): string
    {
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
