<?php

namespace App\Enums;

enum BillingInterval: string
{
    case Monthly = 'monthly';
    case Semiannual = 'semiannual';
    case Annual = 'annual';

    public function label(): string
    {
        return match ($this) {
            self::Monthly => 'Mensal',
            self::Semiannual => 'Semestral',
            self::Annual => 'Anual',
        };
    }

    public function months(): int
    {
        return match ($this) {
            self::Monthly => 1,
            self::Semiannual => 6,
            self::Annual => 12,
        };
    }

    /**
     * Meses pagos no catálogo para este ciclo (ex.: semestral = 4 meses por 6 de uso).
     */
    public function catalogMonthlyMultiplier(): int
    {
        return match ($this) {
            self::Monthly => 1,
            self::Semiannual => 4,
            self::Annual => 8,
        };
    }

    public function savingsHint(): ?string
    {
        return match ($this) {
            self::Monthly => null,
            self::Semiannual => '2 meses grátis',
            self::Annual => '4 meses grátis',
        };
    }

    public function signupPriceSuffix(): string
    {
        return match ($this) {
            self::Monthly => '/mês',
            self::Semiannual => '/semestre',
            self::Annual => '/ano',
        };
    }

    public function signupTotalSuffix(): string
    {
        return match ($this) {
            self::Monthly => 'por mês',
            self::Semiannual => 'a cada 6 meses',
            self::Annual => 'por ano',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $interval) => [$interval->value => $interval->label()])
            ->all();
    }
}
