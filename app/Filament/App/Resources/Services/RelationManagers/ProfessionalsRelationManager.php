<?php

namespace App\Filament\App\Resources\Services\RelationManagers;

use App\Enums\CommissionType;
use App\Models\Company;
use App\Models\Professional;
use App\Models\Service;
use App\Services\Service\ServiceProfessionalAssignmentService;
use Filament\Actions\AttachAction;
use Filament\Actions\DetachAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ProfessionalsRelationManager extends RelationManager
{
    protected static string $relationship = 'professionals';

    protected static ?string $title = 'Profissionais';

    protected static ?string $modelLabel = 'profissional';

    protected static ?string $pluralModelLabel = 'profissionais';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('custom_price')
                    ->label('Preço personalizado')
                    ->numeric()
                    ->prefix('R$')
                    ->step(0.01)
                    ->minValue(0),
                TextInput::make('custom_duration_minutes')
                    ->label('Duração personalizada (minutos)')
                    ->numeric()
                    ->minValue(1),
                Toggle::make('is_active')
                    ->label('Associação ativa')
                    ->default(true),
                Select::make('commission_type')
                    ->label('Comissão personalizada')
                    ->options([
                        'default' => 'Usar configuração padrão',
                        ...CommissionType::options(),
                    ])
                    ->default('default')
                    ->native(false)
                    ->live(),
                TextInput::make('commission_value')
                    ->label('Valor da comissão')
                    ->numeric()
                    ->minValue(0)
                    ->step(0.0001)
                    ->prefix(fn ($get): ?string => $get('commission_type') === CommissionType::Fixed->value ? 'R$' : null)
                    ->suffix(fn ($get): ?string => $get('commission_type') === CommissionType::Percentage->value ? '%' : null)
                    ->visible(fn ($get): bool => filled($get('commission_type'))
                        && $get('commission_type') !== 'default'
                        && $get('commission_type') !== CommissionType::None->value)
                    ->required(fn ($get): bool => filled($get('commission_type'))
                        && $get('commission_type') !== 'default'
                        && $get('commission_type') !== CommissionType::None->value),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Profissional')
                    ->searchable(),
                TextColumn::make('pivot.custom_price')
                    ->label('Preço personalizado')
                    ->money('BRL', locale: 'pt_BR')
                    ->placeholder('—'),
                TextColumn::make('pivot.custom_duration_minutes')
                    ->label('Duração personalizada')
                    ->formatStateUsing(fn (?int $state): string => $state ? "{$state} min" : '—'),
                IconColumn::make('pivot.is_active')
                    ->label('Ativo')
                    ->boolean(),
                TextColumn::make('pivot.commission_type')
                    ->label('Comissão')
                    ->formatStateUsing(function (?string $state, $record): string {
                        if (! filled($state)) {
                            return 'Padrão';
                        }

                        $type = CommissionType::from($state);
                        $value = $record->pivot->commission_value;

                        if ($type === CommissionType::None) {
                            return 'Sem comissão';
                        }

                        if ($type === CommissionType::Fixed) {
                            return 'Fixo: R$ '.number_format((float) $value, 2, ',', '.');
                        }

                        return number_format((float) $value, 4, ',', '.').'%';
                    }),
            ])
            ->headerActions([
                AttachAction::make()
                    ->label('Vincular profissional')
                    ->preloadRecordSelect()
                    ->recordSelectOptionsQuery(function ($query): void {
                        /** @var Company $company */
                        $company = Filament::getTenant();

                        /** @var Service $service */
                        $service = $this->getOwnerRecord();

                        $linkedIds = $service->professionals()->pluck('professionals.id');

                        $query
                            ->where('company_id', $company->getKey())
                            ->where('is_active', true)
                            ->when($linkedIds->isNotEmpty(), fn ($builder) => $builder->whereNotIn('id', $linkedIds));
                    })
                    ->recordSelectSearchColumns(['name', 'email'])
                    ->schema(fn (AttachAction $action): array => [
                        $action->getRecordSelect()
                            ->label('Profissional'),
                        TextInput::make('custom_price')
                            ->label('Preço personalizado')
                            ->numeric()
                            ->prefix('R$')
                            ->step(0.01)
                            ->minValue(0),
                        TextInput::make('custom_duration_minutes')
                            ->label('Duração personalizada (minutos)')
                            ->numeric()
                            ->minValue(1),
                        Toggle::make('is_active')
                            ->label('Associação ativa')
                            ->default(true),
                        Select::make('commission_type')
                            ->label('Comissão personalizada')
                            ->options([
                                'default' => 'Usar configuração padrão',
                                ...CommissionType::options(),
                            ])
                            ->default('default')
                            ->native(false)
                            ->live(),
                        TextInput::make('commission_value')
                            ->label('Valor da comissão')
                            ->numeric()
                            ->minValue(0)
                            ->step(0.0001)
                            ->prefix(fn ($get): ?string => $get('commission_type') === CommissionType::Fixed->value ? 'R$' : null)
                            ->suffix(fn ($get): ?string => $get('commission_type') === CommissionType::Percentage->value ? '%' : null)
                            ->visible(fn ($get): bool => filled($get('commission_type'))
                                && $get('commission_type') !== 'default'
                                && $get('commission_type') !== CommissionType::None->value),
                    ])
                    ->mutateFormDataUsing(function (array $data): array {
                        if (($data['commission_type'] ?? 'default') === 'default') {
                            $data['commission_type'] = null;
                            $data['commission_value'] = null;
                        }

                        return $data;
                    })
                    ->action(function (AttachAction $action, array $data, array $arguments): void {
                        /** @var Company $company */
                        $company = Filament::getTenant();

                        /** @var Service $service */
                        $service = $this->getOwnerRecord();

                        app(ServiceProfessionalAssignmentService::class)->attach($company, $service, [
                            'professional_id' => $data['recordId'],
                            'custom_price' => $data['custom_price'] ?? null,
                            'custom_duration_minutes' => $data['custom_duration_minutes'] ?? null,
                            'is_active' => $data['is_active'] ?? true,
                            'commission_type' => $data['commission_type'] ?? null,
                            'commission_value' => $data['commission_value'] ?? null,
                        ]);

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
                    ->fillForm(function (Professional $record): array {
                        $commissionType = $record->pivot->commission_type;

                        return [
                            'custom_price' => $record->pivot->custom_price,
                            'custom_duration_minutes' => $record->pivot->custom_duration_minutes,
                            'is_active' => $record->pivot->is_active,
                            'commission_type' => $commissionType instanceof CommissionType
                                ? $commissionType->value
                                : ($commissionType ? (string) $commissionType : 'default'),
                            'commission_value' => $record->pivot->commission_value,
                        ];
                    })
                    ->using(function (Professional $record, array $data): void {
                        /** @var Company $company */
                        $company = Filament::getTenant();

                        /** @var Service $service */
                        $service = $this->getOwnerRecord();

                        if (($data['commission_type'] ?? 'default') === 'default') {
                            $data['commission_type'] = null;
                            $data['commission_value'] = null;
                        }

                        app(ServiceProfessionalAssignmentService::class)->update($company, $service, $record, [
                            'custom_price' => $data['custom_price'] ?? null,
                            'custom_duration_minutes' => $data['custom_duration_minutes'] ?? null,
                            'is_active' => $data['is_active'] ?? true,
                            'commission_type' => $data['commission_type'] ?? null,
                            'commission_value' => $data['commission_value'] ?? null,
                        ]);
                    }),
                DetachAction::make()
                    ->label('Desvincular')
                    ->requiresConfirmation()
                    ->modalHeading('Desvincular profissional')
                    ->modalDescription('O profissional será desvinculado deste serviço.')
                    ->action(function (Professional $record): void {
                        /** @var Company $company */
                        $company = Filament::getTenant();

                        /** @var Service $service */
                        $service = $this->getOwnerRecord();

                        app(ServiceProfessionalAssignmentService::class)->detach($company, $service, $record);
                    }),
            ]);
    }
}
