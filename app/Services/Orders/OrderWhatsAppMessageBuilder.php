<?php

namespace App\Services\Orders;

use App\Enums\OrderFulfillment;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Support\Money;

class OrderWhatsAppMessageBuilder
{
    public function build(Order $order, OrderStatus $eventStatus): string
    {
        $company = $order->company;
        $lines = [];

        $greeting = filled($order->customer_name)
            ? "Olá, {$order->customer_name}!"
            : 'Olá!';

        $lines[] = $greeting;
        $lines[] = '';
        $lines[] = match ($eventStatus) {
            OrderStatus::Received => "Recebemos o seu pedido {$order->displayNumber()} em {$company?->name}.",
            OrderStatus::Ready => ($order->fulfillment ?? OrderFulfillment::Pickup)
                ->readyWhatsAppLine($order->displayNumber()),
            OrderStatus::OutForDelivery => "Seu pedido {$order->displayNumber()} saiu para entrega.",
            default => "Atualização do pedido {$order->displayNumber()}.",
        };
        $lines[] = "Código: {$order->public_code}";
        $lines[] = 'Total: '.Money::formatCents((int) $order->total_cents);
        $lines[] = $order->fulfillment?->paymentInstruction() ?? 'Pague na retirada.';

        return implode("\n", $lines);
    }
}
