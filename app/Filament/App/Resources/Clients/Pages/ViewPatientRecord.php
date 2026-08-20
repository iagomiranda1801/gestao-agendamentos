<?php

namespace App\Filament\App\Resources\Clients\Pages;

use App\Enums\CompanyPermission;
use App\Filament\App\Resources\Anamneses\AnamnesisResource;
use App\Filament\App\Resources\Clients\ClientResource;
use App\Filament\App\Resources\ClinicalAttachments\ClinicalAttachmentResource;
use App\Filament\App\Resources\ClinicalEntries\ClinicalEntryResource;
use App\Filament\App\Resources\Odontograms\OdontogramResource;
use App\Filament\App\Resources\TreatmentPlans\TreatmentPlanResource;
use App\Models\Client;
use App\Models\Company;
use App\Services\Clinical\ClinicalAuditService;
use App\Services\Company\CompanyPermissionService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ViewPatientRecord extends ViewRecord
{
    protected static string $resource = ClientResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);
        /** @var Company $company */ $company = Filament::getTenant();
        /** @var Client $patient */ $patient = $this->getRecord();
        $patient->loadMissing([
            'dentalProfile', 'activeClinicalAlerts', 'clinicalEntries.professional', 'treatmentPlans.professional',
            'dentalAnamneses.reviewer', 'clinicalAttachments', 'clinicalAuditEvents',
        ]);

        if ($this->canViewClinical()) {
            app(ClinicalAuditService::class)->record($company, $patient, auth()->user(), 'patient_record.viewed', $patient);
            $patient->unsetRelation('clinicalAuditEvents')->load('clinicalAuditEvents');
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()->label('Editar paciente'),
            Action::make('new_anamnesis')->label('Nova anamnese')->url(fn (): string => AnamnesisResource::getUrl('create', ['client_id' => $this->record->getKey()]))->visible(fn (): bool => $this->canWriteClinical()),
            Action::make('new_entry')->label('Nova evolução')->url(fn (): string => ClinicalEntryResource::getUrl('create', ['client_id' => $this->record->getKey()]))->visible(fn (): bool => $this->canWriteClinical()),
            Action::make('new_plan')->label('Novo plano')->url(fn (): string => TreatmentPlanResource::getUrl('create', ['client_id' => $this->record->getKey()]))->visible(fn (): bool => $this->canManagePlans()),
            Action::make('new_odontogram')->label('Novo odontograma')->url(fn (): string => OdontogramResource::getUrl('create', ['client_id' => $this->record->getKey()]))->visible(fn (): bool => $this->canWriteClinical()),
            Action::make('new_document')->label('Anexar documento')->url(fn (): string => ClinicalAttachmentResource::getUrl('create', ['client_id' => $this->record->getKey()]))->visible(fn (): bool => $this->canWriteClinical()),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Resumo do paciente')->schema([
                TextEntry::make('name')->label('Nome'),
                TextEntry::make('dentalProfile.social_name')->label('Nome social')->placeholder('—'),
                TextEntry::make('dentalProfile.record_number')->label('Prontuário')->placeholder('—'),
                TextEntry::make('birth_date')->label('Nascimento')->date('d/m/Y')->placeholder('—'),
                TextEntry::make('phone')->label('Telefone'),
                TextEntry::make('email')->label('E-mail')->placeholder('—'),
            ])->columns(3),
            Section::make('Alertas clínicos ativos')->schema([
                RepeatableEntry::make('activeClinicalAlerts')->label('')->schema([
                    TextEntry::make('severity')->label('Nível')->badge()->formatStateUsing(fn (string $state): string => static::alertSeverityLabel($state)),
                    TextEntry::make('title')->label('Alerta'),
                    TextEntry::make('description')->label('Detalhes')->placeholder('—'),
                ])->columns(3),
            ])->visible(fn (): bool => $this->canViewClinical()),
            Section::make('Evoluções recentes')->schema([
                RepeatableEntry::make('clinicalEntries')->label('')->schema([
                    TextEntry::make('occurred_at')->label('Data')->dateTime('d/m/Y H:i'),
                    TextEntry::make('professional.name')->label('Dentista'),
                    TextEntry::make('procedure_performed')->label('Procedimento')->placeholder('—'),
                    TextEntry::make('status')->label('Status')->badge()->formatStateUsing(fn (string $state): string => $state === 'finalized' ? 'Finalizada' : 'Rascunho'),
                ])->columns(4),
            ])->visible(fn (): bool => $this->canViewClinical()),
            Section::make('Anamneses')->schema([
                RepeatableEntry::make('dentalAnamneses')->label('')->schema([
                    TextEntry::make('version')->label('Versão'),
                    TextEntry::make('status')->label('Status')->badge()->formatStateUsing(fn (string $state): string => match ($state) {
                        'completed' => 'Concluída', 'superseded' => 'Substituída', default => 'Rascunho',
                    }),
                    TextEntry::make('reviewer.name')->label('Revisada por')->placeholder('—'),
                    TextEntry::make('completed_at')->label('Conclusão')->dateTime('d/m/Y H:i')->placeholder('—'),
                ])->columns(4),
            ])->visible(fn (): bool => $this->canViewClinical()),
            Section::make('Planos de tratamento')->schema([
                RepeatableEntry::make('treatmentPlans')->label('')->schema([
                    TextEntry::make('title')->label('Plano'),
                    TextEntry::make('professional.name')->label('Dentista'),
                    TextEntry::make('status')->label('Situação')->badge()->formatStateUsing(fn (string $state): string => static::treatmentStatusLabel($state)),
                    TextEntry::make('total_amount')->label('Total')->money('BRL', locale: 'pt_BR'),
                ])->columns(4),
            ])->visible(fn (): bool => $this->canManagePlans()),
            Section::make('Documentos')->schema([
                RepeatableEntry::make('clinicalAttachments')->label('')->schema([
                    TextEntry::make('title')->label('Documento'),
                    TextEntry::make('type')->label('Tipo')->badge()->formatStateUsing(fn (string $state): string => static::attachmentTypeLabel($state)),
                    TextEntry::make('document_date')->label('Data')->date('d/m/Y')->placeholder('—'),
                    TextEntry::make('original_name')->label('Arquivo'),
                ])->columns(4),
            ])->visible(fn (): bool => $this->canViewClinical()),
            Section::make('Histórico de acesso e alterações')->schema([
                RepeatableEntry::make('clinicalAuditEvents')->hiddenLabel()->schema([
                    TextEntry::make('occurred_at')->label('Data')->dateTime('d/m/Y H:i'),
                    TextEntry::make('action')->label('O que aconteceu')->formatStateUsing(fn (?string $state): string => static::auditActionLabel($state)),
                    TextEntry::make('entity_type')->label('Registro')->formatStateUsing(fn (?string $state): string => static::auditEntityLabel($state)),
                ])->columns(3)->visible(fn (): bool => $this->getRecord()->clinicalAuditEvents->isNotEmpty()),
                TextEntry::make('clinical_audit_events_empty')
                    ->label('')
                    ->state('Nenhum acesso ou alteração clínica registrado até o momento.')
                    ->visible(fn (): bool => $this->getRecord()->clinicalAuditEvents->isEmpty()),
            ])->visible(fn (): bool => $this->allows(CompanyPermission::ManagePermissions)),
        ]);
    }

    protected function canViewClinical(): bool
    {
        return $this->allows(CompanyPermission::ViewClinicalRecords);
    }

    protected function canWriteClinical(): bool
    {
        return $this->allows(CompanyPermission::WriteClinicalRecords);
    }

    protected function canManagePlans(): bool
    {
        return $this->allows(CompanyPermission::ManageTreatmentPlans);
    }

    protected function allows(CompanyPermission $permission): bool
    {
        $company = Filament::getTenant();

        return $company instanceof Company && app(CompanyPermissionService::class)->allows(auth()->user(), $company, $permission);
    }

    protected static function auditActionLabel(?string $action): string
    {
        return match ($action) {
            'patient_record.viewed' => 'Prontuário visualizado',
            'clinical_entry.created' => 'Evolução criada',
            'clinical_entry.updated' => 'Evolução atualizada',
            'clinical_entry.finalized' => 'Evolução finalizada',
            'anamnesis.created' => 'Anamnese criada',
            'anamnesis.updated' => 'Anamnese atualizada',
            'anamnesis.completed' => 'Anamnese concluída',
            'treatment_plan.created' => 'Plano de tratamento criado',
            'treatment_plan.updated' => 'Plano de tratamento atualizado',
            default => filled($action) ? str($action)->replace(['.', '_'], ' ')->ucfirst()->toString() : 'Alteração registrada',
        };
    }

    protected static function auditEntityLabel(?string $entity): string
    {
        return match ($entity) {
            Client::class => 'Paciente',
            default => 'Prontuário do paciente',
        };
    }

    protected static function treatmentStatusLabel(string $status): string
    {
        return match ($status) {
            'draft' => 'Rascunho',
            'presented' => 'Apresentado',
            'partially_approved' => 'Parcialmente aprovado',
            'in_progress' => 'Em execução',
            'completed' => 'Concluído',
            'cancelled' => 'Cancelado',
            default => 'Não informado',
        };
    }

    protected static function alertSeverityLabel(string $severity): string
    {
        return match ($severity) {
            'information' => 'Informativo',
            'attention' => 'Atenção',
            'critical' => 'Crítico',
            default => 'Não informado',
        };
    }

    protected static function attachmentTypeLabel(string $type): string
    {
        return match ($type) {
            'radiograph' => 'Radiografia',
            'photo' => 'Fotografia',
            'exam' => 'Exame',
            'prescription' => 'Receita',
            'certificate' => 'Atestado',
            'consent' => 'Termo / consentimento',
            default => 'Documento geral',
        };
    }
}
