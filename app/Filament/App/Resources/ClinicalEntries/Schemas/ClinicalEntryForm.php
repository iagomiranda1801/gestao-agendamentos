<?php

namespace App\Filament\App\Resources\ClinicalEntries\Schemas;

use App\Models\Company;
use App\Models\Professional;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class ClinicalEntryForm
{
    public static function configure(Schema $schema): Schema
    {
        $disabled = fn ($record): bool => $record?->status === 'finalized';

        return $schema->components([
            Section::make('Identificação')->schema([
                Select::make('client_id')->label('Paciente')->relationship('client', 'name', fn (Builder $query): Builder => $query->where('company_id', Filament::getTenant()?->getKey())->active())->searchable()->preload()->required()->disabled($disabled),
                Select::make('professional_id')->label('Dentista')->options(fn (): array => self::professionalOptions())->required()->searchable()->disabled($disabled),
                DateTimePicker::make('occurred_at')->label('Data e hora')->seconds(false)->default(now())->required()->disabled($disabled),
                TagsInput::make('teeth')->label('Dentes envolvidos')->placeholder('Ex.: 11')->disabled($disabled),
            ])->columns(2),
            Section::make('Registro clínico')->schema([
                Textarea::make('chief_complaint')->label('Queixa / relato')->rows(2)->disabled($disabled),
                Textarea::make('clinical_assessment')->label('Avaliação clínica')->rows(3)->disabled($disabled),
                Textarea::make('procedure_performed')->label('Procedimento executado')->rows(3)->disabled($disabled),
                Textarea::make('materials_medications')->label('Materiais e medicamentos')->rows(2)->disabled($disabled),
                Textarea::make('anesthetic')->label('Anestésico e quantidade')->rows(2)->disabled($disabled),
                Textarea::make('complications')->label('Intercorrências')->rows(2)->disabled($disabled),
                Textarea::make('guidance')->label('Orientações fornecidas')->rows(2)->disabled($disabled),
                Textarea::make('next_steps')->label('Conduta e próximos passos')->rows(2)->disabled($disabled),
                DatePicker::make('recommended_return_at')->label('Retorno recomendado')->native(false)->disabled($disabled),
            ])->columns(2),
        ]);
    }

    protected static function professionalOptions(): array
    {
        $company = Filament::getTenant();
        if (! $company instanceof Company) {
            return [];
        }
        $query = Professional::query()->where('company_id', $company->getKey())->active()->orderBy('name');
        if (! auth()->user()?->is_super_admin) {
            $query->where('user_id', auth()->id());
        }

        return $query->pluck('name', 'id')->all();
    }
}
