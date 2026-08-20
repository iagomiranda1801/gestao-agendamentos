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
        $word = $company instanceof Company && $company->isDentalClinic()
            ? ($plural ? 'dentistas' : 'dentista')
            : ($plural ? 'profissionais' : 'profissional');

        return $capitalized ? ucfirst($word) : $word;
    }
}
