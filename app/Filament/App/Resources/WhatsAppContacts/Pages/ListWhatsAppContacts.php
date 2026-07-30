<?php

namespace App\Filament\App\Resources\WhatsAppContacts\Pages;

use App\Filament\App\Resources\WhatsAppContacts\WhatsAppContactResource;
use App\Models\Company;
use App\Models\CompanyWhatsAppInstance;
use App\Services\WhatsApp\WhatsAppContactService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
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
        ];
    }
}
