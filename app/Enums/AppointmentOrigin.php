<?php

namespace App\Enums;

enum AppointmentOrigin: string
{
    case Internal = 'internal';
    case Online = 'online';
    case Import = 'import';
    case Api = 'api';

    public function label(): string
    {
        return match ($this) {
            self::Internal => 'Agendamento interno',
            self::Online => 'Agendamento online',
            self::Import => 'Importação',
            self::Api => 'Integração',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $origin) => [$origin->value => $origin->label()])
            ->all();
    }
}
