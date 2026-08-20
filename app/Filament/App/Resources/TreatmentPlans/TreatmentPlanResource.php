<?php

namespace App\Filament\App\Resources\TreatmentPlans;

use App\Enums\CompanyModule;
use App\Filament\App\Concerns\RequiresCompanyModuleResource;
use App\Filament\App\Resources\TreatmentPlans\Pages\CreateTreatmentPlan;
use App\Filament\App\Resources\TreatmentPlans\Pages\EditTreatmentPlan;
use App\Filament\App\Resources\TreatmentPlans\Pages\ListTreatmentPlans;
use App\Filament\App\Resources\TreatmentPlans\Schemas\TreatmentPlanForm;
use App\Models\DentalTreatmentPlan;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class TreatmentPlanResource extends Resource
{
    use RequiresCompanyModuleResource;

    protected static ?string $model = DentalTreatmentPlan::class;

    protected static ?string $slug = 'planos-tratamento';

    protected static ?string $modelLabel = 'plano de tratamento';

    protected static ?string $pluralModelLabel = 'planos de tratamento';

    protected static ?string $navigationLabel = 'Planos de tratamento';

    protected static string|UnitEnum|null $navigationGroup = 'Clínico';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCurrencyDollar;

    protected static ?string $tenantOwnershipRelationshipName = 'company';

    protected static function requiredCompanyModule(): CompanyModule
    {
        return CompanyModule::ClinicalRecords;
    }

    public static function form(Schema $schema): Schema
    {
        return TreatmentPlanForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('plan_date')->label('Data')->date('d/m/Y')->sortable(),
            TextColumn::make('client.name')->label('Paciente')->searchable()->sortable(),
            TextColumn::make('title')->label('Plano')->searchable(),
            TextColumn::make('professional.name')->label('Dentista'),
            TextColumn::make('status')->label('Situação')->badge()->formatStateUsing(fn (string $state): string => match ($state) {
                'draft' => 'Rascunho',
                'presented' => 'Apresentado',
                'partially_approved' => 'Parcialmente aprovado',
                'in_progress' => 'Em execução',
                'completed' => 'Concluído',
                'cancelled' => 'Cancelado',
                default => 'Não informado',
            }),
            TextColumn::make('total_amount')->label('Total')->money('BRL', locale: 'pt_BR'),
        ])->defaultSort('plan_date', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListTreatmentPlans::route('/'), 'create' => CreateTreatmentPlan::route('/create'), 'edit' => EditTreatmentPlan::route('/{record}/edit')];
    }
}
