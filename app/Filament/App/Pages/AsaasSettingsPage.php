<?php

namespace App\Filament\App\Pages;

use App\Enums\AsaasEnvironment;
use App\Enums\CompanyRole;
use App\Exceptions\AsaasApiException;
use App\Models\Company;
use App\Models\FinancialAccount;
use App\Models\User;
use App\Services\Asaas\AsaasClient;
use App\Services\Asaas\CompanyAsaasSettingService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
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
use Illuminate\Validation\ValidationException;
use UnitEnum;

class AsaasSettingsPage extends Page
{
    protected static ?string $slug = 'configuracoes-asaas';

    protected static ?string $navigationLabel = 'Asaas';

    protected static ?string $title = 'Asaas';

    protected static string|UnitEnum|null $navigationGroup = 'Configurações';

    protected static ?int $navigationSort = 15;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQrCode;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();
        $company = Filament::getTenant();

        return $user instanceof User
            && $company instanceof Company
            && $user->hasActiveRoleInCompany($company, CompanyRole::CompanyAdmin);
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->fillForm();
    }

    public function save(CompanyAsaasSettingService $settings): void
    {
        abort_unless(static::canAccess(), 403);

        try {
            $settings->update($this->company(), $this->form->getState());
        } catch (ValidationException $exception) {
            throw $this->prefixedException($exception);
        }

        $this->fillForm();

        Notification::make()
            ->success()
            ->title('Integração Asaas salva')
            ->send();
    }

    public function testConnection(AsaasClient $asaas, CompanyAsaasSettingService $settings): void
    {
        abort_unless(static::canAccess(), 403);

        $setting = $settings->getOrCreate($this->company());
        $typedKey = trim((string) ($this->data['api_key'] ?? ''));
        $apiKey = $typedKey !== '' ? $typedKey : (string) $setting->api_key;
        $environment = AsaasEnvironment::tryFrom((string) ($this->data['environment'] ?? ''))
            ?? $setting->environment;

        if ($apiKey === '') {
            Notification::make()
                ->warning()
                ->title('Informe a chave de API')
                ->send();

            return;
        }

        try {
            $balance = $asaas->balance($environment, $apiKey);
        } catch (AsaasApiException $exception) {
            Notification::make()
                ->danger()
                ->title('Não foi possível conectar ao Asaas')
                ->body($exception->getMessage())
                ->send();

            return;
        }

        Notification::make()
            ->success()
            ->title('Conexão com o Asaas ok')
            ->body('Saldo: R$ '.number_format((float) $balance, 2, ',', '.'))
            ->send();
    }

    public function defaultForm(Schema $schema): Schema
    {
        return $schema->statePath('data');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Conta Asaas')
                ->description('A chave é da empresa. O Agendaqui não usa uma conta Asaas única para todos.')
                ->schema([
                    Select::make('environment')
                        ->label('Ambiente')
                        ->options(AsaasEnvironment::options())
                        ->required()
                        ->native(false),
                    TextInput::make('api_key')
                        ->label('Chave de API')
                        ->password()
                        ->revealable()
                        ->helperText('Deixe em branco para manter a chave já salva.')
                        ->maxLength(2000),
                    Toggle::make('is_enabled')
                        ->label('Integração ativa')
                        ->helperText('Com a integração ativa, contas a receber e o sinal do WhatsApp podem gerar cobrança nesta conta.'),
                    Select::make('financial_account_id')
                        ->label('Conta que recebe no caixa')
                        ->options(fn (): array => FinancialAccount::query()
                            ->where('company_id', Filament::getTenant()?->getKey())
                            ->where('is_active', true)
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->searchable()
                        ->native(false)
                        ->placeholder('Não lançar no caixa')
                        ->helperText('Obrigatória para quitar uma conta a receber. No sinal, sem conta o pagamento só libera o agendamento.'),
                ])
                ->columns(2),
            Section::make('Webhook')
                ->description('No painel do Asaas, cadastre a URL e cole o token no campo de autenticação. O Asaas envia esse token no header asaas-access-token.')
                ->schema([
                    TextInput::make('webhook_url')
                        ->label('URL do webhook')
                        ->disabled()
                        ->dehydrated(false)
                        ->columnSpanFull(),
                    TextInput::make('webhook_token')
                        ->label('Token do webhook')
                        ->disabled()
                        ->dehydrated(false)
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('asaas-settings-form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([
                        Action::make('testConnection')
                            ->label('Testar conexão')
                            ->color('gray')
                            ->action('testConnection'),
                        Action::make('save')
                            ->label('Salvar')
                            ->submit('save'),
                    ]),
                ]),
        ]);
    }

    protected function fillForm(): void
    {
        $company = $this->company();
        $setting = app(CompanyAsaasSettingService::class)->getOrCreate($company);

        $this->form->fill([
            'environment' => $setting->environment->value,
            'api_key' => '',
            'is_enabled' => $setting->is_enabled,
            'financial_account_id' => $setting->financial_account_id,
            'webhook_url' => route('webhooks.asaas', ['company' => $company->slug]),
            'webhook_token' => $setting->webhook_token,
        ]);
    }

    protected function company(): Company
    {
        /** @var Company $company */
        $company = Filament::getTenant();

        return $company;
    }

    protected function prefixedException(ValidationException $exception): ValidationException
    {
        $errors = [];
        foreach ($exception->errors() as $key => $messages) {
            $errors['data.'.$key] = $messages;
        }

        return ValidationException::withMessages($errors);
    }
}
