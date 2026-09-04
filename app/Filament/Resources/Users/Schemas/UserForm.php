<?php

namespace App\Filament\Resources\Users\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Hash;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('email')
                    ->label('Email address')
                    ->email()
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),
                Select::make('role')
                    ->options([
                        'admin' => 'Admin',
                        'coordinator' => 'Coordinator',
                        'surgeon' => 'Surgeon',
                    ])
                    ->default('coordinator')
                    ->required()
                    ->live(),
                TextInput::make('specialty')
                    ->maxLength(255)
                    // Only shown / required for surgeons.
                    ->visible(fn ($get) => $get('role') === 'surgeon')
                    ->required(fn ($get) => $get('role') === 'surgeon'),
                TextInput::make('password')
                    ->password()
                    ->revealable()
                    ->minLength(8)
                    ->dehydrateStateUsing(fn ($state) => Hash::make($state))
                    // Only persist if a value was entered (edit form leaves it empty).
                    ->dehydrated(fn ($state) => filled($state))
                    // Required only on create.
                    ->required(fn (string $operation) => $operation === 'create'),
            ]);
    }
}
