<?php

namespace App\Filament\App\Resources\TattooAiConversations;

use App\Enums\CompanyModule;
use App\Filament\App\Concerns\RequiresCompanyModuleResource;
use App\Filament\App\Resources\TattooAiConversations\Pages\ListTattooAiConversations;
use App\Filament\App\Resources\TattooAiConversations\Pages\ViewTattooAiConversation;
use App\Filament\App\Resources\TattooRequests\TattooRequestResource;
use App\Models\Company;
use App\Models\Professional;
use App\Models\TattooAiConversation;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Forms\Components\Placeholder;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use UnitEnum;

class TattooAiConversationResource extends Resource
{
    use RequiresCompanyModuleResource;

    protected static ?string $model = TattooAiConversation::class;

    protected static ?string $slug = 'atendimentos-ia';

    protected static ?string $navigationLabel = 'Atendimentos IA';

    protected static string|UnitEnum|null $navigationGroup = 'Agenda';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static ?string $tenantOwnershipRelationshipName = 'company';

    protected static function requiredCompanyModule(): CompanyModule
    {
        return CompanyModule::WhatsApp;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canViewAny(): bool
    {
        $company = Filament::getTenant();

        return $company instanceof Company && $company->isTattooStudio()
            && auth()->user()?->canAccessTenant($company);
    }

    public static function canView($record): bool
    {
        return static::canViewAny() && (int) $record->company_id === (int) Filament::getTenant()?->getKey()
            && (TattooRequestResource::canManageRequests()
                || ((int) ($record->request?->professional?->user_id ?? $record->professional?->user_id) === (int) auth()->id()
                    && ($record->request?->professional_id ?? $record->professional_id) !== null));
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        if (TattooRequestResource::canManageRequests()) {
            return $query;
        }
        $professionalId = Professional::query()->where('company_id', Filament::getTenant()?->getKey())
            ->where('user_id', auth()->id())->value('id');

        return $query->where(function (Builder $builder) use ($professionalId): void {
            $builder->where('professional_id', $professionalId ?? 0)
                ->orWhereHas('request', fn (Builder $request) => $request->where('professional_id', $professionalId ?? 0));
        });
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('last_interaction_at')->label('Última interação')->dateTime('d/m/Y H:i')->sortable(),
            TextColumn::make('client.name')->label('Cliente')->placeholder('A identificar'),
            TextColumn::make('phone_normalized')->label('Telefone')->searchable(),
            TextColumn::make('status')->label('Status')->badge(),
            TextColumn::make('request.id')->label('Pedido')->placeholder('—'),
            TextColumn::make('human_takeover')->label('Equipe assumiu')->formatStateUsing(fn (bool $state) => $state ? 'Sim' : 'Não'),
        ])->defaultSort('last_interaction_at', 'desc')
            ->recordUrl(fn (TattooAiConversation $record) => self::getUrl('view', ['record' => $record]));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Placeholder::make('history')->label('Mensagens recentes')->content(function (?TattooAiConversation $record): HtmlString {
                $lines = $record?->messages()->latest('id')->limit(30)->get()->reverse()
                    ->map(fn ($message) => '<strong>'.($message->direction === 'in' ? 'Cliente' : 'IA').':</strong> '.nl2br(e($message->body ?: '[mídia]')))->all() ?? [];

                return new HtmlString(implode('<br><br>', $lines) ?: 'Nenhuma mensagem.');
            }),
            Placeholder::make('request_link')->label('Orçamento')->content(function (?TattooAiConversation $record): HtmlString {
                if (! $record?->tattoo_request_id) {
                    return new HtmlString('Ainda não criado.');
                }

                return new HtmlString('<a href="'.e(TattooRequestResource::getUrl('edit', ['record' => $record->tattoo_request_id])).'">Abrir pedido</a>');
            }),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListTattooAiConversations::route('/'), 'view' => ViewTattooAiConversation::route('/{record}')];
    }
}
