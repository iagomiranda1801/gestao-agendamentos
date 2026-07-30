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
            self::Consumable => 'Produto de consumo',
            self::Asset => 'Material operacional',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Sale => 'Aparece no PDV e pode ser vendido diretamente ao cliente.',
            self::Consumable => 'Usado internamente em serviços e não aparece no PDV.',
            self::Asset => 'Equipamento ou material permanente da empresa.',
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
