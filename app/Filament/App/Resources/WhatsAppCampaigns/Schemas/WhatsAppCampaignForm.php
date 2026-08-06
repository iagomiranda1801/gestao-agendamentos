<?php

namespace App\Filament\App\Resources\WhatsAppCampaigns\Schemas;

use App\Enums\WhatsAppCampaignAudience;
use App\Enums\WhatsAppCampaignStatus;
use App\Models\Client;
use App\Models\WhatsAppCampaign;
use Filament\Facades\Filament;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class WhatsAppCampaignForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Campanha')
                    ->schema([
                        Select::make('audience_type')
                            ->label('Para quem?')
                            ->options(WhatsAppCampaignAudience::options())
                            ->default(WhatsAppCampaignAudience::OptedInActiveClients->value)
                            ->required()
                            ->native(false)
                            ->live(),
                        Select::make('selected_client_ids')
                            ->label('Clientes autorizados')
                            ->helperText('Apenas clientes ativos que autorizaram campanhas aparecem aqui.')
                            ->options(fn (): array => self::clientOptions())
                            ->getSearchResultsUsing(fn (string $search): array => self::searchClientOptions($search))
                            ->getOptionLabelsUsing(fn (array $values): array => self::clientOptionLabels($values))
                            ->multiple()
                            ->searchable()
                            ->preload()
                            ->native(false)
                            ->required(fn (Get $get): bool => $get('audience_type') === WhatsAppCampaignAudience::SelectedClients->value)
                            ->visible(fn (Get $get): bool => $get('audience_type') === WhatsAppCampaignAudience::SelectedClients->value)
                            ->live()
                            ->columnSpanFull(),
                        Placeholder::make('audience_summary')
                            ->label('Destinatários')
                            ->content(fn (Get $get): string => self::audienceSummary($get)),
                        Select::make('message_suggestion')
                            ->label('Começar com um modelo')
                            ->placeholder('Mensagem em branco')
                            ->options(self::messageSuggestions())
                            ->native(false)
                            ->live()
                            ->dehydrated(false)
                            ->afterStateUpdated(function (?string $state, Set $set): void {
                                if ($state !== null) {
                                    $set('message_template', self::messageSuggestions()[$state]);
                                }
                            }),
                        Textarea::make('message_template')
                            ->label('O que você quer dizer?')
                            ->helperText('Use {nome} e {empresa} para personalizar a mensagem.')
                            ->rows(8)
                            ->maxLength(4000)
                            ->required()
                            ->live(debounce: 500)
                            ->columnSpanFull(),
                        Placeholder::make('message_preview')
                            ->label('Prévia')
                            ->content(fn (Get $get): string => self::messagePreview($get))
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
                Section::make('Enviar')
                    ->description('A lista será preparada automaticamente quando você confirmar.')
                    ->schema([
                        Select::make('delivery_type')
                            ->label('Quando enviar?')
                            ->options([
                                'now' => 'Enviar agora',
                                'scheduled' => 'Agendar envio',
                            ])
                            ->default(fn (?WhatsAppCampaign $record): string => $record?->scheduled_at ? 'scheduled' : 'now')
                            ->required()
                            ->native(false)
                            ->live(),
                        DateTimePicker::make('scheduled_at')
                            ->label('Data e hora do envio')
                            ->seconds(false)
                            ->minDate(now())
                            ->required(fn (Get $get): bool => $get('delivery_type') === 'scheduled')
                            ->visible(fn (Get $get): bool => $get('delivery_type') === 'scheduled'),
                    ])
                    ->columns(2),
                Section::make('Opções avançadas')
                    ->schema([
                        TextInput::make('name')
                            ->label('Nome interno')
                            ->helperText('Se ficar em branco, um nome será criado automaticamente.')
                            ->maxLength(255),
                        TextInput::make('send_interval_seconds')
                            ->label('Intervalo entre mensagens (segundos)')
                            ->numeric()
                            ->minValue(10)
                            ->maxValue(300)
                            ->default(20)
                            ->required(),
                    ])
                    ->columns(2)
                    ->collapsible()
                    ->collapsed(),
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
            ->whatsappMarketingOptedIn()
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
            ->whatsappMarketingOptedIn()
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

    /**
     * @return array<string, string>
     */
    protected static function messageSuggestions(): array
    {
        return [
            'promotion' => 'Promoção: confira uma condição especial preparada para você, {nome}!',
            'news' => 'Novidade na {empresa}! Olá, {nome}, temos algo novo para você conhecer.',
            'birthday' => 'Feliz aniversário, {nome}! A equipe da {empresa} deseja um dia especial para você.',
        ];
    }

    protected static function audienceSummary(Get $get): string
    {
        $query = Client::query()
            ->where('company_id', Filament::getTenant()?->getKey())
            ->active()
            ->whatsappMarketingOptedIn()
            ->whereNotNull('phone_normalized')
            ->where('phone_normalized', '!=', '');

        if ($get('audience_type') === WhatsAppCampaignAudience::SelectedClients->value) {
            $query->whereKey($get('selected_client_ids') ?: []);
        }

        $count = $query->count();

        return $count === 1 ? '1 cliente autorizado receberá esta mensagem.' : "{$count} clientes autorizados receberão esta mensagem.";
    }

    protected static function messagePreview(Get $get): string
    {
        $message = (string) ($get('message_template') ?? '');

        if (blank($message)) {
            return 'Escreva a mensagem para ver uma prévia.';
        }

        return strtr($message, [
            '{nome}' => 'Maria',
            '{empresa}' => Filament::getTenant()?->name ?? 'sua empresa',
        ]);
    }
}
