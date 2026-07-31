<?php

namespace App\Filament\App\Resources\WhatsAppCampaigns\Pages;

use App\Enums\WhatsAppCampaignStatus;
use App\Filament\App\Resources\WhatsAppCampaigns\WhatsAppCampaignResource;
use App\Models\Company;
use App\Models\WhatsAppCampaign;
use App\Services\WhatsApp\Campaigns\WhatsAppCampaignService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
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
            Action::make('duplicateForResend')
                ->label('Copiar para editar')
                ->icon('heroicon-o-document-duplicate')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Criar cópia da campanha')
                ->modalDescription('Uma nova campanha em rascunho será criada com a mesma mensagem e público, para você editar e enviar novamente.')
                ->action(function () {
                    /** @var Company $company */
                    $company = Filament::getTenant();

                    /** @var WhatsAppCampaign $campaign */
                    $campaign = $this->record;
                    $copy = app(WhatsAppCampaignService::class)->duplicateForResend($company, $campaign, auth()->user());

                    Notification::make()
                        ->success()
                        ->title('Cópia criada para edição')
                        ->send();

                    return redirect(static::getResource()::getUrl('edit', ['record' => $copy]));
                }),
            Action::make('resend')
                ->label('Reenviar agora')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Reenviar campanha')
                ->modalDescription('Será criada uma nova cópia da campanha e ela será colocada na fila de envio imediatamente.')
                ->visible(fn (): bool => $this->record->status !== WhatsAppCampaignStatus::Draft)
                ->action(function (): void {
                    /** @var Company $company */
                    $company = Filament::getTenant();

                    app(WhatsAppCampaignService::class)->resend($company, $this->record, auth()->user());

                    Notification::make()
                        ->success()
                        ->title('Campanha reenviada em uma nova cópia')
                        ->send();
                }),
            DeleteAction::make()
                ->label('Excluir')
                ->icon('heroicon-o-trash')
                ->requiresConfirmation(),
        ];
    }
}
