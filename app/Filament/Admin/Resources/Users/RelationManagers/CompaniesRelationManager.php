<?php

namespace App\Filament\Admin\Resources\Users\RelationManagers;

use App\Enums\AdminAuditAction;
use App\Enums\CompanyRole;
use App\Models\Company;
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

class CompaniesRelationManager extends RelationManager
{
    protected static string $relationship = 'companies';

    protected static ?string $title = 'Empresas vinculadas';

    protected static ?string $modelLabel = 'empresa';

    protected static ?string $pluralModelLabel = 'empresas';

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
                TextColumn::make('slug')
                    ->label('Slug')
                    ->searchable(),
                TextColumn::make('pivot.role')
                    ->label('Papel')
                    ->formatStateUsing(fn ($state): string => $state instanceof CompanyRole ? $state->label() : CompanyRole::from($state)->label()),
                IconColumn::make('pivot.is_active')
                    ->label('Vínculo ativo')
                    ->boolean(),
                IconColumn::make('is_active')
                    ->label('Empresa ativa')
                    ->boolean(),
            ])
            ->headerActions([
                AttachAction::make()
                    ->label('Vincular empresa')
                    ->preloadRecordSelect()
                    ->recordSelectSearchColumns(['name', 'slug'])
                    ->schema(fn (AttachAction $action): array => [
                        $action->getRecordSelect()
                            ->label('Empresa')
                            ->getSearchResultsUsing(function (string $search): array {
                                return Company::query()
                                    ->where(function ($query) use ($search): void {
                                        $query->where('name', 'like', "%{$search}%")
                                            ->orWhere('slug', 'like', "%{$search}%");
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
                        $user = $this->getOwnerRecord();

                        if ($user->companies()->where('companies.id', $data['recordId'])->exists()) {
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
                                $this->getOwnerRecord()->companies()->attach($data['recordId'], [
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

                        $user = $this->getOwnerRecord();
                        $company = Company::query()->find($data['recordId']);
                        $audit = app(AdminAuditService::class);
                        $actor = auth()->user();

                        if ($actor instanceof User && $company instanceof Company) {
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
                    ->before(function (Company $record, array $data): void {
                        $this->membershipAuditBefore[$record->getKey()] = app(AdminAuditService::class)
                            ->membershipSnapshot($record, $this->getOwnerRecord());

                        app(CompanyMembershipGuard::class)->ensureCanRemoveLastActiveAdmin(
                            $record,
                            $this->getOwnerRecord(),
                            CompanyRole::from($data['role']),
                            (bool) ($data['is_active'] ?? true),
                        );
                    })
                    ->after(function (Company $record): void {
                        $user = $this->getOwnerRecord();
                        $audit = app(AdminAuditService::class);
                        $before = $this->membershipAuditBefore[$record->getKey()] ?? [];
                        $after = $audit->membershipSnapshot($record, $user);
                        $actor = auth()->user();

                        if ($actor instanceof User && $before !== $after) {
                            $audit->record($actor, AdminAuditAction::CompanyMembershipUpdated, $user, $before, $after, $record, $audit->membershipLabel($record, $user));
                        }
                    }),
                DetachAction::make()
                    ->label('Desvincular')
                    ->before(function (Company $record, DetachAction $action): void {
                        $this->membershipAuditBefore[$record->getKey()] = app(AdminAuditService::class)
                            ->membershipSnapshot($record, $this->getOwnerRecord());

                        try {
                            app(CompanyMembershipGuard::class)->ensureCanRemoveLastActiveAdmin(
                                $record,
                                $this->getOwnerRecord(),
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
                    ->after(function (Company $record): void {
                        $user = $this->getOwnerRecord();
                        $audit = app(AdminAuditService::class);
                        $actor = auth()->user();

                        if ($actor instanceof User) {
                            $audit->record(
                                $actor,
                                AdminAuditAction::CompanyMembershipDetached,
                                $user,
                                $this->membershipAuditBefore[$record->getKey()] ?? [],
                                ['linked' => false],
                                $record,
                                $audit->membershipLabel($record, $user),
                            );
                        }
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DetachBulkAction::make()
                        ->label('Desvincular selecionados')
                        ->before(function ($records): void {
                            $audit = app(AdminAuditService::class);
                            $user = $this->getOwnerRecord();
                            $this->membershipAuditBefore = $records
                                ->mapWithKeys(fn (Company $company): array => [$company->getKey() => $audit->membershipSnapshot($company, $user)])
                                ->all();
                        })
                        ->after(function (): void {
                            $user = $this->getOwnerRecord();
                            $audit = app(AdminAuditService::class);
                            $actor = auth()->user();

                            if (! $actor instanceof User) {
                                return;
                            }

                            foreach ($this->membershipAuditBefore as $before) {
                                $company = Company::query()->find($before['company_id']);

                                if ($company instanceof Company) {
                                    $audit->record($actor, AdminAuditAction::CompanyMembershipDetached, $user, $before, ['linked' => false], $company, $audit->membershipLabel($company, $user));
                                }
                            }
                        }),
                ]),
            ]);
    }
}
