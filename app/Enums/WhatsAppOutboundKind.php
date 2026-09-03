<?php

namespace App\Enums;

enum WhatsAppOutboundKind: string
{
    case Confirmation = 'confirmation';
    case Reminder = 'reminder';
    case AfterSales = 'after_sales';
    case Marketing = 'marketing';

    public function bypassesDailyLimit(): bool
    {
        return $this === self::Confirmation;
    }

    public function bypassesCircuitBreaker(): bool
    {
        return $this === self::Confirmation;
    }

    public function skipsSunday(): bool
    {
        return $this === self::Marketing;
    }

    public function outboundLane(): string
    {
        return $this === self::Confirmation ? 'operational' : 'paced';
    }

    public static function forAutomation(WhatsAppAutomationType $type): self
    {
        return match ($type) {
            WhatsAppAutomationType::Reminder => self::Reminder,
            WhatsAppAutomationType::AfterSales => self::AfterSales,
            WhatsAppAutomationType::WinBack => self::Marketing,
        };
    }
}
