<?php

namespace App\Filament\Resources\ScheduleSuggestions\Tables;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ScheduleSuggestionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('surgery.id')
                    ->label('Surgery #')
                    ->prefix('#')
                    ->searchable(),
                TextColumn::make('surgery.patient.name')
                    ->label('Patient')
                    ->toggleable(),
                TextColumn::make('suggestedRoom.name')
                    ->label('Suggested room')
                    ->searchable(),
                TextColumn::make('suggested_start')
                    ->label('Suggested start')
                    ->dateTime('M j, H:i')
                    ->sortable(),
                TextColumn::make('reason')
                    ->wrap()
                    ->limit(80),
                TextColumn::make('status')
                    ->badge()
                    ->colors([
                        'warning' => 'pending',
                        'success' => 'accepted',
                        'gray'    => 'rejected',
                    ]),
                TextColumn::make('created_at')
                    ->dateTime('M j, H:i')
                    ->sortable()
                    ->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'pending'  => 'Pending',
                        'accepted' => 'Accepted',
                        'rejected' => 'Rejected',
                    ]),
            ])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
