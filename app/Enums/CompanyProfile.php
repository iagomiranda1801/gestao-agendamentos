<?php

namespace App\Enums;

enum CompanyProfile: string
{
    case Professional = 'professional';
    case Clinic = 'clinic';
    case DentalClinic = 'dental_clinic';
    case Salon = 'salon';
    case Store = 'store';
    case ServicesAndProducts = 'services_products';
    case CarWash = 'car_wash';
    case Restaurant = 'restaurant';
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::Professional => 'Profissional autônomo',
            self::Clinic => 'Clínica ou empresa de atendimentos',
            self::DentalClinic => 'Clínica odontológica',
            self::Salon => 'Salão, estética ou bem-estar',
            self::Store => 'Loja de produtos',
            self::ServicesAndProducts => 'Serviços e produtos',
            self::CarWash => 'Lava jato ou estética automotiva',
            self::Restaurant => 'Restaurante ou food service',
            self::Custom => 'Configuração personalizada',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Professional => 'Para psicólogos, médicos, nutricionistas, terapeutas e profissionais que trabalham com horário marcado. O prontuário clínico pode ser ligado à parte.',
            self::Clinic => 'Para operações com equipe, pacientes, agenda, financeiro e, se quiser, prontuário clínico compartilhado.',
            self::DentalClinic => 'Para clínicas com recepção, dentistas, pacientes, prontuário, agenda e gestão financeira.',
            self::Salon => 'Para negócios de beleza, estética, saúde e bem-estar que também controlam vendas ou consumo.',
            self::Store => 'Para empresas focadas em produtos, estoque, vendas e financeiro.',
            self::ServicesAndProducts => 'Para quem agenda serviços e também vende produtos.',
            self::CarWash => 'Para lava jatos e estética automotiva: pacotes de lavagem, agenda, PDV e retorno do cliente no WhatsApp.',
            self::Restaurant => 'Para restaurantes, lanchonetes e food service: cardápio online, pedidos para retirada ou entrega e tela da cozinha.',
            self::Custom => 'Escolha manualmente os recursos que a empresa utilizará.',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $profile) => [$profile->value => $profile->label()])->all();
    }

    /** @return list<CompanyModule> */
    public function defaultModules(): array
    {
        return match ($this) {
            self::Professional => [CompanyModule::Scheduling, CompanyModule::WhatsApp],
            self::Clinic => [CompanyModule::Scheduling, CompanyModule::Finance, CompanyModule::WhatsApp],
            self::DentalClinic => [CompanyModule::Scheduling, CompanyModule::ClinicalRecords, CompanyModule::Finance, CompanyModule::WhatsApp],
            self::Salon => [CompanyModule::Scheduling, CompanyModule::Sales, CompanyModule::Stock, CompanyModule::Finance, CompanyModule::WhatsApp],
            self::Store => [CompanyModule::Sales, CompanyModule::Stock, CompanyModule::Finance],
            self::ServicesAndProducts => [CompanyModule::Scheduling, CompanyModule::Sales, CompanyModule::Stock, CompanyModule::Finance, CompanyModule::WhatsApp],
            self::CarWash => [CompanyModule::Scheduling, CompanyModule::Sales, CompanyModule::Stock, CompanyModule::Finance, CompanyModule::WhatsApp, CompanyModule::Marketing],
            self::Restaurant => [CompanyModule::Orders, CompanyModule::WhatsApp],
            self::Custom => [CompanyModule::Scheduling],
        };
    }
}
