<?php

namespace App\Services\WhatsApp;

use App\Models\Appointment;
use App\Models\Company;
use App\Support\CompanyDateTime;
use App\Support\VehiclePlate;
use Carbon\CarbonImmutable;

class WhatsAppConfirmationMessageBuilder
{
    public function professionalSubject(Company $company, string $notificationType): string
    {
        $action = match ($notificationType) {
            'confirmed' => 'Agendamento confirmado',
            'rescheduled' => 'Agendamento remarcado',
            'cancelled' => 'Agendamento cancelado',
            default => 'Novo agendamento',
        };

        return "{$action} - {$company->name}";
    }

    public function buildForProfessional(
        Company $company,
        Appointment $appointment,
        string $notificationType,
        ?CarbonImmutable $oldStartAt = null,
    ): string {
        $localStart = CompanyDateTime::utcToLocal($company, $appointment->start_at);
        $oldLocalStart = $oldStartAt ? CompanyDateTime::utcToLocal($company, $oldStartAt) : null;
        $title = match ($notificationType) {
            'confirmed' => 'Agendamento confirmado',
            'rescheduled' => 'Agendamento remarcado',
            'cancelled' => 'Agendamento cancelado',
            default => 'Novo agendamento',
        };
        $status = $appointment->status->label();
        $origin = $appointment->origin->label();
        $clientName = (string) ($appointment->client_name_snapshot ?: $appointment->client?->name ?: 'Cliente');
        $clientPhone = (string) ($appointment->client_phone_snapshot ?: $appointment->client?->phone ?: 'Não informado');

        $lines = [
            "{$title} em {$company->name}",
            '',
            "Cliente: {$clientName}",
            "Telefone: {$clientPhone}",
            "Serviço: {$appointment->service_name_snapshot}",
            "Data: {$localStart->format('d/m/Y')}",
            "Horário: {$localStart->format('H:i')}",
            "Status: {$status}",
            "Origem: {$origin}",
        ];

        if ($notificationType === 'rescheduled' && $oldLocalStart !== null) {
            $lines[] = "Horário anterior: {$oldLocalStart->format('d/m/Y H:i')}";
        }

        if ($notificationType === 'cancelled' && filled($appointment->cancellation_reason)) {
            $lines[] = "Motivo: {$appointment->cancellation_reason}";
        }

        if (filled($appointment->notes)) {
            $lines[] = "Observações: {$appointment->notes}";
        }

        return implode("\n", $lines);
    }

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
            '{placa}' => (string) (VehiclePlate::format($appointment->client?->vehicle_plate) ?? $appointment->client?->vehicle_plate ?? ''),
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

    public function buildCancellation(Company $company, Appointment $appointment): string
    {
        $localStart = CompanyDateTime::utcToLocal($company, $appointment->start_at);

        return strtr(
            <<<'TXT'
Olá, {nome}. Seu agendamento em {empresa} foi cancelado.

Serviço: {servico}
Data: {data}
Horário: {hora}
Motivo: {motivo}
TXT,
            [
                '{nome}' => (string) ($appointment->client_name_snapshot ?? 'cliente'),
                '{empresa}' => (string) $company->name,
                '{servico}' => (string) ($appointment->service_name_snapshot ?? 'serviço'),
                '{data}' => $localStart->format('d/m/Y'),
                '{hora}' => $localStart->format('H:i'),
                '{motivo}' => (string) ($appointment->cancellation_reason ?? 'Não informado'),
            ],
        );
    }

    public function buildCancellationForStaff(Company $company, Appointment $appointment): string
    {
        $localStart = CompanyDateTime::utcToLocal($company, $appointment->start_at);

        return strtr(
            <<<'TXT'
Agendamento cancelado em {empresa}

Cliente: {nome}
Telefone: {telefone}
Serviço: {servico}
Profissional: {profissional}
Data: {data}
Horário: {hora}
Motivo: {motivo}
TXT,
            [
                '{empresa}' => (string) $company->name,
                '{nome}' => (string) ($appointment->client_name_snapshot ?? $appointment->client?->name ?? 'cliente'),
                '{telefone}' => (string) ($appointment->client_phone_snapshot ?? $appointment->client?->phone ?? ''),
                '{servico}' => (string) ($appointment->service_name_snapshot ?? 'serviço'),
                '{profissional}' => (string) ($appointment->professional?->name ?? 'A definir'),
                '{data}' => $localStart->format('d/m/Y'),
                '{hora}' => $localStart->format('H:i'),
                '{motivo}' => (string) ($appointment->cancellation_reason ?? 'Não informado'),
            ],
        );
    }

    public function buildReschedule(
        Company $company,
        Appointment $appointment,
        ?CarbonImmutable $oldStartAt = null,
    ): string {
        $newStart = CompanyDateTime::utcToLocal($company, $appointment->start_at);
        $oldStart = $oldStartAt ? CompanyDateTime::utcToLocal($company, $oldStartAt) : null;

        return strtr(
            <<<'TXT'
Olá, {nome}. Seu agendamento em {empresa} foi remarcado.

Serviço: {servico}
Data anterior: {data_anterior} às {hora_anterior}
Nova data: {nova_data} às {nova_hora}
TXT,
            [
                '{nome}' => (string) ($appointment->client_name_snapshot ?? 'cliente'),
                '{empresa}' => (string) $company->name,
                '{servico}' => (string) ($appointment->service_name_snapshot ?? 'serviço'),
                '{data_anterior}' => $oldStart?->format('d/m/Y') ?? 'Não informada',
                '{hora_anterior}' => $oldStart?->format('H:i') ?? '--:--',
                '{nova_data}' => $newStart->format('d/m/Y'),
                '{nova_hora}' => $newStart->format('H:i'),
            ],
        );
    }

    public function buildRescheduleForStaff(
        Company $company,
        Appointment $appointment,
        ?CarbonImmutable $oldStartAt = null,
    ): string {
        $newStart = CompanyDateTime::utcToLocal($company, $appointment->start_at);
        $oldStart = $oldStartAt ? CompanyDateTime::utcToLocal($company, $oldStartAt) : null;

        return strtr(
            <<<'TXT'
Agendamento remarcado em {empresa}

Cliente: {nome}
Telefone: {telefone}
Serviço: {servico}
Profissional: {profissional}
Data anterior: {data_anterior} às {hora_anterior}
Nova data: {nova_data} às {nova_hora}
TXT,
            [
                '{empresa}' => (string) $company->name,
                '{nome}' => (string) ($appointment->client_name_snapshot ?? $appointment->client?->name ?? 'cliente'),
                '{telefone}' => (string) ($appointment->client_phone_snapshot ?? $appointment->client?->phone ?? ''),
                '{servico}' => (string) ($appointment->service_name_snapshot ?? 'serviço'),
                '{profissional}' => (string) ($appointment->professional?->name ?? 'A definir'),
                '{data_anterior}' => $oldStart?->format('d/m/Y') ?? 'Não informada',
                '{hora_anterior}' => $oldStart?->format('H:i') ?? '--:--',
                '{nova_data}' => $newStart->format('d/m/Y'),
                '{nova_hora}' => $newStart->format('H:i'),
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
