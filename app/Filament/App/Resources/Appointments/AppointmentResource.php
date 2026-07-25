<?php

namespace App\Filament\App\Resources\Appointments;

use App\Filament\App\Resources\Appointments\Pages\CreateAppointment;
use App\Filament\App\Resources\Appointments\Pages\EditAppointment;
use App\Filament\App\Resources\Appointments\Pages\ListAppointments;
use App\Filament\App\Resources\Appointments\Pages\ViewAppointment;
use App\Filament\App\Resources\Appointments\RelationManagers\HistoriesRelationManager;
use App\Filament\App\Resources\Appointments\Schemas\AppointmentForm;
use App\Filament\App\Resources\Appointments\Tables\AppointmentsTable;
use App\Models\Appointment;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class AppointmentResource extends Resource
{
    protected static ?string $model = Appointment::class;

    protected static ?string $slug = 'agendamentos';

    protected static ?string $recordTitleAttribute = 'service_name_snapshot';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?string $modelLabel = 'agendamento';

    protected static ?string $pluralModelLabel = 'agendamentos';

    protected static ?string $navigationLabel = 'Agendamentos';

    protected static string|UnitEnum|null $navigationGroup = 'Agenda';

    protected static ?int $navigationSort = 11;

    protected static ?string $tenantOwnershipRelationshipName = 'company';

    public static function form(Schema $schema): Schema
    {
        return AppointmentForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AppointmentsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            HistoriesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAppointments::route('/'),
            'create' => CreateAppointment::route('/create'),
            'view' => ViewAppointment::route('/{record}'),
            'edit' => EditAppointment::route('/{record}/edit'),
        ];
    }
}
