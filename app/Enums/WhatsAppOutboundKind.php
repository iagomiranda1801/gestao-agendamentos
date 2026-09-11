<?php

namespace App\Enums;

enum WhatsAppOutboundKind: string
{
    case Confirmation = 'confirmation';
    case Reminder = 'reminder';
    case AfterSales = 'after_sales';
    case Marketing = 'marketing';
    case BotReply = 'bot_reply';

    public function bypassesDailyLimit(): bool
    {
        return in_array($this, [self::Confirmation, self::BotReply], true);
    }

    public function bypassesCircuitBreaker(): bool
    {
        return in_array($this, [self::Confirmation, self::BotReply], true);
    }

    public function skipsSunday(): bool
    {
        return $this === self::Marketing;
    }

    public function outboundLane(): string
    {
        if ($this === self::BotReply) {
            return 'bot';
        }

        return $this === self::Confirmation ? 'operational' : 'paced';
    }

    public function usesSendInterval(): bool
    {
        return ! in_array($this, [self::Confirmation, self::BotReply], true);
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
