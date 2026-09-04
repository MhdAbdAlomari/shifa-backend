<?php

namespace App\Filament\Resources\Surgeries\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class SurgeryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Participants')
                    ->columns(2)
                    ->components([
                        Select::make('patient_id')
                            ->label('Patient')
                            ->relationship('patient', 'name')
                            ->searchable()
                            ->preload()
                            ->required(),
                        Select::make('surgeon_id')
                            ->label('Surgeon')
                            ->relationship('surgeon', 'name', fn ($query) => $query->where('role', 'surgeon'))
                            ->searchable()
                            ->preload()
                            ->required(),
                        Select::make('room_id')
                            ->label('Operating room')
                            ->relationship('room', 'name')
                            ->searchable()
                            ->preload()
                            ->required(),
                        Select::make('surgery_type_id')
                            ->label('Surgery type')
                            ->relationship('surgeryType', 'name')
                            ->searchable()
                            ->preload()
                            ->required(),
                    ]),

                Section::make('Scheduling')
                    ->columns(2)
                    ->components([
                        Select::make('priority')
                            ->options(['normal' => 'Normal', 'emergency' => 'Emergency'])
                            ->default('normal')
                            ->required(),
                        Select::make('status')
                            ->options([
                                'scheduled'   => 'Scheduled',
                                'in_progress' => 'In progress',
                                'completed'   => 'Completed',
                                'cancelled'   => 'Cancelled',
                                'delayed'     => 'Delayed',
                            ])
                            ->default('scheduled')
                            ->required(),
                        DateTimePicker::make('scheduled_start')
                            ->required()
                            ->seconds(false),
                        TextInput::make('estimated_duration_min')
                            ->label('Estimated duration')
                            ->required()
                            ->numeric()
                            ->minValue(5)
                            ->suffix('min'),
                    ]),

                Section::make('Actual timings')
                    ->columns(2)
                    ->collapsed()
                    ->components([
                        DateTimePicker::make('actual_start')->seconds(false),
                        DateTimePicker::make('actual_end')->seconds(false),
                    ]),
            ]);
    }
}
