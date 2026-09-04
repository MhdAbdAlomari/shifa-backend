<?php

namespace App\Filament\Resources\Surgeries\Pages;

use App\Filament\Resources\Surgeries\SurgeryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSurgeries extends ListRecords
{
    protected static string $resource = SurgeryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
