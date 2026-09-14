<?php

namespace App\Filament\App\Pages;

use App\Enums\CompanyModule;
use App\Filament\App\Concerns\RequiresCompanyModule;
use App\Models\Company;
use App\Policies\CompanyOrderSettingPolicy;
use App\Services\Orders\CompanyOrderSettingService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
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

class OrderSettingsPage extends Page
{
    use RequiresCompanyModule;

    protected static ?string $slug = 'configuracoes-pedidos';

    protected static ?string $navigationLabel = 'Configurações de pedidos';

    protected static ?string $title = 'Configurações de pedidos';

    protected static string|UnitEnum|null $navigationGroup = 'Configurações';

    protected static ?int $navigationSort = 4;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

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

        return $user !== null && (new CompanyOrderSettingPolicy)->viewAny($user);
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        /** @var Company $company */
        $company = Filament::getTenant();
        $setting = app(CompanyOrderSettingService::class)->getOrCreate($company);

        $this->form->fill([
            'online_ordering_enabled' => $setting->online_ordering_enabled,
            'pickup_enabled' => $setting->pickup_enabled,
            'delivery_enabled' => $setting->delivery_enabled,
            'delivery_fee_reais' => number_format(((int) $setting->delivery_fee_cents) / 100, 2, '.', ''),
            'min_order_reais' => number_format(((int) $setting->min_order_cents) / 100, 2, '.', ''),
            'delivery_radius_note' => $setting->delivery_radius_note,
            'orders_whatsapp_notify' => $setting->orders_whatsapp_notify,
            'whatsapp_order_link_bot_enabled' => $setting->whatsapp_order_link_bot_enabled,
            'page_title' => $setting->page_title,
            'page_description' => $setting->page_description,
            'confirmation_message' => $setting->confirmation_message,
            'primary_color' => $setting->primary_color,
        ]);
    }

    public function defaultForm(Schema $schema): Schema
    {
        return $schema->statePath('data');
    }

    public function form(Schema $schema): Schema
    {
        /** @var Company $company */
        $company = Filament::getTenant();

        return $schema->components([
            Section::make('Pedidos online')->schema([
                Placeholder::make('public_link')
                    ->label('Link público')
                    ->content(route('public.orders.show', ['company' => $company->slug])),
                Toggle::make('online_ordering_enabled')
                    ->label('Aceitar pedidos pelo link público'),
                Toggle::make('pickup_enabled')
                    ->label('Permitir retirada'),
                Toggle::make('delivery_enabled')
                    ->label('Permitir entrega'),
                TextInput::make('delivery_fee_reais')
                    ->label('Taxa de entrega')
                    ->numeric()
                    ->prefix('R$')
                    ->step(0.01)
                    ->minValue(0)
                    ->default('0.00'),
                TextInput::make('min_order_reais')
                    ->label('Pedido mínimo')
                    ->numeric()
                    ->prefix('R$')
                    ->step(0.01)
                    ->minValue(0)
                    ->default('0.00'),
                Textarea::make('delivery_radius_note')
                    ->label('Área ou observações de entrega')
                    ->rows(3)
                    ->columnSpanFull(),
                Toggle::make('orders_whatsapp_notify')
                    ->label('Avisar o cliente no WhatsApp (se o módulo estiver conectado)'),
                Toggle::make('whatsapp_order_link_bot_enabled')
                    ->label('Enviar link do cardápio no WhatsApp')
                    ->helperText('Quando alguém mandar mensagem no WhatsApp da empresa, o bot responde só com o link do cardápio. Não inicia conversa de agendamento.')
                    ->columnSpanFull(),
            ])->columns(2),
            Section::make('Página pública')->schema([
                TextInput::make('page_title')
                    ->label('Título da página')
                    ->maxLength(120),
                ColorPicker::make('primary_color')
                    ->label('Cor principal'),
                Textarea::make('page_description')
                    ->label('Descrição')
                    ->rows(3)
                    ->columnSpanFull(),
                Textarea::make('confirmation_message')
                    ->label('Mensagem de confirmação')
                    ->rows(3)
                    ->columnSpanFull(),
            ])->columns(2),
        ]);
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        $state = $this->form->getState();

        app(CompanyOrderSettingService::class)->update(Filament::getTenant(), [
            'online_ordering_enabled' => (bool) ($state['online_ordering_enabled'] ?? false),
            'pickup_enabled' => (bool) ($state['pickup_enabled'] ?? false),
            'delivery_enabled' => (bool) ($state['delivery_enabled'] ?? false),
            'delivery_fee_cents' => (int) round(((float) ($state['delivery_fee_reais'] ?? 0)) * 100),
            'min_order_cents' => (int) round(((float) ($state['min_order_reais'] ?? 0)) * 100),
            'delivery_radius_note' => $state['delivery_radius_note'] ?? null,
            'orders_whatsapp_notify' => (bool) ($state['orders_whatsapp_notify'] ?? false),
            'whatsapp_order_link_bot_enabled' => (bool) ($state['whatsapp_order_link_bot_enabled'] ?? false),
            'page_title' => $state['page_title'] ?? null,
            'page_description' => $state['page_description'] ?? null,
            'confirmation_message' => $state['confirmation_message'] ?? null,
            'primary_color' => $state['primary_color'] ?? null,
        ]);

        Notification::make()->success()->title('Configurações de pedidos salvas')->send();
    }

    protected function getFormActions(): array
    {
        return [Action::make('save')->label('Salvar configurações')->submit('save')];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('order-settings-form')
                ->livewireSubmitHandler('save')
                ->footer([Actions::make($this->getFormActions())]),
        ]);
    }

    protected static function requiredCompanyModule(): CompanyModule
    {
        return CompanyModule::Orders;
    }
}
