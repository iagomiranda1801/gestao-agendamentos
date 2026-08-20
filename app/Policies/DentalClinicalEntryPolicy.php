<?php

namespace App\Policies;

use App\Enums\CompanyPermission;
use App\Models\DentalClinicalEntry;
use App\Models\User;
use App\Policies\Concerns\AuthorizesClinicalRecords;

class DentalClinicalEntryPolicy
{
    use AuthorizesClinicalRecords;

    public function viewAny(User $user): bool
    {
        return $this->allowsClinical($user, CompanyPermission::ViewClinicalRecords);
    }

    public function view(User $user, DentalClinicalEntry $record): bool
    {
        return $this->allowsClinical($user, CompanyPermission::ViewClinicalRecords, $record);
    }

    public function create(User $user): bool
    {
        return $this->allowsClinical($user, CompanyPermission::WriteClinicalRecords);
    }

    public function update(User $user, DentalClinicalEntry $record): bool
    {
        return $record->status === 'draft' && (int) $record->author_id === (int) $user->getKey() && $this->allowsClinical($user, CompanyPermission::WriteClinicalRecords, $record);
    }

    public function delete(User $user, DentalClinicalEntry $record): bool
    {
        return false;
    }
}
