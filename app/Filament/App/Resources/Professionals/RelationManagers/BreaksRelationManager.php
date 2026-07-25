<?php

namespace App\Filament\App\Resources\Professionals\RelationManagers;

use App\Enums\Weekday;
use App\Models\Company;
use App\Models\Professional;
use App\Models\ProfessionalBreak;
use App\Services\Scheduling\ProfessionalBreakService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class BreaksRelationManager extends RelationManager
{
    protected static string $relationship = 'breaks';

    protected static ?string $title = 'Intervalos';

    protected static ?string $modelLabel = 'intervalo';

    protected static ?string $pluralModelLabel = 'intervalos';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Nome')
                    ->required()
                    ->maxLength(255),
                Select::make('weekday')
                    ->label('Dia')
                    ->options(Weekday::options())
                    ->required()
                    ->native(false),
                TimePicker::make('start_time')
                    ->label('Início')
                    ->seconds(false)
                    ->required(),
                TimePicker::make('end_time')
                    ->label('Fim')
                    ->seconds(false)
                    ->required(),
                DatePicker::make('valid_from')
                    ->label('Válido de')
                    ->native(false),
                DatePicker::make('valid_until')
                    ->label('Válido até')
                    ->native(false),
                Toggle::make('is_active')
                    ->label('Ativo')
                    ->default(true),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Nome')
                    ->searchable(),
                TextColumn::make('weekday')
                    ->label('Dia')
                    ->formatStateUsing(fn (int $state): string => Weekday::from($state)->label()),
                TextColumn::make('start_time')
                    ->label('Início')
                    ->formatStateUsing(fn ($state): string => substr((string) $state, 0, 5)),
                TextColumn::make('end_time')
                    ->label('Fim')
                    ->formatStateUsing(fn ($state): string => substr((string) $state, 0, 5)),
                TextColumn::make('valid_from')
                    ->label('Válido de')
                    ->date('d/m/Y')
                    ->placeholder('—'),
                TextColumn::make('valid_until')
                    ->label('Válido até')
                    ->date('d/m/Y')
                    ->placeholder('—'),
                IconColumn::make('is_active')
                    ->label('Status')
                    ->boolean(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Adicionar intervalo')
                    ->using(function (array $data): ProfessionalBreak {
                        /** @var Company $company */
                        $company = Filament::getTenant();

                        /** @var Professional $professional */
                        $professional = $this->getOwnerRecord();

                        return app(ProfessionalBreakService::class)->create($company, $professional, $data);
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->using(function (ProfessionalBreak $record, array $data): ProfessionalBreak {
                        /** @var Company $company */
                        $company = Filament::getTenant();

                        return app(ProfessionalBreakService::class)->update($company, $record, $data);
                    }),
                Action::make('activate')
                    ->label('Ativar')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (ProfessionalBreak $record): bool => ! $record->is_active)
                    ->action(function (ProfessionalBreak $record): void {
                        /** @var Company $company */
                        $company = Filament::getTenant();

                        app(ProfessionalBreakService::class)->changeStatus($company, $record, true);
                    }),
                Action::make('deactivate')
                    ->label('Desativar')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (ProfessionalBreak $record): bool => $record->is_active)
                    ->action(function (ProfessionalBreak $record): void {
                        /** @var Company $company */
                        $company = Filament::getTenant();

                        app(ProfessionalBreakService::class)->changeStatus($company, $record, false);
                    }),
            ])
            ->defaultSort('weekday');
    }
}
