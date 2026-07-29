<?php

namespace App\Enums;

enum PayableOrigin: string
{
    case Manual = 'manual';
    case StockPurchase = 'stock_purchase';
    case Recurring = 'recurring';
    case ProfessionalCommission = 'professional_commission';
    case Import = 'import';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Lançamento manual',
            self::StockPurchase => 'Compra de estoque',
            self::Recurring => 'Despesa recorrente',
            self::ProfessionalCommission => 'Comissão profissional',
            self::Import => 'Importação',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $origin) => [$origin->value => $origin->label()])
            ->all();
    }
}
