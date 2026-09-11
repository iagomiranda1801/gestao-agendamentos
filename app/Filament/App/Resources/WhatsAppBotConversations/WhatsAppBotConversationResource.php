<?php

namespace App\Filament\App\Resources\WhatsAppBotConversations;

use App\Enums\CompanyModule;
use App\Filament\App\Concerns\RequiresCompanyModuleResource;
use App\Filament\App\Resources\WhatsAppBotConversations\Pages\ListWhatsAppBotConversations;
use App\Filament\App\Resources\WhatsAppBotConversations\Tables\WhatsAppBotConversationsTable;
use App\Models\WhatsAppBotConversation;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class WhatsAppBotConversationResource extends Resource
{
    use RequiresCompanyModuleResource;

    protected static ?string $model = WhatsAppBotConversation::class;

    protected static ?string $slug = 'whatsapp-bot-conversas';

    protected static ?string $recordTitleAttribute = 'phone_normalized';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static ?string $modelLabel = 'conversa do bot';

    protected static ?string $pluralModelLabel = 'conversas do bot';

    protected static ?string $navigationLabel = 'Bot WhatsApp';

    protected static string|UnitEnum|null $navigationGroup = 'Configurações';

    protected static ?int $navigationSort = 12;

    protected static ?string $tenantOwnershipRelationshipName = 'company';

    public static function form(Schema $schema): Schema
    {
        return $schema;
    }

    public static function table(Table $table): Table
    {
        return WhatsAppBotConversationsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWhatsAppBotConversations::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    protected static function requiredCompanyModule(): CompanyModule
    {
        return CompanyModule::WhatsApp;
    }
}
