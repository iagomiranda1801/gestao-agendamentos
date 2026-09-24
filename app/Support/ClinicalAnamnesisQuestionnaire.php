<?php

namespace App\Support;

use App\Enums\ClinicalSpecialty;
use App\Models\Company;
use App\Models\Professional;
use App\Models\User;

class ClinicalAnamnesisQuestionnaire
{
    /**
     * @return list<array{key: string, label: string, kind: string, alert_type?: string, severity?: string}>
     */
    public static function questions(?ClinicalSpecialty $specialty): array
    {
        return match ($specialty) {
            ClinicalSpecialty::Psychology => self::psychology(),
            ClinicalSpecialty::Psychiatry => self::psychiatry(),
            ClinicalSpecialty::Nutrition => self::nutrition(),
            ClinicalSpecialty::Dentistry => DentalAnamnesisQuestionnaire::questions(),
            ClinicalSpecialty::Medicine, null => self::medicine(),
        };
    }

    public static function resolve(Company $company, ?User $user = null): ClinicalSpecialty
    {
        if ($user !== null) {
            $professional = Professional::query()
                ->where('company_id', $company->getKey())
                ->where('user_id', $user->getKey())
                ->where('is_active', true)
                ->first();

            if ($professional?->clinical_specialty instanceof ClinicalSpecialty) {
                return $professional->clinical_specialty;
            }
        }

        if ($company->isDentalClinic()) {
            return ClinicalSpecialty::Dentistry;
        }

        if ($company->isPsychiatrist()) {
            return ClinicalSpecialty::Psychiatry;
        }

        return ClinicalSpecialty::Medicine;
    }

    /**
     * @return list<array{key: string, label: string, kind: string, alert_type?: string, severity?: string}>
     */
    protected static function medicine(): array
    {
        return [
            ['key' => 'chief_complaint', 'label' => 'Queixa principal e motivo da consulta', 'kind' => 'text'],
            ['key' => 'history_of_present_illness', 'label' => 'História da doença atual', 'kind' => 'text'],
            ['key' => 'medical_treatment', 'label' => 'Está em tratamento médico?', 'kind' => 'boolean_details', 'alert_type' => 'systemic_condition'],
            ['key' => 'medications', 'label' => 'Usa medicamentos?', 'kind' => 'boolean_details', 'alert_type' => 'medication'],
            ['key' => 'allergies', 'label' => 'Possui alergias?', 'kind' => 'boolean_details', 'alert_type' => 'allergy', 'severity' => 'critical'],
            ['key' => 'surgeries_hospitalizations', 'label' => 'Cirurgias ou internações anteriores?', 'kind' => 'boolean_details'],
            ['key' => 'family_history', 'label' => 'Antecedentes familiares relevantes?', 'kind' => 'boolean_details'],
            ['key' => 'pregnancy_breastfeeding', 'label' => 'Gestação ou amamentação?', 'kind' => 'boolean_details', 'alert_type' => 'pregnancy'],
            ['key' => 'smoking_alcohol', 'label' => 'Tabagismo ou consumo de álcool?', 'kind' => 'boolean_details'],
            ['key' => 'diabetes', 'label' => 'Possui diabetes?', 'kind' => 'boolean_details', 'alert_type' => 'systemic_condition'],
            ['key' => 'hypertension', 'label' => 'Possui hipertensão?', 'kind' => 'boolean_details', 'alert_type' => 'systemic_condition'],
            ['key' => 'heart_condition', 'label' => 'Possui problema cardíaco?', 'kind' => 'boolean_details', 'alert_type' => 'systemic_condition', 'severity' => 'critical'],
            ['key' => 'patient_notes', 'label' => 'Observações do paciente', 'kind' => 'text'],
            ['key' => 'professional_notes', 'label' => 'Observações do profissional', 'kind' => 'text'],
        ];
    }

    /**
     * @return list<array{key: string, label: string, kind: string, alert_type?: string, severity?: string}>
     */
    protected static function psychiatry(): array
    {
        return [
            ['key' => 'chief_complaint', 'label' => 'Queixa principal e motivo da consulta', 'kind' => 'text'],
            ['key' => 'psychiatric_history', 'label' => 'História psiquiátrica', 'kind' => 'text'],
            ['key' => 'medications', 'label' => 'Usa medicamentos (incluindo psicotrópicos)?', 'kind' => 'boolean_details', 'alert_type' => 'medication'],
            ['key' => 'sleep', 'label' => 'Alterações de sono?', 'kind' => 'boolean_details'],
            ['key' => 'mood', 'label' => 'Alterações de humor?', 'kind' => 'boolean_details'],
            ['key' => 'risk_self_harm', 'label' => 'Ideação suicida ou risco imediato?', 'kind' => 'boolean_details', 'alert_type' => 'special_care', 'severity' => 'critical'],
            ['key' => 'substance_use', 'label' => 'Uso de álcool ou outras substâncias?', 'kind' => 'boolean_details'],
            ['key' => 'patient_notes', 'label' => 'Observações do paciente', 'kind' => 'text'],
            ['key' => 'professional_notes', 'label' => 'Observações do profissional', 'kind' => 'text'],
        ];
    }

    /**
     * @return list<array{key: string, label: string, kind: string, alert_type?: string, severity?: string}>
     */
    protected static function psychology(): array
    {
        return [
            ['key' => 'chief_complaint', 'label' => 'Motivo da busca e queixa principal', 'kind' => 'text'],
            ['key' => 'session_context', 'label' => 'Contexto atual (trabalho, família, rotina)', 'kind' => 'text'],
            ['key' => 'previous_therapy', 'label' => 'Já fez acompanhamento psicológico ou psiquiátrico?', 'kind' => 'boolean_details'],
            ['key' => 'psychiatric_treatment', 'label' => 'Está em tratamento psiquiátrico?', 'kind' => 'boolean_details', 'alert_type' => 'systemic_condition'],
            ['key' => 'medications', 'label' => 'Usa medicamentos (incluindo psicotrópicos)?', 'kind' => 'boolean_details', 'alert_type' => 'medication'],
            ['key' => 'allergies', 'label' => 'Possui alergias?', 'kind' => 'boolean_details', 'alert_type' => 'allergy', 'severity' => 'critical'],
            ['key' => 'sleep_appetite', 'label' => 'Alterações de sono ou apetite?', 'kind' => 'boolean_details'],
            ['key' => 'risk_self_harm', 'label' => 'Ideação de autoagressão ou risco imediato?', 'kind' => 'boolean_details', 'alert_type' => 'special_care', 'severity' => 'critical'],
            ['key' => 'substance_use', 'label' => 'Uso de álcool ou outras substâncias?', 'kind' => 'boolean_details'],
            ['key' => 'support_network', 'label' => 'Rede de apoio', 'kind' => 'text'],
            ['key' => 'patient_notes', 'label' => 'Observações do paciente', 'kind' => 'text'],
            ['key' => 'professional_notes', 'label' => 'Observações do profissional', 'kind' => 'text'],
        ];
    }

    /**
     * @return list<array{key: string, label: string, kind: string, alert_type?: string, severity?: string}>
     */
    protected static function nutrition(): array
    {
        return [
            ['key' => 'chief_complaint', 'label' => 'Motivo da consulta nutricional', 'kind' => 'text'],
            ['key' => 'food_routine', 'label' => 'Rotina alimentar atual', 'kind' => 'text'],
            ['key' => 'restrictions', 'label' => 'Restrições, aversões ou dietas em andamento?', 'kind' => 'boolean_details'],
            ['key' => 'allergies', 'label' => 'Alergias ou intolerâncias alimentares?', 'kind' => 'boolean_details', 'alert_type' => 'allergy', 'severity' => 'critical'],
            ['key' => 'medications', 'label' => 'Usa medicamentos ou suplementos?', 'kind' => 'boolean_details', 'alert_type' => 'medication'],
            ['key' => 'medical_treatment', 'label' => 'Está em tratamento médico?', 'kind' => 'boolean_details', 'alert_type' => 'systemic_condition'],
            ['key' => 'diabetes', 'label' => 'Possui diabetes?', 'kind' => 'boolean_details', 'alert_type' => 'systemic_condition'],
            ['key' => 'hypertension', 'label' => 'Possui hipertensão?', 'kind' => 'boolean_details', 'alert_type' => 'systemic_condition'],
            ['key' => 'gastrointestinal', 'label' => 'Sintomas gastrointestinais relevantes?', 'kind' => 'boolean_details', 'alert_type' => 'special_care'],
            ['key' => 'pregnancy_breastfeeding', 'label' => 'Gestação ou amamentação?', 'kind' => 'boolean_details', 'alert_type' => 'pregnancy'],
            ['key' => 'physical_activity', 'label' => 'Atividade física', 'kind' => 'text'],
            ['key' => 'goals', 'label' => 'Objetivos do acompanhamento', 'kind' => 'text'],
            ['key' => 'professional_notes', 'label' => 'Observações do profissional', 'kind' => 'text'],
        ];
    }
}
