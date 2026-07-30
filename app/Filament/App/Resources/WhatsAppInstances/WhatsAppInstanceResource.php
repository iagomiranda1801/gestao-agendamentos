<?php

namespace App\Filament\App\Resources\WhatsAppInstances;

use App\Enums\CompanyModule;
use App\Filament\App\Concerns\RequiresCompanyModuleResource;
use App\Filament\App\Resources\WhatsAppInstances\Pages\CreateWhatsAppInstance;
use App\Filament\App\Resources\WhatsAppInstances\Pages\EditWhatsAppInstance;
use App\Filament\App\Resources\WhatsAppInstances\Pages\ListWhatsAppInstances;
use App\Filament\App\Resources\WhatsAppInstances\Schemas\WhatsAppInstanceForm;
use App\Filament\App\Resources\WhatsAppInstances\Tables\WhatsAppInstancesTable;
use App\Models\CompanyWhatsAppInstance;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class WhatsAppInstanceResource extends Resource
{
    use RequiresCompanyModuleResource;

    protected static ?string $model = CompanyWhatsAppInstance::class;

    protected static ?string $slug = 'whatsapp-instancias';

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQrCode;

    protected static ?string $modelLabel = 'conexão WhatsApp';

    protected static ?string $pluralModelLabel = 'conexões WhatsApp';

    protected static ?string $navigationLabel = 'WhatsApp';

    protected static string|UnitEnum|null $navigationGroup = 'WhatsApp';

    protected static ?int $navigationSort = 2;

    protected static ?string $tenantOwnershipRelationshipName = 'company';

    protected static ?string $tenantRelationshipName = 'whatsappInstances';

    public static function form(Schema $schema): Schema
    {
        return WhatsAppInstanceForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return WhatsAppInstancesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWhatsAppInstances::route('/'),
            'create' => CreateWhatsAppInstance::route('/create'),
            'edit' => EditWhatsAppInstance::route('/{record}/edit'),
        ];
    }

    protected static function requiredCompanyModule(): CompanyModule
    {
        return CompanyModule::WhatsApp;
    }
}
