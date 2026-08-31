<?php

namespace App\Enums;

enum WhatsAppCampaignAudience: string
{
    case OptedInActiveClients = 'opted_in_active_clients';
    case SelectedClients = 'selected_clients';
    case InactiveSinceDays = 'inactive_since_days';

    public function label(): string
    {
        return match ($this) {
            self::OptedInActiveClients => 'Clientes ativos com aceite',
            self::SelectedClients => 'Clientes selecionados',
            self::InactiveSinceDays => 'Inativos há N dias (com aceite)',
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
