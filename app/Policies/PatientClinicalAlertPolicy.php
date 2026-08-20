<?php

namespace App\Policies;

use App\Enums\CompanyPermission;
use App\Models\PatientClinicalAlert;
use App\Models\User;
use App\Policies\Concerns\AuthorizesClinicalRecords;

class PatientClinicalAlertPolicy
{
    use AuthorizesClinicalRecords;

    public function viewAny(User $user): bool
    {
        return $this->allowsClinical($user, CompanyPermission::ViewClinicalAlerts);
    }

    public function view(User $user, PatientClinicalAlert $record): bool
    {
        return $this->allowsClinical($user, CompanyPermission::ViewClinicalAlerts, $record);
    }

    public function create(User $user): bool
    {
        return $this->allowsClinical($user, CompanyPermission::WriteClinicalRecords);
    }

    public function update(User $user, PatientClinicalAlert $record): bool
    {
        return false;
    }

    public function delete(User $user, PatientClinicalAlert $record): bool
    {
        return false;
    }
}
