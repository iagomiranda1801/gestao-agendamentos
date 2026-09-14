<?php

namespace App\Jobs;

use App\Enums\CompanyModule;
use App\Enums\OrderStatus;
use App\Enums\WhatsAppOutboundKind;
use App\Jobs\Concerns\DefersViaWhatsAppOutboundGate;
use App\Models\Order;
use App\Services\Company\CompanyModuleService;
use App\Services\Orders\CompanyOrderSettingService;
use App\Services\Orders\OrderWhatsAppMessageBuilder;
use App\Services\WhatsApp\CompanyWhatsAppInstanceService;
use App\Services\WhatsApp\EvolutionApiClient;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendOrderWhatsAppNotificationJob implements ShouldBeUnique, ShouldQueue
{
    use DefersViaWhatsAppOutboundGate;
    use Queueable;

    public int $uniqueFor = 3600;

    public function __construct(
        public int $orderId,
        public string $eventStatus,
    ) {}

    public function uniqueId(): string
    {
        return $this->orderId.':'.$this->eventStatus;
    }

    public function handle(
        EvolutionApiClient $client,
        OrderWhatsAppMessageBuilder $messageBuilder,
        CompanyWhatsAppInstanceService $instances,
        CompanyOrderSettingService $orderSettings,
        CompanyModuleService $modules,
    ): void {
        $order = Order::query()
            ->with(['company.orderSetting', 'items'])
            ->find($this->orderId);

        if ($order === null || $order->company === null) {
            return;
        }

        $status = OrderStatus::tryFrom($this->eventStatus);

        if ($status === null) {
            return;
        }

        $company = $order->company;
        $setting = $company->orderSetting ?? $orderSettings->getOrCreate($company);

        if (! $setting->orders_whatsapp_notify) {
            return;
        }

        if (! $modules->hasModule($company, CompanyModule::WhatsApp)
            && ! $modules->hasModule($company, CompanyModule::Marketing)) {
            return;
        }

        $instance = $instances->resolvedNameForCompany($company);

        if ($instance === '') {
            Log::info('Order WhatsApp skipped.', [
                'reason' => 'no_instance',
                'order_id' => $order->getKey(),
            ]);

            return;
        }

        $phone = (string) ($order->customer_phone_normalized ?: $order->customer_phone);

        if ($phone === '') {
            return;
        }

        if (! $this->deferUntilOutboundSlot($company, WhatsAppOutboundKind::Confirmation)) {
            return;
        }

        try {
            $client->sendText($instance, $phone, $messageBuilder->build($order, $status));
            $this->rememberOutboundSuccess($company);
        } catch (Throwable $exception) {
            Log::warning('Order WhatsApp failed.', [
                'order_id' => $order->getKey(),
                'error' => $exception->getMessage(),
            ]);
            $this->rememberOutboundFailureAndMaybeRethrow($company, $exception);
        }
    }
}
