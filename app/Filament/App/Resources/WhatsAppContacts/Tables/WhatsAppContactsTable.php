<?php

namespace App\Filament\App\Resources\WhatsAppContacts\Tables;

use App\Models\Company;
use App\Models\CompanyWhatsAppInstance;
use App\Models\WhatsAppContact;
use App\Services\WhatsApp\WhatsAppContactService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

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
            ->filters([
                SelectFilter::make('company_whatsapp_instance_id')
                    ->label('Instância')
                    ->options(fn (): array => CompanyWhatsAppInstance::query()
                        ->where('company_id', Filament::getTenant()?->getKey())
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all()),
                TernaryFilter::make('imported_as_client_at')
                    ->label('Importado como cliente')
                    ->nullable()
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNotNull('imported_as_client_at'),
                        false: fn (Builder $query): Builder => $query->whereNull('imported_as_client_at'),
                        blank: fn (Builder $query): Builder => $query,
                    ),
            ])
            ->recordActions([
                Action::make('import')
                    ->label('Importar como cliente')
                    ->icon('heroicon-o-user-plus')
                    ->visible(fn (WhatsAppContact $record): bool => $record->imported_as_client_at === null)
                    ->requiresConfirmation()
                    ->modalHeading('Importar contato como cliente')
                    ->modalDescription('Cria ou vincula o cliente. Esta ação não altera a autorização para campanhas.')
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
                Action::make('importAndAuthorizeMarketing')
                    ->label('Autorizar campanhas')
                    ->icon('heroicon-o-megaphone')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Autorizar campanhas')
                    ->modalDescription('O contato será importado ou vinculado, se necessário. Use somente quando ele autorizou receber mensagens promocionais no WhatsApp.')
                    ->form([
                        Checkbox::make('marketing_consent_confirmed')
                            ->label('Confirmo que este contato autorizou receber campanhas no WhatsApp.')
                            ->accepted()
                            ->required(),
                    ])
                    ->action(function (WhatsAppContact $record): void {
                        /** @var Company $company */
                        $company = Filament::getTenant();

                        $result = app(WhatsAppContactService::class)->importAsClients(
                            $company,
                            new Collection([$record]),
                            true,
                        );

                        Notification::make()
                            ->success()
                            ->title($result['created'] > 0 ? 'Cliente criado e autorizado' : 'Contato autorizado para campanhas')
                            ->send();
                    }),
                DeleteAction::make()
                    ->label('Excluir contato')
                    ->modalHeading('Excluir contato WhatsApp')
                    ->modalDescription('Isso remove apenas o contato sincronizado do WhatsApp. O cliente vinculado, se existir, será mantido. Você pode sincronizar novamente depois.'),
            ])
            ->bulkActions([
                BulkAction::make('importSelected')
                    ->label('Importar selecionados')
                    ->icon('heroicon-o-user-plus')
                    ->requiresConfirmation()
                    ->modalHeading('Importar contatos selecionados')
                    ->modalDescription('Cria ou vincula os clientes. Esta ação não altera a autorização para campanhas.')
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
                BulkAction::make('importAndAuthorizeSelected')
                    ->label('Autorizar campanhas')
                    ->icon('heroicon-o-megaphone')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Autorizar campanhas')
                    ->modalDescription('Os contatos serão importados ou vinculados, se necessário. Use somente quando todos os selecionados autorizaram receber mensagens promocionais no WhatsApp.')
                    ->form([
                        Checkbox::make('marketing_consent_confirmed')
                            ->label('Confirmo que todos os contatos selecionados autorizaram receber campanhas no WhatsApp.')
                            ->accepted()
                            ->required(),
                    ])
                    ->action(function (Collection $records): void {
                        /** @var Company $company */
                        $company = Filament::getTenant();

                        $result = app(WhatsAppContactService::class)->importAsClients($company, $records, true);

                        Notification::make()
                            ->success()
                            ->title('Importação e autorização concluídas')
                            ->body("{$result['created']} cliente(s) criado(s) e {$result['linked']} contato(s) vinculado(s) e autorizados.")
                            ->send();
                    }),
                DeleteBulkAction::make()
                    ->label('Excluir selecionados')
                    ->modalHeading('Excluir contatos selecionados')
                    ->modalDescription('Isso remove apenas os contatos sincronizados do WhatsApp. Clientes vinculados serão mantidos. Você pode sincronizar novamente depois.'),
            ])
            ->searchable();
    }
}
