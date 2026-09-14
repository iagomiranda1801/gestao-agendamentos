<?php

namespace Tests\Unit\Orders;

use App\Enums\OrderFulfillment;
use PHPUnit\Framework\TestCase;

class OrderFulfillmentTest extends TestCase
{
    public function test_dine_in_public_label_and_copy(): void
    {
        $this->assertSame('dine_in', OrderFulfillment::DineIn->value);
        $this->assertSame('Comer no local', OrderFulfillment::DineIn->label());
        $this->assertSame('Pague no local.', OrderFulfillment::DineIn->paymentInstruction());
        $this->assertFalse(OrderFulfillment::DineIn->usesDeliveryAddress());
        $this->assertContains(OrderFulfillment::DineIn, OrderFulfillment::publicOptions());
    }
}
