<?php

namespace App\Filament\App\Pages;

use App\Enums\CommissionType;
use App\Models\Company;
use App\Policies\CompanyFinancialSettingPolicy;
use App\Services\Financial\CompanyFinancialSettingService;
use App\Services\Financial\FinancialDistributionValidator;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class FinancialSettingsPage extends Page
{
    protected static ?string $slug = 'configuracoes-financeiras';

    protected static ?string $navigationLabel = 'Configurações financeiras';

    protected static ?string $title = 'Configurações financeiras';

    protected static string|UnitEnum|null $navigationGroup = 'Configurações';

    protected static ?int $navigationSort = 2;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && (new CompanyFinancialSettingPolicy)->viewAny($user);
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        /** @var Company $company */
        $company = Filament::getTenant();

        $setting = app(CompanyFinancialSettingService::class)->getOrCreate($company);

        $this->form->fill([
            'default_commission_type' => $setting->default_commission_type->value,
            'default_commission_value' => $setting->default_commission_value,
            'materials_reserve_percentage' => $setting->materials_reserve_percentage,
            'business_reserve_percentage' => $setting->business_reserve_percentage,
            'allow_partial_payments' => $setting->allow_partial_payments,
            'allow_unpaid_completion' => $setting->allow_unpaid_completion,
            'default_payment_due_days' => $setting->default_payment_due_days,
        ]);
    }

    public function save(): void
    {
        /** @var Company $company */
        $company = Filament::getTenant();

        abort_unless(
            (new CompanyFinancialSettingPolicy)->update(
                auth()->user(),
                app(CompanyFinancialSettingService::class)->getOrCreate($company),
            ),
            403,
        );

        $data = $this->form->getState();

        app(CompanyFinancialSettingService::class)->update($company, $data);

        Notification::make()
            ->success()
            ->title('Configurações financeiras salvas')
            ->send();
    }

    public function defaultForm(Schema $schema): Schema
    {
        return $schema
            ->statePath('data');
    }

    public function form(Schema $schema): Schema
    {
        $canEdit = fn (): bool => (new CompanyFinancialSettingPolicy)->update(
            auth()->user(),
            app(CompanyFinancialSettingService::class)->getOrCreate(Filament::getTenant()),
        );

        return $schema
            ->components([
                Section::make('Comissão padrão')
                    ->schema([
                        Select::make('default_commission_type')
                            ->label('Tipo de comissão padrão')
                            ->options(CommissionType::options())
                            ->required()
                            ->native(false)
                            ->live()
                            ->disabled(fn (): bool => ! $canEdit()),
                        TextInput::make('default_commission_value')
                            ->label(fn ($get): string => $get('default_commission_type') === CommissionType::Fixed->value
                                ? 'Valor fixo padrão'
                                : 'Percentual padrão')
                            ->numeric()
                            ->minValue(0)
                            ->step(0.0001)
                            ->prefix(fn ($get): ?string => $get('default_commission_type') === CommissionType::Fixed->value ? 'R$' : null)
                            ->suffix(fn ($get): ?string => $get('default_commission_type') === CommissionType::Percentage->value ? '%' : null)
                            ->visible(fn ($get): bool => $get('default_commission_type') !== CommissionType::None->value)
                            ->required(fn ($get): bool => $get('default_commission_type') !== CommissionType::None->value)
                            ->disabled(fn (): bool => ! $canEdit()),
                    ])
                    ->columns(2),
                Section::make('Distribuição gerencial')
                    ->schema([
                        TextInput::make('materials_reserve_percentage')
                            ->label('Reserva para materiais')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->step(0.0001)
                            ->suffix('%')
                            ->required()
                            ->live(onBlur: true)
                            ->disabled(fn (): bool => ! $canEdit()),
                        TextInput::make('business_reserve_percentage')
                            ->label('Reserva do negócio')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->step(0.0001)
                            ->suffix('%')
                            ->required()
                            ->live(onBlur: true)
                            ->disabled(fn (): bool => ! $canEdit()),
                        Placeholder::make('owner_allocation_preview')
                            ->label('Parcela do proprietário')
                            ->content(function ($get): string {
                                $commissionType = $get('default_commission_type');
                                $commissionValue = (string) ($get('default_commission_value') ?? '0');
                                $materials = (string) ($get('materials_reserve_percentage') ?? '0');
                                $business = (string) ($get('business_reserve_percentage') ?? '0');

                                $commissionPercentage = $commissionType === CommissionType::Percentage->value
                                    ? $commissionValue
                                    : '0';

                                $owner = app(FinancialDistributionValidator::class)->calculateOwnerAllocationPercentage(
                                    $materials,
                                    $business,
                                    $commissionPercentage,
                                );

                                return number_format((float) $owner, 4, ',', '.').'%';
                            })
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
                Section::make('Pagamentos')
                    ->schema([
                        Toggle::make('allow_partial_payments')
                            ->label('Permitir pagamentos parciais')
                            ->default(true)
                            ->disabled(fn (): bool => ! $canEdit()),
                        Toggle::make('allow_unpaid_completion')
                            ->label('Permitir concluir sem pagamento')
                            ->default(true)
                            ->disabled(fn (): bool => ! $canEdit()),
                        TextInput::make('default_payment_due_days')
                            ->label('Prazo padrão para pagamento (dias)')
                            ->numeric()
                            ->minValue(0)
                            ->required()
                            ->disabled(fn (): bool => ! $canEdit()),
                    ])
                    ->columns(2),
            ]);
    }

    /**
     * @return array<Action>
     */
    protected function getFormActions(): array
    {
        /** @var Company $company */
        $company = Filament::getTenant();

        return [
            Action::make('save')
                ->label('Salvar configurações')
                ->submit('save')
                ->visible(fn (): bool => (new CompanyFinancialSettingPolicy)->update(
                    auth()->user(),
                    app(CompanyFinancialSettingService::class)->getOrCreate($company),
                )),
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([EmbeddedSchema::make('form')])
                    ->id('financial-settings-form')
                    ->livewireSubmitHandler('save')
                    ->footer([
                        Actions::make($this->getFormActions()),
                    ]),
            ]);
    }
}
