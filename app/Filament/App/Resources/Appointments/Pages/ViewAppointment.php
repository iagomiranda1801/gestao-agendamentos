<?php

namespace App\Filament\App\Resources\Appointments\Pages;

use App\Filament\App\Resources\Appointments\AppointmentResource;
use App\Filament\App\Resources\Appointments\Concerns\InteractsWithAppointmentActions;
use App\Filament\App\Resources\Appointments\Schemas\AppointmentForm;
use App\Models\Appointment;
use App\Models\Company;
use App\Support\CompanyDateTime;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;

class ViewAppointment extends ViewRecord
{
    use InteractsWithAppointmentActions;

    protected static string $resource = AppointmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->visible(fn (Appointment $record): bool => auth()->user()?->can('update', $record) ?? false),
            ...$this->getAppointmentActions(),
        ];
    }

    public function form(Schema $schema): Schema
    {
        return AppointmentForm::configure($schema, readOnly: true);
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Company $company */
        $company = Filament::getTenant();

        /** @var Appointment $record */
        $record = $this->getRecord();
        $localStart = CompanyDateTime::utcToLocal($company, $record->start_at);

        $data['client_id'] = $record->client_id;
        $data['service_id'] = $record->service_id;
        $data['professional_id'] = $record->professional_id;
        $data['appointment_date'] = $localStart->toDateString();
        $data['appointment_time'] = $localStart->format('H:i');
        $data['status_label'] = $record->status->label();
        $data['notes'] = $record->notes;
        $data['internal_notes'] = $record->internal_notes;
        $data['public_confirmation_code'] = $record->public_confirmation_code;
        $data['client_name_snapshot'] = $record->client_name_snapshot;
        $data['client_phone_snapshot'] = $record->client_phone_snapshot;
        $data['client_email_snapshot'] = $record->client_email_snapshot;
        $data['public_booked_at_label'] = $record->public_booked_at
            ? CompanyDateTime::utcToLocal($company, $record->public_booked_at)->format('d/m/Y H:i')
            : '—';
        $data['privacy_accepted_label'] = $record->privacy_accepted_at
            ? CompanyDateTime::utcToLocal($company, $record->privacy_accepted_at)->format('d/m/Y H:i')
            : '—';
        $data['terms_accepted_label'] = $record->terms_accepted_at
            ? CompanyDateTime::utcToLocal($company, $record->terms_accepted_at)->format('d/m/Y H:i')
            : '—';

        return $data;
    }
}
