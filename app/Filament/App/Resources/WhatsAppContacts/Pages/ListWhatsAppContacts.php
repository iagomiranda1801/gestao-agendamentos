<?php

namespace App\Filament\App\Resources\WhatsAppContacts\Pages;

use App\Filament\App\Resources\WhatsAppContacts\WhatsAppContactResource;
use App\Models\Company;
use App\Models\CompanyWhatsAppInstance;
use App\Services\WhatsApp\WhatsAppContactCleanupService;
use App\Services\WhatsApp\WhatsAppContactService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Validation\ValidationException;

class ListWhatsAppContacts extends ListRecords
{
    protected static string $resource = WhatsAppContactResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('syncContacts')
                ->label('Sincronizar contatos')
                ->icon('heroicon-o-arrow-path')
                ->form([
                    Select::make('instance_id')
                        ->label('Instância')
                        ->options(fn (): array => CompanyWhatsAppInstance::query()
                            ->where('company_id', Filament::getTenant()?->getKey())
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->required()
                        ->native(false),
                ])
                ->action(function (array $data): void {
                    /** @var Company $company */
                    $company = Filament::getTenant();
                    $instance = CompanyWhatsAppInstance::query()
                        ->where('company_id', $company->getKey())
                        ->findOrFail($data['instance_id']);

                    try {
                        $count = app(WhatsAppContactService::class)->sync($company, $instance);

                        Notification::make()
                            ->success()
                            ->title('Contatos sincronizados')
                            ->body("{$count} contato(s) foram atualizados.")
                            ->send();
                    } catch (ValidationException $exception) {
                        Notification::make()
                            ->warning()
                            ->title('Não foi possível sincronizar')
                            ->body($exception->getMessage())
                            ->send();
                    }
                }),
            Action::make('cleanupContacts')
                ->label('Limpar contatos')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Limpar contatos WhatsApp')
                ->modalDescription('Isso remove apenas contatos sincronizados/importados do WhatsApp. Clientes já cadastrados não serão apagados.')
                ->form([
                    Select::make('instance_id')
                        ->label('Instância')
                        ->placeholder('Todas as instâncias')
                        ->options(fn (): array => CompanyWhatsAppInstance::query()
                            ->where('company_id', Filament::getTenant()?->getKey())
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->native(false),
                    Select::make('import_status')
                        ->label('Importação')
                        ->options([
                            'not_imported' => 'Somente não importados como cliente',
                            'imported' => 'Somente importados como cliente',
                            'all' => 'Todos os contatos WhatsApp',
                        ])
                        ->default('not_imported')
                        ->required()
                        ->native(false),
                    DatePicker::make('synced_before')
                        ->label('Sincronizados até')
                        ->helperText('Opcional. Use para limpar contatos antigos.'),
                    TextInput::make('confirmation')
                        ->label('Confirmação')
                        ->helperText('Digite LIMPAR para confirmar.')
                        ->required()
                        ->rules(['in:LIMPAR']),
                ])
                ->action(function (array $data): void {
                    /** @var Company $company */
                    $company = Filament::getTenant();

                    $deleted = app(WhatsAppContactCleanupService::class)->deleteByFilters($company, $data);

                    Notification::make()
                        ->success()
                        ->title('Contatos removidos')
                        ->body("{$deleted} contato(s) WhatsApp removido(s). Clientes vinculados foram mantidos.")
                        ->send();
                }),
        ];
    }
}
