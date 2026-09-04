<?php

namespace App\Filament\Resources\Surgeries\Tables;

use App\Models\OperatingRoom;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Table;

class SurgeriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('patient.name')
                    ->label('Patient')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('surgeon.name')
                    ->label('Surgeon')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('room.name')
                    ->label('Room')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('surgeryType.name')
                    ->label('Type')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('priority')
                    ->badge()
                    ->colors([
                        'gray'   => 'normal',
                        'danger' => 'emergency',
                    ]),
                TextColumn::make('scheduled_start')
                    ->label('Scheduled')
                    ->dateTime('M j, H:i')
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->colors([
                        'info'    => 'scheduled',
                        'warning' => 'in_progress',
                        'success' => 'completed',
                        'gray'    => 'cancelled',
                        'danger'  => 'delayed',
                    ]),
            ])
            ->defaultSort('scheduled_start', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'scheduled'   => 'Scheduled',
                        'in_progress' => 'In progress',
                        'completed'   => 'Completed',
                        'cancelled'   => 'Cancelled',
                        'delayed'     => 'Delayed',
                    ]),
                SelectFilter::make('priority')
                    ->options(['normal' => 'Normal', 'emergency' => 'Emergency']),
                SelectFilter::make('room_id')
                    ->label('Room')
                    ->options(fn () => OperatingRoom::pluck('name', 'id')->all()),
                Filter::make('scheduled_between')
                    ->schema([
                        DatePicker::make('from')->label('From'),
                        DatePicker::make('to')->label('To'),
                    ])
                    ->query(function ($query, array $data) {
                        return $query
                            ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('scheduled_start', '>=', $d))
                            ->when($data['to'] ?? null, fn ($q, $d) => $q->whereDate('scheduled_start', '<=', $d));
                    }),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->visible(fn () => auth()->user()?->role === 'admin'),
                ]),
            ]);
    }
}
