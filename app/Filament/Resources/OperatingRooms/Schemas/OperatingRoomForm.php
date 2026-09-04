<?php

namespace App\Filament\Resources\OperatingRooms\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class OperatingRoomForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required(),
                Select::make('status')
                    ->options(['free' => 'Free', 'preparing' => 'Preparing', 'in_use' => 'In use', 'cleaning' => 'Cleaning'])
                    ->default('free')
                    ->required(),
                TextInput::make('supported_specialty')
                    ->default(null),
            ]);
    }
}
