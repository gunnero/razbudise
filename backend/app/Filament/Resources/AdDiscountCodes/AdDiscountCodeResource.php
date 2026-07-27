<?php

namespace App\Filament\Resources\AdDiscountCodes;

use App\Filament\Resources\AdDiscountCodes\Pages\CreateAdDiscountCode;
use App\Filament\Resources\AdDiscountCodes\Pages\EditAdDiscountCode;
use App\Filament\Resources\AdDiscountCodes\Pages\ListAdDiscountCodes;
use App\Filament\Resources\AdDiscountCodes\Pages\ViewAdDiscountCode;
use App\Filament\Resources\AdDiscountCodes\Schemas\AdDiscountCodeForm;
use App\Filament\Resources\AdDiscountCodes\Schemas\AdDiscountCodeInfolist;
use App\Filament\Resources\AdDiscountCodes\Tables\AdDiscountCodesTable;
use App\Filament\Resources\Concerns\HasLocalizedResourceLabels;
use App\Filament\Resources\Concerns\HasResourcePermissions;
use App\Models\AdDiscountCode;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class AdDiscountCodeResource extends Resource
{
    use HasLocalizedResourceLabels;
    use HasResourcePermissions;

    protected static ?string $model = AdDiscountCode::class;

    protected const string VIEW_PERMISSION = 'ads.view';

    protected const string MANAGE_PERMISSION = 'ads.manage';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTicket;

    protected static string|\UnitEnum|null $navigationGroup = 'Advertising';

    protected static ?string $navigationLabel = 'Ad Discount Codes';

    protected static ?int $navigationSort = 7;

    public static function form(Schema $schema): Schema
    {
        return AdDiscountCodeForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return AdDiscountCodeInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AdDiscountCodesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAdDiscountCodes::route('/'),
            'create' => CreateAdDiscountCode::route('/create'),
            'view' => ViewAdDiscountCode::route('/{record}'),
            'edit' => EditAdDiscountCode::route('/{record}/edit'),
        ];
    }
}
