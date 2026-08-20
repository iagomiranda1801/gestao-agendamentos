<?php

namespace App\Filament\App\Resources\ClinicalAttachments;

use App\Enums\CompanyModule;
use App\Filament\App\Concerns\RequiresCompanyModuleResource;
use App\Filament\App\Resources\ClinicalAttachments\Pages\CreateClinicalAttachment;
use App\Filament\App\Resources\ClinicalAttachments\Pages\ListClinicalAttachments;
use App\Models\ClinicalAttachment;
use App\Models\Company;
use App\Services\Clinical\ClinicalAttachmentService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class ClinicalAttachmentResource extends Resource
{
    use RequiresCompanyModuleResource;

    protected static ?string $model = ClinicalAttachment::class;

    protected static ?string $slug = 'documentos-clinicos';

    protected static ?string $modelLabel = 'documento clínico';

    protected static ?string $pluralModelLabel = 'documentos clínicos';

    protected static ?string $navigationLabel = 'Documentos clínicos';

    protected static string|UnitEnum|null $navigationGroup = 'Clínico';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPaperClip;

    protected static ?string $tenantOwnershipRelationshipName = 'company';

    protected static function requiredCompanyModule(): CompanyModule
    {
        return CompanyModule::ClinicalRecords;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([Section::make('Documento')->schema([
            Select::make('client_id')->label('Paciente')->relationship('client', 'name', fn (Builder $query): Builder => $query->where('company_id', Filament::getTenant()?->getKey())->active())->searchable()->preload()->required(),
            Select::make('type')->label('Tipo')->options(['radiograph' => 'Radiografia', 'photo' => 'Fotografia', 'exam' => 'Exame', 'prescription' => 'Receita', 'certificate' => 'Atestado', 'consent' => 'Termo / consentimento', 'general' => 'Documento geral'])->required(),
            TextInput::make('title')->label('Título')->required()->maxLength(255),
            DatePicker::make('document_date')->label('Data do documento')->native(false),
            Textarea::make('description')->label('Descrição')->rows(2)->columnSpanFull(),
            FileUpload::make('file')->label('Arquivo')->storeFiles(false)->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'application/dicom', 'application/octet-stream'])->maxSize(20480)->required()->columnSpanFull(),
        ])->columns(2)]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('client.name')->label('Paciente')->searchable(),
            TextColumn::make('title')->label('Documento')->searchable(),
            TextColumn::make('type')->label('Tipo')->badge()->formatStateUsing(fn (string $state): string => match ($state) {
                'radiograph' => 'Radiografia', 'photo' => 'Fotografia', 'exam' => 'Exame', 'prescription' => 'Receita',
                'certificate' => 'Atestado', 'consent' => 'Termo / consentimento', default => 'Documento geral',
            }),
            TextColumn::make('document_date')->label('Data')->date('d/m/Y')->placeholder('—'),
            TextColumn::make('original_name')->label('Arquivo')->limit(40),
            TextColumn::make('created_at')->label('Enviado em')->dateTime('d/m/Y H:i'),
        ])->recordActions([
            Action::make('download')->label('Baixar')->icon('heroicon-o-arrow-down-tray')->action(function (ClinicalAttachment $record) {
                /** @var Company $company */ $company = Filament::getTenant();
                $path = app(ClinicalAttachmentService::class)->download($company, $record, auth()->user());

                return response()->download($path, $record->original_name);
            }),
            Action::make('remove')->label('Remover')->color('danger')->requiresConfirmation()->schema([Textarea::make('reason')->label('Motivo')->required()])->action(function (ClinicalAttachment $record, array $data): void {
                /** @var Company $company */ $company = Filament::getTenant();
                app(ClinicalAttachmentService::class)->softDelete($company, $record, auth()->user(), $data['reason']);
            }),
        ])->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListClinicalAttachments::route('/'), 'create' => CreateClinicalAttachment::route('/create')];
    }
}
