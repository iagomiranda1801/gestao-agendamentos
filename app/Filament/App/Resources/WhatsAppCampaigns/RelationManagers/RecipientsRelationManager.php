<?php

namespace App\Filament\App\Resources\WhatsAppCampaigns\RelationManagers;

use App\Enums\WhatsAppCampaignRecipientStatus;
use App\Models\WhatsAppCampaignRecipient;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class RecipientsRelationManager extends RelationManager
{
    protected static string $relationship = 'recipients';

    protected static ?string $title = 'Destinatários';

    protected static ?string $modelLabel = 'destinatário';

    protected static ?string $pluralModelLabel = 'destinatários';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->poll('10s')
            ->columns([
                TextColumn::make('name_snapshot')
                    ->label('Nome')
                    ->searchable()
                    ->placeholder('-'),
                TextColumn::make('phone')
                    ->label('Telefone')
                    ->searchable()
                    ->copyable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->formatStateUsing(fn (WhatsAppCampaignRecipientStatus $state): string => $state->label())
                    ->badge()
                    ->color(fn (WhatsAppCampaignRecipientStatus $state): string => match ($state) {
                        WhatsAppCampaignRecipientStatus::Pending => 'gray',
                        WhatsAppCampaignRecipientStatus::Queued => 'warning',
                        WhatsAppCampaignRecipientStatus::Accepted => 'info',
                        WhatsAppCampaignRecipientStatus::Sent => 'success',
                        WhatsAppCampaignRecipientStatus::Delivered => 'success',
                        WhatsAppCampaignRecipientStatus::Read => 'success',
                        WhatsAppCampaignRecipientStatus::Failed => 'danger',
                        WhatsAppCampaignRecipientStatus::Skipped => 'gray',
                    }),
                TextColumn::make('provider_status')
                    ->label('Status Evolution')
                    ->placeholder('-')
                    ->badge()
                    ->toggleable(),
                TextColumn::make('provider_message_id')
                    ->label('ID Evolution')
                    ->placeholder('-')
                    ->copyable()
                    ->toggleable(),
                TextColumn::make('attempts')
                    ->label('Tent.')
                    ->sortable(),
                TextColumn::make('queued_at')
                    ->label('Fila')
                    ->dateTime('d/m/Y H:i:s')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('attempted_at')
                    ->label('Tentativa')
                    ->dateTime('d/m/Y H:i:s')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('sent_at')
                    ->label('Confirmado')
                    ->dateTime('d/m/Y H:i:s')
                    ->sortable()
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('error_message')
                    ->label('Erro')
                    ->placeholder('-')
                    ->wrap()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(WhatsAppCampaignRecipientStatus::options()),
            ])
            ->defaultSort('id')
            ->headerActions([])
            ->recordActions([])
            ->paginated([10, 25, 50, 100]);
    }

    public function isReadOnly(): bool
    {
        return true;
    }
}
