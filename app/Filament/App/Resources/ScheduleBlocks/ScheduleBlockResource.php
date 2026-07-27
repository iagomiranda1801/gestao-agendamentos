<?php

namespace App\Filament\App\Resources\ScheduleBlocks;

use App\Enums\CompanyModule;
use App\Filament\App\Concerns\RequiresCompanyModuleResource;
use App\Filament\App\Resources\ScheduleBlocks\Pages\CreateScheduleBlock;
use App\Filament\App\Resources\ScheduleBlocks\Pages\EditScheduleBlock;
use App\Filament\App\Resources\ScheduleBlocks\Pages\ListScheduleBlocks;
use App\Filament\App\Resources\ScheduleBlocks\Schemas\ScheduleBlockForm;
use App\Filament\App\Resources\ScheduleBlocks\Tables\ScheduleBlocksTable;
use App\Models\ScheduleBlock;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class ScheduleBlockResource extends Resource
{
    use RequiresCompanyModuleResource;

    protected static ?string $model = ScheduleBlock::class;

    protected static ?string $slug = 'bloqueios-agenda';

    protected static ?string $recordTitleAttribute = 'title';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedNoSymbol;

    protected static ?string $modelLabel = 'bloqueio';

    protected static ?string $pluralModelLabel = 'bloqueios';

    protected static ?string $navigationLabel = 'Bloqueios da agenda';

    protected static string|UnitEnum|null $navigationGroup = 'Agenda';

    protected static ?int $navigationSort = 12;

    protected static ?string $tenantOwnershipRelationshipName = 'company';

    public static function form(Schema $schema): Schema
    {
        return ScheduleBlockForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ScheduleBlocksTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListScheduleBlocks::route('/'),
            'create' => CreateScheduleBlock::route('/create'),
            'edit' => EditScheduleBlock::route('/{record}/edit'),
        ];
    }

    protected static function requiredCompanyModule(): CompanyModule
    {
        return CompanyModule::Scheduling;
    }
}
