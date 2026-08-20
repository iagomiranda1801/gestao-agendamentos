<?php

namespace App\Filament\App\Resources\Appointments\Tables;

use App\Enums\AppointmentOrigin;
use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Company;
use App\Support\CompanyDateTime;
use App\Support\CompanyTerminology;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AppointmentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('start_at')
                    ->label('Data')
                    ->formatStateUsing(function ($state, Appointment $record): string {
                        /** @var Company $company */
                        $company = Filament::getTenant();

                        return CompanyDateTime::utcToLocal($company, $record->start_at)->format('d/m/Y');
                    })
                    ->sortable(),
                TextColumn::make('start_time')
                    ->label('Hora')
                    ->state(function (Appointment $record): string {
                        /** @var Company $company */
                        $company = Filament::getTenant();

                        return CompanyDateTime::utcToLocal($company, $record->start_at)->format('H:i');
                    }),
                TextColumn::make('client.name')
                    ->label(CompanyTerminology::client())
                    ->searchable(['client.name', 'client.phone', 'client.phone_normalized'])
                    ->sortable(),
                TextColumn::make('service_name_snapshot')
                    ->label('Serviço')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('professional.name')
                    ->label(CompanyTerminology::professional())
                    ->searchable()
                    ->sortable(),
                TextColumn::make('duration_minutes_snapshot')
                    ->label('Duração')
                    ->suffix(' min')
                    ->sortable(),
                TextColumn::make('price_snapshot')
                    ->label('Preço previsto')
                    ->money('BRL', locale: 'pt_BR'),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (AppointmentStatus $state): string => $state->label())
                    ->color(fn (AppointmentStatus $state): string => match ($state) {
                        AppointmentStatus::Pending => 'warning',
                        AppointmentStatus::Confirmed => 'success',
                        AppointmentStatus::InProgress => 'info',
                        AppointmentStatus::Completed => 'success',
                        AppointmentStatus::Cancelled => 'gray',
                        AppointmentStatus::NoShow => 'danger',
                    }),
                TextColumn::make('origin')
                    ->label('Origem')
                    ->badge()
                    ->formatStateUsing(fn (AppointmentOrigin $state): string => $state->label())
                    ->color(fn (AppointmentOrigin $state): string => match ($state) {
                        AppointmentOrigin::Online => 'info',
                        default => 'gray',
                    })
                    ->toggleable(),
                TextColumn::make('public_confirmation_code')
                    ->label('Código online')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('public_booked_at')
                    ->label('Reservado online em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Filter::make('period')
                    ->label('Período')
                    ->schema([
                        DatePicker::make('from')->label('De'),
                        DatePicker::make('until')->label('Até'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn (Builder $q, $date): Builder => $q->whereDate('start_at', '>=', $date))
                            ->when($data['until'] ?? null, fn (Builder $q, $date): Builder => $q->whereDate('start_at', '<=', $date));
                    }),
                Filter::make('today')
                    ->label('Hoje')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->whereDate('start_at', today())),
                Filter::make('next_seven_days')
                    ->label('Próximos 7 dias')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query
                        ->where('start_at', '>=', now())
                        ->where('start_at', '<=', now()->addDays(7))),
                SelectFilter::make('professional_id')
                    ->label('Profissional')
                    ->relationship('professional', 'name', fn (Builder $query): Builder => $query->where(
                        'company_id',
                        Filament::getTenant()?->getKey(),
                    )),
                SelectFilter::make('service_id')
                    ->label('Serviço')
                    ->relationship('service', 'name', fn (Builder $query): Builder => $query->where(
                        'company_id',
                        Filament::getTenant()?->getKey(),
                    )),
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(AppointmentStatus::options()),
                SelectFilter::make('origin')
                    ->label('Origem')
                    ->options(AppointmentOrigin::options()),
                Filter::make('online_only')
                    ->label('Somente agendamentos online')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->where('origin', AppointmentOrigin::Online)),
                SelectFilter::make('client_id')
                    ->label(CompanyTerminology::client())
                    ->relationship('client', 'name', fn (Builder $query): Builder => $query->where(
                        'company_id',
                        Filament::getTenant()?->getKey(),
                    ))
                    ->searchable(),
            ])
            ->defaultSort('start_at')
            ->recordActions([
                ViewAction::make(),
                EditAction::make()
                    ->visible(fn (Appointment $record): bool => auth()->user()?->can('update', $record) ?? false),
            ])
            ->searchable();
    }
}
