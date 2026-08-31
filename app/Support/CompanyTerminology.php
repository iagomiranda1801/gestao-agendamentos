<?php

namespace App\Support;

use App\Models\Company;
use Filament\Facades\Filament;

class CompanyTerminology
{
    public static function client(?Company $company = null, bool $plural = false, bool $capitalized = true): string
    {
        $company ??= Filament::getTenant();
        $word = $company instanceof Company && $company->isDentalClinic()
            ? ($plural ? 'pacientes' : 'paciente')
            : ($plural ? 'clientes' : 'cliente');

        return $capitalized ? ucfirst($word) : $word;
    }

    public static function professional(?Company $company = null, bool $plural = false, bool $capitalized = true): string
    {
        $company ??= Filament::getTenant();
        $word = match (true) {
            $company instanceof Company && $company->isDentalClinic() => $plural ? 'dentistas' : 'dentista',
            $company instanceof Company && $company->isCarWash() => $plural ? 'lavadores' : 'lavador',
            default => $plural ? 'profissionais' : 'profissional',
        };

        return $capitalized ? ucfirst($word) : $word;
    }

    public static function service(?Company $company = null, bool $plural = false, bool $capitalized = true): string
    {
        $company ??= Filament::getTenant();
        $word = $company instanceof Company && $company->isCarWash()
            ? ($plural ? 'tipos de lavagem' : 'tipo de lavagem')
            : ($plural ? 'serviços' : 'serviço');

        return $capitalized ? ucfirst($word) : $word;
    }
}
