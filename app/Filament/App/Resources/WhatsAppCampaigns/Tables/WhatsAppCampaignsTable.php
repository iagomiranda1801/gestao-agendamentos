<?php

namespace App\Filament\App\Resources\WhatsAppCampaigns\Tables;

use App\Enums\WhatsAppCampaignStatus;
use App\Models\Company;
use App\Models\WhatsAppCampaign;
use App\Services\WhatsApp\Campaigns\WhatsAppCampaignService;
use Filament\Actions\Action;
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
                    ->label('Pendentes Evolution')
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
                Action::make('prepareRecipients')
                    ->label('Preparar lista')
                    ->icon('heroicon-o-user-group')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalHeading('Preparar destinatários')
                    ->modalDescription('A lista será recriada usando apenas clientes ativos com aceite de campanha no WhatsApp.')
                    ->visible(fn (WhatsAppCampaign $record): bool => $record->status === WhatsAppCampaignStatus::Draft)
                    ->action(function (WhatsAppCampaign $record): void {
                        /** @var Company $company */
                        $company = Filament::getTenant();

                        $count = app(WhatsAppCampaignService::class)->prepareRecipients($company, $record);

                        Notification::make()
                            ->success()
                            ->title("Lista preparada: {$count} destinatário(s)")
                            ->send();
                    }),
                Action::make('startSending')
                    ->label('Enviar')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Enviar campanha WhatsApp')
                    ->modalDescription('As mensagens serão colocadas na fila com intervalo entre cada envio. Confirme somente se a campanha foi autorizada pelos clientes.')
                    ->visible(fn (WhatsAppCampaign $record): bool => $record->status === WhatsAppCampaignStatus::Draft && $record->total_recipients > 0)
                    ->action(function (WhatsAppCampaign $record): void {
                        /** @var Company $company */
                        $company = Filament::getTenant();

                        app(WhatsAppCampaignService::class)->startSending($company, $record);

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
            ])
            ->searchable();
    }
}
