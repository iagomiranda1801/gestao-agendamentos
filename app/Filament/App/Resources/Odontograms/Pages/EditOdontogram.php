<?php

namespace App\Filament\App\Resources\Odontograms\Pages;

use App\Filament\App\Resources\Odontograms\OdontogramResource;
use App\Models\Company;
use App\Services\Clinical\ClinicalAuditService;
use App\Services\Clinical\DentalOdontogramService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditOdontogram extends EditRecord
{
    protected static string $resource = OdontogramResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);
        /** @var Company $company */
        $company = Filament::getTenant();
        app(ClinicalAuditService::class)->record($company, $this->record->client, auth()->user(), 'odontogram.viewed', $this->record);
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['entries'] = $this->record->entries()->get()->map->only(['tooth', 'surfaces', 'condition', 'stage', 'notes'])->all();

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('finalize')->label('Finalizar odontograma')->color('success')->requiresConfirmation()->visible(fn (): bool => $this->record->status === 'draft')->action(function (): void {
            /** @var Company $company */ $company = Filament::getTenant();
            app(DentalOdontogramService::class)->finalize($company, $this->record, auth()->user());
            Notification::make()->success()->title('Odontograma finalizado')->send();
            $this->redirect(static::getResource()::getUrl('edit', ['record' => $this->record]));
        })];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Company $company */ $company = Filament::getTenant();

        return app(DentalOdontogramService::class)->updateDraft($company, $record, auth()->user(), $data['entries'] ?? []);
    }
}
