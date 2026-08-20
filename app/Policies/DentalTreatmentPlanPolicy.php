<?php

namespace App\Policies;

use App\Enums\CompanyPermission;
use App\Models\DentalTreatmentPlan;
use App\Models\User;
use App\Policies\Concerns\AuthorizesClinicalRecords;

class DentalTreatmentPlanPolicy
{
    use AuthorizesClinicalRecords;

    public function viewAny(User $user): bool
    {
        return $this->allowsClinical($user, CompanyPermission::ManageTreatmentPlans);
    }

    public function view(User $user, DentalTreatmentPlan $record): bool
    {
        return $this->allowsClinical($user, CompanyPermission::ManageTreatmentPlans, $record);
    }

    public function create(User $user): bool
    {
        return $this->allowsClinical($user, CompanyPermission::ManageTreatmentPlans);
    }

    public function update(User $user, DentalTreatmentPlan $record): bool
    {
        return $record->approved_at === null && $this->allowsClinical($user, CompanyPermission::ManageTreatmentPlans, $record);
    }

    public function delete(User $user, DentalTreatmentPlan $record): bool
    {
        return false;
    }
}
