<?php

namespace App\Enums;

enum CompanyModule: string
{
    case Scheduling = 'scheduling';
    case Stock = 'stock';
    case Finance = 'finance';

    public function label(): string
    {
        return match ($this) {
            self::Scheduling => 'Agenda',
            self::Stock => 'Estoque',
            self::Finance => 'Financeiro',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Scheduling => 'Agenda, agendamentos, bloqueios, atendimentos e configurações da agenda.',
            self::Stock => 'Produtos, fornecedores, compras, ajustes e movimentações de estoque.',
            self::Finance => 'Contas a pagar/receber, caixa, transferências, relatórios e configurações financeiras.',
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
