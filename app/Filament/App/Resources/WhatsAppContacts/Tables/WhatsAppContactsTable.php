<?php

namespace App\Filament\App\Resources\WhatsAppContacts\Tables;

use App\Models\Client;
use App\Models\Company;
use App\Models\WhatsAppContact;
use App\Services\WhatsApp\WhatsAppContactService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Builder;

class WhatsAppContactsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Nome')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('phone')
                    ->label('Telefone')
                    ->searchable(['phone', 'phone_normalized'])
                    ->sortable(),
                TextColumn::make('instance.name')
                    ->label('Instância')
                    ->sortable(),
                TextColumn::make('client.name')
                    ->label('Cliente')
                    ->placeholder('Ainda não importado')
                    ->searchable(),
                IconColumn::make('imported_as_client_at')
                    ->label('Importado')
                    ->boolean()
                    ->state(fn (WhatsAppContact $record): bool => $record->imported_as_client_at !== null)
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-minus-circle')
                    ->trueColor('success')
                    ->falseColor('gray'),
                TextColumn::make('last_synced_at')
                    ->label('Sincronizado em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('name')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereRaw('LENGTH(phone_normalized) >= 10'))
            ->recordActions([
                Action::make('import')
                    ->label('Importar como cliente')
                    ->icon('heroicon-o-user-plus')
                    ->visible(fn (WhatsAppContact $record): bool => $record->imported_as_client_at === null)
                    ->requiresConfirmation()
                    ->modalHeading('Importar contato como cliente')
                    ->modalDescription('O cliente será criado ou vinculado sem autorização automática para campanhas WhatsApp.')
                    ->action(function (WhatsAppContact $record): void {
                        /** @var Company $company */
                        $company = Filament::getTenant();

                        $result = app(WhatsAppContactService::class)->importAsClients(
                            $company,
                            new Collection([$record]),
                        );

                        Notification::make()
                            ->success()
                            ->title($result['created'] > 0 ? 'Cliente criado' : 'Contato vinculado')
                            ->send();
                    }),
            ])
            ->bulkActions([
                BulkAction::make('importSelected')
                    ->label('Importar selecionados')
                    ->icon('heroicon-o-user-plus')
                    ->requiresConfirmation()
                    ->modalHeading('Importar contatos selecionados')
                    ->modalDescription('Os contatos serão criados ou vinculados sem autorização automática para campanhas WhatsApp.')
                    ->action(function (Collection $records): void {
                        /** @var Company $company */
                        $company = Filament::getTenant();

                        $result = app(WhatsAppContactService::class)->importAsClients($company, $records);

                        Notification::make()
                            ->success()
                            ->title('Importação concluída')
                            ->body("{$result['created']} cliente(s) criado(s) e {$result['linked']} contato(s) vinculado(s).")
                            ->send();
                    }),
            ])
            ->searchable();
    }
}
