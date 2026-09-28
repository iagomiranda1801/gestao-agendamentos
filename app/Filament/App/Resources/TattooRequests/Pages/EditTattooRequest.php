<?php

namespace App\Filament\App\Resources\TattooRequests\Pages;

use App\Filament\App\Resources\Appointments\AppointmentResource;
use App\Filament\App\Resources\Pages\EditRecord;
use App\Filament\App\Resources\TattooRequests\TattooRequestResource;
use App\Jobs\SendTattooQuoteWhatsAppJob;
use App\Models\Client;
use App\Models\Professional;
use App\Models\TattooQuote;
use App\Services\Tattoo\TattooImageService;
use App\Services\Tattoo\TattooQuoteService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;

class EditTattooRequest extends EditRecord
{
    protected static string $resource = TattooRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('add_image')->label('Adicionar foto')->schema([
                FileUpload::make('image')->label('Foto')->storeFiles(false)->image()->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])->maxSize(10240)->required(),
            ])->action(function (array $data): void {
                app(TattooImageService::class)->upload($this->getRecord(), $data['image']);
                $this->getRecord()->unsetRelation('images');
                Notification::make()->success()->title('Foto adicionada.')->send();
            }),
            Action::make('quote')->label('Fazer orçamento')->schema([
                Select::make('price_type')->label('Preço')->options(['fixed' => 'Valor fechado', 'range' => 'Faixa estimada'])->default('fixed')->required(),
                TextInput::make('amount_min')->label('Valor ou mínimo (R$)')->numeric()->minValue(0.01)->required(),
                TextInput::make('amount_max')->label('Máximo (R$), se for faixa')->numeric(),
                TextInput::make('sessions')->label('Sessões previstas')->numeric()->default(1)->required(),
                TextInput::make('minutes_per_session')->label('Minutos por sessão')->numeric(),
                TextInput::make('deposit_amount')->label('Sinal (R$)')->numeric(),
                DatePicker::make('valid_until')->label('Válido até'),
                Textarea::make('conditions')->label('Condições e observações'),
            ])->action(function (array $data): void {
                app(TattooQuoteService::class)->create($this->getRecord(), auth()->user(), $data);
                Notification::make()->success()->title('Orçamento salvo. Confira e envie pelo WhatsApp.')->send();
            }),
            Action::make('send_quote')->label('Enviar orçamento')->requiresConfirmation()
                ->visible(fn () => $this->getRecord()->quotes()->latest('version')->first()?->sent_at === null && $this->getRecord()->quotes()->exists())
                ->action(function (): void {
                    /** @var TattooQuote $quote */
                    $quote = $this->getRecord()->quotes()->latest('version')->firstOrFail();
                    SendTattooQuoteWhatsAppJob::dispatch($quote->getKey());
                    Notification::make()->success()->title('Envio do orçamento iniciado.')->send();
                }),
            Action::make('accept')->label('Registrar aceite')->requiresConfirmation()
                ->visible(fn () => $this->getRecord()->status === 'quote_sent')
                ->action(function (): void {
                    $this->getRecord()->update(['status' => 'accepted']);
                    $this->getRecord()->quotes()->latest('version')->first()?->update(['accepted_at' => now()]);
                }),
            Action::make('waiting_client')->label('Aguardar detalhes')
                ->visible(fn () => in_array($this->getRecord()->status, ['awaiting_review', 'in_review'], true))
                ->schema([Textarea::make('note')->label('Detalhes solicitados')->required()->maxLength(3000)])
                ->action(function (array $data): void {
                    $record = $this->getRecord();
                    $record->update([
                        'status' => 'waiting_client',
                        'notes' => trim(($record->notes ? $record->notes."\n\n" : '').'Solicitado ao cliente: '.$data['note']),
                    ]);
                }),
            Action::make('resume')->label('Retomar análise')
                ->visible(fn () => $this->getRecord()->status === 'waiting_client')
                ->action(fn () => $this->getRecord()->update(['status' => 'in_review'])),
            Action::make('decline')->label('Marcar recusado')->color('danger')->requiresConfirmation()
                ->visible(fn () => in_array($this->getRecord()->status, ['quote_sent', 'accepted'], true))
                ->action(fn () => $this->getRecord()->update(['status' => 'declined'])),
            Action::make('schedule')->label('Criar agendamento')
                ->visible(fn () => $this->getRecord()->status === 'accepted')
                ->url(fn () => AppointmentResource::getUrl('create', [
                    'tattoo_request' => $this->getRecord()->getKey(),
                ])),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $companyId = $record->company_id;
        if (! TattooRequestResource::canManageRequests()) {
            unset($data['client_id'], $data['professional_id']);
        }
        if (isset($data['client_id'])) {
            Client::query()->where('company_id', $companyId)->findOrFail($data['client_id']);
        }
        if (! empty($data['professional_id'])) {
            Professional::query()->where('company_id', $companyId)->findOrFail($data['professional_id']);
        }
        $record->update($data);

        return $record;
    }
}
