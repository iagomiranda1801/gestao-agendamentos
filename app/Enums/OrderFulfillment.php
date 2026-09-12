<?php

namespace App\Enums;

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
            self::DineIn => 'No local',
        };
    }

    public function usesDeliveryAddress(): bool
    {
        return $this === self::Delivery;
    }

    /**
     * Fulfillment types offered on the public MVP page.
     *
     * @return list<self>
     */
    public static function publicOptions(): array
    {
        return [self::Pickup, self::Delivery];
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
