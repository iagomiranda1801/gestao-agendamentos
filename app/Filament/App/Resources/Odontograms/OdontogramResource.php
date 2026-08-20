<?php

namespace App\Filament\App\Resources\Odontograms;

use App\Enums\CompanyModule;
use App\Filament\App\Concerns\RequiresCompanyModuleResource;
use App\Filament\App\Resources\Odontograms\Pages\CreateOdontogram;
use App\Filament\App\Resources\Odontograms\Pages\EditOdontogram;
use App\Filament\App\Resources\Odontograms\Pages\ListOdontograms;
use App\Filament\App\Resources\Odontograms\Schemas\OdontogramForm;
use App\Models\DentalOdontogram;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class OdontogramResource extends Resource
{
    use RequiresCompanyModuleResource;

    protected static ?string $model = DentalOdontogram::class;

    protected static ?string $slug = 'odontogramas';

    protected static ?string $modelLabel = 'odontograma';

    protected static ?string $pluralModelLabel = 'odontogramas';

    protected static ?string $navigationLabel = 'Odontogramas';

    protected static string|UnitEnum|null $navigationGroup = 'Clínico';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquaresPlus;

    protected static ?string $tenantOwnershipRelationshipName = 'company';

    protected static function requiredCompanyModule(): CompanyModule
    {
        return CompanyModule::ClinicalRecords;
    }

    public static function form(Schema $schema): Schema
    {
        return OdontogramForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('client.name')->label('Paciente')->searchable()->sortable(),
            TextColumn::make('version')->label('Versão'),
            TextColumn::make('professional.name')->label('Dentista'),
            TextColumn::make('status')->label('Status')->badge()->formatStateUsing(fn (string $state): string => $state === 'finalized' ? 'Finalizado' : 'Rascunho'),
            TextColumn::make('updated_at')->label('Atualizado em')->dateTime('d/m/Y H:i'),
        ])->defaultSort('updated_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListOdontograms::route('/'), 'create' => CreateOdontogram::route('/create'), 'edit' => EditOdontogram::route('/{record}/edit')];
    }
}
