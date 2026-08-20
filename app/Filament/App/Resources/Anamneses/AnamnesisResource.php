<?php

namespace App\Filament\App\Resources\Anamneses;

use App\Enums\CompanyModule;
use App\Filament\App\Concerns\RequiresCompanyModuleResource;
use App\Filament\App\Resources\Anamneses\Pages\CreateAnamnesis;
use App\Filament\App\Resources\Anamneses\Pages\EditAnamnesis;
use App\Filament\App\Resources\Anamneses\Pages\ListAnamneses;
use App\Filament\App\Resources\Anamneses\Schemas\AnamnesisForm;
use App\Models\DentalAnamnesis;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class AnamnesisResource extends Resource
{
    use RequiresCompanyModuleResource;

    protected static ?string $model = DentalAnamnesis::class;

    protected static ?string $slug = 'anamneses';

    protected static ?string $modelLabel = 'anamnese';

    protected static ?string $pluralModelLabel = 'anamneses';

    protected static ?string $navigationLabel = 'Anamneses';

    protected static string|UnitEnum|null $navigationGroup = 'Clínico';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?string $tenantOwnershipRelationshipName = 'company';

    protected static function requiredCompanyModule(): CompanyModule
    {
        return CompanyModule::ClinicalRecords;
    }

    public static function form(Schema $schema): Schema
    {
        return AnamnesisForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('client.name')->label('Paciente')->searchable()->sortable(),
            TextColumn::make('version')->label('Versão')->sortable(),
            TextColumn::make('status')->label('Status')->badge()->formatStateUsing(fn (string $state): string => match ($state) {
                'completed' => 'Concluída', 'superseded' => 'Substituída', default => 'Rascunho'
            }),
            TextColumn::make('reviewer.name')->label('Dentista responsável pela validação')->placeholder('—'),
            TextColumn::make('completed_at')->label('Concluída em')->dateTime('d/m/Y H:i')->placeholder('—'),
        ])->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListAnamneses::route('/'), 'create' => CreateAnamnesis::route('/create'), 'edit' => EditAnamnesis::route('/{record}/edit')];
    }
}
