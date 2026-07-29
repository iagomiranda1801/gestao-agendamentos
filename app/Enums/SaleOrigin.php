<?php

namespace App\Enums;

enum SaleOrigin: string
{
    case Pos = 'pos';
    case QuickSale = 'quick_sale';
    case AttendanceCheckout = 'attendance_checkout';

    public function label(): string
    {
        return match ($this) {
            self::Pos => 'PDV',
            self::QuickSale => 'Venda rápida',
            self::AttendanceCheckout => 'Checkout de atendimento',
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
