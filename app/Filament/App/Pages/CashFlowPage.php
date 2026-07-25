<?php

namespace App\Filament\App\Pages;

use App\Enums\FinancialDashboardPeriod;
use App\Filament\App\Pages\Concerns\HasFinancialPeriodFilters;
use App\Models\Company;
use App\Models\FinancialAccount;
use App\Policies\FinancialReportPolicy;
use App\Services\Financial\FinancialCashFlowAggregator;
use App\Services\Financial\FinancialDashboardAggregator;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class CashFlowPage extends Page
{
    use HasFiltersForm;
    use HasFinancialPeriodFilters;

    protected static ?string $slug = 'fluxo-de-caixa';

    protected static ?string $navigationLabel = 'Fluxo de caixa';

    protected static ?string $title = 'Fluxo de caixa';

    protected static string|UnitEnum|null $navigationGroup = 'Financeiro';

    protected static ?int $navigationSort = 15;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowTrendingUp;

    protected string $view = 'filament.app.pages.cash-flow-page';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && (new FinancialReportPolicy)->viewAny($user);
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        if ($this->filters === null) {
            $this->filters = array_merge($this->defaultFinancialFilters(), [
                'financialAccountId' => null,
            ]);
        }
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema
            ->components([
                ...$this->financialPeriodFiltersForm($schema)->getComponents(),
                Select::make('financialAccountId')
                    ->label('Conta')
                    ->options(fn (): array => ['' => 'Consolidado (todas as contas)'] + FinancialAccount::query()
                        ->where('company_id', Filament::getTenant()?->getKey())
                        ->where('is_active', true)
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->default(null)
                    ->native(false),
            ]);
    }

    /**
     * @return array<string, string>
     */
    public function getCashFlowSummaryProperty(): array
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

        $accountId = filled($this->filters['financialAccountId'] ?? null)
            ? [(int) $this->filters['financialAccountId']]
            : null;

        $summary = app(FinancialCashFlowAggregator::class)->aggregate(
            $company,
            $start,
            $end,
            $accountId,
        );

        return [
            'initialBalance' => $summary->initialBalance,
            'inflows' => $summary->inflows,
            'outflows' => $summary->outflows,
            'netFlow' => $summary->netFlow,
            'finalBalance' => $summary->finalBalance,
        ];
    }
}
