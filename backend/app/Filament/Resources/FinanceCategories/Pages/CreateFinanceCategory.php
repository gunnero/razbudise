<?php

namespace App\Filament\Resources\FinanceCategories\Pages;

use App\Filament\Resources\FinanceCategories\FinanceCategoryResource;
use Filament\Resources\Pages\CreateRecord;

class CreateFinanceCategory extends CreateRecord
{
    protected static string $resource = FinanceCategoryResource::class;
}
