<?php

namespace App\Enums;

use App\Models\CompanyOrderSetting;

enum OrderFulfillment: string
{
    case Pickup = 'pickup';
    case Delivery = 'delivery';
    case DineIn = 'dine_in';

    public function label(): string
    {
        return match ($this) {
            self::Pickup => 'Retirada',
            self::Delivery => 'Entrega',
            self::DineIn => 'Comer no local',
        };
    }

    public function publicSubtitle(): string
    {
        return match ($this) {
            self::Pickup => 'Peça agora e retire no estabelecimento. Pagamento na retirada.',
            self::Delivery => 'Receba no endereço informado. Pagamento na entrega.',
            self::DineIn => 'Peça agora e consuma no estabelecimento. Pagamento no local.',
        };
    }

    public function paymentInstruction(): string
    {
        return match ($this) {
            self::Pickup => 'Pague na retirada.',
            self::Delivery => 'Pague na entrega.',
            self::DineIn => 'Pague no local.',
        };
    }

    public function readyWhatsAppLine(string $displayNumber): string
    {
        return match ($this) {
            self::Pickup => "Seu pedido {$displayNumber} está pronto para retirada.",
            self::Delivery => "Seu pedido {$displayNumber} está pronto e logo sai para entrega.",
            self::DineIn => "Seu pedido {$displayNumber} está pronto para consumo no local.",
        };
    }

    public function usesDeliveryAddress(): bool
    {
        return $this === self::Delivery;
    }

    public function isEnabled(CompanyOrderSetting $setting): bool
    {
        return match ($this) {
            self::Pickup => (bool) $setting->pickup_enabled,
            self::Delivery => (bool) $setting->delivery_enabled,
            self::DineIn => (bool) $setting->dine_in_enabled,
        };
    }

    /**
     * Fulfillment types offered on the public page when the company enables them.
     *
     * @return list<self>
     */
    public static function publicOptions(): array
    {
        return [self::Pickup, self::Delivery, self::DineIn];
    }

    /**
     * @return list<self>
     */
    public static function enabledPublicOptions(CompanyOrderSetting $setting): array
    {
        return array_values(array_filter(
            self::publicOptions(),
            fn (self $fulfillment): bool => $fulfillment->isEnabled($setting),
        ));
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $fulfillment) => [$fulfillment->value => $fulfillment->label()])
            ->all();
    }
}
