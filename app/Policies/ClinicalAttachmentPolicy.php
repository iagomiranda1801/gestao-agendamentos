<?php

namespace App\Policies;

use App\Enums\CompanyPermission;
use App\Models\ClinicalAttachment;
use App\Models\User;
use App\Policies\Concerns\AuthorizesClinicalRecords;

class ClinicalAttachmentPolicy
{
    use AuthorizesClinicalRecords;

    public function viewAny(User $user): bool
    {
        return $this->allowsClinical($user, CompanyPermission::ViewClinicalRecords);
    }

    public function view(User $user, ClinicalAttachment $record): bool
    {
        return $this->allowsClinical($user, CompanyPermission::ViewClinicalRecords, $record);
    }

    public function create(User $user): bool
    {
        return $this->allowsClinical($user, CompanyPermission::WriteClinicalRecords);
    }

    public function update(User $user, ClinicalAttachment $record): bool
    {
        return false;
    }

    public function delete(User $user, ClinicalAttachment $record): bool
    {
        return $this->allowsClinical($user, CompanyPermission::WriteClinicalRecords, $record);
    }
}
