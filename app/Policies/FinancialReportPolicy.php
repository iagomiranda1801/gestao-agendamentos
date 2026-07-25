<?php

namespace App\Policies;

use App\Models\User;
use App\Policies\Concerns\AuthorizesCompanyRecords;
use App\Policies\Concerns\AuthorizesFinancialAccess;

class FinancialReportPolicy
{
    use AuthorizesCompanyRecords;
    use AuthorizesFinancialAccess;

    public function viewAny(User $user): bool
    {
        return $this->userCanViewFinancialRecords($user);
    }
}
