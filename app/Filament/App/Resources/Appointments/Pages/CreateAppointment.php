<?php

namespace App\Filament\App\Resources\Appointments\Pages;

use App\Filament\App\Resources\Appointments\AppointmentResource;
use App\Models\Client;
use App\Models\Company;
use App\Models\Professional;
use App\Models\Service;
use App\Services\Scheduling\AppointmentService;
use App\Support\CompanyDateTime;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateAppointment extends CreateRecord
{
    protected static string $resource = AppointmentResource::class;

    public function mount(): void
    {
        parent::mount();

        if ($date = request()->query('date')) {
            $this->form->fill([
                'appointment_date' => $date,
                'appointment_time' => request()->query('time'),
            ]);
        }
    }

    protected function handleRecordCreation(array $data): Model
    {
        /** @var Company $company */
        $company = Filament::getTenant();

        $client = Client::query()->findOrFail($data['client_id']);
        $service = Service::query()->findOrFail($data['service_id']);
        $professional = Professional::query()->findOrFail($data['professional_id']);

        $localStart = CompanyDateTime::parseLocal(
            $company,
            $data['appointment_date'],
            $data['appointment_time'],
        );

        return app(AppointmentService::class)->createInternalAppointment(
            $company,
            auth()->user(),
            $client,
            $professional,
            $service,
            $localStart,
            $data,
        );
    }
}
