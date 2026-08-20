<?php

namespace App\Filament\App\Resources\TeamMembers;

use App\Enums\CompanyPermission;
use App\Enums\CompanyRole;
use App\Filament\App\Resources\TeamMembers\Pages\CreateTeamMember;
use App\Filament\App\Resources\TeamMembers\Pages\EditTeamMember;
use App\Filament\App\Resources\TeamMembers\Pages\ListTeamMembers;
use App\Models\Company;
use App\Models\User;
use App\Services\Company\CompanyPermissionService;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class TeamMemberResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $slug = 'equipe';

    protected static ?string $modelLabel = 'membro da equipe';

    protected static ?string $pluralModelLabel = 'equipe';

    protected static ?string $navigationLabel = 'Equipe e permissões';

    protected static string|UnitEnum|null $navigationGroup = 'Configurações';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static bool $isScopedToTenant = false;

    public static function canViewAny(): bool
    {
        return static::allowsManagement();
    }

    public static function canCreate(): bool
    {
        return static::allowsManagement();
    }

    public static function canEdit(Model $record): bool
    {
        return static::allowsManagement() && static::getEloquentQuery()->whereKey($record)->exists();
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    protected static function allowsManagement(): bool
    {
        $company = Filament::getTenant();

        return $company instanceof Company && auth()->user() instanceof User
            && app(CompanyPermissionService::class)->allows(auth()->user(), $company, CompanyPermission::ManagePermissions);
    }

    public static function getEloquentQuery(): Builder
    {
        $company = Filament::getTenant();

        return parent::getEloquentQuery()->whereHas('companies', fn (Builder $query): Builder => $query->where('companies.id', $company?->getKey()));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([Section::make('Acesso à clínica')->schema([
            TextInput::make('name')->label('Nome')->required()->maxLength(255),
            TextInput::make('email')->label('E-mail')->email()->required()->maxLength(255),
            TextInput::make('password')->label('Senha')->password()->required(fn (string $operation): bool => $operation === 'create')->dehydrated(fn (?string $state): bool => filled($state)),
            Select::make('role')->label('Papel')->options(CompanyRole::options())->required()->live(),
            Toggle::make('membership_active')->label('Acesso ativo')->default(true),
            Toggle::make('use_role_defaults')->label('Usar permissões padrão do papel')->default(true)->live(),
            CheckboxList::make('permissions')->label('Permissões personalizadas')->options(CompanyPermission::options())->columns(2)->visible(fn (Get $get): bool => ! (bool) $get('use_role_defaults'))->columnSpanFull(),
        ])->columns(2)]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label('Nome')->searchable()->sortable(),
            TextColumn::make('email')->label('E-mail')->searchable(),
            TextColumn::make('membership_role')->label('Papel')->state(function (User $record): string {
                $company = Filament::getTenant();
                $role = $record->companies()->where('companies.id', $company?->getKey())->first()?->pivot?->role;
                $role = $role instanceof CompanyRole ? $role : CompanyRole::tryFrom((string) $role);

                return $role?->label() ?? '—';
            }),
            IconColumn::make('membership_active')->label('Acesso ativo')->boolean()->state(fn (User $record): bool => (bool) $record->companies()->where('companies.id', Filament::getTenant()?->getKey())->first()?->pivot?->is_active),
        ])->defaultSort('name');
    }

    public static function getPages(): array
    {
        return ['index' => ListTeamMembers::route('/'), 'create' => CreateTeamMember::route('/create'), 'edit' => EditTeamMember::route('/{record}/edit')];
    }
}
