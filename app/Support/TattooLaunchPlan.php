<?php

namespace App\Support;

use App\Enums\CompanyModule;

final class TattooLaunchPlan
{
    public const KEY = 'tattoflow_launch';

    public static function priceCents(): int
    {
        return max(0, (int) config('subscriptions.tattoflow_launch_price_cents', 7990));
    }

    /** @return list<CompanyModule> */
    public static function modules(): array
    {
        return [
            CompanyModule::Scheduling,
            CompanyModule::WhatsApp,
            CompanyModule::Finance,
            CompanyModule::Stock,
            CompanyModule::Sales,
            CompanyModule::Marketing,
        ];
    }

    /** @return list<string> */
    public static function moduleValues(): array
    {
        return array_map(fn (CompanyModule $module): string => $module->value, self::modules());
    }
}
