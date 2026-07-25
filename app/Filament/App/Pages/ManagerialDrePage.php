<?php

namespace App\Filament\App\Pages;

use App\Enums\FinancialDashboardPeriod;
use App\Filament\App\Pages\Concerns\HasFinancialPeriodFilters;
use App\Models\Company;
use App\Policies\FinancialReportPolicy;
use App\Services\Financial\FinancialDashboardAggregator;
use App\Services\Financial\ManagerialDreAggregator;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class ManagerialDrePage extends Page
{
    use HasFiltersForm;
    use HasFinancialPeriodFilters;

    protected static ?string $slug = 'resultado-gerencial';

    protected static ?string $navigationLabel = 'Resultado gerencial';

    protected static ?string $title = 'Resultado gerencial';

    protected static string|UnitEnum|null $navigationGroup = 'Relatórios';

    protected static ?int $navigationSort = 1;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentChartBar;

    protected string $view = 'filament.app.pages.managerial-dre-page';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && (new FinancialReportPolicy)->viewAny($user);
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        if ($this->filters === null) {
            $this->filters = $this->defaultFinancialFilters();
        }
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $this->financialPeriodFiltersForm($schema);
    }

    /**
     * @return array<string, string>
     */
    public function getDreSummaryProperty(): array
    {
        /** @var Company $company */
        $company = Filament::getTenant();

        $period = FinancialDashboardPeriod::tryFrom((string) ($this->filters['period'] ?? FinancialDashboardPeriod::Month->value))
            ?? FinancialDashboardPeriod::Month;

        $aggregator = app(FinancialDashboardAggregator::class);
        [$start, $end] = $aggregator->resolvePeriodBounds(
            $company,
            $period,
            $this->filters['startDate'] ?? null,
            $this->filters['endDate'] ?? null,
        );

        $summary = app(ManagerialDreAggregator::class)->aggregate($company, $start, $end);

        return [
            'grossRevenue' => $summary->grossRevenue,
            'discounts' => $summary->discounts,
            'netRevenue' => $summary->netRevenue,
            'materialCost' => $summary->materialCost,
            'commissions' => $summary->commissions,
            'paymentFees' => $summary->paymentFees,
            'contributionMargin' => $summary->contributionMargin,
            'operationalExpenses' => $summary->operationalExpenses,
            'operationalResult' => $summary->operationalResult,
            'materialReserve' => $summary->materialReserve,
            'businessReserve' => $summary->businessReserve,
            'ownerAllocation' => $summary->ownerAllocation,
        ];
    }
}
