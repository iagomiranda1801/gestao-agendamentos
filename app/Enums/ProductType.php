<?php

namespace App\Enums;

enum ProductType: string
{
    case Sale = 'sale';
    case Consumable = 'consumable';
    case Asset = 'asset';

    public function label(): string
    {
        return match ($this) {
            self::Sale => 'Produto de venda',
            self::Consumable => 'Material de consumo',
            self::Asset => 'Ativo/material operacional',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $type) => [$type->value => $type->label()])
            ->all();
    }
}
