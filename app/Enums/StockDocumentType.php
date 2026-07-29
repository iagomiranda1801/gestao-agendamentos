<?php

namespace App\Enums;

enum StockDocumentType: string
{
    case OpeningBalance = 'opening_balance';
    case Purchase = 'purchase';
    case ManualEntry = 'manual_entry';
    case ManualExit = 'manual_exit';
    case Loss = 'loss';
    case InventoryCount = 'inventory_count';
    case Reversal = 'reversal';
    case ServiceConsumption = 'service_consumption';
    case ProductSale = 'product_sale';

    public function label(): string
    {
        return match ($this) {
            self::OpeningBalance => 'Saldo inicial',
            self::Purchase => 'Compra',
            self::ManualEntry => 'Entrada manual',
            self::ManualExit => 'Saída manual',
            self::Loss => 'Perda ou avaria',
            self::InventoryCount => 'Inventário por contagem',
            self::Reversal => 'Estorno',
            self::ServiceConsumption => 'Consumo de atendimento',
            self::ProductSale => 'Venda de produto',
        };
    }

    public function isInbound(): bool
    {
        return in_array($this, [
            self::OpeningBalance,
            self::Purchase,
            self::ManualEntry,
            self::InventoryCount,
            self::Reversal,
        ], true);
    }

    public function requiresJustification(): bool
    {
        return in_array($this, [
            self::ManualEntry,
            self::ManualExit,
            self::Loss,
        ], true);
    }

    public function isOutbound(): bool
    {
        return in_array($this, [
            self::ManualExit,
            self::Loss,
            self::ServiceConsumption,
            self::ProductSale,
        ], true);
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

    /**
     * @return array<string, string>
     */
    public static function adjustmentOptions(): array
    {
        return collect([
            self::OpeningBalance,
            self::ManualEntry,
            self::ManualExit,
            self::Loss,
            self::InventoryCount,
        ])->mapWithKeys(fn (self $type) => [$type->value => $type->label()])->all();
    }
}
