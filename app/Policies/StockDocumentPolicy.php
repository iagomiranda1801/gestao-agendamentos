<?php

namespace App\Policies;

use App\Enums\CompanyRole;
use App\Models\StockDocument;
use App\Models\User;
use App\Policies\Concerns\AuthorizesCompanyRecords;

class StockDocumentPolicy
{
    use AuthorizesCompanyRecords;

    /**
     * @return list<CompanyRole>
     */
    protected function allowedRoles(): array
    {
        return [
            CompanyRole::CompanyAdmin,
            CompanyRole::Manager,
        ];
    }

    public function viewAny(User $user): bool
    {
        return $this->userCanManageRecords($user, null, ...$this->allowedRoles());
    }

    public function view(User $user, StockDocument $document): bool
    {
        return $this->recordBelongsToAccessibleTenant($user, $document->company)
            && $this->userCanManageRecords($user, $document->company, ...$this->allowedRoles());
    }

    public function create(User $user): bool
    {
        return $this->userCanManageRecords($user, null, ...$this->allowedRoles());
    }

    public function update(User $user, StockDocument $document): bool
    {
        return $this->view($user, $document);
    }

    public function delete(User $user, StockDocument $document): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function post(User $user, StockDocument $document): bool
    {
        return $this->view($user, $document) && $document->isDraft();
    }

    public function reverse(User $user, StockDocument $document): bool
    {
        return $this->view($user, $document) && $document->isPosted();
    }
}
