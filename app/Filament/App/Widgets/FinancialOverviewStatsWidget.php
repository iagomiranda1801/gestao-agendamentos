<?php

namespace App\Filament\App\Widgets;

use App\Enums\FinancialDashboardPeriod;
use App\Filament\App\Pages\FinancialOverviewPage;
use App\Models\Company;
use App\Services\Financial\FinancialDashboardAggregator;
use App\Services\Financial\FinancialOverviewAggregator;
use Filament\Facades\Filament;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class FinancialOverviewStatsWidget extends StatsOverviewWidget
{
    use InteractsWithPageFilters;

    protected function getStats(): array
    {
        /** @var Company $company */
        $company = Filament::getTenant();

        $period = FinancialDashboardPeriod::tryFrom((string) ($this->pageFilters['period'] ?? FinancialDashboardPeriod::Month->value))
            ?? FinancialDashboardPeriod::Month;

        $periodAggregator = app(FinancialDashboardAggregator::class);
        [$start, $end] = $periodAggregator->resolvePeriodBounds(
            $company,
            $period,
            $this->pageFilters['startDate'] ?? null,
            $this->pageFilters['endDate'] ?? null,
        );

        $summary = app(FinancialOverviewAggregator::class)->aggregate($company, $start, $end);

        return [
            Stat::make('Saldo total', $this->formatMoney($summary->totalBalance))
                ->description('Todas as contas ativas'),
            Stat::make('Saldo em caixa', $this->formatMoney($summary->cashBalance))
                ->description('Contas do tipo caixa'),
            Stat::make('Recebido no período', $this->formatMoney($summary->received))
                ->description('Entradas confirmadas')
                ->color('success'),
            Stat::make('Pago no período', $this->formatMoney($summary->paid))
                ->description('Saídas confirmadas')
                ->color('danger'),
            Stat::make('Fluxo líquido', $this->formatMoney($summary->netFlow))
                ->description('Entradas − saídas no período')
                ->color('primary'),
            Stat::make('A receber em aberto', $this->formatMoney($summary->receivablesOutstanding))
                ->description('Contas a receber pendentes')
                ->color('warning'),
            Stat::make('A pagar em aberto', $this->formatMoney($summary->payablesOutstanding))
                ->description('Contas a pagar pendentes')
                ->color('warning'),
            Stat::make('Contas vencidas', $this->formatMoney($summary->payablesOverdue))
                ->description('Parcelas em atraso')
                ->color('danger'),
            Stat::make('Resultado gerencial', $this->formatMoney($summary->managerialResult))
                ->description('Resultado operacional no período'),
            Stat::make('Despesas do mês', $this->formatMoney($summary->monthlyExpenses))
                ->description('Competência do mês atual'),
            Stat::make('Próximos vencimentos', $this->formatMoney($summary->upcomingPayables))
                ->description('Próximos 7 dias'),
        ];
    }

    public static function canView(): bool
    {
        return FinancialOverviewPage::canAccess();
    }

    protected function formatMoney(string $amount): string
    {
        return 'R$ '.number_format((float) $amount, 2, ',', '.');
    }
}
