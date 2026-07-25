<?php

namespace App\Filament\App\Resources\Attendances;

use App\Filament\App\Resources\Attendances\Pages\ListAttendances;
use App\Filament\App\Resources\Attendances\Pages\ViewAttendance;
use App\Filament\App\Resources\Attendances\RelationManagers\HistoriesRelationManager;
use App\Filament\App\Resources\Attendances\RelationManagers\MaterialsRelationManager;
use App\Filament\App\Resources\Attendances\RelationManagers\PaymentsRelationManager;
use App\Filament\App\Resources\Attendances\Schemas\AttendanceForm;
use App\Filament\App\Resources\Attendances\Tables\AttendancesTable;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\User;
use App\Policies\AttendancePolicy;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class AttendanceResource extends Resource
{
    protected static ?string $model = Attendance::class;

    protected static ?string $slug = 'atendimentos';

    protected static ?string $recordTitleAttribute = 'service_name_snapshot';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?string $modelLabel = 'atendimento';

    protected static ?string $pluralModelLabel = 'atendimentos';

    protected static ?string $navigationLabel = 'Atendimentos';

    protected static string|UnitEnum|null $navigationGroup = 'Agenda';

    protected static ?int $navigationSort = 20;

    protected static ?string $tenantOwnershipRelationshipName = 'company';

    public static function form(Schema $schema): Schema
    {
        return AttendanceForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AttendancesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            MaterialsRelationManager::class,
            PaymentsRelationManager::class,
            HistoriesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAttendances::route('/'),
            'view' => ViewAttendance::route('/{record}'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();
        $company = Filament::getTenant();

        if (! $user instanceof User || ! $company instanceof Company) {
            return $query;
        }

        return app(AttendancePolicy::class)->scopeAccessibleToUser($query, $user, $company);
    }
}
