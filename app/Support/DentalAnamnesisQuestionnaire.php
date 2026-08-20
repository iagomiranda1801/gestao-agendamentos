<?php

namespace App\Support;

class DentalAnamnesisQuestionnaire
{
    /** @return list<array{key: string, label: string, kind: string, alert_type?: string, severity?: string}> */
    public static function questions(): array
    {
        return [
            ['key' => 'chief_complaint', 'label' => 'Queixa principal e motivo da consulta', 'kind' => 'text'],
            ['key' => 'medical_treatment', 'label' => 'Está em tratamento médico?', 'kind' => 'boolean_details', 'alert_type' => 'systemic_condition'],
            ['key' => 'medications', 'label' => 'Usa medicamentos?', 'kind' => 'boolean_details', 'alert_type' => 'medication'],
            ['key' => 'allergies', 'label' => 'Possui alergias?', 'kind' => 'boolean_details', 'alert_type' => 'allergy', 'severity' => 'critical'],
            ['key' => 'surgeries_hospitalizations', 'label' => 'Cirurgias ou internações anteriores?', 'kind' => 'boolean_details'],
            ['key' => 'pregnancy_breastfeeding', 'label' => 'Gestação ou amamentação?', 'kind' => 'boolean_details', 'alert_type' => 'pregnancy'],
            ['key' => 'smoking_alcohol', 'label' => 'Tabagismo ou consumo de álcool?', 'kind' => 'boolean_details'],
            ['key' => 'bleeding', 'label' => 'Histórico de sangramento?', 'kind' => 'boolean_details', 'alert_type' => 'special_care'],
            ['key' => 'anticoagulants', 'label' => 'Usa anticoagulantes?', 'kind' => 'boolean_details', 'alert_type' => 'medication', 'severity' => 'critical'],
            ['key' => 'diabetes', 'label' => 'Possui diabetes?', 'kind' => 'boolean_details', 'alert_type' => 'systemic_condition'],
            ['key' => 'hypertension', 'label' => 'Possui hipertensão?', 'kind' => 'boolean_details', 'alert_type' => 'systemic_condition'],
            ['key' => 'heart_condition', 'label' => 'Possui problema cardíaco?', 'kind' => 'boolean_details', 'alert_type' => 'systemic_condition', 'severity' => 'critical'],
            ['key' => 'infectious_disease', 'label' => 'Possui condição infectocontagiosa relevante?', 'kind' => 'boolean_details', 'alert_type' => 'special_care'],
            ['key' => 'anesthetic_reaction', 'label' => 'Já teve reação a anestésico?', 'kind' => 'boolean_details', 'alert_type' => 'allergy', 'severity' => 'critical'],
            ['key' => 'oral_hygiene', 'label' => 'Hábitos de higiene oral', 'kind' => 'text'],
            ['key' => 'bruxism', 'label' => 'Bruxismo ou apertamento?', 'kind' => 'boolean_details'],
            ['key' => 'patient_notes', 'label' => 'Observações do paciente', 'kind' => 'text'],
            ['key' => 'professional_notes', 'label' => 'Observações do profissional', 'kind' => 'text'],
        ];
    }
}
