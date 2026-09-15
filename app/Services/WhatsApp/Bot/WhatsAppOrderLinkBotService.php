<?php

namespace App\Services\WhatsApp\Bot;

use App\Enums\CompanyModule;
use App\Models\Company;
use App\Services\Company\CompanyModuleService;
use App\Services\Orders\CompanyOrderSettingService;
use App\Support\CompanyDateTime;
use App\Support\PhoneNormalizer;
use App\Support\WhatsAppInboundText;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class WhatsAppOrderLinkBotService
{
    /**
     * Extra TTL past local midnight so the dated cache key is cleaned up
     * even if the store does not evict exactly at end-of-day.
     */
    public const SENT_KEY_GRACE_MINUTES = 30;

    /**
     * @var list<string>
     */
    public const TRIGGER_PHRASES = [
        'oi',
        'oie',
        'oii',
        'ola',
        'olá',
        'hello',
        'hi',
        'hey',
        'eai',
        'e ai',
        'bom dia',
        'boa tarde',
        'boa noite',
        'boa madrugada',
        'cardapio',
        'cardápio',
        'menu',
        'pedir',
        'pedido',
        'pedidos',
    ];

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

    public function handleIncoming(Company $company, string $rawPhone, string $text = '', ?string $messageId = null): ?string
    {
        $phone = PhoneNormalizer::normalize($rawPhone);

        if ($phone === null) {
            return null;
        }

        $lock = Cache::lock("wa:order-link:{$company->getKey()}:{$phone}", 10);

        try {
            $lock->block(5);

            if ($messageId !== null && $messageId !== '') {
                $messageKey = "wa:order-link-msgid:{$company->getKey()}:{$messageId}";

                if (! Cache::add($messageKey, true, now()->addMinutes(30))) {
                    Log::info('WhatsApp order-link bot: suppressed (duplicate message id).', [
                        'company_id' => $company->getKey(),
                        'phone' => $phone,
                        'message_id' => $messageId,
                    ]);

                    return null;
                }
            }

            if (! $this->looksLikeMenuLinkTrigger($text)) {
                Log::info('WhatsApp order-link bot: suppressed (not a greeting/menu request).', [
                    'company_id' => $company->getKey(),
                    'phone' => $phone,
                ]);

                return null;
            }

            $localNow = CompanyDateTime::nowLocal($company);
            $localDate = $localNow->toDateString();
            $cooldownKey = $this->sentCacheKey($company, $phone, $localDate);
            $expiresAt = $localNow->endOfDay()->addMinutes(self::SENT_KEY_GRACE_MINUTES);

            if (! Cache::add($cooldownKey, true, $expiresAt)) {
                Log::info('WhatsApp order-link bot: suppressed (already sent today).', [
                    'company_id' => $company->getKey(),
                    'phone' => $phone,
                    'local_date' => $localDate,
                    'timezone' => CompanyDateTime::timezone($company),
                ]);

                return null;
            }

            return $this->composeMessage($company);
        } finally {
            optional($lock)->release();
        }
    }

    public function looksLikeMenuLinkTrigger(string $text): bool
    {
        return WhatsAppInboundText::containsAnyPhrase($text, self::TRIGGER_PHRASES);
    }

    public function composeMessage(Company $company): string
    {
        $url = route('public.orders.show', ['company' => $company->slug]);

        return "Olá! Peça pelo cardápio da {$company->name}:\n{$url}";
    }

    protected function sentCacheKey(Company $company, string $phone, string $localDate): string
    {
        return "wa:order-link-sent:{$company->getKey()}:{$phone}:{$localDate}";
    }
}
