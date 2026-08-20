<?php

namespace App\Filament\App\Resources\Attendances\Tables;

use App\Models\Attendance;
use App\Models\Company;
use App\Policies\AttendancePolicy;
use App\Support\CompanyDateTime;
use App\Support\CompanyTerminology;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AttendancesTable
{
    public static function configure(Table $table): Table
    {
        $canViewDistribution = fn (): bool => (new AttendancePolicy)->viewFinancialDistribution(auth()->user());

        return $table
            ->columns([
                TextColumn::make('completed_at')
                    ->label('Concluído em')
                    ->formatStateUsing(function ($state, Attendance $record): string {
                        if (! $record->completed_at) {
                            return '—';
                        }

                        /** @var Company $company */
                        $company = Filament::getTenant();

                        return CompanyDateTime::formatLocal($company, $record->completed_at);
                    })
                    ->sortable(),
                TextColumn::make('client_name_snapshot')
                    ->label(CompanyTerminology::client())
                    ->searchable()
                    ->sortable(),
                TextColumn::make('service_name_snapshot')
                    ->label('Serviço')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('professional_name_snapshot')
                    ->label(CompanyTerminology::professional())
                    ->searchable()
                    ->sortable(),
                TextColumn::make('final_amount')
                    ->label('Valor final')
                    ->money('BRL', locale: 'pt_BR')
                    ->sortable(),
                TextColumn::make('commission_amount')
                    ->label('Comissão')
                    ->money('BRL', locale: 'pt_BR')
                    ->sortable(),
                TextColumn::make('actual_material_cost')
                    ->label('Custo de materiais')
                    ->money('BRL', locale: 'pt_BR')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('materials_reserve_amount')
                    ->label('Reserva de materiais')
                    ->money('BRL', locale: 'pt_BR')
                    ->sortable()
                    ->visible($canViewDistribution)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('business_reserve_amount')
                    ->label('Reserva do negócio')
                    ->money('BRL', locale: 'pt_BR')
                    ->sortable()
                    ->visible($canViewDistribution)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('owner_allocation_amount')
                    ->label('Parcela do proprietário')
                    ->money('BRL', locale: 'pt_BR')
                    ->sortable()
                    ->visible($canViewDistribution)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('payment_fee_amount')
                    ->label('Taxas de pagamento')
                    ->money('BRL', locale: 'pt_BR')
                    ->sortable()
                    ->visible($canViewDistribution)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('operational_result')
                    ->label('Resultado operacional')
                    ->money('BRL', locale: 'pt_BR')
                    ->sortable()
                    ->visible($canViewDistribution)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('completedBy.name')
                    ->label('Concluído por')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Filter::make('completed_at')
                    ->label('Período')
                    ->schema([
                        DatePicker::make('from')
                            ->label('De')
                            ->native(false),
                        DatePicker::make('until')
                            ->label('Até')
                            ->native(false),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['from'] ?? null,
                                fn (Builder $query, string $date): Builder => $query->whereDate('completed_at', '>=', $date),
                            )
                            ->when(
                                $data['until'] ?? null,
                                fn (Builder $query, string $date): Builder => $query->whereDate('completed_at', '<=', $date),
                            );
                    }),
                SelectFilter::make('professional_id')
                    ->label('Profissional')
                    ->relationship('professional', 'name', fn (Builder $query): Builder => $query->where(
                        'company_id',
                        Filament::getTenant()?->getKey(),
                    ))
                    ->searchable()
                    ->preload()
                    ->native(false),
                SelectFilter::make('client_id')
                    ->label(CompanyTerminology::client())
                    ->relationship('client', 'name', fn (Builder $query): Builder => $query->where(
                        'company_id',
                        Filament::getTenant()?->getKey(),
                    ))
                    ->searchable()
                    ->preload()
                    ->native(false),
                SelectFilter::make('service_id')
                    ->label('Serviço')
                    ->relationship('service', 'name', fn (Builder $query): Builder => $query->where(
                        'company_id',
                        Filament::getTenant()?->getKey(),
                    ))
                    ->searchable()
                    ->preload()
                    ->native(false),
            ])
            ->defaultSort('completed_at', 'desc')
            ->recordActions([
                ViewAction::make(),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['completedBy', 'client', 'professional', 'service']));
    }
}
