<?php

namespace App\Filament\App\Resources\Anamneses\Schemas;

use App\Models\Company;
use App\Support\ClinicalAnamnesisQuestionnaire;
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
        $company = Filament::getTenant();
        $record = method_exists($schema, 'getRecord') ? $schema->getRecord() : null;
        $snapshot = is_object($record) ? ($record->questionnaire_snapshot ?? null) : null;
        $questions = is_array($snapshot)
            ? $snapshot
            : ClinicalAnamnesisQuestionnaire::questions(
                $company instanceof Company
                    ? ClinicalAnamnesisQuestionnaire::resolve($company, auth()->user())
                    : null,
            );

        $fields = [];
        foreach ($questions as $question) {
            if (($question['kind'] ?? 'text') === 'text') {
                $fields[] = Textarea::make('answers.'.$question['key'])->label($question['label'])->rows(2)->disabled($disabled)->columnSpanFull();

                continue;
            }
            $fields[] = Select::make('answers.'.$question['key'].'.answer')->label($question['label'])->options(['yes' => 'Sim', 'no' => 'Não', 'unknown' => 'Não informado'])->required()->native(false)->disabled($disabled);
            $fields[] = Textarea::make('answers.'.$question['key'].'.details')->label('Detalhes')->rows(2)->disabled($disabled);
        }

        return $schema->columns(1)->components([
            Section::make('Anamnese')
                ->description('Selecione o paciente e responda às perguntas. Quando necessário, informe os detalhes ao lado.')
                ->schema([
                    Select::make('client_id')
                        ->label('Paciente')
                        ->relationship('client', 'name', fn (Builder $query): Builder => $query->where('company_id', Filament::getTenant()?->getKey())->active())
                        ->searchable()
                        ->preload()
                        ->required()
                        ->disabled($disabled)
                        ->columnSpanFull(),
                    ...$fields,
                ])
                ->columns(2)
                ->columnSpanFull(),
        ]);
    }
}
