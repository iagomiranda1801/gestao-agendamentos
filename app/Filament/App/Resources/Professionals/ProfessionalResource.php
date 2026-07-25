<?php

namespace App\Filament\App\Resources\Professionals;

use App\Filament\App\Resources\Professionals\Pages\CreateProfessional;
use App\Filament\App\Resources\Professionals\Pages\EditProfessional;
use App\Filament\App\Resources\Professionals\Pages\ListProfessionals;
use App\Filament\App\Resources\Professionals\RelationManagers\BreaksRelationManager;
use App\Filament\App\Resources\Professionals\RelationManagers\WorkingHoursRelationManager;
use App\Filament\App\Resources\Professionals\Schemas\ProfessionalForm;
use App\Filament\App\Resources\Professionals\Tables\ProfessionalsTable;
use App\Models\Professional;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class ProfessionalResource extends Resource
{
    protected static ?string $model = Professional::class;

    protected static ?string $slug = 'profissionais';

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBriefcase;

    protected static ?string $modelLabel = 'profissional';

    protected static ?string $pluralModelLabel = 'profissionais';

    protected static ?string $navigationLabel = 'Profissionais';

    protected static string|UnitEnum|null $navigationGroup = 'Cadastros';

    protected static ?int $navigationSort = 2;

    protected static ?string $tenantOwnershipRelationshipName = 'company';

    public static function form(Schema $schema): Schema
    {
        return ProfessionalForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProfessionalsTable::configure($table);
    }

    /**
     * @return list<string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'specialty', 'phone', 'phone_normalized', 'email'];
    }

    public static function getRelations(): array
    {
        return [
            WorkingHoursRelationManager::class,
            BreaksRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProfessionals::route('/'),
            'create' => CreateProfessional::route('/create'),
            'edit' => EditProfessional::route('/{record}/edit'),
        ];
    }
}
