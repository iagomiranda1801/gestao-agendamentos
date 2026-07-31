<?php

namespace App\Enums;

enum WhatsAppCampaignRecipientStatus: string
{
    case Pending = 'pending';
    case Queued = 'queued';
    case Accepted = 'accepted';
    case Sent = 'sent';
    case Failed = 'failed';
    case Skipped = 'skipped';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendente',
            self::Queued => 'Na fila',
            self::Accepted => 'Pendente na Evolution',
            self::Sent => 'Enviado',
            self::Failed => 'Falhou',
            self::Skipped => 'Ignorado',
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
