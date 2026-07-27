<?php

namespace App\Filament\Resources\AdCampaigns;

use App\Filament\Resources\AdCampaigns\Pages\CreateAdCampaign;
use App\Filament\Resources\AdCampaigns\Pages\EditAdCampaign;
use App\Filament\Resources\AdCampaigns\Pages\ListAdCampaigns;
use App\Filament\Resources\AdCampaigns\Pages\ViewAdCampaign;
use App\Filament\Resources\AdCampaigns\RelationManagers\CreativesRelationManager;
use App\Filament\Resources\AdCampaigns\Schemas\AdCampaignForm;
use App\Filament\Resources\AdCampaigns\Schemas\AdCampaignInfolist;
use App\Filament\Resources\AdCampaigns\Tables\AdCampaignsTable;
use App\Filament\Resources\Concerns\HasLocalizedResourceLabels;
use App\Filament\Resources\Concerns\HasResourcePermissions;
use App\Models\AdCampaign;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class AdCampaignResource extends Resource
{
    use HasLocalizedResourceLabels;
    use HasResourcePermissions;

    protected static ?string $model = AdCampaign::class;

    protected const string VIEW_PERMISSION = 'ads.view';

    protected const string MANAGE_PERMISSION = 'ads.manage';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static string|\UnitEnum|null $navigationGroup = 'Advertising';

    protected static ?string $navigationLabel = 'Ad Campaigns';

    protected static ?int $navigationSort = 20;

    public static function form(Schema $schema): Schema
    {
        return AdCampaignForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return AdCampaignInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AdCampaignsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            CreativesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAdCampaigns::route('/'),
            'create' => CreateAdCampaign::route('/create'),
            'view' => ViewAdCampaign::route('/{record}'),
            'edit' => EditAdCampaign::route('/{record}/edit'),
        ];
    }
}
