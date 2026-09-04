<?php

namespace App\Filament\App\Resources\TreatmentPlans\Pages;

use App\Filament\App\Resources\TreatmentPlans\TreatmentPlanResource;
use App\Models\Company;
use App\Services\Clinical\ClinicalAuditService;
use App\Services\Clinical\DentalTreatmentPlanService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use App\Filament\App\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditTreatmentPlan extends EditRecord
{
    protected static string $resource = TreatmentPlanResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);
        /** @var Company $company */
        $company = Filament::getTenant();
        app(ClinicalAuditService::class)->record($company, $this->record->client, auth()->user(), 'treatment_plan.viewed', $this->record);
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['items'] = $this->record->items()->get()->map->only(['service_id', 'professional_id', 'description', 'tooth', 'surfaces', 'quantity', 'unit_price', 'discount_amount', 'priority', 'status'])->all();

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('approve')->label('Aprovar plano')->color('success')->requiresConfirmation()->visible(fn (): bool => $this->record->approved_at === null)->action(function (): void {
            /** @var Company $company */ $company = Filament::getTenant();
            app(DentalTreatmentPlanService::class)->approve($company, $this->record, auth()->user());
            Notification::make()->success()->title('Plano aprovado')->send();
            $this->redirect(static::getResource()::getUrl('edit', ['record' => $this->record]));
        }),
            Action::make('print')->label('Imprimir / exportar')->icon('heroicon-o-printer')->url(fn (): string => route('dental.treatment-plan.print', ['company' => Filament::getTenant(), 'plan' => $this->record]))->openUrlInNewTab(),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Company $company */ $company = Filament::getTenant();
        $items = $data['items'] ?? [];
        unset($data['client_id'], $data['professional_id'], $data['items']);

        return app(DentalTreatmentPlanService::class)->update($company, $record, auth()->user(), $data, $items);
    }
}
