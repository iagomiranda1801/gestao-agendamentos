<?php

namespace App\Services\Company;

use App\Enums\BillingInterval;
use App\Enums\CompanyProfile;
use App\Models\Company;
use App\Services\Scheduling\CompanySchedulingSettingService;
use App\Support\TattooLaunchPlan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TattooSignupService
{
    /** @param array{company_name: string, phone: string, name: string, email: string, password: string} $data
     *  @return array{company: \App\Models\Company, user: \App\Models\User}
     */
    public function register(array $data): array
    {
        return DB::transaction(function () use ($data): array {
            $result = app(CompanyProvisioningService::class)->provision([
                'name' => $data['company_name'],
                'slug' => Company::generateUniqueSlug(Str::slug($data['company_name']) ?: 'estudio'),
                'email' => $data['email'],
                'phone' => $data['phone'],
                'business_profile' => CompanyProfile::TattooStudio,
                'enabled_modules' => TattooLaunchPlan::modules(),
                'billing_interval' => BillingInterval::Monthly,
                'admin_name' => $data['name'],
                'admin_email' => $data['email'],
                'admin_password' => $data['password'],
            ]);

            $result['company']->forceFill([
                'subscription_plan' => TattooLaunchPlan::KEY,
                'subscription_price_cents' => TattooLaunchPlan::priceCents(),
            ])->save();

            app(CompanySchedulingSettingService::class)->update($result['company'], [
                'whatsapp_bot_enabled' => true,
            ]);

            return $result;
        });
    }
}
