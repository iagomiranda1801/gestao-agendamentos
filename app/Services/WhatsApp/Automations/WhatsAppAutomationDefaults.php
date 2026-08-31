<?php

namespace App\Services\WhatsApp\Automations;

use App\Enums\WhatsAppAutomationType;
use App\Models\Company;

class WhatsAppAutomationDefaults
{
    /**
     * @return array{
     *     delay_value: int,
     *     cooldown_days: int,
     *     quiet_hours_start: string,
     *     quiet_hours_end: string,
     *     message_template: string
     * }
     */
    public static function forType(WhatsAppAutomationType $type, ?Company $company = null): array
    {
        $carWash = $company?->isCarWash() ?? false;

        return [
            'delay_value' => match ($type) {
                WhatsAppAutomationType::Reminder => 24,
                WhatsAppAutomationType::AfterSales => 2,
                WhatsAppAutomationType::WinBack => 30,
            },
            'cooldown_days' => match ($type) {
                WhatsAppAutomationType::Reminder => 0,
                WhatsAppAutomationType::AfterSales => 7,
                WhatsAppAutomationType::WinBack => 30,
            },
            'quiet_hours_start' => '08:00:00',
            'quiet_hours_end' => '20:00:00',
            'message_template' => self::template($type, $carWash),
        ];
    }

    public static function template(WhatsAppAutomationType $type, bool $carWash): string
    {
        if ($carWash) {
            return match ($type) {
                WhatsAppAutomationType::Reminder => <<<'TXT'
Olá {nome}, sua lavagem {servico} é amanhã às {hora}.

Se precisar remarcar: {link}
TXT,
                WhatsAppAutomationType::AfterSales => <<<'TXT'
Obrigado, {nome}! Seu {placa} ficou pronto.

Quer agendar a próxima lavagem? {link}
TXT,
                WhatsAppAutomationType::WinBack => <<<'TXT'
Olá {nome}. Faz um tempo da última lavagem do {placa}.

Tem horário esta semana: {link}
TXT,
            };
        }

        return match ($type) {
            WhatsAppAutomationType::Reminder => <<<'TXT'
Olá, {nome}! Lembrete do seu horário em {empresa}.

Serviço: {servico}
Data: {data}
Horário: {hora}

Se precisar remarcar: {link}
TXT,
            WhatsAppAutomationType::AfterSales => <<<'TXT'
Obrigado, {nome}! Foi um prazer atender você na {empresa}.

Quando quiser, agende o próximo horário: {link}
TXT,
            WhatsAppAutomationType::WinBack => <<<'TXT'
Olá, {nome}. Faz um tempo que você não vem à {empresa}.

Tem horário disponível esta semana: {link}
TXT,
        };
    }
}
