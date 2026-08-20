<?php

namespace App\Filament\App\Resources\Clients;

use App\Filament\App\Resources\Clients\Pages\CreateClient;
use App\Filament\App\Resources\Clients\Pages\EditClient;
use App\Filament\App\Resources\Clients\Pages\ListClients;
use App\Filament\App\Resources\Clients\Pages\ViewPatientRecord;
use App\Filament\App\Resources\Clients\Schemas\ClientForm;
use App\Filament\App\Resources\Clients\Tables\ClientsTable;
use App\Models\Client;
use App\Models\Company;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class ClientResource extends Resource
{
    protected static ?string $model = Client::class;

    protected static ?string $slug = 'clientes';

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?string $modelLabel = 'cliente';

    protected static ?string $pluralModelLabel = 'clientes';

    protected static ?string $navigationLabel = 'Clientes';

    protected static string|UnitEnum|null $navigationGroup = 'Cadastros';

    protected static ?int $navigationSort = 1;

    protected static ?string $tenantOwnershipRelationshipName = 'company';

    public static function form(Schema $schema): Schema
    {
        return ClientForm::configure($schema);
    }

    public static function getModelLabel(): string
    {
        return static::isDentalTenant() ? 'paciente' : 'cliente';
    }

    public static function getPluralModelLabel(): string
    {
        return static::isDentalTenant() ? 'pacientes' : 'clientes';
    }

    public static function getNavigationLabel(): string
    {
        return static::isDentalTenant() ? 'Pacientes' : 'Clientes';
    }

    protected static function isDentalTenant(): bool
    {
        $company = Filament::getTenant();

        return $company instanceof Company && $company->isDentalClinic();
    }

    public static function table(Table $table): Table
    {
        return ClientsTable::configure($table);
    }

    /**
     * @return list<string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'phone', 'phone_normalized', 'email'];
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListClients::route('/'),
            'create' => CreateClient::route('/create'),
            'view' => ViewPatientRecord::route('/{record}'),
            'edit' => EditClient::route('/{record}/edit'),
        ];
    }
}
