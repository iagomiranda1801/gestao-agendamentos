<?php

namespace App\Filament\App\Resources\WhatsAppCampaigns\Pages;

use App\Enums\WhatsAppCampaignStatus;
use App\Filament\App\Resources\WhatsAppCampaigns\WhatsAppCampaignResource;
use App\Models\Company;
use App\Models\WhatsAppCampaign;
use App\Services\WhatsApp\Campaigns\WhatsAppCampaignService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditWhatsAppCampaign extends EditRecord
{
    protected static string $resource = WhatsAppCampaignResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Company $company */
        $company = Filament::getTenant();

        return app(WhatsAppCampaignService::class)->update($company, $record, $data);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('prepareRecipients')
                ->label('Preparar lista')
                ->icon('heroicon-o-user-group')
                ->color('gray')
                ->requiresConfirmation()
                ->visible(fn (): bool => $this->record->status === WhatsAppCampaignStatus::Draft)
                ->action(function (): void {
                    /** @var Company $company */
                    $company = Filament::getTenant();

                    /** @var WhatsAppCampaign $campaign */
                    $campaign = $this->record;
                    $count = app(WhatsAppCampaignService::class)->prepareRecipients($company, $campaign);

                    Notification::make()
                        ->success()
                        ->title("Lista preparada: {$count} destinatário(s)")
                        ->send();
                }),
            Action::make('startSending')
                ->label('Enviar campanha')
                ->icon('heroicon-o-paper-airplane')
                ->color('warning')
                ->requiresConfirmation()
                ->visible(fn (): bool => $this->record->status === WhatsAppCampaignStatus::Draft && $this->record->total_recipients > 0)
                ->action(function (): void {
                    /** @var Company $company */
                    $company = Filament::getTenant();

                    app(WhatsAppCampaignService::class)->startSending($company, $this->record);

                    Notification::make()
                        ->success()
                        ->title('Campanha colocada na fila')
                        ->send();
                }),
        ];
    }
}
