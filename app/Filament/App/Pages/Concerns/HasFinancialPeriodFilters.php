<?php

namespace App\Filament\App\Pages\Concerns;

use App\Enums\FinancialDashboardPeriod;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

trait HasFinancialPeriodFilters
{
    public function financialPeriodFiltersForm(Schema $schema): Schema
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
     * @return array{period: string, startDate: string|null, endDate: string|null}
     */
    protected function defaultFinancialFilters(): array
    {
        return [
            'period' => FinancialDashboardPeriod::Month->value,
            'startDate' => null,
            'endDate' => null,
        ];
    }
}
