<?php

namespace App\Jobs;

use App\Enums\CompanyRole;
use App\Filament\App\Resources\Appointments\AppointmentResource;
use App\Models\Appointment;
use App\Models\User;
use App\Support\CompanyDateTime;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Throwable;

class NotifyStaffOfOnlineBookingJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $appointmentId,
    ) {}

    public function handle(): void
    {
        $appointment = Appointment::query()
            ->with(['company', 'professional.user'])
            ->find($this->appointmentId);

        if ($appointment === null || $appointment->company === null) {
            return;
        }

        $recipients = $this->recipients($appointment);

        if ($recipients->isEmpty()) {
            return;
        }

        $company = $appointment->company;
        $localStart = CompanyDateTime::utcToLocal($company, $appointment->start_at);
        $clientName = (string) ($appointment->client_name_snapshot ?? 'Cliente');
        $serviceName = (string) ($appointment->service_name_snapshot ?? 'serviço');
        $when = $localStart->format('d/m/Y H:i');

        try {
            $url = AppointmentResource::getUrl(
                name: 'view',
                parameters: ['record' => $appointment],
                panel: 'app',
                tenant: $company,
            );
        } catch (Throwable) {
            $url = null;
        }

        $notification = Notification::make()
            ->title('Novo agendamento online')
            ->body("{$clientName} · {$serviceName} · {$when}")
            ->success();

        if (filled($url)) {
            $notification->actions([
                Action::make('view')
                    ->label('Ver agendamento')
                    ->url($url)
                    ->markAsRead(),
            ]);
        }

        $notification->sendToDatabase($recipients);
    }

    /**
     * @return Collection<int, User>
     */
    protected function recipients(Appointment $appointment): Collection
    {
        $company = $appointment->company;

        $allowedRoles = [
            CompanyRole::CompanyAdmin->value,
            CompanyRole::Manager->value,
        ];

        $admins = $company->users()
            ->where('users.is_active', true)
            ->wherePivot('is_active', true)
            ->get()
            ->filter(function (User $user) use ($allowedRoles): bool {
                $role = $user->pivot->role;

                $value = $role instanceof CompanyRole ? $role->value : (string) $role;

                return in_array($value, $allowedRoles, true);
            });

        $recipients = $admins->keyBy(fn (User $user): int => (int) $user->getKey());

        $professionalUser = $appointment->professional?->user;
        if ($professionalUser !== null
            && $professionalUser->is_active
            && $professionalUser->hasActiveCompanyMembershipWith($company)) {
            $recipients->put((int) $professionalUser->getKey(), $professionalUser);
        }

        return $recipients->values();
    }
}
