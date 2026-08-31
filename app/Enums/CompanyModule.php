<?php

namespace App\Enums;

enum CompanyModule: string
{
    case Scheduling = 'scheduling';
    case ClinicalRecords = 'clinical_records';
    case Stock = 'stock';
    case Finance = 'finance';
    case Sales = 'sales';
    case WhatsApp = 'whatsapp';
    case Marketing = 'marketing';

    public function label(): string
    {
        return match ($this) {
            self::Scheduling => 'Agenda',
            self::ClinicalRecords => 'Prontuário clínico',
            self::Stock => 'Estoque',
            self::Finance => 'Financeiro',
            self::Sales => 'Vendas/PDV',
            self::WhatsApp => 'WhatsApp operacional',
            self::Marketing => 'Marketing',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Scheduling => 'Agenda, agendamentos, bloqueios, atendimentos e configurações da agenda.',
            self::ClinicalRecords => 'Pacientes, anamneses, alertas, evoluções, planos de tratamento, documentos e odontograma.',
            self::Stock => 'Produtos, fornecedores, compras, ajustes e movimentações de estoque.',
            self::Finance => 'Contas a pagar/receber, caixa, transferências, relatórios e configurações financeiras.',
            self::Sales => 'Venda rápida, PDV, carrinho de produtos e checkout.',
            self::WhatsApp => 'Conexão do WhatsApp e mensagens operacionais de agendamento.',
            self::Marketing => 'Campanhas, automações de reconquista, listas e comunicação promocional com clientes.',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $module) => [$module->value => $module->label()])
            ->all();
    }

    /**
     * @return list<self>
     */
    public static function trialDefaults(): array
    {
        return [self::Scheduling];
    }
}
