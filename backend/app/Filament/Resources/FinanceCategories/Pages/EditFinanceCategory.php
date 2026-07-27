<?php

namespace App\Filament\Resources\FinanceCategories\Pages;

use App\Filament\Resources\FinanceCategories\FinanceCategoryResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditFinanceCategory extends EditRecord
{
    protected static string $resource = FinanceCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
