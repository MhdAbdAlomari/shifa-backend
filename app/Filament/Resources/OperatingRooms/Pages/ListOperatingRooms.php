<?php

namespace App\Filament\Resources\OperatingRooms\Pages;

use App\Filament\Resources\OperatingRooms\OperatingRoomResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListOperatingRooms extends ListRecords
{
    protected static string $resource = OperatingRoomResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
