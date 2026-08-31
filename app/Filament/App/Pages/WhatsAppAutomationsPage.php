<?php

namespace App\Filament\App\Pages;

use App\Enums\CompanyModule;
use App\Enums\WhatsAppAutomationType;
use App\Filament\App\Concerns\RequiresCompanyModule;
use App\Models\Company;
use App\Policies\WhatsAppAutomationPolicy;
use App\Services\WhatsApp\Automations\WhatsAppAutomationService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
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

class WhatsAppAutomationsPage extends Page
{
    use RequiresCompanyModule;

    protected static ?string $slug = 'automacoes-whatsapp';

    protected static ?string $navigationLabel = 'Automações WhatsApp';

    protected static ?string $title = 'Reconquista no WhatsApp';

    protected static string|UnitEnum|null $navigationGroup = 'Marketing';

    protected static ?int $navigationSort = 11;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPath;

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

        return $user !== null && (new WhatsAppAutomationPolicy)->viewAny($user);
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        /** @var Company $company */
        $company = Filament::getTenant();
        $automation = app(WhatsAppAutomationService::class)
            ->getOrCreate($company, WhatsAppAutomationType::WinBack);

        $this->form->fill([
            'is_enabled' => $automation->is_enabled,
            'delay_value' => $automation->delay_value,
            'cooldown_days' => $automation->cooldown_days,
            'quiet_hours_start' => substr((string) $automation->quiet_hours_start, 0, 5),
            'quiet_hours_end' => substr((string) $automation->quiet_hours_end, 0, 5),
            'message_template' => $automation->message_template,
        ]);
    }

    public function save(): void
    {
        /** @var Company $company */
        $company = Filament::getTenant();

        abort_unless((new WhatsAppAutomationPolicy)->viewAny(auth()->user()), 403);

        app(WhatsAppAutomationService::class)->update(
            $company,
            WhatsAppAutomationType::WinBack,
            $this->form->getState(),
        );

        Notification::make()
            ->success()
            ->title('Automações salvas')
            ->send();
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
                Section::make('Quem sumiu')
                    ->description('Só envia para clientes ativos que autorizaram campanhas, sem horário futuro, cuja última visita passou do intervalo.')
                    ->schema([
                        Toggle::make('is_enabled')
                            ->label('Ativar reconquista automática')
                            ->live(),
                        TextInput::make('delay_value')
                            ->label('Dias sem visita')
                            ->numeric()
                            ->minValue(7)
                            ->maxValue(365)
                            ->required(fn (Get $get): bool => (bool) $get('is_enabled')),
                        TextInput::make('cooldown_days')
                            ->label('Mínimo de dias entre mensagens')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(365)
                            ->required(),
                        TimePicker::make('quiet_hours_start')
                            ->label('Não enviar antes das')
                            ->seconds(false)
                            ->required(),
                        TimePicker::make('quiet_hours_end')
                            ->label('Não enviar depois das')
                            ->seconds(false)
                            ->required(),
                        Textarea::make('message_template')
                            ->label('Mensagem')
                            ->helperText('Placeholders: {nome}, {servico}, {empresa}, {placa}, {link}')
                            ->rows(8)
                            ->maxLength(4000)
                            ->required()
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }

    /**
     * @return array<Action>
     */
    protected function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label('Salvar')
                ->submit('save'),
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([EmbeddedSchema::make('form')])
                    ->id('whatsapp-automations-form')
                    ->livewireSubmitHandler('save')
                    ->footer([
                        Actions::make($this->getFormActions()),
                    ]),
            ]);
    }

    protected static function requiredCompanyModule(): CompanyModule
    {
        return CompanyModule::Marketing;
    }
}
