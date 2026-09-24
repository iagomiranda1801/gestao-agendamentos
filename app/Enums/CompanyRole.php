<?php

namespace App\Enums;

use App\Models\Company;

enum CompanyRole: string
{
    case CompanyAdmin = 'company_admin';
    case Manager = 'manager';
    case Employee = 'employee';
    case Receptionist = 'receptionist';
    case Dentist = 'dentist';

    public function label(?Company $company = null): string
    {
        return match ($this) {
            self::CompanyAdmin => 'Administrador',
            self::Manager => 'Gerente',
            self::Employee => 'Colaborador',
            self::Receptionist => 'Recepção / Secretária',
            self::Dentist => match (true) {
                $company instanceof Company && $company->isPsychiatrist() => 'Psiquiatra',
                $company instanceof Company && ! $company->isDentalClinic() => 'Profissional clínico',
                default => 'Dentista',
            },
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(?Company $company = null): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $role) => [$role->value => $role->label($company)])
            ->all();
    }
}
