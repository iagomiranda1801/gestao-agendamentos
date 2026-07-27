<?php

namespace App\Filament\App\Pages;

use App\Enums\CompanyModule;
use App\Enums\PaymentMethod;
use App\Filament\App\Concerns\RequiresCompanyModule;
use App\Models\Company;
use App\Models\ExpenseCategory;
use App\Models\FinancialAccount;
use App\Models\Supplier;
use App\Policies\PayablePolicy;
use App\Services\Financial\PayableService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class RegisterExpensePage extends Page
{
    use RequiresCompanyModule;

    protected static ?string $slug = 'registrar-despesa';

    protected static ?string $navigationLabel = 'Registrar despesa';

    protected static ?string $title = 'Registrar despesa';

    protected static string|UnitEnum|null $navigationGroup = 'Financeiro';

    protected static ?int $navigationSort = 23;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

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

        return $user !== null && (new PayablePolicy)->create($user);
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->form->fill([
            'paid_now' => false,
            'issue_date' => now()->toDateString(),
            'competence_date' => now()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
            'method' => PaymentMethod::Pix->value,
            'interest_amount' => '0.00',
            'penalty_amount' => '0.00',
            'fee_amount' => '0.00',
            'discount_amount' => '0.00',
        ]);
    }

    public function save(): void
    {
        /** @var Company $company */
        $company = Filament::getTenant();
        $data = $this->form->getState();

        $category = ExpenseCategory::query()
            ->where('company_id', $company->getKey())
            ->findOrFail($data['expense_category_id']);

        $supplier = filled($data['supplier_id'] ?? null)
            ? Supplier::query()->where('company_id', $company->getKey())->find($data['supplier_id'])
            : null;

        $account = filled($data['financial_account_id'] ?? null)
            ? FinancialAccount::query()->where('company_id', $company->getKey())->find($data['financial_account_id'])
            : null;

        app(PayableService::class)->createQuickExpense(
            $company,
            $category,
            [
                'description' => $data['description'],
                'total_amount' => $data['total_amount'],
                'issue_date' => $data['issue_date'],
                'competence_date' => $data['competence_date'],
                'due_date' => $data['due_date'],
                'paid_now' => (bool) $data['paid_now'],
                'method' => PaymentMethod::from($data['method']),
                'interest_amount' => $data['interest_amount'] ?? '0.00',
                'penalty_amount' => $data['penalty_amount'] ?? '0.00',
                'fee_amount' => $data['fee_amount'] ?? '0.00',
                'discount_amount' => $data['discount_amount'] ?? '0.00',
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'paid_at' => now(),
            ],
            auth()->user(),
            $supplier,
            $account,
        );

        Notification::make()
            ->success()
            ->title('Despesa registrada')
            ->send();

        $this->form->fill([
            'paid_now' => false,
            'issue_date' => now()->toDateString(),
            'competence_date' => now()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
            'method' => PaymentMethod::Pix->value,
            'interest_amount' => '0.00',
            'penalty_amount' => '0.00',
            'fee_amount' => '0.00',
            'discount_amount' => '0.00',
        ]);
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
                Section::make('Despesa')
                    ->schema([
                        TextInput::make('description')
                            ->label('Descrição')
                            ->required()
                            ->maxLength(255),
                        Select::make('expense_category_id')
                            ->label('Categoria')
                            ->options(fn (): array => ExpenseCategory::query()
                                ->where('company_id', Filament::getTenant()?->getKey())
                                ->where('is_active', true)
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->searchable()
                            ->required()
                            ->native(false),
                        Select::make('supplier_id')
                            ->label('Fornecedor')
                            ->options(fn (): array => Supplier::query()
                                ->where('company_id', Filament::getTenant()?->getKey())
                                ->where('is_active', true)
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->searchable()
                            ->native(false),
                        TextInput::make('total_amount')
                            ->label('Valor')
                            ->required()
                            ->numeric()
                            ->minValue(0.01)
                            ->step('0.01'),
                        DatePicker::make('competence_date')
                            ->label('Data de competência')
                            ->required()
                            ->native(false),
                        DatePicker::make('issue_date')
                            ->label('Data de emissão')
                            ->required()
                            ->native(false),
                        DatePicker::make('due_date')
                            ->label('Data de vencimento')
                            ->required()
                            ->native(false),
                        Toggle::make('paid_now')
                            ->label('Despesa paga agora')
                            ->live(),
                    ])
                    ->columns(2),
                Section::make('Pagamento')
                    ->schema([
                        Select::make('financial_account_id')
                            ->label('Conta financeira')
                            ->options(fn (): array => FinancialAccount::query()
                                ->where('company_id', Filament::getTenant()?->getKey())
                                ->where('is_active', true)
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->required(fn (Get $get): bool => (bool) $get('paid_now'))
                            ->visible(fn (Get $get): bool => (bool) $get('paid_now'))
                            ->native(false),
                        Select::make('method')
                            ->label('Forma de pagamento')
                            ->options(PaymentMethod::options())
                            ->required(fn (Get $get): bool => (bool) $get('paid_now'))
                            ->visible(fn (Get $get): bool => (bool) $get('paid_now'))
                            ->native(false),
                        TextInput::make('interest_amount')
                            ->label('Juros')
                            ->numeric()
                            ->default('0.00')
                            ->visible(fn (Get $get): bool => (bool) $get('paid_now')),
                        TextInput::make('penalty_amount')
                            ->label('Multa')
                            ->numeric()
                            ->default('0.00')
                            ->visible(fn (Get $get): bool => (bool) $get('paid_now')),
                        TextInput::make('fee_amount')
                            ->label('Tarifa')
                            ->numeric()
                            ->default('0.00')
                            ->visible(fn (Get $get): bool => (bool) $get('paid_now')),
                        TextInput::make('discount_amount')
                            ->label('Desconto')
                            ->numeric()
                            ->default('0.00')
                            ->visible(fn (Get $get): bool => (bool) $get('paid_now')),
                        TextInput::make('reference')
                            ->label('Referência')
                            ->maxLength(255)
                            ->visible(fn (Get $get): bool => (bool) $get('paid_now')),
                    ])
                    ->columns(2)
                    ->visible(fn (Get $get): bool => (bool) $get('paid_now')),
                Textarea::make('notes')
                    ->label('Observação')
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
                ->label('Registrar despesa')
                ->submit('save'),
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([EmbeddedSchema::make('form')])
                    ->id('register-expense-form')
                    ->livewireSubmitHandler('save')
                    ->footer([
                        Actions::make($this->getFormActions()),
                    ]),
            ]);
    }

    protected static function requiredCompanyModule(): CompanyModule
    {
        return CompanyModule::Finance;
    }
}
