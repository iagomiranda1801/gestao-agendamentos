<?php

namespace App\Filament\App\Pages;

use App\Enums\FinancialDashboardPeriod;
use App\Filament\App\Pages\Concerns\HasFinancialPeriodFilters;
use App\Models\Company;
use App\Policies\FinancialReportPolicy;
use App\Services\Financial\FinancialDashboardAggregator;
use App\Services\Financial\FinancialOverviewAggregator;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use UnitEnum;

class ExpenseByCategoryReportPage extends Page
{
    use HasFiltersForm;
    use HasFinancialPeriodFilters;

    protected static ?string $slug = 'despesas-por-categoria';

    protected static ?string $navigationLabel = 'Despesas por categoria';

    protected static ?string $title = 'Despesas por categoria';

    protected static string|UnitEnum|null $navigationGroup = 'Relatórios';

    protected static ?int $navigationSort = 2;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartPie;

    protected string $view = 'filament.app.pages.expense-by-category-report-page';

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

    public function getExpenseRowsProperty(): Collection
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

        return app(FinancialOverviewAggregator::class)->expensesByCategory($company, $start, $end);
    }
}
