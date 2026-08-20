<?php

namespace App\Filament\App\Resources\Anamneses\Pages;

use App\Filament\App\Resources\Anamneses\AnamnesisResource;
use App\Models\Company;
use App\Models\Professional;
use App\Services\Clinical\ClinicalAuditService;
use App\Services\Clinical\DentalAnamnesisService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditAnamnesis extends EditRecord
{
    protected static string $resource = AnamnesisResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);
        /** @var Company $company */
        $company = Filament::getTenant();
        app(ClinicalAuditService::class)->record($company, $this->record->client, auth()->user(), 'anamnesis.viewed', $this->record);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('complete')->label('Concluir anamnese')->color('success')->visible(fn (): bool => $this->record->status === 'draft')->schema([
                Select::make('reviewed_by')
                    ->label('Dentista responsável pela validação')
                    ->helperText('Selecione o dentista que conferiu e confirmou esta anamnese.')
                    ->options(fn (): array => Professional::query()->where('company_id', Filament::getTenant()?->getKey())->where('user_id', auth()->id())->active()->pluck('name', 'id')->all())
                    ->required(),
            ])->requiresConfirmation()->action(function (array $data): void {
                /** @var Company $company */ $company = Filament::getTenant();
                $reviewer = Professional::query()->where('company_id', $company->getKey())->findOrFail($data['reviewed_by']);
                app(DentalAnamnesisService::class)->complete($company, $this->record, $reviewer, auth()->user());
                Notification::make()->success()->title('Anamnese concluída')->send();
                $this->redirect(static::getResource()::getUrl('edit', ['record' => $this->record]));
            }),
        ];
    }

    protected function getFormActions(): array
    {
        return $this->record->status === 'draft' ? parent::getFormActions() : [];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Company $company */ $company = Filament::getTenant();

        return app(DentalAnamnesisService::class)->updateDraft($company, $record, auth()->user(), $data['answers'] ?? []);
    }
}
