<?php

namespace App\Filament\Resources\AdCustomers\Pages;

use App\Filament\Resources\AdCustomers\AdCustomerResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditAdCustomer extends EditRecord
{
    protected static string $resource = AdCustomerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
