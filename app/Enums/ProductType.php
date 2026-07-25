<?php

namespace App\Enums;

enum ProductType: string
{
    case Consumable = 'consumable';
    case Asset = 'asset';

    public function label(): string
    {
        return match ($this) {
            self::Consumable => 'Material de consumo',
            self::Asset => 'Investimento/ativo',
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
