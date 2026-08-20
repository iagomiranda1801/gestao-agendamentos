<?php

namespace App\Filament\App\Resources\ClinicalEntries;

use App\Enums\CompanyModule;
use App\Filament\App\Concerns\RequiresCompanyModuleResource;
use App\Filament\App\Resources\ClinicalEntries\Pages\CreateClinicalEntry;
use App\Filament\App\Resources\ClinicalEntries\Pages\EditClinicalEntry;
use App\Filament\App\Resources\ClinicalEntries\Pages\ListClinicalEntries;
use App\Filament\App\Resources\ClinicalEntries\Schemas\ClinicalEntryForm;
use App\Models\DentalClinicalEntry;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class ClinicalEntryResource extends Resource
{
    use RequiresCompanyModuleResource;

    protected static ?string $model = DentalClinicalEntry::class;

    protected static ?string $slug = 'evolucoes-clinicas';

    protected static ?string $modelLabel = 'evolução clínica';

    protected static ?string $pluralModelLabel = 'evoluções clínicas';

    protected static ?string $navigationLabel = 'Evoluções clínicas';

    protected static string|UnitEnum|null $navigationGroup = 'Clínico';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $tenantOwnershipRelationshipName = 'company';

    protected static function requiredCompanyModule(): CompanyModule
    {
        return CompanyModule::ClinicalRecords;
    }

    public static function form(Schema $schema): Schema
    {
        return ClinicalEntryForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('occurred_at')->label('Data')->dateTime('d/m/Y H:i')->sortable(),
            TextColumn::make('client.name')->label('Paciente')->searchable()->sortable(),
            TextColumn::make('professional.name')->label('Dentista')->searchable(),
            TextColumn::make('procedure_performed')->label('Procedimento')->limit(50)->placeholder('—'),
            TextColumn::make('status')->label('Status')->badge()->formatStateUsing(fn (string $state): string => $state === 'finalized' ? 'Finalizada' : 'Rascunho'),
        ])->defaultSort('occurred_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListClinicalEntries::route('/'),
            'create' => CreateClinicalEntry::route('/create'),
            'edit' => EditClinicalEntry::route('/{record}/edit'),
        ];
    }
}
