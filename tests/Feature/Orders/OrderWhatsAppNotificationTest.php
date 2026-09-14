<?php

namespace Tests\Feature\Orders;

use App\Enums\OrderFulfillment;
use App\Enums\OrderStatus;
use App\Jobs\SendOrderWhatsAppNotificationJob;
use App\Models\CompanyWhatsAppInstance;
use App\Services\Company\CompanyModuleService;
use App\Services\Orders\CompanyOrderSettingService;
use App\Services\Orders\OrderService;
use App\Services\Orders\OrderWhatsAppMessageBuilder;
use App\Services\WhatsApp\CompanyWhatsAppInstanceService;
use App\Services\WhatsApp\EvolutionApiClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesOrderFixtures;
use Tests\TestCase;

class OrderWhatsAppNotificationTest extends TestCase
{
    use CreatesOrderFixtures;

    public function test_order_creation_dispatches_whatsapp_job(): void
    {
        Queue::fake();

        $setup = $this->createRestaurantSetup();

        app(OrderService::class)->createPublic($setup['company'], [
            'items' => [['product_id' => $setup['burger']->id, 'quantity' => 1]],
            'fulfillment' => OrderFulfillment::Pickup,
            'customer_name' => 'Maria',
            'customer_phone' => '34988887777',
        ]);

        Queue::assertPushed(SendOrderWhatsAppNotificationJob::class, function (SendOrderWhatsAppNotificationJob $job): bool {
            return $job->eventStatus === OrderStatus::Received->value;
        });
    }

    public function test_ready_and_out_for_delivery_dispatch_whatsapp_jobs(): void
    {
        Queue::fake();

        $setup = $this->createRestaurantSetup();
        $user = $this->createCompanyUser($setup['company']);
        $service = app(OrderService::class);
        $order = $service->createPublic($setup['company'], [
            'items' => [['product_id' => $setup['burger']->id, 'quantity' => 1]],
            'fulfillment' => OrderFulfillment::Delivery,
            'customer_name' => 'Pedro',
            'customer_phone' => '34988887777',
            'delivery_address' => 'Rua A, 10',
        ]);

        $order = $service->advance($setup['company'], $order, $user);
        $order = $service->advance($setup['company'], $order, $user);
        $this->assertSame(OrderStatus::Ready, $order->status);
        $order = $service->advance($setup['company'], $order, $user);
        $this->assertSame(OrderStatus::OutForDelivery, $order->status);

        Queue::assertPushed(SendOrderWhatsAppNotificationJob::class, fn (SendOrderWhatsAppNotificationJob $job): bool => $job->eventStatus === OrderStatus::Ready->value);
        Queue::assertPushed(SendOrderWhatsAppNotificationJob::class, fn (SendOrderWhatsAppNotificationJob $job): bool => $job->eventStatus === OrderStatus::OutForDelivery->value);
    }

    public function test_job_sends_text_when_instance_is_connected(): void
    {
        config([
            'services.evolution.url' => 'https://evolution.test',
            'services.evolution.key' => 'test-key',
            'services.evolution.instance' => '',
        ]);

        Http::fake([
            'evolution.test/*' => Http::response(['ok' => true], 200),
        ]);

        $setup = $this->createRestaurantSetup();
        $instance = new CompanyWhatsAppInstance([
            'name' => 'Principal',
            'instance_name' => 'loja-1',
            'sender_phone' => '5511900000000',
            'status' => 'open',
            'is_default' => true,
            'connected_at' => now(),
        ]);
        $instance->company()->associate($setup['company']);
        $instance->save();

        $order = app(OrderService::class)->createPublic($setup['company'], [
            'items' => [['product_id' => $setup['burger']->id, 'quantity' => 1]],
            'fulfillment' => OrderFulfillment::Pickup,
            'customer_name' => 'Maria',
            'customer_phone' => '34988887777',
        ]);

        (new SendOrderWhatsAppNotificationJob($order->id, OrderStatus::Received->value))->handle(
            app(EvolutionApiClient::class),
            app(OrderWhatsAppMessageBuilder::class),
            app(CompanyWhatsAppInstanceService::class),
            app(CompanyOrderSettingService::class),
            app(CompanyModuleService::class),
        );

        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/message/sendText/loja-1'));
    }
}
