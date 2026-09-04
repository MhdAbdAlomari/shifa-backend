<?php

namespace App\Filament\Resources\SurgeryTypes\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class SurgeryTypeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('average_duration_min')
                    ->label('Average duration (minutes)')
                    ->required()
                    ->numeric()
                    ->minValue(5)
                    ->suffix('min'),
                TextInput::make('required_specialty')
                    ->maxLength(255)
                    ->placeholder('e.g. Cardiology'),
            ]);
    }
}
