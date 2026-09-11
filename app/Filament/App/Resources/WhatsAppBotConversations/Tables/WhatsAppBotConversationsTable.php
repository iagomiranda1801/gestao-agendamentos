<?php

namespace App\Filament\App\Resources\WhatsAppBotConversations\Tables;

use App\Enums\WhatsAppBotConversationState;
use App\Models\WhatsAppBotConversation;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class WhatsAppBotConversationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('phone_normalized')
                    ->label('Telefone')
                    ->searchable()
                    ->formatStateUsing(fn (string $state): string => static::formatPhone($state)),
                TextColumn::make('state')
                    ->label('Etapa')
                    ->badge()
                    ->formatStateUsing(fn (WhatsAppBotConversationState $state): string => $state->label()),
                TextColumn::make('appointment.public_confirmation_code')
                    ->label('Agendamento')
                    ->placeholder('—'),
                TextColumn::make('finished_reason')
                    ->label('Encerramento')
                    ->placeholder('Em andamento'),
                TextColumn::make('last_activity_at')
                    ->label('Última atividade')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                TextColumn::make('finished_at')
                    ->label('Encerrada em')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->defaultSort('last_activity_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'active' => 'Em andamento',
                        'finished' => 'Encerradas',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value'] ?? null) {
                            'active' => $query->whereNull('finished_at'),
                            'finished' => $query->whereNotNull('finished_at'),
                            default => $query,
                        };
                    }),
                SelectFilter::make('state')
                    ->label('Etapa')
                    ->options(collect(WhatsAppBotConversationState::cases())
                        ->mapWithKeys(fn (WhatsAppBotConversationState $state): array => [$state->value => $state->label()])
                        ->all()),
            ])
            ->recordActions([])
            ->bulkActions([]);
    }

    protected static function formatPhone(string $digits): string
    {
        $normalized = preg_replace('/\D+/', '', $digits) ?? $digits;
        $length = strlen($normalized);

        if ($length === 13 && str_starts_with($normalized, '55')) {
            $ddd = substr($normalized, 2, 2);
            $part1 = substr($normalized, 4, 5);
            $part2 = substr($normalized, 9);

            return "+55 ({$ddd}) {$part1}-{$part2}";
        }

        return $digits;
    }
}
