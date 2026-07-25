<?php

namespace App\Enums;

enum CompanyRole: string
{
    case CompanyAdmin = 'company_admin';
    case Manager = 'manager';
    case Employee = 'employee';

    public function label(): string
    {
        return match ($this) {
            self::CompanyAdmin => 'Administrador',
            self::Manager => 'Gerente',
            self::Employee => 'Colaborador',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $role) => [$role->value => $role->label()])
            ->all();
    }
}
