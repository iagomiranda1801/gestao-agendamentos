<?php

namespace App\Filament\Admin\Resources\Companies\Tables;

use App\Enums\BillingInterval;
use App\Enums\CompanyModule;
use App\Enums\CompanyProfile;
use App\Enums\SubscriptionStatus;
use App\Services\Company\CompanySubscriptionService;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class CompaniesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Nome')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('slug')
                    ->label('Slug')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('business_profile')
                    ->label('Perfil')
                    ->formatStateUsing(fn (?CompanyProfile $state): string => $state?->label() ?? 'Personalizado')
                    ->badge(),
                TextColumn::make('enabled_modules')
                    ->label('Módulos')
                    ->badge()
                    ->formatStateUsing(function (array|string|null $state): array|string {
                        if (is_string($state)) {
                            return CompanyModule::tryFrom($state)?->label() ?? $state;
                        }

                        return collect($state ?? [])
                            ->map(fn (string $value) => CompanyModule::tryFrom($value)?->label() ?? $value)
                            ->all();
                    }),
                TextColumn::make('subscription_status')
                    ->label('Assinatura')
                    ->badge()
                    ->formatStateUsing(fn (?SubscriptionStatus $state): string => $state?->label() ?? '-'),
                TextColumn::make('billing_interval')
                    ->label('Ciclo')
                    ->formatStateUsing(fn (?BillingInterval $state): string => $state?->label() ?? '—')
                    ->toggleable(),
                TextColumn::make('quoted_price_cents')
                    ->label('Valor')
                    ->formatStateUsing(fn (?int $state): string => app(CompanySubscriptionService::class)->formatReais($state))
                    ->toggleable(),
                TextColumn::make('current_period_end')
                    ->label('Vigente até')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('Sem vencimento')
                    ->color(function ($state): ?string {
                        if ($state === null) {
                            return 'warning';
                        }

                        if ($state->lte(now()->addDays(7))) {
                            return 'danger';
                        }

                        return null;
                    })
                    ->sortable(),
                TextColumn::make('trial_ends_at')
                    ->label('Trial até')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('document')
                    ->label('Documento')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('phone')
                    ->label('Telefone')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('email')
                    ->label('E-mail')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('is_active')
                    ->label('Ativa')
                    ->boolean(),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Status')
                    ->trueLabel('Ativas')
                    ->falseLabel('Inativas')
                    ->placeholder('Todas'),
            ])
            ->defaultSort('name')
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->requiresConfirmation(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->requiresConfirmation(),
                ]),
            ]);
    }
}
