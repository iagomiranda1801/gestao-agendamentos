<?php

namespace App\Filament\App\Resources\ClinicalEntries\Pages;

use App\Filament\App\Resources\ClinicalEntries\ClinicalEntryResource;
use App\Models\Company;
use App\Services\Clinical\ClinicalAuditService;
use App\Services\Clinical\DentalClinicalEntryService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditClinicalEntry extends EditRecord
{
    protected static string $resource = ClinicalEntryResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);
        /** @var Company $company */
        $company = Filament::getTenant();
        app(ClinicalAuditService::class)->record($company, $this->record->client, auth()->user(), 'clinical_entry.viewed', $this->record);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('finalize')->label('Finalizar evolução')->color('success')->requiresConfirmation()->visible(fn (): bool => $this->record->status === 'draft')->action(function (): void {
                /** @var Company $company */ $company = Filament::getTenant();
                app(DentalClinicalEntryService::class)->finalize($company, $this->record, auth()->user());
                Notification::make()->success()->title('Evolução finalizada')->send();
                $this->redirect(static::getResource()::getUrl('edit', ['record' => $this->record]));
            }),
            Action::make('addendum')->label('Adicionar adendo')->visible(fn (): bool => $this->record->status === 'finalized')->schema([
                Textarea::make('reason')->label('Justificativa')->required(),
                Textarea::make('content')->label('Conteúdo do adendo')->required(),
            ])->action(function (array $data): void {
                /** @var Company $company */ $company = Filament::getTenant();
                app(DentalClinicalEntryService::class)->addAddendum($company, $this->record, auth()->user(), $data['reason'], $data['content']);
                Notification::make()->success()->title('Adendo registrado')->send();
            }),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Company $company */ $company = Filament::getTenant();
        unset($data['client_id'], $data['professional_id']);

        return app(DentalClinicalEntryService::class)->updateDraft($company, $record, auth()->user(), $data);
    }
}
