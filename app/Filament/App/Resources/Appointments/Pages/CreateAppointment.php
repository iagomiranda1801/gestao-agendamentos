<?php

namespace App\Filament\App\Resources\Appointments\Pages;

use App\Filament\App\Resources\Appointments\AppointmentResource;
use App\Filament\App\Resources\Pages\CreateRecord;
use App\Filament\App\Support\AppointmentSchedulingForm;
use App\Models\Client;
use App\Models\Company;
use App\Models\Professional;
use App\Models\Service;
use App\Models\TattooRequest;
use App\Services\Scheduling\AppointmentService;
use App\Support\CompanyDateTime;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateAppointment extends CreateRecord
{
    protected static string $resource = AppointmentResource::class;

    public ?int $tattooRequestId = null;

    public function mount(): void
    {
        parent::mount();

        if ($date = request()->query('date')) {
            $this->form->fill(array_merge($this->data ?? [], [
                'appointment_date' => $date,
                'appointment_time' => request()->query('time'),
            ]));
        }

        if ($requestId = request()->query('tattoo_request')) {
            $tattooRequest = TattooRequest::query()
                ->where('company_id', Filament::getTenant()?->getKey())
                ->where('status', 'accepted')
                ->findOrFail($requestId);
            $this->tattooRequestId = $tattooRequest->getKey();
            $this->form->fill(array_merge($this->data ?? [], [
                'client_id' => $tattooRequest->client_id,
                'professional_id' => $tattooRequest->professional_id,
                'service_selection_mode' => 'to_be_defined',
                'duration_minutes_snapshot' => $tattooRequest->quotes()->latest('version')->value('minutes_per_session') ?: 60,
                'internal_notes' => 'Pedido de tatuagem #'.$tattooRequest->getKey().': '.$tattooRequest->description,
            ]));
        }
    }

    protected function afterCreate(): void
    {
        if ($requestId = $this->tattooRequestId) {
            TattooRequest::query()
                ->where('company_id', Filament::getTenant()?->getKey())
                ->where('status', 'accepted')
                ->where('client_id', $this->getRecord()->client_id)
                ->whereNull('appointment_id')
                ->whereKey($requestId)
                ->update(['appointment_id' => $this->getRecord()->getKey(), 'status' => 'booked']);
        }
    }

    protected function handleRecordCreation(array $data): Model
    {
        /** @var Company $company */
        $company = Filament::getTenant();

        $client = Client::query()->findOrFail($data['client_id']);
        $service = filled($data['service_id'] ?? null)
            ? Service::query()->findOrFail($data['service_id'])
            : null;
        $professional = Professional::query()->findOrFail($data['professional_id']);

        if ($this->tattooRequestId !== null) {
            $tattooRequest = TattooRequest::query()->where('company_id', $company->getKey())
                ->where('status', 'accepted')->whereNull('appointment_id')->findOrFail($this->tattooRequestId);
            if ((int) $tattooRequest->client_id !== (int) $client->getKey()
                || ($tattooRequest->professional_id !== null && (int) $tattooRequest->professional_id !== (int) $professional->getKey())) {
                throw ValidationException::withMessages(['client_id' => 'O cliente ou tatuador não corresponde ao orçamento aceito.']);
            }
        }

        $localStart = CompanyDateTime::parseLocal(
            $company,
            $data['appointment_date'],
            $data['appointment_time'],
        );

        try {
            return app(AppointmentService::class)->createInternalAppointment(
                $company,
                auth()->user(),
                $client,
                $professional,
                $service,
                $localStart,
                $data,
            );
        } catch (ValidationException $exception) {
            $this->hasNotifiedValidationError = true;
            AppointmentSchedulingForm::notifyAndRethrow($exception, $this->form->getStatePath());
        }
    }
}
