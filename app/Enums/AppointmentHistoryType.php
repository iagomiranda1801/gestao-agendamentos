<?php

namespace App\Enums;

enum AppointmentHistoryType: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Confirmed = 'confirmed';
    case Started = 'started';
    case Rescheduled = 'rescheduled';
    case Cancelled = 'cancelled';
    case NoShow = 'no_show';
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::Created => 'Criado',
            self::Updated => 'Atualizado',
            self::Confirmed => 'Confirmado',
            self::Started => 'Iniciado',
            self::Cancelled => 'Cancelado',
            self::Rescheduled => 'Remarcado',
            self::NoShow => 'Não compareceu',
            self::Completed => 'Concluído',
        };
    }
}
