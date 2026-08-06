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
            Action::make('startSending')
                ->label('Enviar agora')
                ->icon('heroicon-o-paper-airplane')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Enviar campanha WhatsApp')
                ->modalDescription('A lista será preparada automaticamente usando somente clientes ativos e autorizados.')
                ->visible(fn (): bool => in_array($this->record->status, [
                    WhatsAppCampaignStatus::Draft,
                    WhatsAppCampaignStatus::Scheduled,
                ], true))
                ->action(function (): void {
                    /** @var Company $company */
                    $company = Filament::getTenant();

                    /** @var WhatsAppCampaign $campaign */
                    $campaign = $this->record;
                    app(WhatsAppCampaignService::class)->sendNow($company, $campaign);

                    Notification::make()
                        ->success()
                        ->title('Campanha colocada na fila')
                        ->send();
                }),
            Action::make('cancel')
                ->label('Cancelar campanha')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->requiresConfirmation()
                ->visible(fn (): bool => in_array($this->record->status, [
                    WhatsAppCampaignStatus::Draft,
                    WhatsAppCampaignStatus::Scheduled,
                    WhatsAppCampaignStatus::Sending,
                ], true))
                ->action(function (): void {
                    /** @var Company $company */
                    $company = Filament::getTenant();

                    app(WhatsAppCampaignService::class)->cancel($company, $this->record, auth()->user());

                    Notification::make()
                        ->success()
                        ->title('Campanha cancelada')
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
                ->visible(fn (): bool => in_array($this->record->status, [
                    WhatsAppCampaignStatus::Completed,
                    WhatsAppCampaignStatus::Cancelled,
                ], true))
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
