<?php

namespace App\Filament\App\Resources\WhatsAppInstances\Pages;

use App\Filament\App\Resources\WhatsAppInstances\WhatsAppInstanceResource;
use App\Models\Company;
use App\Services\WhatsApp\CompanyWhatsAppInstanceService;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditWhatsAppInstance extends EditRecord
{
    protected static string $resource = WhatsAppInstanceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->label('Excluir')
                ->requiresConfirmation()
                ->modalHeading('Excluir conexão do WhatsApp?')
                ->modalDescription('A instância será removida da Evolution e os contatos importados desta conexão serão excluídos. Essa ação não pode ser desfeita.')
                ->using(function (Model $record): void {
                    /** @var Company $company */
                    $company = Filament::getTenant();

                    app(CompanyWhatsAppInstanceService::class)->delete($company, $record);
                }),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Company $company */
        $company = Filament::getTenant();

        return app(CompanyWhatsAppInstanceService::class)->update($company, $record, $data);
    }
}
