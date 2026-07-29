<?php

namespace App\Enums;

enum SaleItemType: string
{
    case Product = 'product';
    case Service = 'service';
    case Attendance = 'attendance';

    public function label(): string
    {
        return match ($this) {
            self::Product => 'Produto',
            self::Service => 'Serviço',
            self::Attendance => 'Atendimento',
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
