<?php

namespace App\Filament\App\Resources\Odontograms\Schemas;

use App\Models\Professional;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View as ViewComponent;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class OdontogramForm
{
    public static function configure(Schema $schema): Schema
    {
        $disabled = fn ($record): bool => $record?->status === 'finalized';

        return $schema->components([
            Section::make('Odontograma FDI')->description('Registre dentes e faces. O histórico é preservado por versões.')->schema([
                Select::make('client_id')->label('Paciente')->relationship('client', 'name', fn (Builder $query): Builder => $query->where('company_id', Filament::getTenant()?->getKey())->active())->searchable()->preload()->required()->disabled($disabled),
                Select::make('professional_id')->label('Dentista')->options(fn (): array => Professional::query()->where('company_id', Filament::getTenant()?->getKey())->where('user_id', auth()->id())->active()->pluck('name', 'id')->all())->required()->disabled($disabled),
                ViewComponent::make('filament.app.resources.odontograms.odontogram-preview')
                    ->viewData(fn (Get $get): array => ['entries' => $get('entries') ?? []])
                    ->columnSpanFull(),
                Repeater::make('entries')->label('Marcações')->schema([
                    TextInput::make('tooth')->label('Dente FDI')->required()->maxLength(2),
                    Select::make('surfaces')->label('Faces')->multiple()->options(['M' => 'Mesial', 'D' => 'Distal', 'V' => 'Vestibular', 'L' => 'Lingual/Palatina', 'O' => 'Oclusal/Incisal']),
                    Select::make('condition')->label('Condição')->required()->options([
                        'healthy' => 'Hígido', 'missing' => 'Ausente', 'extracted' => 'Extraído', 'caries' => 'Cárie',
                        'restoration' => 'Restauração', 'crown' => 'Coroa', 'implant' => 'Implante', 'root_canal' => 'Canal tratado',
                        'endodontic_indication' => 'Indicação endodôntica', 'fracture' => 'Fratura', 'prosthesis' => 'Prótese',
                        'sealant' => 'Selante', 'note' => 'Observação',
                    ]),
                    Select::make('stage')->label('Situação')->options(['existing' => 'Existente', 'planned' => 'Planejado', 'completed' => 'Concluído'])->default('existing')->required(),
                    Textarea::make('notes')->label('Observações')->rows(2)->columnSpanFull(),
                ])->columns(4)->defaultItems(0)->reorderable()->disabled($disabled)->columnSpanFull(),
            ])->columns(2),
        ]);
    }
}
