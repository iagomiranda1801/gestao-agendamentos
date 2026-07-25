<?php

namespace App\Filament\Admin\Resources\Companies\RelationManagers;

use App\Enums\CompanyRole;
use App\Models\User;
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
                        app(CompanyMembershipGuard::class)->ensureCanRemoveLastActiveAdmin(
                            $this->getOwnerRecord(),
                            $record,
                            CompanyRole::from($data['role']),
                            (bool) ($data['is_active'] ?? true),
                        );
                    }),
                DetachAction::make()
                    ->label('Desvincular')
                    ->before(function (User $record, DetachAction $action): void {
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
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DetachBulkAction::make()
                        ->label('Desvincular selecionados')
                        ->before(function (DetachBulkAction $action, $records): void {
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
                        }),
                ]),
            ]);
    }
}
