<?php

namespace App\Filament\App\Pages;

use Filament\Facades\Filament;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;

class Dashboard extends BaseDashboard
{
    protected static ?string $navigationLabel = 'Início';

    protected static ?string $title = 'Início';

    public function getTitle(): string
    {
        return Filament::getTenant()?->name ?? 'Início';
    }

    public function content(Schema $schema): Schema
    {
        $companyName = Filament::getTenant()?->name ?? 'Empresa';
        $userName = auth()->user()?->name ?? 'Usuário';

        return $schema
            ->components([
                Section::make('Bem-vindo')
                    ->schema([
                        Text::make("Empresa: {$companyName}")
                            ->weight(FontWeight::SemiBold),
                        Text::make("Usuário: {$userName}"),
                        Text::make('Sistema em configuração')
                            ->color('gray'),
                    ]),
            ]);
    }
}
