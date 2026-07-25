<?php

namespace App\Filament\App\Widgets;

use App\Enums\FinancialDashboardPeriod;
use App\Filament\App\Pages\FinancialDashboard;
use App\Models\Company;
use App\Services\Financial\FinancialDashboardAggregator;
use Filament\Facades\Filament;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class FinancialStatsWidget extends StatsOverviewWidget
{
    use InteractsWithPageFilters;

    protected function getStats(): array
    {
        /** @var Company $company */
        $company = Filament::getTenant();

        $period = FinancialDashboardPeriod::tryFrom((string) ($this->pageFilters['period'] ?? FinancialDashboardPeriod::Month->value))
            ?? FinancialDashboardPeriod::Month;

        $aggregator = app(FinancialDashboardAggregator::class);

        [$start, $end] = $aggregator->resolvePeriodBounds(
            $company,
            $period,
            $this->pageFilters['startDate'] ?? null,
            $this->pageFilters['endDate'] ?? null,
        );

        $summary = $aggregator->aggregate($company, $start, $end);

        return [
            Stat::make('Receita concluída', $this->formatMoney($summary->completedRevenue))
                ->description('Atendimentos finalizados no período'),
            Stat::make('Recebido', $this->formatMoney($summary->received))
                ->description('Pagamentos registrados')
                ->color('success'),
            Stat::make('Em aberto', $this->formatMoney($summary->outstanding))
                ->description('Saldo pendente')
                ->color('warning'),
            Stat::make('Custo de materiais', $this->formatMoney($summary->materialCost))
                ->description('Consumo real registrado'),
            Stat::make('Comissões', $this->formatMoney($summary->commissions))
                ->description('Total de comissões'),
            Stat::make('Reserva de materiais', $this->formatMoney($summary->materialReserve))
                ->description('Provisionamento para materiais'),
            Stat::make('Reserva do negócio', $this->formatMoney($summary->businessReserve))
                ->description('Provisionamento operacional'),
            Stat::make('Parcela do proprietário', $this->formatMoney($summary->ownerAllocation))
                ->description('Alocação do proprietário'),
            Stat::make('Taxas de pagamento', $this->formatMoney($summary->paymentFees))
                ->description('Taxas de meios de pagamento'),
            Stat::make('Resultado operacional', $this->formatMoney($summary->operationalResult))
                ->description('Resultado após custos e taxas')
                ->color('primary'),
        ];
    }

    public static function canView(): bool
    {
        return FinancialDashboard::canAccess();
    }

    protected function formatMoney(string $amount): string
    {
        return 'R$ '.number_format((float) $amount, 2, ',', '.');
    }
}
