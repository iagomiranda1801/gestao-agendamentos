<?php

namespace App\Filament\App\Resources\Anamneses\Schemas;

use App\Support\DentalAnamnesisQuestionnaire;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class AnamnesisForm
{
    public static function configure(Schema $schema): Schema
    {
        $disabled = fn ($record): bool => $record !== null && $record->status !== 'draft';
        $fields = [];
        foreach (DentalAnamnesisQuestionnaire::questions() as $question) {
            if ($question['kind'] === 'text') {
                $fields[] = Textarea::make('answers.'.$question['key'])->label($question['label'])->rows(2)->disabled($disabled)->columnSpanFull();

                continue;
            }
            $fields[] = Select::make('answers.'.$question['key'].'.answer')->label($question['label'])->options(['yes' => 'Sim', 'no' => 'Não', 'unknown' => 'Não informado'])->required()->native(false)->disabled($disabled);
            $fields[] = Textarea::make('answers.'.$question['key'].'.details')->label('Detalhes')->rows(2)->disabled($disabled);
        }

        return $schema->components([
            Section::make('Paciente')->schema([
                Select::make('client_id')->label('Paciente')->relationship('client', 'name', fn (Builder $query): Builder => $query->where('company_id', Filament::getTenant()?->getKey())->active())->searchable()->preload()->required()->disabled($disabled),
            ]),
            Section::make('Questionário clínico')->description('Respostas positivas relevantes geram alertas clínicos ao concluir.')->schema($fields)->columns(2),
        ]);
    }
}
