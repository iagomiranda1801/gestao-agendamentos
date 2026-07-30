<?php

namespace App\Filament\App\Resources\WhatsAppInstances\Schemas;

use App\Models\CompanyWhatsAppInstance;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

class WhatsAppInstanceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Conexão')
                    ->schema([
                        TextInput::make('name')
                            ->label('Nome')
                            ->default('WhatsApp principal')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('instance_name')
                            ->label('Instância Evolution')
                            ->helperText('Se deixar vazio, o sistema usa o nome da conexão para criar.')
                            ->maxLength(120),
                        TextInput::make('sender_phone')
                            ->label('Número remetente')
                            ->tel()
                            ->mask('(99) 99999-9999')
                            ->maxLength(20),
                        Toggle::make('is_default')
                            ->label('Conexão padrão')
                            ->helperText('A conexão padrão será usada nas campanhas e sincronizada com as notificações de agendamento.')
                            ->default(true),
                    ])
                    ->columns(2),
                Section::make('Estado')
                    ->schema([
                        Placeholder::make('status')
                            ->label('Status')
                            ->content(fn (?CompanyWhatsAppInstance $record): string => match ($record?->status) {
                                'open', 'connected' => 'Conectado',
                                'qrcode', 'connecting' => 'Aguardando leitura do QR code',
                                'error' => 'Erro ao provisionar na Evolution',
                                default => filled($record?->status) ? (string) $record->status : 'Não verificado',
                            }),
                        Placeholder::make('connected_at')
                            ->label('Conectado em')
                            ->content(fn (?CompanyWhatsAppInstance $record): string => $record?->connected_at?->format('d/m/Y H:i') ?? '-'),
                        Placeholder::make('qr_code')
                            ->label('QR code')
                            ->content(fn (?CompanyWhatsAppInstance $record): HtmlString|string => self::qrCodeContent((string) ($record?->qr_code ?? '')))
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->visible(fn (?CompanyWhatsAppInstance $record): bool => $record !== null),
            ]);
    }

    protected static function qrCodeContent(string $qrCode): HtmlString|string
    {
        if (blank($qrCode)) {
            return 'Use a ação Gerar QR depois de salvar a conexão.';
        }

        if (str_starts_with($qrCode, 'data:image')) {
            return new HtmlString('<img src="'.e($qrCode).'" alt="QR code WhatsApp" style="width: 220px; max-width: 100%; border-radius: 8px;">');
        }

        return $qrCode;
    }
}
