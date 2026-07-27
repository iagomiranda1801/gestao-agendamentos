<?php

namespace App\Filament\App\Pages;

use App\Enums\CompanyModule;
use App\Filament\App\Concerns\RequiresCompanyModule;
use App\Filament\App\Pages\Concerns\HasFinancialPeriodFilters;
use App\Filament\App\Widgets\FinancialOverviewStatsWidget;
use App\Policies\FinancialReportPolicy;
use BackedEnum;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\Widget;
use Filament\Widgets\WidgetConfiguration;
use UnitEnum;

class FinancialOverviewPage extends BaseDashboard
{
    use HasFiltersForm;
    use HasFinancialPeriodFilters;
    use RequiresCompanyModule;

    protected static string $routePath = '/visao-financeira';

    protected static ?string $slug = 'visao-financeira';

    protected static ?string $navigationLabel = 'Visão financeira';

    protected static ?string $title = 'Visão financeira';

    protected static string|UnitEnum|null $navigationGroup = 'Financeiro';

    protected static ?int $navigationSort = 0;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartLine;

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
     * @return array<class-string<Widget> | WidgetConfiguration>
     */
    public function getWidgets(): array
    {
        return [
            FinancialOverviewStatsWidget::class,
        ];
    }

    /**
     * @return int | array<string, ?int>
     */
    public function getColumns(): int|array
    {
        return [
            'default' => 1,
            'md' => 2,
            'xl' => 3,
        ];
    }

    protected static function requiredCompanyModule(): CompanyModule
    {
        return CompanyModule::Finance;
    }
}
