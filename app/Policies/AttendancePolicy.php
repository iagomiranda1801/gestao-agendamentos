<?php

namespace App\Policies;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\User;
use App\Policies\Concerns\AuthorizesCompanyRecords;
use App\Policies\Concerns\AuthorizesFinancialAccess;
use App\Policies\Concerns\AuthorizesSchedulingAccess;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Builder;

class AttendancePolicy
{
    use AuthorizesCompanyRecords;
    use AuthorizesFinancialAccess;
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

    public function view(User $user, Attendance $attendance): bool
    {
        if (! $this->recordBelongsToAccessibleTenant($user, $attendance->company)) {
            return false;
        }

        if ($this->userCanManageScheduling($user, $attendance->company)) {
            return true;
        }

        return $this->employeeCanAccessOwnAttendance($user, $attendance);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Attendance $attendance): bool
    {
        return false;
    }

    public function delete(User $user, Attendance $attendance): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function viewFinancialDistribution(User $user, ?Attendance $attendance = null): bool
    {
        $company = $attendance?->company ?? Filament::getTenant();

        return $company instanceof Company
            && $this->userCanViewFinancialRecords($user, $company);
    }

    public function registerPayment(User $user, Attendance $attendance): bool
    {
        if (! $this->view($user, $attendance)) {
            return false;
        }

        $attendance->loadMissing('receivable');

        if ($attendance->receivable === null) {
            return false;
        }

        if ($attendance->receivable->isSettled() || $attendance->receivable->isCancelled()) {
            return false;
        }

        return true;
    }

    public function complete(User $user, Appointment $appointment): bool
    {
        if (! $this->recordBelongsToAccessibleTenant($user, $appointment->company)) {
            return false;
        }

        if ($appointment->attendance()->exists()) {
            return false;
        }

        if (! in_array($appointment->status, [
            AppointmentStatus::Confirmed,
            AppointmentStatus::InProgress,
        ], true)) {
            return false;
        }

        if ($this->userCanManageScheduling($user, $appointment->company)) {
            return true;
        }

        return $this->employeeCanAccessOwnAppointment($user, $appointment);
    }

    public function scopeAccessibleToUser(Builder $query, User $user, Company $company): Builder
    {
        if ($this->userCanManageScheduling($user, $company)) {
            return $query;
        }

        $professional = $this->linkedProfessional($user, $company);

        if ($professional === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('professional_id', $professional->getKey());
    }

    protected function employeeCanAccessOwnAttendance(User $user, Attendance $attendance): bool
    {
        $professional = $this->linkedProfessional($user, $attendance->company);

        return $professional !== null
            && (int) $professional->getKey() === (int) $attendance->professional_id;
    }
}
