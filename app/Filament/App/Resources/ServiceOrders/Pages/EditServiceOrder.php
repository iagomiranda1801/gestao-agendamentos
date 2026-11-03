<?php

namespace App\Filament\App\Resources\ServiceOrders\Pages;

use App\Enums\CompanyPermission;
use App\Enums\CompanyRole;
use App\Enums\PaymentMethod;
use App\Enums\ProductType;
use App\Filament\App\Resources\Pages\EditRecord;
use App\Filament\App\Resources\ServiceOrders\ServiceOrderResource;
use App\Models\FinancialAccount;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceOrder;
use App\Services\Company\CompanyPermissionService;
use App\Services\ServiceOrders\ServiceOrderMessagingService;
use App\Services\ServiceOrders\ServiceOrderService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class EditServiceOrder extends EditRecord
{
    protected static string $resource = ServiceOrderResource::class;

    public function getTitle(): string
    {
        return 'OS #'.$this->record->number.' · '.$this->record->device;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        app(ServiceOrderService::class)->authorize($record->company, auth()->user());
        $record->update(Arr::only($data, ['notes', 'warranty']));

        return $record;
    }

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                Action::make('quote')->label('Fazer orçamento')->visible(fn () => ! in_array($this->record->status, ['ready', 'delivered', 'returned', 'reversed']))
                    ->schema([
                        Repeater::make('items')->label('Serviços e peças')->minItems(1)->defaultItems(1)->schema([
                            Select::make('item_type')->label('Tipo')->options(['custom' => 'Mão de obra avulsa', 'service' => 'Serviço do catálogo', 'product' => 'Peça / produto'])->default('custom')->required()->live(),
                            Select::make('product_id')->label('Peça')->options(fn () => Product::where('company_id', Filament::getTenant()->id)->where('is_active', true)->whereIn('type', [ProductType::Sale->value, ProductType::Consumable->value])->pluck('name', 'id'))->searchable()->visible(fn (Get $get) => $get('item_type') === 'product')->required(fn (Get $get) => $get('item_type') === 'product'),
                            Select::make('service_id')->label('Serviço')->options(fn () => Service::where('company_id', Filament::getTenant()->id)->pluck('name', 'id'))->searchable()->visible(fn (Get $get) => $get('item_type') === 'service')->required(fn (Get $get) => $get('item_type') === 'service'),
                            TextInput::make('name')->label('Descrição da mão de obra')->maxLength(255)->visible(fn (Get $get) => $get('item_type') === 'custom')->required(fn (Get $get) => $get('item_type') === 'custom'),
                            TextInput::make('quantity')->label('Quantidade')->numeric()->default(1)->minValue(0.0001)->required(),
                            TextInput::make('unit_price')->label('Valor unitário')->prefix('R$')->numeric()->minValue(0.01)->required(),
                        ])->columns(2)->helperText('Adicione todas as peças utilizadas separadamente. O serviço do catálogo representa apenas a mão de obra.'),
                        TextInput::make('deadline')->label('Prazo previsto')->placeholder('Ex.: 2 dias úteis após aprovação')->required()->maxLength(255),
                        DatePicker::make('valid_until')->label('Orçamento válido até')->default(fn () => now(Filament::getTenant()->timezone)->addDays(7))->minDate(fn () => now(Filament::getTenant()->timezone)->toDateString())->required(),
                        Textarea::make('conditions')->label('Condições')->maxLength(5000),
                    ])->action(function (array $data) {
                        app(ServiceOrderService::class)->quote($this->record, auth()->user(), $data);
                        $this->done('Orçamento criado. Envie ao cliente pelo WhatsApp.');
                    }),
                Action::make('approveInPerson')->label('Registrar aprovação presencial')
                    ->visible(fn () => $this->record->latestQuote() !== null && in_array($this->record->status, ['analysis', 'awaiting_approval']))
                    ->requiresConfirmation()->modalDescription('Confirme somente se o cliente aprovou os itens e valores desta versão. A aprovação ficará registrada com seu usuário.')
                    ->action(function () {
                        $quote = app(ServiceOrderService::class)->publish($this->record, auth()->user());
                        app(ServiceOrderService::class)->decide($quote, 'approved', auth()->user());
                        $this->done('Aprovação presencial registrada.');
                    }),
            ])->label('Orçamento')->button(),
            ActionGroup::make(array_map(fn ($kind, $label) => Action::make('send_'.$kind)->label($label)
                ->visible(fn () => match ($kind) {
                    'quote' => $this->record->latestQuote() !== null && ! in_array($this->record->status, ['ready', 'delivered', 'returned', 'reversed']),
                    'ready' => in_array($this->record->status, ['ready', 'delivered']),
                    'receipt' => $this->record->status === 'delivered',
                    default => true,
                })
                ->schema([Textarea::make('body')->label('Mensagem para o cliente')->default(fn () => app(ServiceOrderMessagingService::class)->preview($this->record, $kind))->required()->maxLength(8000)->rows(9)])
                ->action(function (array $data) use ($kind) {
                    app(ServiceOrderMessagingService::class)->queue($this->record, auth()->user(), $kind, $data['body']);
                    $this->done('Consulte o resultado do envio no histórico de mensagens.');
                }), ['order', 'quote', 'ready', 'receipt'], ['Enviar OS', 'Enviar orçamento', 'Avisar que está pronto', 'Enviar comprovante']))->label('WhatsApp')->button(),
            Action::make('stage')->label('Atualizar conserto')->visible(fn () => ! in_array($this->record->status, ['delivered', 'returned', 'reversed']))->schema([
                Select::make('status')->label('Próxima etapa')->options(fn () => $this->nextStages())->required(),
                Toggle::make('confirmed_parts')->label('Conferi que todas as peças utilizadas e valores correspondem ao orçamento aprovado')->required()->accepted()->visible(fn (Get $get) => $get('status') === 'ready'),
                Toggle::make('allow_outstanding')->label('Autorizar entrega com saldo pendente (somente administrador ou gerente)')->visible(fn (Get $get) => $get('status') === 'delivered'),
            ])->action(function (array $data) {
                app(ServiceOrderService::class)->transition($this->record, auth()->user(), $data['status'], (bool) ($data['allow_outstanding'] ?? false));
                $this->done('Etapa atualizada.');
            }),
            Action::make('payment')->label('Registrar pagamento')->visible(fn () => $this->record->sale_id !== null && app(CompanyPermissionService::class)->allows(auth()->user(), $this->record->company, CompanyPermission::ViewFinancial))
                ->schema([
                    Hidden::make('request_key')->default(fn () => (string) Str::uuid()),
                    TextInput::make('amount')->label('Valor recebido')->prefix('R$')->numeric()->minValue(0.01)->required()->default(fn () => $this->record->sale->receivable->outstanding_amount),
                    Select::make('method')->label('Forma de pagamento')->options(PaymentMethod::options())->required(),
                    Select::make('financial_account_id')->label('Conta de recebimento')->options(fn () => FinancialAccount::where('company_id', $this->record->company_id)->where('is_active', true)->pluck('name', 'id'))->required(),
                ])->action(function (array $data) {
                    app(ServiceOrderService::class)->payment($this->record, auth()->user(), $data);
                    $this->done('Pagamento registrado.');
                }),
            ActionGroup::make([
                Action::make('reverse')->label('Estornar conserto')->color('danger')->visible(fn () => $this->record->sale_id && $this->record->status !== 'reversed' && auth()->user()->hasActiveRoleInCompany($this->record->company, CompanyRole::CompanyAdmin, CompanyRole::Manager))
                    ->modalDescription('Estorna os lançamentos financeiros e devolve as peças ao estoque. A devolução efetiva de dinheiro ao cliente deve ser conferida pela loja. O histórico será preservado.')
                    ->schema([Textarea::make('reason')->label('Motivo do estorno')->required()->maxLength(2000)])
                    ->action(function (array $data) {
                        app(ServiceOrderService::class)->reverse($this->record, auth()->user(), $data['reason']);
                        $this->done('Conserto estornado. Histórico preservado.');
                    }),
                Action::make('photos')->label('Adicionar fotos internas')->schema([
                    FileUpload::make('photos')->label('Fotos do aparelho')->image()->multiple()->maxFiles(10)->maxSize(5120)->storeFiles(false)->required(),
                ])->action(function (array $data) {
                    app(ServiceOrderService::class)->authorize($this->record->company, auth()->user());
                    foreach ($data['photos'] as $photo) {
                        abort_unless($photo instanceof TemporaryUploadedFile, 422);
                        validator(['photo' => $photo], ['photo' => 'required|image|max:5120'])->validate();
                        $path = $photo->store('service-orders/'.$this->record->company_id.'/'.$this->record->id, 'local');
                        $this->record->photos()->create(['path' => $path]);
                    }
                    $this->done('Fotos adicionadas.');
                }),
                Action::make('retry')->label('Tentar envio novamente')->schema([
                    Select::make('message_id')->label('Mensagem')->options(fn () => $this->retryableMessages()->get()->mapWithKeys(fn ($message) => [$message->id => '#'.$message->id.' · '.$message->kind.' · '.$message->error]))->required(),
                    Toggle::make('checked')->label('Conferi a conversa e confirmo que é necessário reenviar')->accepted()->required(),
                ])->action(function (array $data) {
                    app(ServiceOrderService::class)->authorize($this->record->company, auth()->user());
                    app(ServiceOrderMessagingService::class)->retry($this->record, auth()->user(), (int) $data['message_id']);
                    $this->done('Nova tentativa solicitada.');
                }),
            ])->label('Mais ações'),
        ];
    }

    private function nextStages(): array
    {
        $keys = match ($this->record->status) {
            'analysis', 'awaiting_approval' => ['returned'], 'approved' => ['repairing', 'awaiting_part', 'returned'],
            'awaiting_part' => ['repairing', 'returned'], 'repairing' => ['ready', 'awaiting_part'],
            'ready' => ['delivered'], default => [],
        };

        return array_intersect_key(ServiceOrder::STATUSES, array_flip($keys));
    }

    private function retryableMessages(): HasMany
    {
        return $this->record->messages()->where(function ($query) {
            $query->whereIn('status', ['failed', 'uncertain'])->orWhere(function ($query) {
                $query->whereIn('status', ['queued', 'sending'])->where('updated_at', '<', now()->subMinutes(5));
            });
        });
    }

    private function done(string $message): void
    {
        $this->record->refresh();
        Notification::make()->success()->title($message)->send();
    }
}
