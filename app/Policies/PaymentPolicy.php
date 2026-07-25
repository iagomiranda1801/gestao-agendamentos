<?php

namespace App\Policies;

use App\Models\Attendance;
use App\Models\Payment;
use App\Models\User;
use App\Policies\Concerns\AuthorizesCompanyRecords;
use App\Policies\Concerns\AuthorizesFinancialAccess;
use App\Policies\Concerns\AuthorizesSchedulingAccess;

class PaymentPolicy
{
    use AuthorizesCompanyRecords;
    use AuthorizesFinancialAccess;
    use AuthorizesSchedulingAccess;

    public function viewAny(User $user): bool
    {
        return $this->userCanViewFinancialRecords($user);
    }

    public function view(User $user, Payment $payment): bool
    {
        if (! $this->recordBelongsToAccessibleTenant($user, $payment->company)) {
            return false;
        }

        if ($this->userCanViewFinancialRecords($user, $payment->company)) {
            return true;
        }

        $payment->loadMissing('attendance');

        return $payment->attendance !== null
            && $this->employeeCanAccessOwnAttendance($user, $payment->attendance);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Payment $payment): bool
    {
        return false;
    }

    public function delete(User $user, Payment $payment): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function cancel(User $user, Payment $payment): bool
    {
        if (! $this->recordBelongsToAccessibleTenant($user, $payment->company)) {
            return false;
        }

        if (! $payment->isConfirmed()) {
            return false;
        }

        return $this->userCanManageScheduling($user, $payment->company);
    }

    protected function employeeCanAccessOwnAttendance(User $user, Attendance $attendance): bool
    {
        $professional = $this->linkedProfessional($user, $attendance->company);

        return $professional !== null
            && (int) $professional->getKey() === (int) $attendance->professional_id;
    }
}
