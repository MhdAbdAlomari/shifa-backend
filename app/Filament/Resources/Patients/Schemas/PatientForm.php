<?php

namespace App\Filament\Resources\Patients\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;

class PatientForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('mrn')
                    ->label('MRN')
                    ->required()
                    ->maxLength(50)
                    ->unique(ignoreRecord: true),
                Textarea::make('medical_notes')
                    ->rows(4)
                    ->columnSpanFull(),
            ]);
    }
}
