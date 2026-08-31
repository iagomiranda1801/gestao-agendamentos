<?php

namespace App\Enums;

enum WhatsAppAutomationType: string
{
    case Reminder = 'reminder';
    case AfterSales = 'after_sales';
    case WinBack = 'win_back';

    public function label(): string
    {
        return match ($this) {
            self::Reminder => 'Lembrete de horário',
            self::AfterSales => 'Pós-venda',
            self::WinBack => 'Reconquista',
        };
    }

    public function requiresMarketingOptIn(): bool
    {
        return $this === self::WinBack;
    }

    public function delayUnitLabel(): string
    {
        return match ($this) {
            self::Reminder => 'horas antes',
            self::AfterSales => 'horas depois',
            self::WinBack => 'dias sem visita',
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
