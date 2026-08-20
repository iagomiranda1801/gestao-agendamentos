<?php

namespace App\Filament\App\Resources\TreatmentPlans\Schemas;

use App\Models\Professional;
use App\Models\Service;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class TreatmentPlanForm
{
    public static function configure(Schema $schema): Schema
    {
        $disabled = fn ($record): bool => $record?->approved_at !== null;

        return $schema->components([
            Section::make('Plano')->schema([
                Select::make('client_id')->label('Paciente')->relationship('client', 'name', fn (Builder $query): Builder => $query->where('company_id', Filament::getTenant()?->getKey())->active())->searchable()->preload()->required()->disabled($disabled),
                Select::make('professional_id')->label('Dentista responsável')->options(fn (): array => Professional::query()->where('company_id', Filament::getTenant()?->getKey())->active()->orderBy('name')->pluck('name', 'id')->all())->required()->searchable()->disabled($disabled),
                TextInput::make('title')->label('Título')->required()->maxLength(255)->disabled($disabled),
                Select::make('status')->label('Situação')->options(['draft' => 'Rascunho', 'presented' => 'Apresentado', 'partially_approved' => 'Parcialmente aprovado', 'in_progress' => 'Em execução', 'completed' => 'Concluído', 'cancelled' => 'Cancelado'])->default('draft')->disabled($disabled),
                DatePicker::make('plan_date')->label('Data')->default(today())->required()->native(false)->disabled($disabled),
                DatePicker::make('valid_until')->label('Validade')->native(false)->disabled($disabled),
                Toggle::make('is_primary')->label('Plano principal')->default(false)->disabled($disabled),
                TextInput::make('discount_amount')->label('Desconto geral')->numeric()->prefix('R$')->default(0)->disabled($disabled),
                Textarea::make('clinical_notes')->label('Observações clínicas')->rows(2)->columnSpanFull()->disabled($disabled),
                Textarea::make('commercial_notes')->label('Observações comerciais')->rows(2)->columnSpanFull()->disabled($disabled),
            ])->columns(2),
            Section::make('Procedimentos')->schema([
                Repeater::make('items')->label('Itens do plano')->schema([
                    Select::make('service_id')->label('Procedimento cadastrado')->options(fn (): array => Service::query()->where('company_id', Filament::getTenant()?->getKey())->active()->orderBy('name')->pluck('name', 'id')->all())->searchable(),
                    TextInput::make('description')->label('Descrição')->required()->maxLength(255),
                    TextInput::make('tooth')->label('Dente (FDI)')->maxLength(2),
                    Select::make('surfaces')->label('Faces')->multiple()->options(['M' => 'Mesial', 'D' => 'Distal', 'V' => 'Vestibular', 'L' => 'Lingual/Palatina', 'O' => 'Oclusal/Incisal']),
                    TextInput::make('quantity')->label('Quantidade')->integer()->minValue(1)->default(1)->required(),
                    TextInput::make('unit_price')->label('Valor unitário')->numeric()->prefix('R$')->default(0)->required(),
                    TextInput::make('discount_amount')->label('Desconto')->numeric()->prefix('R$')->default(0),
                    Select::make('priority')->label('Prioridade')->options(['low' => 'Baixa', 'normal' => 'Normal', 'high' => 'Alta', 'urgent' => 'Urgente'])->default('normal'),
                    Select::make('status')->label('Status')->options(['proposed' => 'Proposto', 'approved' => 'Aprovado', 'refused' => 'Recusado', 'scheduled' => 'Agendado', 'performed' => 'Realizado', 'cancelled' => 'Cancelado'])->default('proposed'),
                ])->columns(3)->defaultItems(1)->reorderable()->disabled($disabled)->columnSpanFull(),
            ]),
        ]);
    }
}
