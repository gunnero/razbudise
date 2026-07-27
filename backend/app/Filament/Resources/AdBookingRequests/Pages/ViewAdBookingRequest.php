<?php

namespace App\Filament\Resources\AdBookingRequests\Pages;

use App\Filament\Resources\AdBookingRequests\AdBookingRequestResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewAdBookingRequest extends ViewRecord
{
    protected static string $resource = AdBookingRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
