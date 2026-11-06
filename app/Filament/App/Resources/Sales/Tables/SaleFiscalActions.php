<?php

namespace App\Filament\App\Resources\Sales\Tables;

use App\Enums\SaleStatus;
use App\Exceptions\AsaasApiException;
use App\Models\Sale;
use App\Models\SaleFiscalDocument;
use App\Models\SaleItem;
use App\Services\Asaas\SaleFiscalService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class SaleFiscalActions
{
    public static function make(): ActionGroup
    {
        return ActionGroup::make([
            self::issue(SaleFiscalDocument::SERVICE),
            self::issue(SaleFiscalDocument::PRODUCT),
            Action::make('continueProductInvoice')
                ->label('Continuar emissão da NF-e de teste')
                ->authorize('view')
                ->visible(fn (Sale $record): bool => $record->status !== SaleStatus::Cancelled
                    && $record->fiscalDocuments->contains(fn (SaleFiscalDocument $document): bool => $document->type === SaleFiscalDocument::PRODUCT
                        && $document->status === 'order_pending'))
                ->requiresConfirmation()
                ->action(function (Sale $record, SaleFiscalService $fiscal): void {
                    Gate::authorize('view', $record);
                    abort_if($record->status === SaleStatus::Cancelled, 403);
                    $document = $record->fiscalDocuments()->where('type', SaleFiscalDocument::PRODUCT)
                        ->where('status', 'order_pending')->firstOrFail();

                    try {
                        $fiscal->continueProductInvoice($document, $record->company);
                        $document->refresh();
                        $notification = Notification::make()->title('NF-e — '.$document->statusLabel());
                        if (filled($document->error)) {
                            $notification->warning()->body($document->error);
                        }
                        $notification->send();
                    } catch (ValidationException $exception) {
                        Notification::make()->warning()->title('Confira a configuração fiscal')
                            ->body(self::message($exception))->send();
                    }
                }),
            Action::make('fiscalDocuments')
                ->label('Consultar notas de teste')
                ->authorize('view')
                ->visible(fn (Sale $record): bool => $record->fiscalDocuments->isNotEmpty())
                ->modalHeading('Notas fiscais de teste')
                ->modalContent(fn (Sale $record) => view('filament.sales.fiscal-documents', [
                    'documents' => $record->fiscalDocuments()->get(),
                ]))
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Fechar'),
            Action::make('refreshFiscalDocuments')
                ->label('Atualizar notas de teste')
                ->authorize('view')
                ->visible(fn (Sale $record): bool => $record->fiscalDocuments->isNotEmpty())
                ->action(function (Sale $record, SaleFiscalService $fiscal): void {
                    Gate::authorize('view', $record);
                    $errors = [];

                    foreach ($record->fiscalDocuments()->get() as $document) {
                        if (in_array($document->status, ['rejected', 'cancelled'], true)) {
                            continue;
                        }

                        try {
                            $fiscal->refresh($document, $record->company);
                        } catch (ValidationException|AsaasApiException|ConnectionException $exception) {
                            $errors[] = $document->typeLabel().' — '.self::message($exception);
                        }
                    }

                    $notification = Notification::make()->title($errors === []
                        ? 'Consulta concluída. Confira o status nas notas de teste.'
                        : 'Algumas notas não puderam ser consultadas.');
                    if ($errors !== []) {
                        $notification->warning()->body(implode(' ', $errors));
                    }
                    $notification->send();
                }),
        ]);
    }

    private static function issue(string $type): Action
    {
        $isProduct = $type === SaleFiscalDocument::PRODUCT;
        $label = $isProduct ? 'NF-e' : 'NFS-e';

        return Action::make('issue_'.$type)
            ->label('Emitir '.$label.' de teste')
            ->authorize('view')
            ->visible(function (Sale $record, SaleFiscalService $fiscal) use ($type): bool {
                $document = $record->fiscalDocuments->firstWhere('type', $type);

                return ! in_array($record->status, [SaleStatus::Draft, SaleStatus::Cancelled], true)
                    && ($document === null || $document->status === 'rejected')
                    && $fiscal->items($record, $type)->isNotEmpty();
            })
            ->modalHeading('Emitir '.$label.' em Sandbox')
            ->modalDescription(function (Sale $record, SaleFiscalService $fiscal) use ($type): string {
                $amount = number_format((float) $fiscal->amount($record, $type), 2, ',', '.');
                $items = $fiscal->items($record, $type)->pluck('name_snapshot')->implode(', ');
                $description = "Valor da nota: R$ {$amount}. Itens: {$items}. Esta emissão é de teste, sem validade fiscal.";

                return $type === SaleFiscalDocument::SERVICE
                    ? $description.' Use esta nota para serviços com a mesma classificação fiscal.'
                    : $description;
            })
            ->modalSubmitActionLabel('Enviar em Sandbox')
            ->schema(fn (Sale $record, SaleFiscalService $fiscal): array => $isProduct
                ? self::productFields($fiscal->items($record, $type))
                : self::serviceFields())
            ->action(function (Sale $record, array $data, SaleFiscalService $fiscal) use ($type, $label): void {
                Gate::authorize('view', $record);

                try {
                    $document = $fiscal->issue($record, $type, $data);
                    $notification = Notification::make()->title($label.' — '.$document->statusLabel());
                    if (filled($document->error)) {
                        $notification->warning()->body($document->error);
                    }
                    $notification->send();
                } catch (ValidationException $exception) {
                    Notification::make()->warning()->title('Confira os dados da venda')
                        ->body(self::message($exception))->send();
                }
            });
    }

    private static function serviceFields(): array
    {
        $fields = [
            TextInput::make('serviceCode')->label('Código fiscal do serviço')->required()->maxLength(30),
            TextInput::make('serviceName')->label('Nome fiscal do serviço')->required()->maxLength(150),
            Toggle::make('retainIss')->label('Reter ISS'),
        ];

        foreach (['iss' => 'ISS', 'pis' => 'PIS', 'cofins' => 'COFINS', 'csll' => 'CSLL', 'inss' => 'INSS', 'ir' => 'IR'] as $key => $label) {
            $fields[] = TextInput::make($key)->label($label.' (%)')->numeric()->minValue(0)->maxValue(100)
                ->required()->helperText('Informe 0 se o tributo não se aplicar.');
        }

        foreach ([
            'federalServiceTaxCode' => 'Código de Tributação Nacional',
            'nbsCode' => 'Código NBS',
            'taxSituationCode' => 'CST de IBS/CBS',
            'taxClassificationCode' => 'Classificação tributária de IBS/CBS',
            'operationIndicatorCode' => 'Indicador de operação',
        ] as $key => $label) {
            $fields[] = TextInput::make($key)->label($label)->maxLength(30);
        }

        $fields[] = TextInput::make('postalCode')->label('CEP do cliente')->regex('/^\d{5}-?\d{3}$/')
            ->helperText('Preencha o endereço se o cliente ainda não estiver cadastrado no Asaas.');
        $fields[] = TextInput::make('address')->label('Rua')->maxLength(150);
        $fields[] = TextInput::make('addressNumber')->label('Número')->maxLength(20);
        $fields[] = TextInput::make('province')->label('Bairro')->maxLength(100);

        return $fields;
    }

    private static function productFields(iterable $items): array
    {
        $fields = [
            self::baseId('baseCustomer', 'Código do cliente no Base ERP'),
            self::baseId('baseBank', 'Código da conta de recebimento no Base ERP'),
            Select::make('basePaymentType')->label('Forma de pagamento')->required()->options([
                'PIX' => 'Pix',
                'BOLETO' => 'Boleto',
                'CREDIT_CARD' => 'Cartão de crédito',
                'DEBIT_CARD' => 'Cartão de débito',
                'DEPOSIT' => 'Depósito',
                'TRANSFER' => 'Transferência',
                'UNDEFINED' => 'Outra',
            ])->in(['PIX', 'BOLETO', 'CREDIT_CARD', 'DEBIT_CARD', 'DEPOSIT', 'TRANSFER', 'UNDEFINED']),
            DatePicker::make('dueDate')->label('Vencimento no Base ERP')->required()->default(today()),
        ];

        /** @var SaleItem $item */
        foreach ($items as $item) {
            $fields[] = self::baseId('product_'.$item->getKey(), 'Código no Base ERP · '.$item->name_snapshot);
            $fields[] = TextInput::make('tax_'.$item->getKey())->label('Classificação IBS/CBS · '.$item->name_snapshot)
                ->maxLength(6)->regex('/^\d{6}$/')
                ->helperText('Preencha quando a classificação da venda for diferente da cadastrada no Base.');
        }

        return $fields;
    }

    private static function baseId(string $field, string $label): TextInput
    {
        return TextInput::make($field)->label($label)->required()->integer()->minValue(1)->maxValue(PHP_INT_MAX);
    }

    private static function message(ValidationException|AsaasApiException|ConnectionException $exception): string
    {
        return $exception instanceof ConnectionException
            ? 'O emissor não respondeu. Confira a conexão e tente consultar novamente.'
            : $exception->getMessage();
    }
}
