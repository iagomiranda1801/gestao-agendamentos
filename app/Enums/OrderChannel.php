<?php

namespace App\Enums;

enum OrderChannel: string
{
    case Online = 'online';
    case Floor = 'floor';

    public function label(): string
    {
        return match ($this) {
            self::Online => 'Pedido online',
            self::Floor => 'Comanda',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $channel) => [$channel->value => $channel->label()])
            ->all();
    }
}
