<?php

namespace App\Enums;

enum ExpenseCategoryType: string
{
    case Operational = 'operational';
    case Administrative = 'administrative';
    case Marketing = 'marketing';
    case Tax = 'tax';
    case Financial = 'financial';
    case Personnel = 'personnel';
    case StockPurchase = 'stock_purchase';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Operational => 'Operacional',
            self::Administrative => 'Administrativa',
            self::Marketing => 'Marketing',
            self::Tax => 'Impostos e tributos',
            self::Financial => 'Financeira',
            self::Personnel => 'Pessoal',
            self::StockPurchase => 'Compra de estoque',
            self::Other => 'Outra',
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
