<?php

namespace App\Enums;

enum WhatsAppAutomationSendStatus: string
{
    case Pending = 'pending';
    case Sent = 'sent';
    case Skipped = 'skipped';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendente',
            self::Sent => 'Enviada',
            self::Skipped => 'Ignorada',
            self::Failed => 'Falhou',
        };
    }
}
