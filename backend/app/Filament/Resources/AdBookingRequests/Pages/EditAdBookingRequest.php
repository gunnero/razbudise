<?php

namespace App\Filament\Resources\AdBookingRequests\Pages;

use App\Filament\Resources\AdBookingRequests\AdBookingRequestResource;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditAdBookingRequest extends EditRecord
{
    protected static string $resource = AdBookingRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
    }
}
