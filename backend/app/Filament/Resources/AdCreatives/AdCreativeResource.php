<?php

namespace App\Filament\Resources\AdCreatives;

use App\Filament\Resources\AdCreatives\Pages\CreateAdCreative;
use App\Filament\Resources\AdCreatives\Pages\EditAdCreative;
use App\Filament\Resources\AdCreatives\Pages\ListAdCreatives;
use App\Filament\Resources\AdCreatives\Pages\ViewAdCreative;
use App\Filament\Resources\AdCreatives\Schemas\AdCreativeForm;
use App\Filament\Resources\AdCreatives\Schemas\AdCreativeInfolist;
use App\Filament\Resources\AdCreatives\Tables\AdCreativesTable;
use App\Filament\Resources\Concerns\HasLocalizedResourceLabels;
use App\Filament\Resources\Concerns\HasResourcePermissions;
use App\Models\AdCreative;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class AdCreativeResource extends Resource
{
    use HasLocalizedResourceLabels;
    use HasResourcePermissions;

    protected static ?string $model = AdCreative::class;

    protected const string VIEW_PERMISSION = 'ads.view';

    protected const string MANAGE_PERMISSION = 'ads.manage';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhoto;

    protected static string|\UnitEnum|null $navigationGroup = 'Advertising';

    protected static ?string $navigationLabel = 'Ad Creatives';

    protected static ?int $navigationSort = 30;

    public static function form(Schema $schema): Schema
    {
        return AdCreativeForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return AdCreativeInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AdCreativesTable::configure($table);
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
            'index' => ListAdCreatives::route('/'),
            'create' => CreateAdCreative::route('/create'),
            'view' => ViewAdCreative::route('/{record}'),
            'edit' => EditAdCreative::route('/{record}/edit'),
        ];
    }
}
