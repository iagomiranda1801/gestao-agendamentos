<?php

namespace App\Filament\App\Resources\ScheduleBlocks\Tables;

use App\Enums\ScheduleBlockType;
use App\Models\Company;
use App\Models\ScheduleBlock;
use App\Services\Scheduling\ScheduleBlockService;
use App\Support\CompanyDateTime;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ScheduleBlocksTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->label('Título')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('type')
                    ->label('Tipo')
                    ->formatStateUsing(fn ($state) => $state->label())
                    ->sortable(),
                TextColumn::make('professional.name')
                    ->label('Profissional')
                    ->placeholder('Toda a empresa')
                    ->sortable(),
                TextColumn::make('start_at')
                    ->label('Início')
                    ->formatStateUsing(function ($state, ScheduleBlock $record): string {
                        /** @var Company $company */
                        $company = Filament::getTenant();

                        return CompanyDateTime::formatLocal($company, $record->start_at);
                    })
                    ->sortable(),
                TextColumn::make('end_at')
                    ->label('Fim')
                    ->formatStateUsing(function ($state, ScheduleBlock $record): string {
                        /** @var Company $company */
                        $company = Filament::getTenant();

                        return CompanyDateTime::formatLocal($company, $record->end_at);
                    })
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label('Status')
                    ->boolean()
                    ->trueColor('success')
                    ->falseColor('danger'),
                TextColumn::make('creator.name')
                    ->label('Criado por')
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->filters([
                Filter::make('period')
                    ->label('Período')
                    ->schema([
                        DatePicker::make('from')
                            ->label('De'),
                        DatePicker::make('until')
                            ->label('Até'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn (Builder $q, $date): Builder => $q->whereDate('start_at', '>=', $date))
                            ->when($data['until'] ?? null, fn (Builder $q, $date): Builder => $q->whereDate('end_at', '<=', $date));
                    }),
                SelectFilter::make('professional_id')
                    ->label('Profissional')
                    ->relationship('professional', 'name', fn (Builder $query): Builder => $query->where(
                        'company_id',
                        Filament::getTenant()?->getKey(),
                    )),
                SelectFilter::make('type')
                    ->label('Tipo')
                    ->options(ScheduleBlockType::options()),
                TernaryFilter::make('is_active')
                    ->label('Status')
                    ->trueLabel('Ativos')
                    ->falseLabel('Inativos')
                    ->placeholder('Todos'),
                Filter::make('company_wide')
                    ->label('Bloqueios gerais')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->whereNull('professional_id')),
            ])
            ->defaultSort('start_at', 'desc')
            ->recordActions([
                EditAction::make(),
                Action::make('activate')
                    ->label('Ativar')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (ScheduleBlock $record): bool => ! $record->is_active)
                    ->action(function (ScheduleBlock $record): void {
                        /** @var Company $company */
                        $company = Filament::getTenant();

                        app(ScheduleBlockService::class)->changeStatus($company, $record, true);
                    }),
                Action::make('deactivate')
                    ->label('Desativar')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (ScheduleBlock $record): bool => $record->is_active)
                    ->action(function (ScheduleBlock $record): void {
                        /** @var Company $company */
                        $company = Filament::getTenant();

                        app(ScheduleBlockService::class)->changeStatus($company, $record, false);
                    }),
            ])
            ->searchable();
    }
}
