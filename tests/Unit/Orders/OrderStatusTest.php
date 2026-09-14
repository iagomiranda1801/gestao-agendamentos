<?php

namespace Tests\Unit\Orders;

use App\Enums\OrderFulfillment;
use App\Enums\OrderStatus;
use PHPUnit\Framework\TestCase;

class OrderStatusTest extends TestCase
{
    public function test_pickup_skips_out_for_delivery(): void
    {
        $this->assertSame(OrderStatus::Preparing, OrderStatus::Received->next(OrderFulfillment::Pickup));
        $this->assertSame(OrderStatus::Ready, OrderStatus::Preparing->next(OrderFulfillment::Pickup));
        $this->assertSame(OrderStatus::Completed, OrderStatus::Ready->next(OrderFulfillment::Pickup));
        $this->assertNull(OrderStatus::Completed->next(OrderFulfillment::Pickup));
    }

    public function test_delivery_includes_out_for_delivery(): void
    {
        $this->assertSame(OrderStatus::OutForDelivery, OrderStatus::Ready->next(OrderFulfillment::Delivery));
        $this->assertSame(OrderStatus::Completed, OrderStatus::OutForDelivery->next(OrderFulfillment::Delivery));
    }

    public function test_cancelled_and_completed_are_terminal(): void
    {
        $this->assertTrue(OrderStatus::Cancelled->isTerminal());
        $this->assertTrue(OrderStatus::Completed->isTerminal());
        $this->assertFalse(OrderStatus::Ready->isTerminal());
    }
}
