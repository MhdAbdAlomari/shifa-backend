<?php

namespace App\Filament\Resources\OperatingRooms;

use App\Filament\Resources\OperatingRooms\Pages\CreateOperatingRoom;
use App\Filament\Resources\OperatingRooms\Pages\EditOperatingRoom;
use App\Filament\Resources\OperatingRooms\Pages\ListOperatingRooms;
use App\Filament\Resources\OperatingRooms\Schemas\OperatingRoomForm;
use App\Filament\Resources\OperatingRooms\Tables\OperatingRoomsTable;
use App\Models\OperatingRoom;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class OperatingRoomResource extends Resource
{
    protected static ?string $model = OperatingRoom::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?string $navigationLabel = 'Operating Rooms';

    protected static string|\UnitEnum|null $navigationGroup = 'Facilities';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return OperatingRoomForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return OperatingRoomsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOperatingRooms::route('/'),
            'create' => CreateOperatingRoom::route('/create'),
            'edit' => EditOperatingRoom::route('/{record}/edit'),
        ];
    }
}
