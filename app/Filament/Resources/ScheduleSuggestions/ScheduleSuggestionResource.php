<?php

namespace App\Filament\Resources\ScheduleSuggestions;

use App\Filament\Resources\ScheduleSuggestions\Pages\ListScheduleSuggestions;
use App\Filament\Resources\ScheduleSuggestions\Tables\ScheduleSuggestionsTable;
use App\Models\ScheduleSuggestion;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ScheduleSuggestionResource extends Resource
{
    protected static ?string $model = ScheduleSuggestion::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLightBulb;

    protected static ?string $navigationLabel = 'Schedule Suggestions';

    protected static string|\UnitEnum|null $navigationGroup = 'Scheduling';

    protected static ?int $navigationSort = 2;

    public static function table(Table $table): Table
    {
        return ScheduleSuggestionsTable::configure($table);
    }

    // Read-only in the panel — suggestions are created and actioned via the API/app.
    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListScheduleSuggestions::route('/'),
        ];
    }
}
