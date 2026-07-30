<?php

namespace App\Filament\App\Resources\Services\Schemas;

use App\Models\Company;
use App\Models\Professional;
use Filament\Facades\Filament;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class ServiceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Dados do serviço')
                    ->schema([
                        TextInput::make('name')
                            ->label('Nome')
                            ->required()
                            ->maxLength(255)
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (?string $state, Set $set, ?string $operation): void {
                                if ($operation !== 'create' || blank($state)) {
                                    return;
                                }

                                $set('slug', Str::slug($state));
                            }),
                        TextInput::make('slug')
                            ->label('Slug')
                            ->required()
                            ->maxLength(255),
                        Textarea::make('description')
                            ->label('Descrição')
                            ->rows(3)
                            ->columnSpanFull(),
                        TextInput::make('price')
                            ->label('Preço')
                            ->numeric()
                            ->prefix('R$')
                            ->step(0.01)
                            ->minValue(0)
                            ->required(),
                        TextInput::make('duration_minutes')
                            ->label('Duração em minutos')
                            ->numeric()
                            ->minValue(1)
                            ->required(),
                        TextInput::make('buffer_before_minutes')
                            ->label('Preparação antes do serviço')
                            ->numeric()
                            ->minValue(0)
                            ->default(0)
                            ->suffix('min'),
                        TextInput::make('buffer_after_minutes')
                            ->label('Intervalo depois do serviço')
                            ->numeric()
                            ->minValue(0)
                            ->default(0)
                            ->suffix('min'),
                        ColorPicker::make('color')
                            ->label('Cor'),
                        TextInput::make('sort_order')
                            ->label('Ordem de exibição')
                            ->numeric()
                            ->minValue(0)
                            ->default(0)
                            ->required(),
                    ])
                    ->columns(2),
                Section::make('Agendamento')
                    ->schema([
                        Toggle::make('is_bookable')
                            ->label('Disponível para agendamento')
                            ->default(true)
                            ->live(),
                        Toggle::make('is_sellable')
                            ->label('Disponível para venda no PDV')
                            ->default(true),
                        Toggle::make('is_online_booking_enabled')
                            ->label('Disponível no agendamento online')
                            ->default(true)
                            ->live(),
                        Toggle::make('is_active')
                            ->label('Serviço ativo')
                            ->default(true),
                        Select::make('professional_ids')
                            ->label('Profissionais que realizam este serviço')
                            ->multiple()
                            ->searchable()
                            ->preload()
                            ->native(false)
                            ->options(fn (): array => self::activeProfessionalOptions())
                            ->required(fn ($get): bool => (bool) $get('is_online_booking_enabled'))
                            ->helperText('Para aparecer no agendamento online, o serviço precisa estar vinculado a pelo menos um profissional com horários configurados.')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
                Section::make('Informações adicionais')
                    ->schema([
                        Textarea::make('notes')
                            ->label('Observações')
                            ->rows(4)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    /**
     * @return array<int, string>
     */
    protected static function activeProfessionalOptions(): array
    {
        /** @var Company|null $company */
        $company = Filament::getTenant();

        if (! $company) {
            return [];
        }

        return Professional::query()
            ->where('company_id', $company->getKey())
            ->where('is_active', true)
            ->where('is_bookable', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }
}
