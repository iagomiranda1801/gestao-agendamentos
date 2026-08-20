<?php

namespace App\Policies;

use App\Enums\CompanyPermission;
use App\Models\DentalOdontogram;
use App\Models\User;
use App\Policies\Concerns\AuthorizesClinicalRecords;

class DentalOdontogramPolicy
{
    use AuthorizesClinicalRecords;

    public function viewAny(User $user): bool
    {
        return $this->allowsClinical($user, CompanyPermission::ViewClinicalRecords);
    }

    public function view(User $user, DentalOdontogram $record): bool
    {
        return $this->allowsClinical($user, CompanyPermission::ViewClinicalRecords, $record);
    }

    public function create(User $user): bool
    {
        return $this->allowsClinical($user, CompanyPermission::WriteClinicalRecords);
    }

    public function update(User $user, DentalOdontogram $record): bool
    {
        return $record->status === 'draft' && (int) $record->created_by === (int) $user->getKey() && $this->allowsClinical($user, CompanyPermission::WriteClinicalRecords, $record);
    }

    public function delete(User $user, DentalOdontogram $record): bool
    {
        return false;
    }
}
