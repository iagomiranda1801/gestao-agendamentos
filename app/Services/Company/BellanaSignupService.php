<?php

namespace App\Services\Company;

use App\Enums\BillingInterval;
use App\Enums\CompanyProfile;
use App\Models\Company;
use App\Models\User;
use App\Services\Scheduling\CompanySchedulingSettingService;
use App\Support\BellanaLaunchPlan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BellanaSignupService
{
    /** @param array{company_name: string, phone: string, name: string, email: string, password: string} $data
     * @return array{company: Company, user: User}
     */
    public function register(array $data): array
    {
        return DB::transaction(function () use ($data): array {
            $result = app(CompanyProvisioningService::class)->provision([
                'name' => $data['company_name'],
                'slug' => Company::generateUniqueSlug(Str::slug($data['company_name']) ?: 'salao'),
                'email' => $data['email'],
                'phone' => $data['phone'],
                'business_profile' => CompanyProfile::Salon,
                'enabled_modules' => BellanaLaunchPlan::modules(),
                'billing_interval' => BillingInterval::Monthly,
                'admin_name' => $data['name'],
                'admin_email' => $data['email'],
                'admin_password' => $data['password'],
            ]);

            $result['company']->forceFill([
                'subscription_plan' => BellanaLaunchPlan::KEY,
                'subscription_price_cents' => BellanaLaunchPlan::priceCents(),
            ])->save();

            // A IA de estética exige provedor e chave próprios, então fica desligada;
            // o bot de agendamento do WhatsApp já sai ligado, como no TattooFlow.
            app(CompanySchedulingSettingService::class)->update($result['company'], [
                'whatsapp_bot_enabled' => true,
            ]);

            return $result;
        });
    }
}
