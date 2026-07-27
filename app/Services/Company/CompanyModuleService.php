<?php

namespace App\Services\Company;

use App\Enums\CompanyModule;
use App\Enums\SubscriptionStatus;
use App\Models\Company;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Date;
use Illuminate\Validation\ValidationException;

class CompanyModuleService
{
    /**
     * @return list<CompanyModule>
     */
    public function enabledModules(Company $company): array
    {
        $raw = $company->enabled_modules;

        if (! is_array($raw) || $raw === []) {
            return CompanyModule::cases();
        }

        return collect($raw)
            ->map(fn (mixed $value): ?CompanyModule => CompanyModule::tryFrom((string) $value))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    public function hasModule(Company $company, CompanyModule $module): bool
    {
        return in_array($module, $this->enabledModules($company), true);
    }

    /**
     * @param  list<CompanyModule|string>  $modules
     */
    public function syncModules(Company $company, array $modules): void
    {
        $normalized = collect($modules)
            ->map(function (mixed $module): ?CompanyModule {
                if ($module instanceof CompanyModule) {
                    return $module;
                }

                return CompanyModule::tryFrom((string) $module);
            })
            ->filter()
            ->unique()
            ->values();

        if ($normalized->isEmpty()) {
            throw ValidationException::withMessages([
                'enabled_modules' => 'Selecione ao menos um módulo.',
            ]);
        }

        $company->forceFill([
            'enabled_modules' => $normalized
                ->map(fn (CompanyModule $module) => $module->value)
                ->all(),
        ])->save();
    }

    public function isTrialActive(Company $company, ?CarbonInterface $now = null): bool
    {
        if ($company->subscription_status !== SubscriptionStatus::Trial) {
            return false;
        }

        if ($company->trial_ends_at === null) {
            return false;
        }

        return $company->trial_ends_at->isFuture();
    }

    public function isAccessAllowed(Company $company, ?CarbonInterface $now = null): bool
    {
        if (! $company->is_active) {
            return false;
        }

        if ($company->subscription_status === SubscriptionStatus::Active) {
            return true;
        }

        if ($company->subscription_status === SubscriptionStatus::Trial) {
            return $this->isTrialActive($company, $now);
        }

        return false;
    }

    public function trialDaysRemaining(Company $company, ?CarbonInterface $now = null): ?int
    {
        if ($company->subscription_status !== SubscriptionStatus::Trial || $company->trial_ends_at === null) {
            return null;
        }

        $now ??= Date::now();

        if ($company->trial_ends_at->isPast()) {
            return 0;
        }

        return max(0, (int) $now->copy()->startOfDay()->diffInDays(
            $company->trial_ends_at->copy()->startOfDay(),
            false,
        ));
    }

    public function shouldShowTrialBanner(Company $company, ?CarbonInterface $now = null): bool
    {
        $days = $this->trialDaysRemaining($company, $now);

        return $days !== null && $days <= 3;
    }

    /**
     * @param  list<CompanyModule|string>  $modules
     */
    public function applyTrialDefaults(Company $company, array $modules): void
    {
        $company->forceFill([
            'enabled_modules' => collect($modules)
                ->map(fn (mixed $module) => $module instanceof CompanyModule ? $module->value : (string) $module)
                ->unique()
                ->values()
                ->all(),
            'subscription_status' => SubscriptionStatus::Trial,
            'trial_ends_at' => Date::now()->addDays(7),
        ]);
    }
}
