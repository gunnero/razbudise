<?php

namespace App\Filament\Resources\AdZones;

use App\Filament\Resources\AdZones\Pages\CreateAdZone;
use App\Filament\Resources\AdZones\Pages\EditAdZone;
use App\Filament\Resources\AdZones\Pages\ListAdZones;
use App\Filament\Resources\AdZones\Pages\ViewAdZone;
use App\Filament\Resources\AdZones\Schemas\AdZoneForm;
use App\Filament\Resources\AdZones\Schemas\AdZoneInfolist;
use App\Filament\Resources\AdZones\Tables\AdZonesTable;
use App\Filament\Resources\Concerns\HasLocalizedResourceLabels;
use App\Filament\Resources\Concerns\HasResourcePermissions;
use App\Models\AdZone;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class AdZoneResource extends Resource
{
    use HasLocalizedResourceLabels;
    use HasResourcePermissions;

    protected static ?string $model = AdZone::class;

    protected const string VIEW_PERMISSION = 'ads.view';

    protected const string MANAGE_PERMISSION = 'ads.manage';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleGroup;

    protected static string|\UnitEnum|null $navigationGroup = 'Advertising';

    protected static ?string $navigationLabel = 'Ad Zones';

    protected static ?int $navigationSort = 40;

    public static function form(Schema $schema): Schema
    {
        return AdZoneForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return AdZoneInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AdZonesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAdZones::route('/'),
            'create' => CreateAdZone::route('/create'),
            'view' => ViewAdZone::route('/{record}'),
            'edit' => EditAdZone::route('/{record}/edit'),
        ];
    }
}
