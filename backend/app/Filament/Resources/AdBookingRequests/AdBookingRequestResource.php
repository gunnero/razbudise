<?php

namespace App\Filament\Resources\AdBookingRequests;

use App\Filament\Resources\AdBookingRequests\Pages\CreateAdBookingRequest;
use App\Filament\Resources\AdBookingRequests\Pages\EditAdBookingRequest;
use App\Filament\Resources\AdBookingRequests\Pages\ListAdBookingRequests;
use App\Filament\Resources\AdBookingRequests\Pages\ViewAdBookingRequest;
use App\Filament\Resources\AdBookingRequests\RelationManagers\LineItemsRelationManager;
use App\Filament\Resources\AdBookingRequests\Schemas\AdBookingRequestForm;
use App\Filament\Resources\AdBookingRequests\Schemas\AdBookingRequestInfolist;
use App\Filament\Resources\AdBookingRequests\Tables\AdBookingRequestsTable;
use App\Filament\Resources\Concerns\HasLocalizedResourceLabels;
use App\Filament\Resources\Concerns\HasResourcePermissions;
use App\Models\AdBookingRequest;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class AdBookingRequestResource extends Resource
{
    use HasLocalizedResourceLabels;
    use HasResourcePermissions;

    protected static ?string $model = AdBookingRequest::class;

    protected const string VIEW_PERMISSION = 'ads.view';

    protected const string MANAGE_PERMISSION = 'ads.manage';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|\UnitEnum|null $navigationGroup = 'Advertising';

    protected static ?string $navigationLabel = 'Ad Booking Requests';

    protected static ?int $navigationSort = 5;

    public static function form(Schema $schema): Schema
    {
        return AdBookingRequestForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return AdBookingRequestInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AdBookingRequestsTable::configure($table);
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getRelations(): array
    {
        return [
            LineItemsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAdBookingRequests::route('/'),
            'create' => CreateAdBookingRequest::route('/create'),
            'view' => ViewAdBookingRequest::route('/{record}'),
            'edit' => EditAdBookingRequest::route('/{record}/edit'),
        ];
    }
}
