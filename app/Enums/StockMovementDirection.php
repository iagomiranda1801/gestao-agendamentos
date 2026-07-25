<?php

namespace App\Enums;

enum StockMovementDirection: string
{
    case Inbound = 'inbound';
    case Outbound = 'outbound';

    public function label(): string
    {
        return match ($this) {
            self::Inbound => 'Entrada',
            self::Outbound => 'Saída',
        };
    }

    public function opposite(): self
    {
        return match ($this) {
            self::Inbound => self::Outbound,
            self::Outbound => self::Inbound,
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $direction) => [$direction->value => $direction->label()])
            ->all();
    }
}
