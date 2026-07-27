<?php

namespace App\Filament\Resources\WordpressImportMappings;

use App\Filament\Resources\Concerns\HasLocalizedResourceLabels;
use App\Filament\Resources\Concerns\HasResourcePermissions;
use App\Filament\Resources\WordpressImportMappings\Pages\CreateWordpressImportMapping;
use App\Filament\Resources\WordpressImportMappings\Pages\EditWordpressImportMapping;
use App\Filament\Resources\WordpressImportMappings\Pages\ListWordpressImportMappings;
use App\Filament\Resources\WordpressImportMappings\Schemas\WordpressImportMappingForm;
use App\Filament\Resources\WordpressImportMappings\Tables\WordpressImportMappingsTable;
use App\Models\WordpressImportMapping;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class WordpressImportMappingResource extends Resource
{
    use HasLocalizedResourceLabels;
    use HasResourcePermissions;

    protected static ?string $model = WordpressImportMapping::class;

    protected const string VIEW_PERMISSION = 'imports.view';

    protected const string MANAGE_PERMISSION = 'imports.manage';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPath;

    protected static string|\UnitEnum|null $navigationGroup = 'Migration';

    protected static ?string $navigationLabel = 'WordPress Import Mappings';

    protected static ?int $navigationSort = 20;

    public static function form(Schema $schema): Schema
    {
        return WordpressImportMappingForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return WordpressImportMappingsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWordpressImportMappings::route('/'),
            'create' => CreateWordpressImportMapping::route('/create'),
            'edit' => EditWordpressImportMapping::route('/{record}/edit'),
        ];
    }
}
