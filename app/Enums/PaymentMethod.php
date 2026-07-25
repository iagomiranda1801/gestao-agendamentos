<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case Cash = 'cash';
    case Pix = 'pix';
    case DebitCard = 'debit_card';
    case CreditCard = 'credit_card';
    case BankTransfer = 'bank_transfer';
    case Voucher = 'voucher';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Dinheiro',
            self::Pix => 'PIX',
            self::DebitCard => 'Cartão de débito',
            self::CreditCard => 'Cartão de crédito',
            self::BankTransfer => 'Transferência',
            self::Voucher => 'Voucher',
            self::Other => 'Outro',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $method) => [$method->value => $method->label()])
            ->all();
    }
}
