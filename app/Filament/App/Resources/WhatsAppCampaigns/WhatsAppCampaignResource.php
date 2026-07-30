<?php

namespace App\Filament\App\Resources\WhatsAppCampaigns;

use App\Enums\CompanyModule;
use App\Filament\App\Concerns\RequiresCompanyModuleResource;
use App\Filament\App\Resources\WhatsAppCampaigns\Pages\CreateWhatsAppCampaign;
use App\Filament\App\Resources\WhatsAppCampaigns\Pages\EditWhatsAppCampaign;
use App\Filament\App\Resources\WhatsAppCampaigns\Pages\ListWhatsAppCampaigns;
use App\Filament\App\Resources\WhatsAppCampaigns\Schemas\WhatsAppCampaignForm;
use App\Filament\App\Resources\WhatsAppCampaigns\Tables\WhatsAppCampaignsTable;
use App\Models\WhatsAppCampaign;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class WhatsAppCampaignResource extends Resource
{
    use RequiresCompanyModuleResource;

    protected static ?string $model = WhatsAppCampaign::class;

    protected static ?string $slug = 'campanhas-whatsapp';

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static ?string $modelLabel = 'campanha WhatsApp';

    protected static ?string $pluralModelLabel = 'campanhas WhatsApp';

    protected static ?string $navigationLabel = 'Campanhas WhatsApp';

    protected static string|UnitEnum|null $navigationGroup = 'Marketing';

    protected static ?int $navigationSort = 10;

    protected static ?string $tenantOwnershipRelationshipName = 'company';

    public static function form(Schema $schema): Schema
    {
        return WhatsAppCampaignForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return WhatsAppCampaignsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWhatsAppCampaigns::route('/'),
            'create' => CreateWhatsAppCampaign::route('/create'),
            'edit' => EditWhatsAppCampaign::route('/{record}/edit'),
        ];
    }

    protected static function requiredCompanyModule(): CompanyModule
    {
        return CompanyModule::Marketing;
    }
}
