<?php

namespace App\Filament\Admin\Resources\SegmentSettings\Schemas;

use App\Models\SegmentSetting;
use App\Support\Segment;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class SegmentSettingForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Endereço do produto')
                    ->description('O domínio é ligado no Forge, no .env. Sem DNS e certificado o endereço não sobe.')
                    ->schema([
                        Placeholder::make('product_label')
                            ->label('Produto')
                            ->content(fn (?SegmentSetting $record): string => $record?->label() ?? '—'),
                        Placeholder::make('domain_display')
                            ->label('Domínio')
                            ->content(fn (?SegmentSetting $record): string => $record === null
                                ? '—'
                                : (string) Segment::get($record->segment, 'domain')),
                        Placeholder::make('enabled_display')
                            ->label('Situação')
                            ->content(fn (?SegmentSetting $record): string => $record !== null && Segment::isEnabled($record->segment)
                                ? 'Ligado — empresas deste perfil usam este endereço'
                                : 'Desligado — falta a variável de domínio no .env'),
                    ])
                    ->columns(3),
                Section::make('Marca')
                    ->schema([
                        TextInput::make('name')
                            ->label('Nome comercial')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('tagline')
                            ->label('Slogan')
                            ->maxLength(255),
                        ColorPicker::make('primary_color')
                            ->label('Cor principal')
                            ->required()
                            ->regex('/^#[0-9A-Fa-f]{6}$/'),
                        TextInput::make('logo_height')
                            ->label('Altura do logo')
                            ->placeholder('2.75rem')
                            ->maxLength(20),
                        FileUpload::make('logo_path')
                            ->label('Logo')
                            ->disk('public')
                            ->directory(fn (?SegmentSetting $record): string => 'segments/'.($record?->segment ?? 'produto'))
                            ->visibility('public')
                            ->image()
                            ->imagePreviewHeight('80')
                            ->maxSize(2048),
                        FileUpload::make('favicon_path')
                            ->label('Favicon')
                            ->disk('public')
                            ->directory(fn (?SegmentSetting $record): string => 'segments/'.($record?->segment ?? 'produto'))
                            ->visibility('public')
                            ->image()
                            ->imagePreviewHeight('48')
                            ->maxSize(1024),
                    ])
                    ->columns(2),
                Section::make('Tela de login')
                    ->schema([
                        FileUpload::make('login_image_path')
                            ->label('Imagem da tela de login')
                            ->disk('public')
                            ->directory(fn (?SegmentSetting $record): string => 'segments/'.($record?->segment ?? 'produto'))
                            ->visibility('public')
                            ->image()
                            ->imagePreviewHeight('120')
                            ->maxSize(4096)
                            ->columnSpanFull(),
                        TextInput::make('login.eyebrow')
                            ->label('Selo acima do título')
                            ->maxLength(255),
                        TextInput::make('login.form_title')
                            ->label('Título do formulário')
                            ->maxLength(255),
                        TextInput::make('login.headline')
                            ->label('Título da história')
                            ->maxLength(255),
                        TextInput::make('login.headline_accent')
                            ->label('Destaque do título')
                            ->maxLength(255),
                        Textarea::make('login.subtitle')
                            ->label('Subtítulo')
                            ->rows(2)
                            ->columnSpanFull(),
                        TextInput::make('login.form_subtitle')
                            ->label('Texto abaixo do título do formulário')
                            ->maxLength(255)
                            ->columnSpanFull(),
                        TextInput::make('login.signup_text')
                            ->label('Texto do cadastro')
                            ->maxLength(255),
                        TextInput::make('login.signup_link')
                            ->label('Link do cadastro')
                            ->maxLength(255),
                        Repeater::make('login.highlights')
                            ->label('Destaques')
                            ->schema([
                                TextInput::make('title')
                                    ->label('Título')
                                    ->required()
                                    ->maxLength(255),
                                TextInput::make('description')
                                    ->label('Descrição')
                                    ->maxLength(255),
                            ])
                            ->defaultItems(0)
                            ->maxItems(4)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }
}
