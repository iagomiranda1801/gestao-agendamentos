<?php

namespace App\Filament\App\Pages;

use App\Enums\CompanyModule;
use App\Enums\FinancialDashboardPeriod;
use App\Filament\App\Concerns\RequiresCompanyModule;
use App\Filament\App\Widgets\FinancialStatsWidget;
use App\Policies\ReceivablePolicy;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\Widget;
use Filament\Widgets\WidgetConfiguration;
use UnitEnum;

class FinancialDashboard extends BaseDashboard
{
    use HasFiltersForm;
    use RequiresCompanyModule;

    protected static string $routePath = '/dashboard-financeiro';

    protected static ?string $slug = 'dashboard-financeiro';

    protected static ?string $navigationLabel = 'Dashboard financeiro';

    protected static ?string $title = 'Dashboard financeiro';

    protected static string|UnitEnum|null $navigationGroup = 'Financeiro';

    protected static ?int $navigationSort = 1;

    protected static bool $shouldRegisterNavigation = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    public static function canAccess(): bool
    {
        if (! static::tenantHasRequiredModule()) {
            return false;
        }

        $user = auth()->user();

        return $user !== null && (new ReceivablePolicy)->viewAny($user);
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        if ($this->filters === null) {
            $this->filters = [
                'period' => FinancialDashboardPeriod::Month->value,
                'startDate' => null,
                'endDate' => null,
            ];
        }
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('period')
                    ->label('Período')
                    ->options(FinancialDashboardPeriod::options())
                    ->default(FinancialDashboardPeriod::Month->value)
                    ->required()
                    ->native(false)
                    ->live(),
                DatePicker::make('startDate')
                    ->label('Data inicial')
                    ->visible(fn (Get $get): bool => $get('period') === FinancialDashboardPeriod::Custom->value)
                    ->required(fn (Get $get): bool => $get('period') === FinancialDashboardPeriod::Custom->value),
                DatePicker::make('endDate')
                    ->label('Data final')
                    ->visible(fn (Get $get): bool => $get('period') === FinancialDashboardPeriod::Custom->value)
                    ->required(fn (Get $get): bool => $get('period') === FinancialDashboardPeriod::Custom->value),
            ]);
    }

    /**
     * @return array<class-string<Widget> | WidgetConfiguration>
     */
    public function getWidgets(): array
    {
        return [
            FinancialStatsWidget::class,
        ];
    }

    /**
     * @return int | array<string, ?int>
     */
    public function getColumns(): int|array
    {
        return 1;
    }

    protected static function requiredCompanyModule(): CompanyModule
    {
        return CompanyModule::Finance;
    }
}
