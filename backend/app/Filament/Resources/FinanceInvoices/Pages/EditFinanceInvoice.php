<?php

namespace App\Filament\Resources\FinanceInvoices\Pages;

use App\Filament\Resources\FinanceInvoices\FinanceInvoiceResource;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditFinanceInvoice extends EditRecord
{
    protected static string $resource = FinanceInvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
    }
}
