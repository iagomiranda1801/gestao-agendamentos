<?php

namespace App\Filament\App\Resources\Clients\Tables;

use App\Filament\App\Resources\Clients\ClientResource;
use App\Models\Client;
use App\Models\Company;
use App\Services\Client\ClientService;
use App\Support\VehiclePlate;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ClientsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Nome')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('dentalProfile.record_number')
                    ->label('Prontuário')
                    ->searchable()
                    ->toggleable()
                    ->visible(fn (): bool => ($company = Filament::getTenant()) instanceof Company && $company->isDentalClinic()),
                TextColumn::make('phone')
                    ->label('Telefone')
                    ->searchable(['phone', 'phone_normalized'])
                    ->sortable(),
                TextColumn::make('vehicle_plate')
                    ->label('Placa')
                    ->formatStateUsing(fn (?string $state): ?string => VehiclePlate::format($state) ?? $state)
                    ->toggleable()
                    ->visible(fn (): bool => ($company = Filament::getTenant()) instanceof Company && $company->isCarWash()),
                TextColumn::make('email')
                    ->label('E-mail')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('birth_date')
                    ->label('Data de nascimento')
                    ->date('d/m/Y')
                    ->sortable()
                    ->toggleable(),
                IconColumn::make('is_active')
                    ->label('Status')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('danger'),
                IconColumn::make('whatsapp_marketing_opt_in')
                    ->label('Marketing WhatsApp')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('gray')
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label('Data de cadastro')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Status')
                    ->trueLabel('Ativos')
                    ->falseLabel('Inativos')
                    ->placeholder('Todos'),
                TernaryFilter::make('whatsapp_marketing_opt_in')
                    ->label('Marketing WhatsApp')
                    ->trueLabel('Com aceite')
                    ->falseLabel('Sem aceite')
                    ->placeholder('Todos'),
                Filter::make('incomplete_dental_registration')
                    ->label('Cadastro incompleto')
                    ->visible(fn (): bool => ($company = Filament::getTenant()) instanceof Company && $company->isDentalClinic())
                    ->query(fn (Builder $query): Builder => $query->where(function (Builder $query): void {
                        $query->whereNull('birth_date')
                            ->orWhereNull('document')
                            ->orWhereDoesntHave('dentalProfile');
                    })),
            ])
            ->defaultSort('name')
            ->recordUrl(function (Client $record): string {
                $company = Filament::getTenant();
                $page = $company instanceof Company && $company->isDentalClinic() ? 'view' : 'edit';

                return ClientResource::getUrl($page, ['record' => $record]);
            })
            ->recordActions([
                ViewAction::make()->visible(fn (): bool => ($company = Filament::getTenant()) instanceof Company && $company->isDentalClinic()),
                EditAction::make(),
                Action::make('activate')
                    ->label('Ativar')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (Client $record): bool => ! $record->is_active)
                    ->action(function (Client $record): void {
                        /** @var Company $company */
                        $company = Filament::getTenant();

                        app(ClientService::class)->changeStatus($company, $record, true);
                    }),
                Action::make('deactivate')
                    ->label('Desativar')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Desativar cliente')
                    ->modalDescription('O cliente será desativado, mas o histórico será preservado.')
                    ->visible(fn (Client $record): bool => $record->is_active)
                    ->action(function (Client $record): void {
                        /** @var Company $company */
                        $company = Filament::getTenant();

                        app(ClientService::class)->changeStatus($company, $record, false);
                    }),
            ])
            ->searchable()
            ->modifyQueryUsing(fn (Builder $query): Builder => $query);
    }
}
