<?php

namespace App\Filament\App\Pages;

use App\Enums\CompanyModule;
use App\Enums\Weekday;
use App\Enums\WhatsAppAutomationType;
use App\Filament\App\Concerns\RequiresCompanyModule;
use App\Models\Company;
use App\Policies\CompanySchedulingSettingPolicy;
use App\Services\Scheduling\CompanyBusinessHoursService;
use App\Services\Scheduling\CompanySchedulingSettingService;
use App\Services\WhatsApp\Automations\WhatsAppAutomationService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
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

class SchedulingSettingsPage extends Page
{
    use RequiresCompanyModule;

    protected static ?string $slug = 'configuracoes-agenda';

    protected static ?string $navigationLabel = 'Configurações da agenda';

    protected static ?string $title = 'Configurações da agenda';

    protected static string|UnitEnum|null $navigationGroup = 'Configurações';

    protected static ?int $navigationSort = 1;

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

        return $user !== null && (new CompanySchedulingSettingPolicy)->viewAny($user);
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        /** @var Company $company */
        $company = Filament::getTenant();

        $setting = app(CompanySchedulingSettingService::class)->getOrCreate($company);
        $hours = app(CompanyBusinessHoursService::class)->getWeeklyHours($company);
        $automations = app(WhatsAppAutomationService::class)->ensureDefaults($company);
        $reminder = $automations[WhatsAppAutomationType::Reminder->value];
        $afterSales = $automations[WhatsAppAutomationType::AfterSales->value];

        $this->form->fill([
            'slot_interval_minutes' => $setting->slot_interval_minutes,
            'calendar_start_time' => substr((string) $setting->calendar_start_time, 0, 5),
            'calendar_end_time' => substr((string) $setting->calendar_end_time, 0, 5),
            'week_starts_on' => $setting->week_starts_on,
            'default_calendar_view' => $setting->default_calendar_view,
            'allow_employee_self_view' => $setting->allow_employee_self_view,
            'public_booking_enabled' => $setting->public_booking_enabled,
            'online_auto_confirm' => $setting->online_auto_confirm,
            'require_email_for_online_booking' => $setting->require_email_for_online_booking,
            'allow_public_cancellation' => $setting->allow_public_cancellation,
            'allow_public_reschedule' => $setting->allow_public_reschedule,
            'allow_professional_selection' => $setting->allow_professional_selection,
            'allow_no_professional_preference' => $setting->allow_no_professional_preference,
            'show_service_price' => $setting->show_service_price,
            'show_service_duration' => $setting->show_service_duration,
            'minimum_advance_minutes' => $setting->minimum_advance_minutes,
            'maximum_advance_days' => $setting->maximum_advance_days,
            'cancellation_minimum_advance_minutes' => $setting->cancellation_minimum_advance_minutes,
            'reschedule_minimum_advance_minutes' => $setting->reschedule_minimum_advance_minutes,
            'booking_page_title' => $setting->booking_page_title,
            'booking_page_description' => $setting->booking_page_description,
            'booking_confirmation_message' => $setting->booking_confirmation_message,
            'booking_primary_color' => $setting->booking_primary_color,
            'privacy_notice' => $setting->privacy_notice,
            'booking_terms' => $setting->booking_terms,
            'whatsapp_notifications_enabled' => $setting->whatsapp_notifications_enabled,
            'whatsapp_instance' => $setting->whatsapp_instance,
            'whatsapp_sender_phone' => $setting->whatsapp_sender_phone,
            'whatsapp_confirmation_template' => $setting->whatsapp_confirmation_template,
            'notify_professional_by_email' => $setting->notify_professional_by_email,
            'notify_professional_by_whatsapp' => $setting->notify_professional_by_whatsapp,
            'reminder_enabled' => $reminder->is_enabled,
            'reminder_delay_value' => $reminder->delay_value,
            'reminder_template' => $reminder->message_template,
            'reminder_quiet_hours_start' => substr((string) $reminder->quiet_hours_start, 0, 5),
            'reminder_quiet_hours_end' => substr((string) $reminder->quiet_hours_end, 0, 5),
            'after_sales_enabled' => $afterSales->is_enabled,
            'after_sales_delay_value' => $afterSales->delay_value,
            'after_sales_template' => $afterSales->message_template,
            'business_hours' => $hours !== [] ? $hours : [
                [
                    'weekday' => 1,
                    'start_time' => '08:00',
                    'end_time' => '18:00',
                    'is_active' => true,
                ],
            ],
        ]);
    }

    public function save(): void
    {
        /** @var Company $company */
        $company = Filament::getTenant();

        $data = $this->form->getState();

        $businessHours = $data['business_hours'] ?? [];
        unset($data['business_hours']);

        $automationPayload = [
            'reminder' => [
                'is_enabled' => (bool) ($data['reminder_enabled'] ?? false),
                'delay_value' => (int) ($data['reminder_delay_value'] ?? 24),
                'message_template' => $data['reminder_template'] ?? '',
                'quiet_hours_start' => $data['reminder_quiet_hours_start'] ?? '08:00',
                'quiet_hours_end' => $data['reminder_quiet_hours_end'] ?? '20:00',
            ],
            'after_sales' => [
                'is_enabled' => (bool) ($data['after_sales_enabled'] ?? false),
                'delay_value' => (int) ($data['after_sales_delay_value'] ?? 2),
                'message_template' => $data['after_sales_template'] ?? '',
                'quiet_hours_start' => $data['reminder_quiet_hours_start'] ?? '08:00',
                'quiet_hours_end' => $data['reminder_quiet_hours_end'] ?? '20:00',
            ],
        ];

        unset(
            $data['reminder_enabled'],
            $data['reminder_delay_value'],
            $data['reminder_template'],
            $data['reminder_quiet_hours_start'],
            $data['reminder_quiet_hours_end'],
            $data['after_sales_enabled'],
            $data['after_sales_delay_value'],
            $data['after_sales_template'],
        );

        app(CompanySchedulingSettingService::class)->update($company, $data);
        app(CompanyBusinessHoursService::class)->replaceWeeklyHours($company, $businessHours);
        app(WhatsAppAutomationService::class)->update($company, WhatsAppAutomationType::Reminder, $automationPayload['reminder']);
        app(WhatsAppAutomationService::class)->update($company, WhatsAppAutomationType::AfterSales, $automationPayload['after_sales']);

        Notification::make()
            ->success()
            ->title('Configurações salvas')
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
                Section::make('Configurações gerais')
                    ->schema([
                        Select::make('slot_interval_minutes')
                            ->label('Intervalo dos horários')
                            ->options([
                                5 => '5 minutos',
                                10 => '10 minutos',
                                15 => '15 minutos',
                                20 => '20 minutos',
                                30 => '30 minutos',
                                60 => '60 minutos',
                            ])
                            ->required()
                            ->native(false),
                        TimePicker::make('calendar_start_time')
                            ->label('Início visual do calendário')
                            ->seconds(false)
                            ->required(),
                        TimePicker::make('calendar_end_time')
                            ->label('Fim visual do calendário')
                            ->seconds(false)
                            ->required(),
                        Select::make('week_starts_on')
                            ->label('Primeiro dia da semana')
                            ->options(Weekday::options())
                            ->required()
                            ->native(false),
                        Select::make('default_calendar_view')
                            ->label('Visualização padrão')
                            ->options([
                                'timeGridWeek' => 'Semana',
                                'dayGridMonth' => 'Mês',
                                'timeGridDay' => 'Dia',
                                'listWeek' => 'Lista',
                            ])
                            ->required()
                            ->native(false),
                        Toggle::make('allow_employee_self_view')
                            ->label('Permitir colaborador visualizar a própria agenda')
                            ->default(true),
                    ])
                    ->columns(2),
                Section::make('Horários de funcionamento')
                    ->schema([
                        Repeater::make('business_hours')
                            ->label('Faixas de horário')
                            ->schema([
                                Select::make('weekday')
                                    ->label('Dia da semana')
                                    ->options(Weekday::options())
                                    ->required()
                                    ->native(false),
                                TimePicker::make('start_time')
                                    ->label('Hora inicial')
                                    ->seconds(false)
                                    ->required(),
                                TimePicker::make('end_time')
                                    ->label('Hora final')
                                    ->seconds(false)
                                    ->required(),
                                Toggle::make('is_active')
                                    ->label('Ativo')
                                    ->default(true),
                            ])
                            ->columns(4)
                            ->addActionLabel('Adicionar faixa')
                            ->columnSpanFull(),
                    ]),
                Section::make('Link público de agendamento')
                    ->schema([
                        Placeholder::make('public_booking_url')
                            ->label('Link público de agendamento')
                            ->content(function (): string {
                                /** @var Company $company */
                                $company = Filament::getTenant();

                                return route('public.booking.show', ['company' => $company->slug]);
                            }),
                    ])
                    ->visible(function (): bool {
                        /** @var Company $company */
                        $company = Filament::getTenant();

                        return (bool) ($company->schedulingSetting?->public_booking_enabled ?? false);
                    }),
                Section::make('Agendamento online')
                    ->schema([
                        Toggle::make('public_booking_enabled')
                            ->label('Habilitar agendamento online')
                            ->live(),
                        Toggle::make('online_auto_confirm')
                            ->label('Confirmar automaticamente')
                            ->default(false),
                        Toggle::make('require_email_for_online_booking')
                            ->label('Exigir e-mail'),
                        Toggle::make('allow_professional_selection')
                            ->label('Permitir escolha do profissional')
                            ->default(true),
                        Toggle::make('allow_no_professional_preference')
                            ->label('Permitir “Sem preferência”')
                            ->default(false),
                        Toggle::make('allow_public_cancellation')
                            ->label('Permitir cancelamento pelo cliente')
                            ->default(true),
                        Toggle::make('allow_public_reschedule')
                            ->label('Permitir remarcação pelo cliente')
                            ->default(true),
                        Toggle::make('show_service_price')
                            ->label('Mostrar preço')
                            ->default(true),
                        Toggle::make('show_service_duration')
                            ->label('Mostrar duração')
                            ->default(true),
                        TextInput::make('minimum_advance_minutes')
                            ->label('Antecedência mínima (minutos)')
                            ->numeric()
                            ->minValue(0)
                            ->required(),
                        TextInput::make('maximum_advance_days')
                            ->label('Máximo de dias futuros')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(365)
                            ->required(),
                        TextInput::make('cancellation_minimum_advance_minutes')
                            ->label('Prazo mínimo para cancelamento (minutos)')
                            ->numeric()
                            ->minValue(0)
                            ->required(),
                        TextInput::make('reschedule_minimum_advance_minutes')
                            ->label('Prazo mínimo para remarcação (minutos)')
                            ->numeric()
                            ->minValue(0)
                            ->required(),
                        TextInput::make('booking_page_title')
                            ->label('Título da página')
                            ->maxLength(120),
                        Textarea::make('booking_page_description')
                            ->label('Descrição da página')
                            ->rows(3)
                            ->maxLength(2000),
                        Textarea::make('booking_confirmation_message')
                            ->label('Mensagem de confirmação')
                            ->rows(3)
                            ->maxLength(2000),
                        TextInput::make('booking_primary_color')
                            ->label('Cor principal')
                            ->placeholder('#2563eb')
                            ->maxLength(7),
                        Textarea::make('privacy_notice')
                            ->label('Aviso de privacidade')
                            ->rows(4)
                            ->maxLength(5000),
                        Textarea::make('booking_terms')
                            ->label('Termos do agendamento')
                            ->rows(4)
                            ->maxLength(5000),
                    ])
                    ->columns(2)
                    ->visible(fn (): bool => (new CompanySchedulingSettingPolicy)->update(
                        auth()->user(),
                        app(CompanySchedulingSettingService::class)->getOrCreate(Filament::getTenant()),
                    )),
                Section::make('WhatsApp (Evolution API)')
                    ->description('Use uma instância Evolution da empresa ou a instância padrão configurada no servidor. Esse número é o remetente das confirmações.')
                    ->schema([
                        Toggle::make('whatsapp_notifications_enabled')
                            ->label('Enviar confirmação no WhatsApp')
                            ->helperText('Requer EVOLUTION_API_URL e EVOLUTION_API_KEY no servidor. A instância abaixo sobrescreve EVOLUTION_INSTANCE.')
                            ->live(),
                        TextInput::make('whatsapp_instance')
                            ->label('Instância Evolution')
                            ->helperText('Opcional quando EVOLUTION_INSTANCE está configurada no servidor.')
                            ->required(fn (Get $get): bool => (bool) $get('whatsapp_notifications_enabled') && blank(config('services.evolution.instance')))
                            ->maxLength(120),
                        TextInput::make('whatsapp_sender_phone')
                            ->label('Número remetente (WhatsApp da empresa)')
                            ->helperText('Número conectado na instância (referência/controle no painel).')
                            ->tel()
                            ->mask('(99) 99999-9999')
                            ->required(fn (Get $get): bool => (bool) $get('whatsapp_notifications_enabled'))
                            ->maxLength(20),
                        Textarea::make('whatsapp_confirmation_template')
                            ->label('Modelo da mensagem')
                            ->helperText('Placeholders: {nome}, {servico}, {profissional}, {data}, {hora}, {codigo}, {link}, {empresa}, {placa}')
                            ->rows(6)
                            ->maxLength(4000)
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->visible(fn (): bool => (new CompanySchedulingSettingPolicy)->update(
                        auth()->user(),
                        app(CompanySchedulingSettingService::class)->getOrCreate(Filament::getTenant()),
                    )),
                Section::make('Lembrete e pós-venda no WhatsApp')
                    ->description('Mensagens operacionais. Lembrete exige confirmação WhatsApp ligada. Reconquista fica em Marketing.')
                    ->schema([
                        Toggle::make('reminder_enabled')
                            ->label('Enviar lembrete antes do horário')
                            ->live(),
                        TextInput::make('reminder_delay_value')
                            ->label('Horas de antecedência')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(168)
                            ->required(fn (Get $get): bool => (bool) $get('reminder_enabled')),
                        TimePicker::make('reminder_quiet_hours_start')
                            ->label('Não enviar antes das')
                            ->seconds(false)
                            ->required(),
                        TimePicker::make('reminder_quiet_hours_end')
                            ->label('Não enviar depois das')
                            ->seconds(false)
                            ->required(),
                        Textarea::make('reminder_template')
                            ->label('Modelo do lembrete')
                            ->helperText('Placeholders: {nome}, {servico}, {data}, {hora}, {codigo}, {link}, {empresa}, {placa}')
                            ->rows(6)
                            ->maxLength(4000)
                            ->columnSpanFull(),
                        Toggle::make('after_sales_enabled')
                            ->label('Enviar agradecimento após o atendimento')
                            ->live(),
                        TextInput::make('after_sales_delay_value')
                            ->label('Horas após a conclusão')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(168)
                            ->required(fn (Get $get): bool => (bool) $get('after_sales_enabled')),
                        Textarea::make('after_sales_template')
                            ->label('Modelo do pós-venda')
                            ->helperText('Texto neutro (obrigado + link). Promoções devem ir para reconquista, com aceite.')
                            ->rows(5)
                            ->maxLength(4000)
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->visible(fn (): bool => (new CompanySchedulingSettingPolicy)->update(
                        auth()->user(),
                        app(CompanySchedulingSettingService::class)->getOrCreate(Filament::getTenant()),
                    )),
                Section::make('Avisos ao profissional')
                    ->description('Notifica diretamente o profissional responsável quando houver criação, confirmação, remarcação ou cancelamento.')
                    ->schema([
                        Toggle::make('notify_professional_by_email')
                            ->label('Avisar o profissional por e-mail')
                            ->helperText('Usa o e-mail do cadastro do profissional e, se estiver vazio, o e-mail do usuário vinculado.')
                            ->default(true),
                        Toggle::make('notify_professional_by_whatsapp')
                            ->label('Avisar o profissional por WhatsApp')
                            ->helperText('Requer telefone no cadastro do profissional e as notificações da Evolution API habilitadas.')
                            ->default(true),
                    ])
                    ->columns(2)
                    ->visible(fn (): bool => (new CompanySchedulingSettingPolicy)->update(
                        auth()->user(),
                        app(CompanySchedulingSettingService::class)->getOrCreate(Filament::getTenant()),
                    )),
            ]);
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        /** @var Company $company */
        $company = Filament::getTenant();
        $publicUrl = route('public.booking.show', ['company' => $company->slug]);

        return [
            Action::make('copyPublicLink')
                ->label('Copiar link')
                ->icon('heroicon-o-link')
                ->visible(fn (): bool => $company->schedulingSetting?->public_booking_enabled ?? false)
                ->action(function () use ($publicUrl): void {
                    $this->js('navigator.clipboard.writeText('.json_encode($publicUrl).')');

                    Notification::make()
                        ->success()
                        ->title('Link copiado!')
                        ->send();
                }),
            Action::make('openPublicPage')
                ->label('Abrir página pública')
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->url($publicUrl, shouldOpenInNewTab: true)
                ->visible(fn (): bool => $company->schedulingSetting?->public_booking_enabled ?? false),
        ];
    }

    /**
     * @return array<Action>
     */
    protected function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label('Salvar configurações')
                ->submit('save'),
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([EmbeddedSchema::make('form')])
                    ->id('scheduling-settings-form')
                    ->livewireSubmitHandler('save')
                    ->footer([
                        Actions::make($this->getFormActions()),
                    ]),
            ]);
    }

    protected static function requiredCompanyModule(): CompanyModule
    {
        return CompanyModule::Scheduling;
    }
}
