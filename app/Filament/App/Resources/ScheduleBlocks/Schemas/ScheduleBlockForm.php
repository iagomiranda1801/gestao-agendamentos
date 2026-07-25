<?php

namespace App\Filament\App\Resources\ScheduleBlocks\Schemas;

use App\Enums\ScheduleBlockType;
use App\Models\Company;
use App\Models\Professional;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ScheduleBlockForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Bloqueio')
                    ->schema([
                        Select::make('type')
                            ->label('Tipo')
                            ->options(ScheduleBlockType::options())
                            ->required()
                            ->native(false),
                        TextInput::make('title')
                            ->label('Título')
                            ->required()
                            ->maxLength(255),
                        Select::make('professional_id')
                            ->label('Profissional')
                            ->options(fn (): array => self::professionalOptions())
                            ->searchable()
                            ->nullable()
                            ->native(false)
                            ->live(),
                        Placeholder::make('company_wide_warning')
                            ->label('')
                            ->content('Este bloqueio afetará todos os profissionais da empresa.')
                            ->visible(fn (callable $get): bool => blank($get('professional_id'))),
                        Toggle::make('is_all_day')
                            ->label('Dia inteiro')
                            ->default(false)
                            ->live(),
                        DatePicker::make('start_date')
                            ->label('Data inicial')
                            ->required()
                            ->native(false),
                        TimePicker::make('start_time')
                            ->label('Hora inicial')
                            ->seconds(false)
                            ->required(fn (callable $get): bool => ! $get('is_all_day'))
                            ->hidden(fn (callable $get): bool => (bool) $get('is_all_day')),
                        DatePicker::make('end_date')
                            ->label('Data final')
                            ->required()
                            ->native(false),
                        TimePicker::make('end_time')
                            ->label('Hora final')
                            ->seconds(false)
                            ->required(fn (callable $get): bool => ! $get('is_all_day'))
                            ->hidden(fn (callable $get): bool => (bool) $get('is_all_day')),
                        Textarea::make('reason')
                            ->label('Motivo')
                            ->rows(3)
                            ->columnSpanFull(),
                        Toggle::make('is_active')
                            ->label('Ativo')
                            ->default(true),
                    ])
                    ->columns(2),
            ]);
    }

    /**
     * @return array<int, string>
     */
    protected static function professionalOptions(): array
    {
        /** @var Company $company */
        $company = Filament::getTenant();

        return Professional::query()
            ->where('company_id', $company->getKey())
            ->active()
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }
}
