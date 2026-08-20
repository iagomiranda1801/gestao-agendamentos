<?php

namespace App\Filament\App\Resources\ClinicalAlerts;

use App\Enums\CompanyModule;
use App\Filament\App\Concerns\RequiresCompanyModuleResource;
use App\Filament\App\Resources\ClinicalAlerts\Pages\CreateClinicalAlert;
use App\Filament\App\Resources\ClinicalAlerts\Pages\ListClinicalAlerts;
use App\Models\Company;
use App\Models\PatientClinicalAlert;
use App\Services\Clinical\PatientClinicalAlertService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class ClinicalAlertResource extends Resource
{
    use RequiresCompanyModuleResource;

    protected static ?string $model = PatientClinicalAlert::class;

    protected static ?string $slug = 'alertas-clinicos';

    protected static ?string $modelLabel = 'alerta clínico';

    protected static ?string $pluralModelLabel = 'alertas clínicos';

    protected static ?string $navigationLabel = 'Alertas clínicos';

    protected static string|UnitEnum|null $navigationGroup = 'Clínico';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static ?string $tenantOwnershipRelationshipName = 'company';

    protected static function requiredCompanyModule(): CompanyModule
    {
        return CompanyModule::ClinicalRecords;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([Section::make('Alerta')->schema([
            Select::make('client_id')->label('Paciente')->relationship('client', 'name', fn (Builder $query): Builder => $query->where('company_id', Filament::getTenant()?->getKey())->active())->searchable()->preload()->required(),
            Select::make('type')->label('Tipo')->options(['allergy' => 'Alergia', 'medication' => 'Medicamento', 'systemic_condition' => 'Condição sistêmica', 'pregnancy' => 'Gestação', 'special_care' => 'Cuidado especial', 'free' => 'Alerta livre'])->default('free')->required(),
            Select::make('severity')->label('Severidade')->options(['information' => 'Informativa', 'attention' => 'Atenção', 'critical' => 'Crítica'])->default('attention')->required(),
            TextInput::make('title')->label('Título')->required()->maxLength(255),
            Textarea::make('description')->label('Descrição')->rows(3)->columnSpanFull(),
        ])->columns(2)]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('client.name')->label('Paciente')->searchable(), TextColumn::make('title')->label('Alerta')->searchable(),
            TextColumn::make('severity')->label('Severidade')->badge(), IconColumn::make('is_active')->label('Ativo')->boolean(),
            TextColumn::make('created_at')->label('Criado em')->dateTime('d/m/Y H:i'),
        ])->recordActions([Action::make('deactivate')->label('Desativar')->requiresConfirmation()->visible(fn (PatientClinicalAlert $record): bool => $record->is_active)->action(function (PatientClinicalAlert $record): void {
            /** @var Company $company */ $company = Filament::getTenant();
            app(PatientClinicalAlertService::class)->deactivate($company, $record, auth()->user());
        })])->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListClinicalAlerts::route('/'), 'create' => CreateClinicalAlert::route('/create')];
    }
}
