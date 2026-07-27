<?php

namespace App\Filament\Resources\AdInvoices\Pages;

use App\Filament\Resources\AdInvoices\AdInvoiceResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditAdInvoice extends EditRecord
{
    protected static string $resource = AdInvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
