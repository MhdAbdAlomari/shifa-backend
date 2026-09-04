<?php

namespace App\Filament\Resources\Surgeries\Pages;

use App\Filament\Resources\Surgeries\SurgeryResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSurgery extends CreateRecord
{
    protected static string $resource = SurgeryResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->id();
        return $data;
    }
}
