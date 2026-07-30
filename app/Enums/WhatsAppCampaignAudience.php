<?php

namespace App\Enums;

enum WhatsAppCampaignAudience: string
{
    case OptedInActiveClients = 'opted_in_active_clients';
    case SelectedClients = 'selected_clients';

    public function label(): string
    {
        return match ($this) {
            self::OptedInActiveClients => 'Clientes ativos com aceite',
            self::SelectedClients => 'Clientes selecionados',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $audience) => [$audience->value => $audience->label()])
            ->all();
    }
}
