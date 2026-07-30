<?php

namespace App\Filament\App\Resources\WhatsAppContacts;

use App\Enums\CompanyModule;
use App\Filament\App\Concerns\RequiresCompanyModuleResource;
use App\Filament\App\Resources\WhatsAppContacts\Pages\ListWhatsAppContacts;
use App\Filament\App\Resources\WhatsAppContacts\Tables\WhatsAppContactsTable;
use App\Models\WhatsAppContact;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class WhatsAppContactResource extends Resource
{
    use RequiresCompanyModuleResource;

    protected static ?string $model = WhatsAppContact::class;

    protected static ?string $slug = 'whatsapp-contatos';

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $modelLabel = 'contato WhatsApp';

    protected static ?string $pluralModelLabel = 'contatos WhatsApp';

    protected static ?string $navigationLabel = 'Contatos WhatsApp';

    protected static string|UnitEnum|null $navigationGroup = 'Marketing';

    protected static ?int $navigationSort = 11;

    protected static ?string $tenantOwnershipRelationshipName = 'company';

    public static function form(Schema $schema): Schema
    {
        return $schema;
    }

    public static function table(Table $table): Table
    {
        return WhatsAppContactsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWhatsAppContacts::route('/'),
        ];
    }

    protected static function requiredCompanyModule(): CompanyModule
    {
        return CompanyModule::Marketing;
    }
}
