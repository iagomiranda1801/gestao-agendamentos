<?php

namespace App\Support;

use App\Enums\CompanyModule;

final class BellanaLaunchPlan
{
    public const KEY = 'bellana_launch';

    public static function priceCents(): int
    {
        return max(0, (int) config('subscriptions.bellana_launch_price_cents', 7990));
    }

    /**
     * Os 5 módulos padrão do perfil Salão, mais Marketing, que libera a tela
     * de lembretes, pós-venda e reconquista prometida na landing.
     *
     * @return list<CompanyModule>
     */
    public static function modules(): array
    {
        return [
            CompanyModule::Scheduling,
            CompanyModule::Sales,
            CompanyModule::Stock,
            CompanyModule::Finance,
            CompanyModule::WhatsApp,
            CompanyModule::Marketing,
        ];
    }

    /** @return list<string> */
    public static function moduleValues(): array
    {
        return array_map(fn (CompanyModule $module): string => $module->value, self::modules());
    }
}
