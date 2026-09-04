<?php

namespace App\Filament\Resources\ScheduleSuggestions\Pages;

use App\Filament\Resources\ScheduleSuggestions\ScheduleSuggestionResource;
use Filament\Resources\Pages\ListRecords;

class ListScheduleSuggestions extends ListRecords
{
    protected static string $resource = ScheduleSuggestionResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
