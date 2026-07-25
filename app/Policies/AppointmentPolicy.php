<?php

namespace App\Policies;

use App\Models\Appointment;
use App\Models\Company;
use App\Models\User;
use App\Policies\Concerns\AuthorizesCompanyRecords;
use App\Policies\Concerns\AuthorizesSchedulingAccess;
use Filament\Facades\Filament;

class AppointmentPolicy
{
    use AuthorizesCompanyRecords;
    use AuthorizesSchedulingAccess;

    public function viewAny(User $user): bool
    {
        if ($this->userCanManageScheduling($user)) {
            return true;
        }

        $company = Filament::getTenant();

        return $company instanceof Company
            && $this->employeeCanAccessCalendar($user, $company);
    }

    public function view(User $user, Appointment $appointment): bool
    {
        if (! $this->recordBelongsToAccessibleTenant($user, $appointment->company)) {
            return false;
        }

        if ($this->userCanManageScheduling($user, $appointment->company)) {
            return true;
        }

        return $this->employeeCanAccessOwnAppointment($user, $appointment);
    }

    public function create(User $user): bool
    {
        return $this->userCanManageScheduling($user);
    }

    public function update(User $user, Appointment $appointment): bool
    {
        return $this->recordBelongsToAccessibleTenant($user, $appointment->company)
            && $this->userCanManageScheduling($user, $appointment->company);
    }

    public function delete(User $user, Appointment $appointment): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function confirm(User $user, Appointment $appointment): bool
    {
        return $this->update($user, $appointment);
    }

    public function start(User $user, Appointment $appointment): bool
    {
        if (! $this->recordBelongsToAccessibleTenant($user, $appointment->company)) {
            return false;
        }

        if ($this->userCanManageScheduling($user, $appointment->company)) {
            return true;
        }

        return $this->employeeCanAccessOwnAppointment($user, $appointment);
    }

    public function reschedule(User $user, Appointment $appointment): bool
    {
        return $this->update($user, $appointment);
    }

    public function cancel(User $user, Appointment $appointment): bool
    {
        return $this->update($user, $appointment);
    }

    public function markNoShow(User $user, Appointment $appointment): bool
    {
        return $this->update($user, $appointment);
    }

    public function complete(User $user, Appointment $appointment): bool
    {
        return app(AttendancePolicy::class)->complete($user, $appointment);
    }
}
