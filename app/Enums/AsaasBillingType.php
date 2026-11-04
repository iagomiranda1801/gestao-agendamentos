<?php

namespace App\Enums;

enum AsaasBillingType: string
{
    case Pix = 'PIX';
    case Boleto = 'BOLETO';
    case CreditCard = 'CREDIT_CARD';

    public function label(): string
    {
        return match ($this) {
            self::Pix => 'PIX',
            self::Boleto => 'Boleto',
            self::CreditCard => 'Cartão',
        };
    }

    public function paymentMethod(): PaymentMethod
    {
        return match ($this) {
            self::Pix => PaymentMethod::Pix,
            self::Boleto => PaymentMethod::BankTransfer,
            self::CreditCard => PaymentMethod::CreditCard,
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $type): array => [$type->value => $type->label()])
            ->all();
    }
}
