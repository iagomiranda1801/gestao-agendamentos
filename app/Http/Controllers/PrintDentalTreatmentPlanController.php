<?php

namespace App\Http\Controllers;

use App\Enums\CompanyPermission;
use App\Models\Company;
use App\Models\DentalTreatmentPlan;
use App\Services\Company\CompanyPermissionService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class PrintDentalTreatmentPlanController
{
    public function __invoke(Request $request, Company $company, DentalTreatmentPlan $plan): View
    {
        abort_unless($request->user()?->canAccessTenant($company), 403);
        abort_unless($company->isDentalClinic() && (int) $plan->company_id === (int) $company->getKey(), 404);
        $permissions = app(CompanyPermissionService::class);
        $canManage = $permissions->allows($request->user(), $company, CompanyPermission::ManageTreatmentPlans);
        $canViewPrices = $permissions->allows($request->user(), $company, CompanyPermission::ViewTreatmentPrices);
        abort_unless($canManage || $canViewPrices, 403);

        $plan->loadMissing(['client.dentalProfile', 'professional', 'items.service']);

        return view('dental.treatment-plan-print', compact('company', 'plan'));
    }
}
