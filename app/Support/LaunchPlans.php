<?php

namespace App\Support;

use App\Models\Company;

/**
 * Planos de lançamento com preço mensal fixo, gravado na empresa no cadastro
 * (subscription_plan + subscription_price_cents).
 */
final class LaunchPlans
{
    /** @return array<string, array{name: string, adjustment: string}> */
    private static function definitions(): array
    {
        return [
            TattooLaunchPlan::KEY => ['name' => 'TattooFlow', 'adjustment' => 'tattoflow_launch_adjustment'],
            BellanaLaunchPlan::KEY => ['name' => 'Bellana', 'adjustment' => 'bellana_launch_adjustment'],
        ];
    }

    public static function isLaunchPlan(?string $key): bool
    {
        return $key !== null && array_key_exists($key, self::definitions());
    }

    public static function name(?string $key): ?string
    {
        return self::isLaunchPlan($key) ? self::definitions()[$key]['name'] : null;
    }

    /** O plano só vale para o perfil de empresa para o qual foi vendido. */
    public static function appliesTo(Company $company): bool
    {
        return match ($company->subscription_plan) {
            TattooLaunchPlan::KEY => $company->isTattooStudio(),
            BellanaLaunchPlan::KEY => $company->isSalon(),
            default => false,
        };
    }

    public static function adjustmentModule(string $key): string
    {
        return self::definitions()[$key]['adjustment'];
    }
}
