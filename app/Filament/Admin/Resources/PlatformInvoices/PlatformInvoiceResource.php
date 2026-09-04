<?php

namespace App\Filament\Admin\Resources\PlatformInvoices;

use App\Enums\BillingInterval;
use App\Enums\PlatformInvoiceStatus;
use App\Filament\Admin\Resources\PlatformInvoices\Pages\ListPlatformInvoices;
use App\Filament\Admin\Resources\PlatformInvoices\Pages\ViewPlatformInvoice;
use App\Models\PlatformInvoice;
use App\Services\Company\CompanySubscriptionService;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class PlatformInvoiceResource extends Resource
{
    protected static ?string $model = PlatformInvoice::class;

    protected static ?string $recordTitleAttribute = 'number';

    protected static ?string $slug = 'faturas';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $modelLabel = 'fatura';

    protected static ?string $pluralModelLabel = 'faturas';

    protected static ?string $navigationLabel = 'Faturas';

    protected static ?int $navigationSort = 4;

    public static function infolist(Schema $schema): Schema
    {
        $money = fn (?int $state): string => app(CompanySubscriptionService::class)->formatReais($state);

        return $schema
            ->components([
                Section::make('Fatura')
                    ->schema([
                        TextEntry::make('number')->label('Número'),
                        TextEntry::make('company.name')->label('Empresa'),
                        TextEntry::make('status')
                            ->label('Status')
                            ->badge()
                            ->color(fn (PlatformInvoiceStatus $state): string => $state->color())
                            ->formatStateUsing(fn (PlatformInvoiceStatus $state): string => $state->label()),
                        TextEntry::make('billing_interval')
                            ->label('Ciclo')
                            ->formatStateUsing(fn (BillingInterval $state): string => $state->label()),
                        TextEntry::make('amount_cents')
                            ->label('Valor')
                            ->formatStateUsing($money),
                        TextEntry::make('due_at')
                            ->label('Vencimento')
                            ->dateTime('d/m/Y H:i'),
                        TextEntry::make('period_start')
                            ->label('Período de')
                            ->dateTime('d/m/Y H:i'),
                        TextEntry::make('period_end')
                            ->label('Período até')
                            ->dateTime('d/m/Y H:i'),
                        TextEntry::make('paid_at')
                            ->label('Paga em')
                            ->dateTime('d/m/Y H:i')
                            ->placeholder('—'),
                        TextEntry::make('cancelled_at')
                            ->label('Cancelada em')
                            ->dateTime('d/m/Y H:i')
                            ->placeholder('—'),
                        TextEntry::make('notes')
                            ->label('Observações')
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
                Section::make('Itens')
                    ->schema([
                        RepeatableEntry::make('items')
                            ->label('')
                            ->schema([
                                TextEntry::make('label')->label('Módulo'),
                                TextEntry::make('price_cents')
                                    ->label('Valor')
                                    ->formatStateUsing($money),
                            ])
                            ->columns(2),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')
                    ->label('Número')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('company.name')
                    ->label('Empresa')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (PlatformInvoiceStatus $state): string => $state->color())
                    ->formatStateUsing(fn (PlatformInvoiceStatus $state): string => $state->label())
                    ->sortable(),
                TextColumn::make('amount_cents')
                    ->label('Valor')
                    ->formatStateUsing(fn (int $state): string => app(CompanySubscriptionService::class)->formatReais($state))
                    ->sortable(),
                TextColumn::make('due_at')
                    ->label('Vencimento')
                    ->dateTime('d/m/Y')
                    ->sortable(),
                TextColumn::make('period_end')
                    ->label('Período até')
                    ->dateTime('d/m/Y')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(PlatformInvoiceStatus::options()),
                SelectFilter::make('company_id')
                    ->label('Empresa')
                    ->relationship('company', 'name')
                    ->searchable()
                    ->preload(),
                Filter::make('due_at')
                    ->label('Vencimento')
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
                                fn (Builder $query, string $date): Builder => $query->whereDate('due_at', '>=', $date),
                            )
                            ->when(
                                $data['until'] ?? null,
                                fn (Builder $query, string $date): Builder => $query->whereDate('due_at', '<=', $date),
                            );
                    }),
            ])
            ->defaultSort('due_at', 'desc')
            ->recordActions([
                ViewAction::make(),
                PlatformInvoiceActions::pay(),
                PlatformInvoiceActions::markOverdue(),
                PlatformInvoiceActions::cancel(),
            ])
            ->recordUrl(fn (PlatformInvoice $record): string => static::getUrl('view', ['record' => $record]));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPlatformInvoices::route('/'),
            'view' => ViewPlatformInvoice::route('/{record}'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }
}
