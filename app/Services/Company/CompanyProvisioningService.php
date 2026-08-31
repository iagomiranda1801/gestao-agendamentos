<?php

namespace App\Services\Company;

use App\Enums\CompanyModule;
use App\Enums\CompanyProfile;
use App\Enums\CompanyRole;
use App\Enums\SubscriptionStatus;
use App\Enums\Weekday;
use App\Models\Company;
use App\Models\User;
use App\Services\Clinical\DentalClinicSettingService;
use App\Services\Scheduling\CompanyBusinessHoursService;
use App\Services\Scheduling\CompanySchedulingSettingService;
use App\Services\WhatsApp\Automations\WhatsAppAutomationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CompanyProvisioningService
{
    public function __construct(
        protected CompanyModuleService $moduleService,
        protected CompanySchedulingSettingService $schedulingSettingService,
        protected CompanyBusinessHoursService $businessHoursService,
        protected DentalClinicSettingService $dentalClinicSettingService,
    ) {}

    /**
     * @param  array{
     *     name: string,
     *     slug?: string|null,
     *     email?: string|null,
     *     phone?: string|null,
     *     timezone?: string,
     *     business_profile?: CompanyProfile|string,
     *     enabled_modules?: list<CompanyModule|string>,
     *     subscription_status?: SubscriptionStatus,
     *     trial_ends_at?: \DateTimeInterface|null,
     *     admin_name: string,
     *     admin_email: string,
     *     admin_password: string,
     * }  $data
     * @return array{company: Company, user: User}
     */
    public function provision(array $data): array
    {
        return DB::transaction(function () use ($data): array {
            $profile = $data['business_profile'] ?? CompanyProfile::Custom;
            $profile = $profile instanceof CompanyProfile ? $profile : CompanyProfile::tryFrom((string) $profile) ?? CompanyProfile::Custom;

            $modules = collect($data['enabled_modules'] ?? $profile->defaultModules())
                ->map(fn (mixed $module) => $module instanceof CompanyModule ? $module : CompanyModule::tryFrom((string) $module))
                ->filter()
                ->unique()
                ->values();

            if ($modules->isEmpty()) {
                throw ValidationException::withMessages([
                    'enabled_modules' => 'Selecione ao menos um módulo.',
                ]);
            }

            if (User::query()->where('email', $data['admin_email'])->exists()) {
                throw ValidationException::withMessages([
                    'admin_email' => 'Este e-mail já está em uso.',
                ]);
            }

            $slug = filled($data['slug'] ?? null)
                ? Str::slug((string) $data['slug'])
                : Company::generateUniqueSlug($data['name']);

            if (Company::query()->where('slug', $slug)->exists()) {
                throw ValidationException::withMessages([
                    'slug' => 'Este identificador já está em uso.',
                ]);
            }

            $subscriptionStatus = $data['subscription_status'] ?? SubscriptionStatus::Trial;
            $trialEndsAt = $data['trial_ends_at'] ?? ($subscriptionStatus === SubscriptionStatus::Trial ? now()->addDays(7) : null);

            $company = Company::query()->create([
                'name' => $data['name'],
                'business_profile' => $profile->value,
                'slug' => $slug,
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'timezone' => $data['timezone'] ?? 'America/Sao_Paulo',
                'is_active' => true,
                'enabled_modules' => $modules->map(fn (CompanyModule $module) => $module->value)->all(),
                'subscription_status' => $subscriptionStatus,
                'trial_ends_at' => $trialEndsAt,
            ]);

            $user = User::query()->create([
                'name' => $data['admin_name'],
                'email' => $data['admin_email'],
                'password' => Hash::make($data['admin_password']),
                'is_super_admin' => false,
                'is_active' => true,
            ]);

            $company->users()->attach($user, [
                'role' => CompanyRole::CompanyAdmin->value,
                'is_active' => true,
            ]);

            if ($modules->contains(CompanyModule::Scheduling)) {
                $this->provisionSchedulingDefaults($company);
            }

            if ($modules->contains(CompanyModule::WhatsApp) || $modules->contains(CompanyModule::Marketing)) {
                app(WhatsAppAutomationService::class)->ensureDefaults($company);
            }

            if ($profile === CompanyProfile::DentalClinic) {
                $this->dentalClinicSettingService->getOrCreate($company);
            }

            return [
                'company' => $company->refresh(),
                'user' => $user,
            ];
        });
    }

    public function provisionSchedulingDefaults(Company $company): void
    {
        $this->schedulingSettingService->getOrCreate($company);

        $this->businessHoursService->replaceWeeklyHours($company, [
            ['weekday' => Weekday::Monday->value, 'start_time' => '08:00:00', 'end_time' => '18:00:00'],
            ['weekday' => Weekday::Tuesday->value, 'start_time' => '08:00:00', 'end_time' => '18:00:00'],
            ['weekday' => Weekday::Wednesday->value, 'start_time' => '08:00:00', 'end_time' => '18:00:00'],
            ['weekday' => Weekday::Thursday->value, 'start_time' => '08:00:00', 'end_time' => '18:00:00'],
            ['weekday' => Weekday::Friday->value, 'start_time' => '08:00:00', 'end_time' => '18:00:00'],
        ]);
    }
}
