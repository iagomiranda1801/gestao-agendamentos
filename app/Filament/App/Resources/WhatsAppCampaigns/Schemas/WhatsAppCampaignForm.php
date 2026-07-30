<?php

namespace App\Filament\App\Resources\WhatsAppCampaigns\Schemas;

use App\Enums\WhatsAppCampaignAudience;
use App\Enums\WhatsAppCampaignStatus;
use App\Models\Client;
use App\Models\WhatsAppCampaign;
use Filament\Facades\Filament;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class WhatsAppCampaignForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Campanha')
                    ->schema([
                        TextInput::make('name')
                            ->label('Nome')
                            ->required()
                            ->maxLength(255),
                        Select::make('audience_type')
                            ->label('Público')
                            ->options(WhatsAppCampaignAudience::options())
                            ->default(WhatsAppCampaignAudience::OptedInActiveClients->value)
                            ->required()
                            ->native(false)
                            ->live(),
                        Select::make('selected_client_ids')
                            ->label('Clientes')
                            ->helperText('Selecione um ou vários clientes. Use somente clientes que autorizaram receber mensagens.')
                            ->options(fn (): array => self::clientOptions())
                            ->getSearchResultsUsing(fn (string $search): array => self::searchClientOptions($search))
                            ->getOptionLabelsUsing(fn (array $values): array => self::clientOptionLabels($values))
                            ->multiple()
                            ->searchable()
                            ->preload()
                            ->native(false)
                            ->required(fn (Get $get): bool => $get('audience_type') === WhatsAppCampaignAudience::SelectedClients->value)
                            ->visible(fn (Get $get): bool => $get('audience_type') === WhatsAppCampaignAudience::SelectedClients->value)
                            ->columnSpanFull(),
                        TextInput::make('send_interval_seconds')
                            ->label('Intervalo entre mensagens (segundos)')
                            ->helperText('Mínimo 10 segundos. Use valores maiores para bases grandes.')
                            ->numeric()
                            ->minValue(10)
                            ->maxValue(300)
                            ->default(20)
                            ->required(),
                        Textarea::make('message_template')
                            ->label('Mensagem')
                            ->helperText('Placeholders: {nome}, {empresa}. Evite spam e envie somente para quem autorizou.')
                            ->rows(8)
                            ->maxLength(4000)
                            ->required()
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
                Section::make('Status')
                    ->schema([
                        Placeholder::make('status_label')
                            ->label('Status')
                            ->content(fn (?WhatsAppCampaign $record): string => $record?->status?->label() ?? WhatsAppCampaignStatus::Draft->label()),
                        Placeholder::make('total_recipients')
                            ->label('Destinatários')
                            ->content(fn (?WhatsAppCampaign $record): int => (int) ($record?->total_recipients ?? 0)),
                    ])
                    ->columns(2)
                    ->visible(fn (?WhatsAppCampaign $record): bool => $record !== null),
            ]);
    }

    /**
     * @return array<int, string>
     */
    protected static function clientOptions(): array
    {
        return Client::query()
            ->where('company_id', Filament::getTenant()?->getKey())
            ->active()
            ->whereNotNull('phone_normalized')
            ->where('phone_normalized', '!=', '')
            ->orderBy('name')
            ->limit(100)
            ->get()
            ->mapWithKeys(fn (Client $client): array => [
                $client->getKey() => "{$client->name} - {$client->phone}",
            ])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    protected static function searchClientOptions(string $search): array
    {
        return Client::query()
            ->where('company_id', Filament::getTenant()?->getKey())
            ->active()
            ->whereNotNull('phone_normalized')
            ->where('phone_normalized', '!=', '')
            ->where(function ($query) use ($search): void {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('phone_normalized', 'like', "%{$search}%");
            })
            ->orderBy('name')
            ->limit(50)
            ->get()
            ->mapWithKeys(fn (Client $client): array => [
                $client->getKey() => "{$client->name} - {$client->phone}",
            ])
            ->all();
    }

    /**
     * @param  array<int|string>  $values
     * @return array<int, string>
     */
    protected static function clientOptionLabels(array $values): array
    {
        return Client::query()
            ->where('company_id', Filament::getTenant()?->getKey())
            ->whereKey($values)
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Client $client): array => [
                $client->getKey() => "{$client->name} - {$client->phone}",
            ])
            ->all();
    }
}
