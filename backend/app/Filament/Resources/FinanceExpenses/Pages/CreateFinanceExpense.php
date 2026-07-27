<?php

namespace App\Filament\Resources\FinanceExpenses\Pages;

use App\Filament\Resources\FinanceExpenses\FinanceExpenseResource;
use Filament\Resources\Pages\CreateRecord;

class CreateFinanceExpense extends CreateRecord
{
    protected static string $resource = FinanceExpenseResource::class;
}
