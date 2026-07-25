<?php

namespace App\Enums;

enum FinancialAccountType: string
{
    case Cash = 'cash';
    case Bank = 'bank';
    case DigitalWallet = 'digital_wallet';
    case CardClearing = 'card_clearing';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Caixa físico',
            self::Bank => 'Conta bancária',
            self::DigitalWallet => 'Carteira digital',
            self::CardClearing => 'Recebimentos de cartão',
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
