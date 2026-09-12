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
    case Orders = 'orders';

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
            self::Orders => 'Pedidos',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Scheduling => 'Agenda, agendamentos, bloqueios, atendimentos e configurações da agenda.',
            self::ClinicalRecords => 'Pacientes, anamneses, alertas, evoluções, documentos e, na odontologia, odontograma e planos de tratamento.',
            self::Stock => 'Produtos, fornecedores, compras, ajustes e movimentações de estoque.',
            self::Finance => 'Contas a pagar/receber, caixa, transferências, relatórios e configurações financeiras.',
            self::Sales => 'Venda rápida, PDV, carrinho de produtos e checkout.',
            self::WhatsApp => 'Conexão do WhatsApp e mensagens operacionais de agendamento.',
            self::Marketing => 'Campanhas, automações de reconquista, listas e comunicação promocional com clientes.',
            self::Orders => 'Cardápio online, pedidos para retirada ou entrega e tela da cozinha.',
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
    public static function billingPreset(string $preset): array
    {
        return match ($preset) {
            'essential' => [self::Scheduling, self::WhatsApp],
            'professional' => [self::Scheduling, self::WhatsApp, self::Finance, self::Sales, self::Stock],
            'complete' => self::cases(),
            default => [self::Scheduling],
        };
    }

    /**
     * @return list<self>
     */
    public static function trialDefaults(): array
    {
        return [self::Scheduling];
    }
}
