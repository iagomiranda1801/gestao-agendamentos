<?php

namespace App\Services\WhatsApp\Automations;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Attendance;
use App\Models\Client;
use App\Models\Company;
use App\Support\CompanyDateTime;
use App\Support\VehiclePlate;

class WhatsAppAutomationMessageBuilder
{
    public function bookingUrl(Company $company): string
    {
        return route('public.booking.show', ['company' => $company->slug]);
    }

    public function render(
        Company $company,
        string $template,
        ?Client $client = null,
        ?Appointment $appointment = null,
        ?Attendance $attendance = null,
    ): string {
        $localStart = $appointment?->start_at
            ? CompanyDateTime::utcToLocal($company, $appointment->start_at)
            : null;

        $serviceName = (string) (
            $appointment?->service_name_snapshot
            ?? $attendance?->service_name_snapshot
            ?? $attendance?->service?->name
            ?? $appointment?->service?->name
            ?? 'serviço'
        );

        $plate = VehiclePlate::format($client?->vehicle_plate) ?? ($client?->vehicle_plate ?: 'veículo');

        return strtr($template, [
            '{nome}' => (string) ($client?->name ?? $appointment?->client_name_snapshot ?? 'cliente'),
            '{servico}' => $serviceName,
            '{data}' => $localStart?->format('d/m/Y') ?? '',
            '{hora}' => $localStart?->format('H:i') ?? '',
            '{codigo}' => (string) ($appointment?->public_confirmation_code ?? ''),
            '{link}' => $this->bookingUrl($company),
            '{empresa}' => (string) $company->name,
            '{placa}' => $plate,
        ]);
    }

    /**
     * @return list<AppointmentStatus>
     */
    public static function futureBlockingStatuses(): array
    {
        return [
            AppointmentStatus::Pending,
            AppointmentStatus::Confirmed,
            AppointmentStatus::InProgress,
        ];
    }
}
