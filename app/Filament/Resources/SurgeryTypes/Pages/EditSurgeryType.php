<?php

namespace App\Filament\Resources\SurgeryTypes\Pages;

use App\Filament\Resources\SurgeryTypes\SurgeryTypeResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditSurgeryType extends EditRecord
{
    protected static string $resource = SurgeryTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
