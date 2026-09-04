<?php

namespace App\Filament\Resources\SurgeryTypes\Pages;

use App\Filament\Resources\SurgeryTypes\SurgeryTypeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSurgeryTypes extends ListRecords
{
    protected static string $resource = SurgeryTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
