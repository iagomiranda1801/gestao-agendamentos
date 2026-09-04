<?php

namespace App\Filament\App\Resources\Appointments\Pages;

use App\Filament\App\Resources\Appointments\AppointmentResource;
use App\Filament\App\Resources\Appointments\Concerns\InteractsWithAppointmentActions;
use App\Filament\App\Support\AppointmentSchedulingForm;
use App\Models\Appointment;
use App\Models\Company;
use App\Services\Scheduling\AppointmentService;
use App\Support\CompanyDateTime;
use Filament\Facades\Filament;
use App\Filament\App\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditAppointment extends EditRecord
{
    use InteractsWithAppointmentActions;

    protected static string $resource = AppointmentResource::class;

    protected function getHeaderActions(): array
    {
        return $this->getAppointmentActions();
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Company $company */
        $company = Filament::getTenant();

        /** @var Appointment $record */
        $record = $this->getRecord();
        $localStart = CompanyDateTime::utcToLocal($company, $record->start_at);

        $data['appointment_date'] = $localStart->toDateString();
        $data['appointment_time'] = $localStart->format('H:i');
        $data['status_label'] = $record->status->label();

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Company $company */
        $company = Filament::getTenant();

        /** @var Appointment $record */
        try {
            return app(AppointmentService::class)->updateAppointment(
                $company,
                auth()->user(),
                $record,
                $data,
            );
        } catch (ValidationException $exception) {
            $this->hasNotifiedValidationError = true;
            AppointmentSchedulingForm::notifyAndRethrow($exception, $this->form->getStatePath());
        }
    }
}
