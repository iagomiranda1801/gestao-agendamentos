<?php

namespace App\Enums;

enum WhatsAppCampaignStatus: string
{
    case Draft = 'draft';
    case Sending = 'sending';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Rascunho',
            self::Sending => 'Enviando',
            self::Completed => 'Concluída',
            self::Cancelled => 'Cancelada',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $status) => [$status->value => $status->label()])
            ->all();
    }
}
