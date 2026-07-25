<?php

namespace App\Filament\App\Resources\Services;

use App\Filament\App\Resources\Services\Pages\CreateService;
use App\Filament\App\Resources\Services\Pages\EditService;
use App\Filament\App\Resources\Services\Pages\ListServices;
use App\Filament\App\Resources\Services\RelationManagers\ConsumptionsRelationManager;
use App\Filament\App\Resources\Services\RelationManagers\ProfessionalsRelationManager;
use App\Filament\App\Resources\Services\Schemas\ServiceForm;
use App\Filament\App\Resources\Services\Tables\ServicesTable;
use App\Models\Service;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class ServiceResource extends Resource
{
    protected static ?string $model = Service::class;

    protected static ?string $slug = 'servicos';

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static ?string $modelLabel = 'serviço';

    protected static ?string $pluralModelLabel = 'serviços';

    protected static ?string $navigationLabel = 'Serviços';

    protected static string|UnitEnum|null $navigationGroup = 'Cadastros';

    protected static ?int $navigationSort = 3;

    protected static ?string $tenantOwnershipRelationshipName = 'company';

    public static function form(Schema $schema): Schema
    {
        return ServiceForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ServicesTable::configure($table);
    }

    /**
     * @return list<string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'description', 'slug'];
    }

    public static function getRelations(): array
    {
        return [
            ConsumptionsRelationManager::class,
            ProfessionalsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListServices::route('/'),
            'create' => CreateService::route('/create'),
            'edit' => EditService::route('/{record}/edit'),
        ];
    }
}
