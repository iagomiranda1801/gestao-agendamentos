<?php

namespace App\Filament\App\Pages;

use App\Enums\CompanyModule;
use App\Enums\ProductType;
use App\Filament\App\Concerns\RequiresCompanyModule;
use App\Filament\App\Widgets\InventoryPositionStatsWidget;
use App\Models\Company;
use App\Models\Product;
use App\Policies\StockDocumentPolicy;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use UnitEnum;

class InventoryPosition extends Page implements HasTable
{
    use InteractsWithTable {
        makeTable as makeBaseTable;
    }
    use RequiresCompanyModule;

    protected static ?string $slug = 'estoque';

    protected static ?string $navigationLabel = 'Posição do estoque';

    protected static ?string $title = 'Posição do estoque';

    protected static string|UnitEnum|null $navigationGroup = 'Estoque';

    protected static ?int $navigationSort = 2;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    #[Url(as: 'reordering')]
    public bool $isTableReordering = false;

    /**
     * @var array<string, mixed>|null
     */
    #[Url(as: 'filters')]
    public ?array $tableFilters = null;

    #[Url(as: 'grouping')]
    public ?string $tableGrouping = null;

    /**
     * @var ?string
     */
    #[Url(as: 'search')]
    public $tableSearch = '';

    #[Url(as: 'sort')]
    public ?string $tableSort = null;

    public static function canAccess(): bool
    {
        if (! static::tenantHasRequiredModule()) {
            return false;
        }

        $user = auth()->user();

        return $user !== null && (new StockDocumentPolicy)->viewAny($user);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Nome')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('type')
                    ->label('Tipo')
                    ->formatStateUsing(fn (ProductType $state): string => $state->label())
                    ->sortable(),
                TextColumn::make('measurementUnit.symbol')
                    ->label('Unidade')
                    ->sortable(),
                TextColumn::make('current_stock')
                    ->label('Quantidade atual')
                    ->state(fn (Product $record): string => $record->getCurrentStockQuantity())
                    ->numeric(decimalPlaces: 4)
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        return $query
                            ->leftJoin('inventory_balances', 'products.id', '=', 'inventory_balances.product_id')
                            ->orderBy('inventory_balances.quantity_on_hand', $direction)
                            ->select('products.*');
                    }),
                TextColumn::make('average_cost')
                    ->label('Custo médio')
                    ->state(fn (Product $record): string => $record->getCurrentAverageUnitCost())
                    ->money('BRL', locale: 'pt_BR', decimalPlaces: 6),
                TextColumn::make('stock_value')
                    ->label('Valor em estoque')
                    ->state(fn (Product $record): string => $record->getCurrentStockValue())
                    ->money('BRL', locale: 'pt_BR', decimalPlaces: 2),
                TextColumn::make('minimum_stock')
                    ->label('Estoque mínimo')
                    ->numeric(decimalPlaces: 4)
                    ->sortable(),
                TextColumn::make('situation')
                    ->label('Situação')
                    ->badge()
                    ->state(fn (Product $record): string => self::resolveSituation($record))
                    ->color(fn (Product $record): string => match (self::resolveSituation($record)) {
                        'Sem controle' => 'gray',
                        'Sem estoque' => 'danger',
                        'Estoque baixo' => 'warning',
                        default => 'success',
                    }),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Status')
                    ->trueLabel('Ativos')
                    ->falseLabel('Inativos')
                    ->placeholder('Todos'),
                Filter::make('consumable')
                    ->label('Material de consumo')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->consumable()),
                Filter::make('asset')
                    ->label('Investimento/ativo')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->asset()),
                Filter::make('low_stock')
                    ->label('Estoque baixo')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => self::applyLowStockFilter($query)),
                Filter::make('out_of_stock')
                    ->label('Sem estoque')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => self::applyOutOfStockFilter($query)),
                TernaryFilter::make('tracks_stock')
                    ->label('Controle de estoque')
                    ->trueLabel('Controlados')
                    ->falseLabel('Sem controle')
                    ->placeholder('Todos'),
            ])
            ->defaultSort('name')
            ->recordActions([])
            ->searchable()
            ->paginated([10, 25, 50, 100]);
    }

    protected function makeTable(): Table
    {
        return $this->table($this->makeBaseTable());
    }

    protected function getTableQuery(): Builder
    {
        /** @var Company $company */
        $company = Filament::getTenant();

        return Product::query()
            ->where('company_id', $company->getKey())
            ->with(['inventoryBalance', 'measurementUnit']);
    }

    protected function getHeaderWidgets(): array
    {
        return [
            InventoryPositionStatsWidget::class,
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                EmbeddedTable::make(),
            ]);
    }

    protected static function resolveSituation(Product $record): string
    {
        if (! $record->tracks_stock) {
            return 'Sem controle';
        }

        if (bccomp($record->getCurrentStockQuantity(), '0', 4) <= 0) {
            return 'Sem estoque';
        }

        if ($record->isBelowMinimumStock()) {
            return 'Estoque baixo';
        }

        return 'Normal';
    }

    protected static function applyLowStockFilter(Builder $query): Builder
    {
        return $query
            ->where('tracks_stock', true)
            ->where(function (Builder $query): void {
                $query
                    ->where(function (Builder $query): void {
                        $query
                            ->where('minimum_stock', '>', 0)
                            ->whereHas(
                                'inventoryBalance',
                                fn (Builder $query): Builder => $query->whereColumn(
                                    'inventory_balances.quantity_on_hand',
                                    '<=',
                                    'products.minimum_stock',
                                ),
                            );
                    })
                    ->orWhere(function (Builder $query): void {
                        $query
                            ->where('minimum_stock', '<=', 0)
                            ->where(function (Builder $query): void {
                                $query
                                    ->whereDoesntHave('inventoryBalance')
                                    ->orWhereHas(
                                        'inventoryBalance',
                                        fn (Builder $query): Builder => $query->where('quantity_on_hand', '<=', 0),
                                    );
                            });
                    });
            });
    }

    protected static function applyOutOfStockFilter(Builder $query): Builder
    {
        return $query
            ->where('tracks_stock', true)
            ->where(function (Builder $query): void {
                $query
                    ->whereDoesntHave('inventoryBalance')
                    ->orWhereHas(
                        'inventoryBalance',
                        fn (Builder $query): Builder => $query->where('quantity_on_hand', '<=', 0),
                    );
            });
    }

    protected static function requiredCompanyModule(): CompanyModule
    {
        return CompanyModule::Stock;
    }
}
