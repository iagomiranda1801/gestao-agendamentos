<?php

namespace App\Filament\App\Resources\TattooRequests;

use App\Enums\CompanyModule;
use App\Enums\CompanyPermission;
use App\Filament\App\Concerns\RequiresCompanyModuleResource;
use App\Filament\App\Resources\Appointments\AppointmentResource;
use App\Filament\App\Resources\TattooRequests\Pages\CreateTattooRequest;
use App\Filament\App\Resources\TattooRequests\Pages\EditTattooRequest;
use App\Filament\App\Resources\TattooRequests\Pages\ListTattooRequests;
use App\Models\Professional;
use App\Models\TattooRequest;
use App\Services\Company\CompanyPermissionService;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use UnitEnum;

class TattooRequestResource extends Resource
{
    use RequiresCompanyModuleResource;

    protected static ?string $model = TattooRequest::class;

    protected static ?string $slug = 'orcamentos-tatuagem';

    protected static ?string $modelLabel = 'pedido de orçamento';

    protected static ?string $pluralModelLabel = 'orçamentos de tatuagem';

    protected static ?string $navigationLabel = 'Orçamentos de tatuagem';

    protected static string|UnitEnum|null $navigationGroup = 'Agenda';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPencilSquare;

    protected static ?string $tenantOwnershipRelationshipName = 'company';

    protected static function requiredCompanyModule(): CompanyModule
    {
        return CompanyModule::Scheduling;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Pedido de tatuagem')->schema([
                Select::make('client_id')->label('Cliente')->relationship('client', 'name', fn (Builder $query) => $query->where('company_id', Filament::getTenant()?->getKey()))->searchable()->preload()->required()->disabled(fn (?TattooRequest $record) => $record !== null && ! self::canManageRequests()),
                Select::make('professional_id')->label('Tatuador')->relationship('professional', 'name', fn (Builder $query) => $query->where('company_id', Filament::getTenant()?->getKey())->active())->searchable()->preload()->disabled(fn (?TattooRequest $record) => $record !== null && ! self::canManageRequests()),
                Textarea::make('description')->label('Desenho desejado')->required()->maxLength(3000)->columnSpanFull(),
                TextInput::make('body_placement')->label('Local do corpo')->required()->maxLength(255),
                TextInput::make('size_description')->label('Tamanho aproximado')->maxLength(255),
                Textarea::make('notes')->label('Observações')->maxLength(3000)->columnSpanFull(),
            ])->columns(2),
            Section::make('Fotos e propostas')->schema([
                Placeholder::make('images_list')->label('Fotos de referência')->content(function (?TattooRequest $record): HtmlString {
                    if (! $record) {
                        return new HtmlString('Salve o pedido para adicionar fotos.');
                    }
                    $links = $record->images->map(function ($image) use ($record): string {
                        $url = route('tattoo.images.download', ['company' => $record->company, 'image' => $image]);

                        return '<a href="'.e($url).'" target="_blank" rel="noopener">'.e($image->original_name ?: 'Foto '.$image->id).'</a>';
                    })->implode('<br>');

                    return new HtmlString($links ?: 'Nenhuma foto enviada.');
                }),
                Placeholder::make('quotes_list')->label('Orçamentos')->content(function (?TattooRequest $record): HtmlString {
                    $quote = $record?->quotes()->orderByDesc('version')->first();
                    if (! $quote) {
                        return new HtmlString('Nenhum orçamento.');
                    }

                    $lines = [
                        '<strong>Versão '.(int) $quote->version.'</strong> — '.e($quote->situationLabel()),
                        'Valor: '.e($quote->priceLabel()),
                        'Sessões previstas: '.(int) $quote->sessions,
                    ];
                    if (filled($quote->deposit_amount) && (float) $quote->deposit_amount > 0) {
                        $lines[] = 'Sinal: R$ '.e(number_format((float) $quote->deposit_amount, 2, ',', '.'));
                    }
                    if ($quote->valid_until) {
                        $lines[] = 'Válido até: '.e($quote->valid_until->format('d/m/Y'));
                    }

                    return new HtmlString(implode('<br>', $lines));
                }),
                Placeholder::make('appointment_link')->label('Agendamento')->content(function (?TattooRequest $record): HtmlString {
                    if (! $record?->appointment_id) {
                        return new HtmlString('Ainda não agendado.');
                    }
                    $url = AppointmentResource::getUrl('view', ['record' => $record->appointment_id]);

                    return new HtmlString('<a href="'.e($url).'">Abrir agendamento</a>');
                }),
            ])->visible(fn (?TattooRequest $record) => $record !== null),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('created_at')->label('Recebido')->dateTime('d/m/Y H:i')->sortable(),
            TextColumn::make('client.name')->label('Cliente')->searchable(),
            TextColumn::make('body_placement')->label('Local')->searchable(),
            TextColumn::make('professional.name')->label('Tatuador')->default('A atribuir'),
            TextColumn::make('status')->label('Status')->badge()->formatStateUsing(fn (string $state) => self::statuses()[$state] ?? $state),
        ])->filters([
            SelectFilter::make('status')->options(self::statuses()),
        ])->recordUrl(fn (TattooRequest $record) => self::getUrl('edit', ['record' => $record]));
    }

    public static function statuses(): array
    {
        return [
            'collecting' => 'Coletando dados', 'awaiting_review' => 'Aguardando análise',
            'in_review' => 'Em análise', 'waiting_client' => 'Aguardando cliente',
            'quote_sent' => 'Orçamento enviado', 'accepted' => 'Aceito',
            'booked' => 'Agendado', 'declined' => 'Recusado',
            'expired' => 'Expirado', 'cancelled' => 'Cancelado',
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTattooRequests::route('/'),
            'create' => CreateTattooRequest::route('/create'),
            'edit' => EditTattooRequest::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->where('status', '!=', 'collecting');
        $company = Filament::getTenant();
        $user = auth()->user();
        if (self::canManageRequests()) {
            return $query;
        }

        $professionalId = Professional::query()->where('company_id', $company?->getKey())->where('user_id', $user?->getKey())->value('id');

        return $query->where('professional_id', $professionalId ?? 0);
    }

    public static function canManageRequests(): bool
    {
        $company = Filament::getTenant();
        $user = auth()->user();

        return $user !== null && ($user->is_super_admin
            || ($company && app(CompanyPermissionService::class)->allows($user, $company, CompanyPermission::ManageAppointments)));
    }
}
