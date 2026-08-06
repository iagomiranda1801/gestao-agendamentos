<?php

namespace App\Filament\App\Resources\WhatsAppCampaigns\Tables;

use App\Enums\WhatsAppCampaignStatus;
use App\Filament\App\Resources\WhatsAppCampaigns\WhatsAppCampaignResource;
use App\Models\Company;
use App\Models\WhatsAppCampaign;
use App\Services\WhatsApp\Campaigns\WhatsAppCampaignService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class WhatsAppCampaignsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->poll('10s')
            ->columns([
                TextColumn::make('name')
                    ->label('Nome')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->formatStateUsing(fn (WhatsAppCampaignStatus $state): string => $state->label())
                    ->badge()
                    ->color(fn (WhatsAppCampaignStatus $state): string => match ($state) {
                        WhatsAppCampaignStatus::Draft => 'gray',
                        WhatsAppCampaignStatus::Scheduled => 'info',
                        WhatsAppCampaignStatus::Sending => 'warning',
                        WhatsAppCampaignStatus::Completed => 'success',
                        WhatsAppCampaignStatus::Cancelled => 'danger',
                    }),
                TextColumn::make('total_recipients')
                    ->label('Dest.')
                    ->sortable(),
                TextColumn::make('sent_count')
                    ->label('Enviadas')
                    ->sortable(),
                TextColumn::make('accepted_count')
                    ->label('Aceitas Evolution')
                    ->sortable(),
                TextColumn::make('failed_count')
                    ->label('Falhas')
                    ->sortable(),
                TextColumn::make('send_interval_seconds')
                    ->label('Intervalo')
                    ->suffix('s')
                    ->toggleable(),
                TextColumn::make('started_at')
                    ->label('Início')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('scheduled_at')
                    ->label('Agendada para')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label('Criada em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(WhatsAppCampaignStatus::options()),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                EditAction::make(),
                Action::make('startSending')
                    ->label('Enviar agora')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Enviar campanha WhatsApp')
                    ->modalDescription('A lista será preparada automaticamente usando somente clientes ativos e autorizados.')
                    ->visible(fn (WhatsAppCampaign $record): bool => in_array($record->status, [
                        WhatsAppCampaignStatus::Draft,
                        WhatsAppCampaignStatus::Scheduled,
                    ], true))
                    ->action(function (WhatsAppCampaign $record): void {
                        /** @var Company $company */
                        $company = Filament::getTenant();

                        app(WhatsAppCampaignService::class)->sendNow($company, $record);

                        Notification::make()
                            ->success()
                            ->title('Campanha colocada na fila')
                            ->send();
                    }),
                Action::make('cancel')
                    ->label('Cancelar')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (WhatsAppCampaign $record): bool => in_array($record->status, [
                        WhatsAppCampaignStatus::Draft,
                        WhatsAppCampaignStatus::Scheduled,
                        WhatsAppCampaignStatus::Sending,
                    ], true))
                    ->action(function (WhatsAppCampaign $record): void {
                        /** @var Company $company */
                        $company = Filament::getTenant();

                        app(WhatsAppCampaignService::class)->cancel($company, $record, auth()->user());

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
                    ->action(function (WhatsAppCampaign $record) {
                        /** @var Company $company */
                        $company = Filament::getTenant();

                        $copy = app(WhatsAppCampaignService::class)->duplicateForResend($company, $record, auth()->user());

                        Notification::make()
                            ->success()
                            ->title('Cópia criada para edição')
                            ->send();

                        return redirect(WhatsAppCampaignResource::getUrl('edit', ['record' => $copy]));
                    }),
                Action::make('resend')
                    ->label('Reenviar agora')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Reenviar campanha')
                    ->modalDescription('Será criada uma nova cópia da campanha e ela será colocada na fila de envio imediatamente.')
                    ->visible(fn (WhatsAppCampaign $record): bool => in_array($record->status, [
                        WhatsAppCampaignStatus::Completed,
                        WhatsAppCampaignStatus::Cancelled,
                    ], true))
                    ->action(function (WhatsAppCampaign $record): void {
                        /** @var Company $company */
                        $company = Filament::getTenant();

                        app(WhatsAppCampaignService::class)->resend($company, $record, auth()->user());

                        Notification::make()
                            ->success()
                            ->title('Campanha reenviada em uma nova cópia')
                            ->send();
                    }),
                DeleteAction::make()
                    ->label('Excluir')
                    ->icon('heroicon-o-trash')
                    ->requiresConfirmation(),
            ])
            ->searchable();
    }
}
