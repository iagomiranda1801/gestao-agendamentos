<?php

namespace App\Services\WhatsApp\Bot;

use App\Enums\CompanyModule;
use App\Models\Company;
use App\Services\Company\CompanyModuleService;
use App\Services\Orders\CompanyOrderSettingService;
use App\Support\PhoneNormalizer;
use Illuminate\Support\Facades\Cache;

class WhatsAppOrderLinkBotService
{
    public const RESEND_COOLDOWN_SECONDS = 600;

    public function __construct(
        protected CompanyModuleService $modules,
        protected CompanyOrderSettingService $orderSettings,
    ) {}

    /**
     * Restaurants (and Orders-only companies) must never enter the booking conversation.
     */
    public function prefersThisBot(Company $company): bool
    {
        if ($company->isRestaurant()) {
            return true;
        }

        return $this->modules->hasModule($company, CompanyModule::Orders)
            && ! $this->modules->hasModule($company, CompanyModule::Scheduling);
    }

    public function canReply(Company $company): bool
    {
        if (! $company->is_active) {
            return false;
        }

        if (! $this->modules->hasModule($company, CompanyModule::WhatsApp)) {
            return false;
        }

        if (! $this->modules->hasModule($company, CompanyModule::Orders)) {
            return false;
        }

        $settings = $this->orderSettings->getOrCreate($company);

        return (bool) $settings->online_ordering_enabled
            && (bool) $settings->whatsapp_order_link_bot_enabled;
    }

    public function handleIncoming(Company $company, string $rawPhone, ?string $messageId = null): ?string
    {
        $phone = PhoneNormalizer::normalize($rawPhone);

        if ($phone === null) {
            return null;
        }

        $lock = Cache::lock("wa:order-link:{$company->getKey()}:{$phone}", 10);

        try {
            $lock->block(5);

            if ($messageId !== null && $messageId !== '') {
                if (Cache::has($this->messageKey($company, $messageId))) {
                    return null;
                }
            }

            if (Cache::has($this->cooldownKey($company, $phone))) {
                return null;
            }

            return $this->composeMessage($company);
        } finally {
            optional($lock)->release();
        }
    }

    public function rememberDelivered(Company $company, string $rawPhone, ?string $messageId = null): void
    {
        $phone = PhoneNormalizer::normalize($rawPhone);

        if ($phone === null) {
            return;
        }

        $lock = Cache::lock("wa:order-link:{$company->getKey()}:{$phone}", 10);

        try {
            $lock->block(5);

            if ($messageId !== null && $messageId !== '') {
                Cache::put($this->messageKey($company, $messageId), true, now()->addMinutes(30));
            }

            Cache::put($this->cooldownKey($company, $phone), true, now()->addSeconds(self::RESEND_COOLDOWN_SECONDS));
        } finally {
            optional($lock)->release();
        }
    }

    public function composeMessage(Company $company): string
    {
        $url = route('public.orders.show', ['company' => $company->slug]);

        return "Olá! Peça pelo cardápio da {$company->name}:\n{$url}";
    }

    protected function messageKey(Company $company, string $messageId): string
    {
        return "wa:order-link-msgid:{$company->getKey()}:{$messageId}";
    }

    protected function cooldownKey(Company $company, string $phone): string
    {
        return "wa:order-link-sent:{$company->getKey()}:{$phone}";
    }
}
