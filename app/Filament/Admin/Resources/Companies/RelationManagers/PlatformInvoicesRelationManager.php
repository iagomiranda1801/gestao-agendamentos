<?php

namespace App\Filament\Admin\Resources\Companies\RelationManagers;

use App\Enums\BillingInterval;
use App\Enums\PlatformInvoiceStatus;
use App\Filament\Admin\Resources\PlatformInvoices\PlatformInvoiceActions;
use App\Filament\Admin\Resources\PlatformInvoices\PlatformInvoiceResource;
use App\Models\Company;
use App\Models\PlatformInvoice;
use App\Services\Company\CompanySubscriptionService;
use Filament\Actions\ViewAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PlatformInvoicesRelationManager extends RelationManager
{
    protected static string $relationship = 'platformInvoices';

    protected static ?string $title = 'Faturas';

    protected static ?string $modelLabel = 'fatura';

    protected static ?string $pluralModelLabel = 'faturas';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('number')
            ->columns([
                TextColumn::make('number')
                    ->label('Número')
                    ->searchable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (PlatformInvoiceStatus $state): string => $state->color())
                    ->formatStateUsing(fn (PlatformInvoiceStatus $state): string => $state->label()),
                TextColumn::make('amount_cents')
                    ->label('Valor')
                    ->formatStateUsing(fn (int $state): string => app(CompanySubscriptionService::class)->formatReais($state)),
                TextColumn::make('due_at')
                    ->label('Vencimento')
                    ->dateTime('d/m/Y'),
                TextColumn::make('billing_interval')
                    ->label('Ciclo')
                    ->formatStateUsing(fn (BillingInterval $state): string => $state->label()),
            ])
            ->headerActions([
                PlatformInvoiceActions::issue($this->getOwnerRecord() instanceof Company ? $this->getOwnerRecord() : null),
            ])
            ->recordActions([
                ViewAction::make()
                    ->url(fn (PlatformInvoice $record): string => PlatformInvoiceResource::getUrl('view', ['record' => $record])),
                PlatformInvoiceActions::pay(),
                PlatformInvoiceActions::markOverdue(),
                PlatformInvoiceActions::cancel(),
            ])
            ->defaultSort('due_at', 'desc');
    }
}
