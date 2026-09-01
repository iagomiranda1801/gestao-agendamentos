<?php

namespace App\Filament\App\Pages;

use App\DataTransferObjects\Financial\IncomeExpenseReport;
use App\Enums\CompanyModule;
use App\Enums\FinancialDashboardPeriod;
use App\Filament\App\Concerns\RequiresCompanyModule;
use App\Filament\App\Pages\Concerns\HasFinancialPeriodFilters;
use App\Models\Company;
use App\Policies\FinancialReportPolicy;
use App\Services\Financial\FinancialDashboardAggregator;
use App\Services\Financial\IncomeExpenseReportAggregator;
use App\Services\Financial\IncomeExpenseReportExporter;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Symfony\Component\HttpFoundation\Response;
use UnitEnum;

class IncomeExpenseReportPage extends Page
{
    use HasFiltersForm;
    use HasFinancialPeriodFilters;
    use RequiresCompanyModule;

    protected static ?string $slug = 'receitas-e-gastos';

    protected static ?string $navigationLabel = 'Receitas e gastos';

    protected static ?string $title = 'Receitas e gastos';

    protected static string|UnitEnum|null $navigationGroup = 'Relatórios';

    protected static ?int $navigationSort = 3;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected string $view = 'filament.app.pages.income-expense-report-page';

    public static function canAccess(): bool
    {
        if (! static::tenantHasRequiredModule()) {
            return false;
        }

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
     * @return array{
     *     incomeTotal: string,
     *     expenseTotal: string,
     *     balance: string,
     *     periodStartLabel: string,
     *     periodEndLabel: string,
     *     rows: list<array{
     *         occurredAtLocal: string,
     *         typeLabel: string,
     *         description: string,
     *         accountName: string,
     *         directionLabel: string,
     *         amount: string,
     *         isInbound: bool
     *     }>
     * }
     */
    public function getReportProperty(): array
    {
        $report = $this->currentReport();

        return [
            'incomeTotal' => $report->incomeTotal,
            'expenseTotal' => $report->expenseTotal,
            'balance' => $report->balance,
            'periodStartLabel' => $report->periodStartLabel,
            'periodEndLabel' => $report->periodEndLabel,
            'rows' => $report->rows->map(fn ($row): array => [
                'occurredAtLocal' => $row->occurredAtLocal,
                'typeLabel' => $row->typeLabel,
                'description' => $row->description,
                'accountName' => $row->accountName,
                'directionLabel' => $row->directionLabel,
                'amount' => $row->amount,
                'isInbound' => $row->isInbound,
            ])->all(),
        ];
    }

    public function exportExcel(): Response
    {
        abort_unless(static::canAccess(), 403);

        [$company, $start, $end] = $this->resolvedPeriod();

        return app(IncomeExpenseReportExporter::class)->excel($company, $start, $end);
    }

    public function exportPdf(): Response
    {
        abort_unless(static::canAccess(), 403);

        [$company, $start, $end] = $this->resolvedPeriod();

        return app(IncomeExpenseReportExporter::class)->pdf($company, $start, $end);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportExcel')
                ->label('Exportar Excel')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->action(fn (): Response => $this->exportExcel()),
            Action::make('exportPdf')
                ->label('Exportar PDF')
                ->icon(Heroicon::OutlinedDocumentArrowDown)
                ->color('gray')
                ->action(fn (): Response => $this->exportPdf()),
        ];
    }

    protected function currentReport(): IncomeExpenseReport
    {
        [$company, $start, $end] = $this->resolvedPeriod();

        return app(IncomeExpenseReportAggregator::class)->aggregate($company, $start, $end);
    }

    /**
     * @return array{0: Company, 1: CarbonImmutable, 2: CarbonImmutable}
     */
    protected function resolvedPeriod(): array
    {
        /** @var Company $company */
        $company = Filament::getTenant();

        $period = FinancialDashboardPeriod::tryFrom((string) ($this->filters['period'] ?? FinancialDashboardPeriod::Month->value))
            ?? FinancialDashboardPeriod::Month;

        [$start, $end] = app(FinancialDashboardAggregator::class)->resolvePeriodBounds(
            $company,
            $period,
            $this->filters['startDate'] ?? null,
            $this->filters['endDate'] ?? null,
        );

        return [$company, $start, $end];
    }

    protected static function requiredCompanyModule(): CompanyModule
    {
        return CompanyModule::Finance;
    }
}
