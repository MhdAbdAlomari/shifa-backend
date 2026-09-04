<?php

namespace App\Filament\Resources\SurgeryTypes\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SurgeryTypesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('average_duration_min')
                    ->label('Avg duration')
                    ->numeric()
                    ->suffix(' min')
                    ->sortable(),
                TextColumn::make('required_specialty')
                    ->label('Specialty')
                    ->badge()
                    ->color('gray')
                    ->placeholder('—'),
            ])
            ->defaultSort('name')
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
