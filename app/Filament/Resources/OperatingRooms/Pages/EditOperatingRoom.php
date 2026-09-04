<?php

namespace App\Filament\Resources\OperatingRooms\Pages;

use App\Filament\Resources\OperatingRooms\OperatingRoomResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditOperatingRoom extends EditRecord
{
    protected static string $resource = OperatingRoomResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
