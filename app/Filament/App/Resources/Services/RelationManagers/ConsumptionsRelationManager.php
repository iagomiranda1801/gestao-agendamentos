<?php

namespace App\Filament\App\Resources\Services\RelationManagers;

use App\Enums\ProductType;
use App\Models\Company;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceProductConsumption;
use App\Services\Service\ServiceCompositionService;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ConsumptionsRelationManager extends RelationManager
{
    protected static string $relationship = 'consumptions';

    protected static ?string $title = 'Materiais utilizados';

    protected static ?string $modelLabel = 'material';

    protected static ?string $pluralModelLabel = 'materiais utilizados';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('product_id')
                    ->label('Produto')
                    ->options(fn (): array => $this->getConsumableProductOptions())
                    ->getSearchResultsUsing(fn (string $search): array => $this->searchConsumableProducts($search))
                    ->getOptionLabelUsing(function ($value): ?string {
                        $product = Product::query()
                            ->with('measurementUnit')
                            ->find($value);

                        if (! $product) {
                            return null;
                        }

                        return "{$product->name} — {$product->measurementUnit->symbol}";
                    })
                    ->searchable()
                    ->required()
                    ->native(false)
                    ->live()
                    ->afterStateUpdated(function (?int $state, callable $set): void {
                        if (! $state) {
                            return;
                        }

                        $product = Product::query()->find($state);

                        $set('reference_unit_cost_display', $product?->reference_unit_cost);
                    }),
                TextInput::make('quantity')
                    ->label('Quantidade por atendimento')
                    ->numeric()
                    ->step(0.0001)
                    ->minValue(0.0001)
                    ->required(),
                TextInput::make('reference_unit_cost_display')
                    ->label('Custo unitário de referência')
                    ->prefix('R$')
                    ->disabled()
                    ->dehydrated(false)
                    ->formatStateUsing(function ($state, ?ServiceProductConsumption $record): ?string {
                        if ($record?->product) {
                            return (string) $record->product->reference_unit_cost;
                        }

                        return $state !== null ? (string) $state : null;
                    }),
                TextInput::make('line_cost_display')
                    ->label('Custo total previsto')
                    ->prefix('R$')
                    ->disabled()
                    ->dehydrated(false)
                    ->formatStateUsing(function ($state, ?ServiceProductConsumption $record, callable $get): ?string {
                        if ($record) {
                            return number_format((float) $record->getLineEstimatedCost(), 2, '.', '');
                        }

                        $productId = $get('product_id');
                        $quantity = $get('quantity');

                        if (! $productId || ! $quantity) {
                            return null;
                        }

                        $product = Product::query()->find($productId);

                        if (! $product) {
                            return null;
                        }

                        return bcmul((string) $quantity, (string) $product->reference_unit_cost, 2);
                    }),
                Textarea::make('notes')
                    ->label('Observação')
                    ->rows(2)
                    ->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('product.name')
            ->columns([
                TextColumn::make('product.name')
                    ->label('Produto')
                    ->searchable(),
                TextColumn::make('quantity')
                    ->label('Quantidade')
                    ->numeric(decimalPlaces: 4),
                TextColumn::make('product.measurementUnit.symbol')
                    ->label('Unidade'),
                TextColumn::make('product.reference_unit_cost')
                    ->label('Custo unitário')
                    ->money('BRL', locale: 'pt_BR', decimalPlaces: 6),
                TextColumn::make('line_estimated_cost')
                    ->label('Custo total previsto')
                    ->state(fn (ServiceProductConsumption $record): string => $record->getLineEstimatedCost())
                    ->money('BRL', locale: 'pt_BR', decimalPlaces: 2),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Adicionar material')
                    ->using(function (array $data): ServiceProductConsumption {
                        /** @var Company $company */
                        $company = Filament::getTenant();

                        /** @var Service $service */
                        $service = $this->getOwnerRecord();

                        return app(ServiceCompositionService::class)->createConsumption($company, $service, $data);
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->using(function (ServiceProductConsumption $record, array $data): ServiceProductConsumption {
                        /** @var Company $company */
                        $company = Filament::getTenant();

                        return app(ServiceCompositionService::class)->updateConsumption($company, $record, $data);
                    }),
                DeleteAction::make()
                    ->label('Remover')
                    ->requiresConfirmation()
                    ->action(function (ServiceProductConsumption $record): void {
                        /** @var Company $company */
                        $company = Filament::getTenant();

                        app(ServiceCompositionService::class)->deleteConsumption($company, $record);
                    }),
            ]);
    }

    /**
     * @return array<int, string>
     */
    protected function getConsumableProductOptions(): array
    {
        /** @var Company $company */
        $company = Filament::getTenant();

        return Product::query()
            ->where('company_id', $company->getKey())
            ->active()
            ->consumable()
            ->with('measurementUnit')
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Product $product): array => [
                $product->getKey() => "{$product->name} — {$product->measurementUnit->symbol}",
            ])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    protected function searchConsumableProducts(string $search): array
    {
        /** @var Company $company */
        $company = Filament::getTenant();

        return Product::query()
            ->where('company_id', $company->getKey())
            ->active()
            ->where('type', ProductType::Consumable)
            ->where('name', 'like', "%{$search}%")
            ->with('measurementUnit')
            ->limit(50)
            ->get()
            ->mapWithKeys(fn (Product $product): array => [
                $product->getKey() => "{$product->name} — {$product->measurementUnit->symbol}",
            ])
            ->all();
    }
}
