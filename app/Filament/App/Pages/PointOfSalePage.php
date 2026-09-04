<?php

namespace App\Filament\App\Pages;

use App\DataTransferObjects\Financial\PaymentData;
use App\Enums\CompanyModule;
use App\Enums\PaymentMethod;
use App\Enums\SaleItemType;
use App\Enums\SaleOrigin;
use App\Filament\App\Concerns\RequiresCompanyModule;
use App\Filament\App\Support\QuickCreateFields;
use App\Filament\Concerns\NotifiesValidationErrors;
use App\Models\Client;
use App\Models\Company;
use App\Models\FinancialAccount;
use App\Models\Product;
use App\Models\Service;
use App\Policies\SalePolicy;
use App\Services\Sales\SaleService;
use BackedEnum;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;
use UnitEnum;

class PointOfSalePage extends Page
{
    use NotifiesValidationErrors;
    use RequiresCompanyModule;

    protected static ?string $slug = 'pdv';

    protected static ?string $navigationLabel = 'PDV';

    protected static ?string $title = 'PDV';

    protected static string|UnitEnum|null $navigationGroup = 'Vendas';

    protected static ?int $navigationSort = 30;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingCart;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        if (! static::tenantHasRequiredModule()) {
            return false;
        }

        $user = auth()->user();

        return $user !== null && (new SalePolicy)->create($user);
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->fillDefaultForm();
    }

    public function save(): void
    {
        $this->hasNotifiedValidationError = false;

        try {
            /** @var Company $company */
            $company = Filament::getTenant();
            $state = $this->form->getState();

            $payments = collect($state['payments'] ?? [])
                ->filter(fn (array $payment): bool => filled($payment['amount'] ?? null))
                ->map(fn (array $payment): PaymentData => new PaymentData(
                    amount: (string) $payment['amount'],
                    feeAmount: (string) ($payment['fee_amount'] ?? '0.00'),
                    method: PaymentMethod::from($payment['method']),
                    paidAt: Carbon::parse($payment['paid_at'] ?? now()),
                    financialAccountId: (int) $payment['financial_account_id'],
                    notes: $payment['notes'] ?? null,
                ))
                ->values()
                ->all();

            $sale = app(SaleService::class)->complete($company, auth()->user(), [
                'client_id' => $state['client_id'] ?? null,
                'origin' => SaleOrigin::Pos->value,
                'sold_at' => $state['sold_at'] ?? now(),
                'discount_amount' => $state['discount_amount'] ?? '0.00',
                'notes' => $state['notes'] ?? null,
                'items' => collect($state['items'] ?? [])
                    ->map(fn (array $item): array => [
                        'item_type' => $item['item_type'] ?? SaleItemType::Product->value,
                        'product_id' => $item['product_id'] ?? null,
                        'service_id' => $item['service_id'] ?? null,
                        'name' => $item['name'] ?? null,
                        'quantity' => $item['quantity'] ?? '1',
                        'unit_price' => $item['unit_price'] ?? '0.00',
                        'discount_amount' => $item['discount_amount'] ?? '0.00',
                    ])
                    ->all(),
                'payments' => $payments,
            ]);
        } catch (ValidationException $exception) {
            $this->notifyValidationException($exception);

            throw $exception;
        }

        Notification::make()
            ->success()
            ->title("Venda #{$sale->getKey()} finalizada")
            ->send();

        $this->fillDefaultForm();
    }

    public function defaultForm(Schema $schema): Schema
    {
        return $schema
            ->statePath('data');
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Venda')
                    ->schema([
                        QuickCreateFields::applyClientCreate(
                            Select::make('client_id')
                                ->label('Cliente')
                                ->options(fn (): array => Client::query()
                                    ->where('company_id', Filament::getTenant()?->getKey())
                                    ->active()
                                    ->orderBy('name')
                                    ->pluck('name', 'id')
                                    ->all())
                                ->searchable()
                                ->native(false),
                        ),
                        DateTimePicker::make('sold_at')
                            ->label('Data da venda')
                            ->default(now())
                            ->required()
                            ->native(false),
                        TextInput::make('discount_amount')
                            ->label('Desconto geral')
                            ->numeric()
                            ->prefix('R$')
                            ->step(0.01)
                            ->minValue(0)
                            ->default('0.00')
                            ->live(onBlur: true),
                    ])
                    ->columns(3),
                Section::make('Itens')
                    ->schema([
                        Repeater::make('items')
                            ->label('Itens')
                            ->schema([
                                Select::make('item_type')
                                    ->label('Tipo')
                                    ->options(self::itemTypeOptions())
                                    ->default(SaleItemType::Product->value)
                                    ->required()
                                    ->live()
                                    ->afterStateUpdated(function (Set $set): void {
                                        $set('product_id', null);
                                        $set('service_id', null);
                                        $set('name', null);
                                        $set('unit_price', '0.00');
                                    })
                                    ->native(false),
                                QuickCreateFields::applyProductCreate(
                                    Select::make('product_id')
                                        ->label('Produto')
                                        ->options(fn (): array => self::productOptions())
                                        ->searchable()
                                        ->required(fn (Get $get): bool => $get('item_type') === SaleItemType::Product->value)
                                        ->visible(fn (Get $get): bool => $get('item_type') === SaleItemType::Product->value)
                                        ->live()
                                        ->afterStateUpdated(function (?int $state, Set $set): void {
                                            if ($state === null) {
                                                return;
                                            }

                                            $product = Product::query()
                                                ->where('company_id', Filament::getTenant()?->getKey())
                                                ->find($state);

                                            if ($product !== null) {
                                                $set('unit_price', (string) $product->sale_price);
                                            }
                                        })
                                        ->native(false),
                                    fillUnitPrice: true,
                                ),
                                QuickCreateFields::applyServiceCreate(
                                    Select::make('service_id')
                                        ->label('Serviço')
                                        ->options(fn (): array => self::serviceOptions())
                                        ->searchable()
                                        ->required(fn (Get $get): bool => $get('item_type') === SaleItemType::Service->value)
                                        ->visible(fn (Get $get): bool => $get('item_type') === SaleItemType::Service->value)
                                        ->live()
                                        ->afterStateUpdated(function (?int $state, Set $set): void {
                                            if ($state === null) {
                                                return;
                                            }

                                            $service = Service::query()
                                                ->where('company_id', Filament::getTenant()?->getKey())
                                                ->find($state);

                                            if ($service !== null) {
                                                $set('unit_price', (string) $service->price);
                                            }
                                        })
                                        ->native(false),
                                    fillUnitPrice: true,
                                ),
                                TextInput::make('name')
                                    ->label('Descrição')
                                    ->required(fn (Get $get): bool => $get('item_type') === SaleItemType::Custom->value)
                                    ->visible(fn (Get $get): bool => $get('item_type') === SaleItemType::Custom->value)
                                    ->maxLength(255),
                                TextInput::make('quantity')
                                    ->label('Qtd.')
                                    ->numeric()
                                    ->step(0.0001)
                                    ->minValue(0.0001)
                                    ->default('1')
                                    ->required()
                                    ->live(onBlur: true),
                                TextInput::make('unit_price')
                                    ->label('Preço')
                                    ->numeric()
                                    ->prefix('R$')
                                    ->step(0.01)
                                    ->minValue(0)
                                    ->default('0.00')
                                    ->required()
                                    ->live(onBlur: true),
                                TextInput::make('discount_amount')
                                    ->label('Desc.')
                                    ->numeric()
                                    ->prefix('R$')
                                    ->step(0.01)
                                    ->minValue(0)
                                    ->default('0.00')
                                    ->live(onBlur: true),
                                Placeholder::make('line_total')
                                    ->label('Total')
                                    ->content(fn (Get $get): string => 'R$ '.self::formatMoney(
                                        self::lineTotal($get('quantity'), $get('unit_price'), $get('discount_amount')),
                                    )),
                            ])
                            ->columns(6)
                            ->defaultItems(1)
                            ->addActionLabel('Adicionar item')
                            ->columnSpanFull(),
                        Placeholder::make('items_total')
                            ->label('Subtotal dos itens')
                            ->content(fn (Get $get): string => 'R$ '.self::formatMoney(self::itemsTotal($get('items') ?? []))),
                        Placeholder::make('sale_total')
                            ->label('Total da venda')
                            ->content(fn (Get $get): string => 'R$ '.self::formatMoney(
                                max(0, self::itemsTotal($get('items') ?? []) - (float) ($get('discount_amount') ?? 0)),
                            )),
                    ])
                    ->columns(2),
                Section::make('Pagamentos')
                    ->schema([
                        Repeater::make('payments')
                            ->label('Pagamentos')
                            ->schema([
                                TextInput::make('amount')
                                    ->label('Valor')
                                    ->numeric()
                                    ->prefix('R$')
                                    ->step(0.01)
                                    ->minValue(0.01)
                                    ->required(),
                                TextInput::make('fee_amount')
                                    ->label('Taxa')
                                    ->numeric()
                                    ->prefix('R$')
                                    ->step(0.01)
                                    ->minValue(0)
                                    ->default('0.00'),
                                Select::make('method')
                                    ->label('Forma')
                                    ->options(PaymentMethod::options())
                                    ->default(PaymentMethod::Pix->value)
                                    ->required()
                                    ->native(false),
                                Select::make('financial_account_id')
                                    ->label('Conta')
                                    ->options(fn (): array => FinancialAccount::query()
                                        ->where('company_id', Filament::getTenant()?->getKey())
                                        ->where('is_active', true)
                                        ->orderBy('name')
                                        ->pluck('name', 'id')
                                        ->all())
                                    ->searchable()
                                    ->required()
                                    ->native(false),
                                DateTimePicker::make('paid_at')
                                    ->label('Pago em')
                                    ->default(now())
                                    ->required()
                                    ->native(false),
                                Textarea::make('notes')
                                    ->label('Obs.')
                                    ->rows(2),
                            ])
                            ->columns(3)
                            ->defaultItems(1)
                            ->addActionLabel('Adicionar pagamento')
                            ->columnSpanFull(),
                    ]),
                Textarea::make('notes')
                    ->label('Observações')
                    ->rows(3)
                    ->columnSpanFull(),
            ]);
    }

    /**
     * @return array<Action>
     */
    protected function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label('Finalizar venda')
                ->icon('heroicon-o-check-circle')
                ->submit('save'),
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([EmbeddedSchema::make('form')])
                    ->id('point-of-sale-form')
                    ->livewireSubmitHandler('save')
                    ->footer([
                        Actions::make($this->getFormActions()),
                    ]),
            ]);
    }

    protected static function requiredCompanyModule(): CompanyModule
    {
        return CompanyModule::Sales;
    }

    /**
     * @return array<string, string>
     */
    protected static function itemTypeOptions(): array
    {
        return [
            SaleItemType::Product->value => SaleItemType::Product->label(),
            SaleItemType::Service->value => SaleItemType::Service->label(),
            SaleItemType::Custom->value => SaleItemType::Custom->label(),
        ];
    }

    /**
     * @return array<int, string>
     */
    protected static function productOptions(): array
    {
        /** @var Company $company */
        $company = Filament::getTenant();

        return Product::query()
            ->where('company_id', $company->getKey())
            ->active()
            ->sellable()
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Product $product): array => [
                $product->getKey() => sprintf(
                    '%s · R$ %s · estoque %s',
                    $product->name,
                    number_format((float) $product->sale_price, 2, ',', '.'),
                    $product->tracks_stock ? $product->getCurrentStockQuantity() : 'sem controle',
                ),
            ])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    protected static function serviceOptions(): array
    {
        /** @var Company $company */
        $company = Filament::getTenant();

        return Service::query()
            ->where('company_id', $company->getKey())
            ->active()
            ->sellable()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Service $service): array => [
                $service->getKey() => sprintf(
                    '%s · R$ %s',
                    $service->name,
                    number_format((float) $service->price, 2, ',', '.'),
                ),
            ])
            ->all();
    }

    protected static function lineTotal(mixed $quantity, mixed $unitPrice, mixed $discount): float
    {
        return max(0, ((float) $quantity * (float) $unitPrice) - (float) $discount);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    protected static function itemsTotal(array $items): float
    {
        return collect($items)
            ->sum(fn (array $item): float => self::lineTotal(
                $item['quantity'] ?? 0,
                $item['unit_price'] ?? 0,
                $item['discount_amount'] ?? 0,
            ));
    }

    protected static function formatMoney(float $amount): string
    {
        return number_format($amount, 2, ',', '.');
    }

    protected function fillDefaultForm(): void
    {
        $this->form->fill([
            'sold_at' => now(),
            'discount_amount' => '0.00',
            'items' => [[
                'item_type' => SaleItemType::Product->value,
                'quantity' => '1',
                'unit_price' => '0.00',
                'discount_amount' => '0.00',
            ]],
            'payments' => [[
                'amount' => null,
                'fee_amount' => '0.00',
                'method' => PaymentMethod::Pix->value,
                'paid_at' => now(),
            ]],
        ]);
    }
}
