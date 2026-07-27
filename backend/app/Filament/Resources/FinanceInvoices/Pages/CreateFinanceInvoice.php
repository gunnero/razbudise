<?php

namespace App\Filament\Resources\FinanceInvoices\Pages;

use App\Filament\Resources\FinanceInvoices\FinanceInvoiceResource;
use Filament\Resources\Pages\CreateRecord;

class CreateFinanceInvoice extends CreateRecord
{
    protected static string $resource = FinanceInvoiceResource::class;
}
