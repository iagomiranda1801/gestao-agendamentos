<?php

namespace App\Filament\App\Resources\WhatsAppInstances\Pages;

use App\Filament\App\Resources\WhatsAppInstances\WhatsAppInstanceResource;
use App\Models\Company;
use App\Services\WhatsApp\CompanyWhatsAppInstanceService;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Http\Client\RequestException;
use Illuminate\Database\Eloquent\Model;
use Throwable;

class CreateWhatsAppInstance extends CreateRecord
{
    protected static string $resource = WhatsAppInstanceResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        /** @var Company $company */
        $company = Filament::getTenant();

        return app(CompanyWhatsAppInstanceService::class)->create($company, $data);
    }

    protected function afterCreate(): void
    {
        /** @var Company $company */
        $company = Filament::getTenant();
        $instance = $this->getRecord();

        try {
            app(CompanyWhatsAppInstanceService::class)->createOrRefreshQrCode($company, $instance);

            Notification::make()
                ->success()
                ->title('Instância criada na Evolution')
                ->body('O QR code já está disponível para conectar o WhatsApp.')
                ->send();
        } catch (Throwable $exception) {
            report($exception);

            $instance->update(['status' => 'error']);

            Notification::make()
                ->danger()
                ->title('Instância salva, mas a Evolution recusou a criação')
                ->body(self::evolutionErrorMessage($exception))
                ->send();
        }
    }

    protected static function evolutionErrorMessage(Throwable $exception): string
    {
        if ($exception instanceof RequestException && $exception->response->status() === 401) {
            return 'A chave atual permite consultar a Evolution, mas não tem permissão para criar instâncias. Configure em EVOLUTION_API_KEY a chave global AUTHENTICATION_API_KEY da Evolution.';
        }

        return 'Revise a URL e a chave da Evolution e tente novamente em “Gerar QR”.';
    }
}
