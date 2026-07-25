<?php

namespace App\Services\WhatsApp;

use App\Models\Appointment;
use App\Models\Company;
use App\Support\CompanyDateTime;

class WhatsAppConfirmationMessageBuilder
{
    public function build(Company $company, Appointment $appointment, ?string $manageUrl = null): string
    {
        $settings = $company->schedulingSetting;
        $template = filled($settings?->whatsapp_confirmation_template)
            ? (string) $settings->whatsapp_confirmation_template
            : $this->defaultTemplate();

        $localStart = CompanyDateTime::utcToLocal($company, $appointment->start_at);

        $replacements = [
            '{nome}' => (string) ($appointment->client_name_snapshot ?? 'cliente'),
            '{servico}' => (string) ($appointment->service_name_snapshot ?? 'serviço'),
            '{data}' => $localStart->format('d/m/Y'),
            '{hora}' => $localStart->format('H:i'),
            '{codigo}' => (string) ($appointment->public_confirmation_code ?? ''),
            '{link}' => (string) ($manageUrl ?? ''),
            '{empresa}' => (string) $company->name,
        ];

        return strtr($template, $replacements);
    }

    public function buildForStaff(Company $company, Appointment $appointment, ?string $manageUrl = null): string
    {
        $localStart = CompanyDateTime::utcToLocal($company, $appointment->start_at);
        $professionalName = $appointment->professional?->name ?? 'A definir';

        return strtr(
            <<<'TXT'
Novo agendamento online em {empresa}

Cliente: {nome}
Telefone: {telefone}
Serviço: {servico}
Profissional: {profissional}
Data: {data}
Horário: {hora}
Código: {codigo}
TXT,
            [
                '{empresa}' => (string) $company->name,
                '{nome}' => (string) ($appointment->client_name_snapshot ?? 'cliente'),
                '{telefone}' => (string) ($appointment->client_phone_snapshot ?? ''),
                '{servico}' => (string) ($appointment->service_name_snapshot ?? 'serviço'),
                '{profissional}' => (string) $professionalName,
                '{data}' => $localStart->format('d/m/Y'),
                '{hora}' => $localStart->format('H:i'),
                '{codigo}' => (string) ($appointment->public_confirmation_code ?? ''),
            ],
        );
    }

    protected function defaultTemplate(): string
    {
        return <<<'TXT'
Olá, {nome}! Seu agendamento em {empresa} foi registrado.

Serviço: {servico}
Data: {data}
Horário: {hora}
Código: {codigo}

Gerencie seu agendamento: {link}
TXT;
    }
}
