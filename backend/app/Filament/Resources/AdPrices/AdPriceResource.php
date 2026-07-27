<?php

namespace App\Filament\Resources\AdPrices;

use App\Filament\Resources\AdPrices\Pages\CreateAdPrice;
use App\Filament\Resources\AdPrices\Pages\EditAdPrice;
use App\Filament\Resources\AdPrices\Pages\ListAdPrices;
use App\Filament\Resources\AdPrices\Pages\ViewAdPrice;
use App\Filament\Resources\AdPrices\Schemas\AdPriceForm;
use App\Filament\Resources\AdPrices\Schemas\AdPriceInfolist;
use App\Filament\Resources\AdPrices\Tables\AdPricesTable;
use App\Filament\Resources\Concerns\HasLocalizedResourceLabels;
use App\Filament\Resources\Concerns\HasResourcePermissions;
use App\Models\AdPrice;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class AdPriceResource extends Resource
{
    use HasLocalizedResourceLabels;
    use HasResourcePermissions;

    protected static ?string $model = AdPrice::class;

    protected const string VIEW_PERMISSION = 'ads.view';

    protected const string MANAGE_PERMISSION = 'ads.manage';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCurrencyDollar;

    protected static string|\UnitEnum|null $navigationGroup = 'Advertising';

    protected static ?string $navigationLabel = 'Ad Prices';

    protected static ?int $navigationSort = 6;

    public static function form(Schema $schema): Schema
    {
        return AdPriceForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return AdPriceInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AdPricesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAdPrices::route('/'),
            'create' => CreateAdPrice::route('/create'),
            'view' => ViewAdPrice::route('/{record}'),
            'edit' => EditAdPrice::route('/{record}/edit'),
        ];
    }
}
