<?php

namespace App\Enums;

enum CompanyPermission: string
{
    case ManagePatients = 'manage_patients';
    case ManageAppointments = 'manage_appointments';
    case ViewClinicalAlerts = 'view_clinical_alerts';
    case ViewClinicalRecords = 'view_clinical_records';
    case WriteClinicalRecords = 'write_clinical_records';
    case FinalizeClinicalRecords = 'finalize_clinical_records';
    case AddClinicalAddenda = 'add_clinical_addenda';
    case ManageTreatmentPlans = 'manage_treatment_plans';
    case ViewTreatmentPrices = 'view_treatment_prices';
    case ViewFinancial = 'view_financial';
    case ManagePermissions = 'manage_permissions';

    /** @return list<self> */
    public static function defaultsForRole(CompanyRole $role): array
    {
        return match ($role) {
            CompanyRole::CompanyAdmin => [
                self::ManagePatients,
                self::ManageAppointments,
                self::ViewClinicalAlerts,
                self::ViewClinicalRecords,
                self::WriteClinicalRecords,
                self::FinalizeClinicalRecords,
                self::AddClinicalAddenda,
                self::ManageTreatmentPlans,
                self::ViewTreatmentPrices,
                self::ViewFinancial,
                self::ManagePermissions,
            ],
            CompanyRole::Manager => [
                self::ManagePatients,
                self::ManageAppointments,
                self::ManageTreatmentPlans,
                self::ViewTreatmentPrices,
                self::ViewFinancial,
            ],
            CompanyRole::Receptionist => [
                self::ManagePatients,
                self::ManageAppointments,
                self::ViewTreatmentPrices,
            ],
            CompanyRole::Dentist => [
                self::ManagePatients,
                self::ManageAppointments,
                self::ViewClinicalAlerts,
                self::ViewClinicalRecords,
                self::WriteClinicalRecords,
                self::FinalizeClinicalRecords,
                self::AddClinicalAddenda,
                self::ManageTreatmentPlans,
            ],
            CompanyRole::Employee => [],
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return [
            self::ManagePatients->value => 'Gerenciar pacientes',
            self::ManageAppointments->value => 'Gerenciar agendamentos',
            self::ViewClinicalAlerts->value => 'Ver alertas clínicos',
            self::ViewClinicalRecords->value => 'Ver prontuários',
            self::WriteClinicalRecords->value => 'Criar registros clínicos',
            self::FinalizeClinicalRecords->value => 'Finalizar registros clínicos',
            self::AddClinicalAddenda->value => 'Adicionar adendos',
            self::ManageTreatmentPlans->value => 'Gerenciar planos de tratamento',
            self::ViewTreatmentPrices->value => 'Ver valores de tratamentos',
            self::ViewFinancial->value => 'Ver financeiro',
            self::ManagePermissions->value => 'Gerenciar permissões',
        ];
    }
}
