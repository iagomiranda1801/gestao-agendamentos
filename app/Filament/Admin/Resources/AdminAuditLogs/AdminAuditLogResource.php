<?php

namespace App\Filament\Admin\Resources\AdminAuditLogs;

use App\Enums\AdminAuditAction;
use App\Enums\CompanyModule;
use App\Enums\CompanyProfile;
use App\Enums\CompanyRole;
use App\Enums\SubscriptionStatus;
use App\Filament\Admin\Resources\AdminAuditLogs\Pages\ListAdminAuditLogs;
use App\Models\AdminAuditLog;
use App\Models\User;
use App\Services\Admin\AdminAuditService;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use UnitEnum;

class AdminAuditLogResource extends Resource
{
    protected static ?string $model = AdminAuditLog::class;

    protected static ?string $recordTitleAttribute = 'subject_label';

    protected static ?string $modelLabel = 'log de auditoria';

    protected static ?string $pluralModelLabel = 'auditoria';

    protected static ?string $navigationLabel = 'Auditoria';

    protected static string|UnitEnum|null $navigationGroup = 'Operação';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?int $navigationSort = 4;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('occurred_at')
                    ->label('Data')
                    ->state(fn (AdminAuditLog $record): string => $record->occurred_at->clone()->timezone('America/Sao_Paulo')->format('d/m/Y'))
                    ->sortable(),
                TextColumn::make('occurred_at')
                    ->label('Hora')
                    ->state(fn (AdminAuditLog $record): string => $record->occurred_at->clone()->timezone('America/Sao_Paulo')->format('H:i:s')),
                TextColumn::make('actor_name')
                    ->label('Responsável')
                    ->description(fn (AdminAuditLog $record): string => $record->actor_email)
                    ->searchable(['actor_name', 'actor_email'])
                    ->sortable(),
                TextColumn::make('action')
                    ->label('Tipo da ação')
                    ->badge()
                    ->formatStateUsing(fn (AdminAuditAction|string|null $state): string => $state instanceof AdminAuditAction ? $state->label() : (AdminAuditAction::tryFrom((string) $state)?->label() ?? (string) $state))
                    ->sortable(),
                TextColumn::make('subject_label')
                    ->label('Entidade afetada')
                    ->searchable()
                    ->wrap(),
                TextColumn::make('summary')
                    ->label('Resumo')
                    ->state(fn (AdminAuditLog $record): string => static::summary($record))
                    ->wrap()
                    ->limit(90),
            ])
            ->filters([
                Filter::make('period')
                    ->label('Período')
                    ->schema([
                        DatePicker::make('from')->label('De')->native(false),
                        DatePicker::make('until')->label('Até')->native(false),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $timezone = 'America/Sao_Paulo';

                        return $query
                            ->when(filled($data['from'] ?? null), fn (Builder $query): Builder => $query->where('occurred_at', '>=', CarbonImmutable::parse($data['from'], $timezone)->startOfDay()->utc()))
                            ->when(filled($data['until'] ?? null), fn (Builder $query): Builder => $query->where('occurred_at', '<', CarbonImmutable::parse($data['until'], $timezone)->addDay()->startOfDay()->utc()));
                    }),
                SelectFilter::make('actor_id')
                    ->label('Responsável')
                    ->options(fn (): array => User::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->preload(),
                SelectFilter::make('action')
                    ->label('Tipo da ação')
                    ->options(AdminAuditAction::options())
                    ->searchable(),
            ])
            ->defaultSort('occurred_at', 'desc')
            ->recordActions([
                Action::make('details')
                    ->label('Ver detalhes')
                    ->icon(Heroicon::OutlinedEye)
                    ->modalHeading('Detalhes da auditoria')
                    ->modalSubmitAction(false)
                    ->modalContent(fn (AdminAuditLog $record) => view('filament.admin.resources.admin-audit-logs.details', [
                        'record' => $record,
                        'changes' => static::changes($record),
                    ])),
            ]);
    }

    /** @return array<string, mixed> */
    public static function getPages(): array
    {
        return [
            'index' => ListAdminAuditLogs::route('/'),
        ];
    }

    public static function summary(AdminAuditLog $record): string
    {
        $fields = array_unique([
            ...array_keys($record->before ?? []),
            ...array_keys($record->after ?? []),
        ]);

        if ($fields === []) {
            return $record->action instanceof AdminAuditAction
                ? $record->action->label()
                : 'Operação concluída';
        }

        return collect($fields)
            ->map(fn (string $field): string => app(AdminAuditService::class)->fieldLabel($field))
            ->take(3)
            ->implode(', ');
    }

    /** @return list<array{field: string, before: string, after: string}> */
    public static function changes(AdminAuditLog $record): array
    {
        $before = $record->before ?? [];
        $after = $record->after ?? [];

        return collect(array_unique([...array_keys($before), ...array_keys($after)]))
            ->map(fn (string $field): array => [
                'field' => app(AdminAuditService::class)->fieldLabel($field),
                'before' => static::displayValue(Arr::get($before, $field)),
                'after' => static::displayValue(Arr::get($after, $field)),
            ])
            ->values()
            ->all();
    }

    private static function displayValue(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        if (is_bool($value)) {
            return $value ? 'Sim' : 'Não';
        }

        if (is_array($value)) {
            if ($value === []) {
                return '—';
            }

            return collect($value)
                ->map(fn (mixed $item): string => static::displayValue($item))
                ->implode(', ');
        }

        $value = (string) $value;

        if (($profile = CompanyProfile::tryFrom($value)) !== null) {
            return $profile->label();
        }

        if (($subscription = SubscriptionStatus::tryFrom($value)) !== null) {
            return $subscription->label();
        }

        if (($role = CompanyRole::tryFrom($value)) !== null) {
            return $role->label();
        }

        if (($module = CompanyModule::tryFrom($value)) !== null) {
            return $module->label();
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}T/', $value) === 1) {
            return CarbonImmutable::parse($value)->timezone('America/Sao_Paulo')->format('d/m/Y H:i:s');
        }

        return Str::limit($value, 500);
    }
}
