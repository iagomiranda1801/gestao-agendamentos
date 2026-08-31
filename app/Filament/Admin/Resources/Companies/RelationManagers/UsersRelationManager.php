<?php

namespace App\Filament\Admin\Resources\Companies\RelationManagers;

use App\Enums\AdminAuditAction;
use App\Enums\CompanyRole;
use App\Models\User;
use App\Services\Admin\AdminAuditService;
use App\Services\Company\CompanyMembershipGuard;
use Filament\Actions\AttachAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DetachAction;
use Filament\Actions\DetachBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\QueryException;

class UsersRelationManager extends RelationManager
{
    protected static string $relationship = 'users';

    protected static ?string $title = 'Usuários vinculados';

    protected static ?string $modelLabel = 'usuário';

    protected static ?string $pluralModelLabel = 'usuários';

    /** @var array<int, array<string, mixed>> */
    protected array $membershipAuditBefore = [];

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('role')
                    ->label('Papel')
                    ->options(CompanyRole::options())
                    ->required()
                    ->native(false),
                Toggle::make('is_active')
                    ->label('Vínculo ativo')
                    ->default(true),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Nome')
                    ->searchable(),
                TextColumn::make('email')
                    ->label('E-mail')
                    ->searchable(),
                TextColumn::make('pivot.role')
                    ->label('Papel')
                    ->formatStateUsing(fn ($state): string => $state instanceof CompanyRole ? $state->label() : CompanyRole::from($state)->label()),
                IconColumn::make('pivot.is_active')
                    ->label('Vínculo ativo')
                    ->boolean(),
            ])
            ->headerActions([
                AttachAction::make()
                    ->label('Vincular usuário')
                    ->preloadRecordSelect()
                    ->recordSelectSearchColumns(['name', 'email'])
                    ->schema(fn (AttachAction $action): array => [
                        $action->getRecordSelect()
                            ->label('Usuário')
                            ->getSearchResultsUsing(function (string $search): array {
                                return User::query()
                                    ->where(function ($query) use ($search): void {
                                        $query->where('name', 'like', "%{$search}%")
                                            ->orWhere('email', 'like', "%{$search}%");
                                    })
                                    ->limit(50)
                                    ->pluck('name', 'id')
                                    ->all();
                            }),
                        Select::make('role')
                            ->label('Papel')
                            ->options(CompanyRole::options())
                            ->required()
                            ->native(false),
                        Toggle::make('is_active')
                            ->label('Vínculo ativo')
                            ->default(true),
                    ])
                    ->before(function (AttachAction $action, array $data): void {
                        $company = $this->getOwnerRecord();

                        if ($company->users()->where('users.id', $data['recordId'])->exists()) {
                            Notification::make()
                                ->title('Este usuário já está vinculado à empresa.')
                                ->danger()
                                ->send();

                            $action->halt();
                        }
                    })
                    ->action(function (AttachAction $action, array $data, array $arguments): void {
                        try {
                            $action->process(function () use ($data): void {
                                $this->getOwnerRecord()->users()->attach($data['recordId'], [
                                    'role' => $data['role'],
                                    'is_active' => $data['is_active'] ?? true,
                                ]);
                            });
                        } catch (QueryException) {
                            Notification::make()
                                ->title('Este usuário já está vinculado à empresa.')
                                ->danger()
                                ->send();

                            $action->halt();
                        }

                        $company = $this->getOwnerRecord();
                        $user = User::query()->find($data['recordId']);
                        $audit = app(AdminAuditService::class);
                        $actor = auth()->user();

                        if ($actor instanceof User && $user instanceof User) {
                            $audit->record(
                                $actor,
                                AdminAuditAction::CompanyMembershipAttached,
                                $user,
                                after: $audit->membershipSnapshot($company, $user),
                                company: $company,
                                subjectLabel: $audit->membershipLabel($company, $user),
                            );
                        }

                        if ($arguments['another'] ?? false) {
                            $action->sendSuccessNotification();
                            $action->record(null);
                            $action->halt();
                        }

                        $action->success();
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->label('Editar vínculo')
                    ->before(function (User $record, array $data): void {
                        $this->membershipAuditBefore[$record->getKey()] = app(AdminAuditService::class)
                            ->membershipSnapshot($this->getOwnerRecord(), $record);

                        app(CompanyMembershipGuard::class)->ensureCanRemoveLastActiveAdmin(
                            $this->getOwnerRecord(),
                            $record,
                            CompanyRole::from($data['role']),
                            (bool) ($data['is_active'] ?? true),
                        );
                    })
                    ->after(function (User $record): void {
                        $company = $this->getOwnerRecord();
                        $audit = app(AdminAuditService::class);
                        $before = $this->membershipAuditBefore[$record->getKey()] ?? [];
                        $after = $audit->membershipSnapshot($company, $record);
                        $actor = auth()->user();

                        if ($actor instanceof User && $before !== $after) {
                            $audit->record($actor, AdminAuditAction::CompanyMembershipUpdated, $record, $before, $after, $company, $audit->membershipLabel($company, $record));
                        }
                    }),
                DetachAction::make()
                    ->label('Desvincular')
                    ->before(function (User $record, DetachAction $action): void {
                        $this->membershipAuditBefore[$record->getKey()] = app(AdminAuditService::class)
                            ->membershipSnapshot($this->getOwnerRecord(), $record);

                        try {
                            app(CompanyMembershipGuard::class)->ensureCanRemoveLastActiveAdmin(
                                $this->getOwnerRecord(),
                                $record,
                                CompanyRole::CompanyAdmin,
                                false,
                            );
                        } catch (\InvalidArgumentException $exception) {
                            Notification::make()
                                ->title($exception->getMessage())
                                ->danger()
                                ->send();

                            $action->halt();
                        }
                    })
                    ->after(function (User $record): void {
                        $company = $this->getOwnerRecord();
                        $audit = app(AdminAuditService::class);
                        $actor = auth()->user();

                        if ($actor instanceof User) {
                            $audit->record(
                                $actor,
                                AdminAuditAction::CompanyMembershipDetached,
                                $record,
                                $this->membershipAuditBefore[$record->getKey()] ?? [],
                                ['linked' => false],
                                $company,
                                $audit->membershipLabel($company, $record),
                            );
                        }
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DetachBulkAction::make()
                        ->label('Desvincular selecionados')
                        ->before(function (DetachBulkAction $action, $records): void {
                            $audit = app(AdminAuditService::class);
                            $company = $this->getOwnerRecord();
                            $this->membershipAuditBefore = $records
                                ->mapWithKeys(fn (User $record): array => [$record->getKey() => $audit->membershipSnapshot($company, $record)])
                                ->all();

                            foreach ($records as $record) {
                                try {
                                    app(CompanyMembershipGuard::class)->ensureCanRemoveLastActiveAdmin(
                                        $this->getOwnerRecord(),
                                        $record,
                                        CompanyRole::CompanyAdmin,
                                        false,
                                    );
                                } catch (\InvalidArgumentException $exception) {
                                    Notification::make()
                                        ->title($exception->getMessage())
                                        ->danger()
                                        ->send();

                                    $action->halt();
                                }
                            }
                        })
                        ->after(function (): void {
                            $company = $this->getOwnerRecord();
                            $audit = app(AdminAuditService::class);
                            $actor = auth()->user();

                            if (! $actor instanceof User) {
                                return;
                            }

                            foreach ($this->membershipAuditBefore as $before) {
                                $user = User::query()->find($before['user_id']);

                                if ($user instanceof User) {
                                    $audit->record($actor, AdminAuditAction::CompanyMembershipDetached, $user, $before, ['linked' => false], $company, $audit->membershipLabel($company, $user));
                                }
                            }
                        }),
                ]),
            ]);
    }
}
