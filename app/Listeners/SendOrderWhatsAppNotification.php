<?php

namespace App\Listeners;

use App\Enums\OrderStatus;
use App\Events\OrderCreated;
use App\Events\OrderStatusChanged;
use App\Jobs\SendOrderWhatsAppNotificationJob;

class SendOrderWhatsAppNotification
{
    public function handle(OrderCreated|OrderStatusChanged $event): void
    {
        $status = $event instanceof OrderCreated
            ? OrderStatus::Received
            : $event->to;

        if (! in_array($status, [
            OrderStatus::Received,
            OrderStatus::Ready,
            OrderStatus::OutForDelivery,
        ], true)) {
            return;
        }

        SendOrderWhatsAppNotificationJob::dispatch($event->order->getKey(), $status->value);
    }
}
