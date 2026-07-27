<?php

namespace App\Filament\Resources\Advertisers;

use App\Filament\Resources\Advertisers\Pages\CreateAdvertiser;
use App\Filament\Resources\Advertisers\Pages\EditAdvertiser;
use App\Filament\Resources\Advertisers\Pages\ListAdvertisers;
use App\Filament\Resources\Advertisers\Pages\ViewAdvertiser;
use App\Filament\Resources\Advertisers\Schemas\AdvertiserForm;
use App\Filament\Resources\Advertisers\Schemas\AdvertiserInfolist;
use App\Filament\Resources\Advertisers\Tables\AdvertisersTable;
use App\Filament\Resources\Concerns\HasLocalizedResourceLabels;
use App\Filament\Resources\Concerns\HasResourcePermissions;
use App\Models\Advertiser;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class AdvertiserResource extends Resource
{
    use HasLocalizedResourceLabels;
    use HasResourcePermissions;

    protected static ?string $model = Advertiser::class;

    protected const string VIEW_PERMISSION = 'ads.view';

    protected const string MANAGE_PERMISSION = 'ads.manage';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|\UnitEnum|null $navigationGroup = 'Advertising';

    protected static ?string $navigationLabel = 'Advertisers';

    protected static ?int $navigationSort = 10;

    public static function form(Schema $schema): Schema
    {
        return AdvertiserForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return AdvertiserInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AdvertisersTable::configure($table);
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
            'index' => ListAdvertisers::route('/'),
            'create' => CreateAdvertiser::route('/create'),
            'view' => ViewAdvertiser::route('/{record}'),
            'edit' => EditAdvertiser::route('/{record}/edit'),
        ];
    }
}
