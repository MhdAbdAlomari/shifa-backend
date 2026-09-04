<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Surgeries\SurgeryResource;
use App\Models\Surgery;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class RecentSurgeries extends TableWidget
{
    protected static ?string $heading = 'Recent surgeries';

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => Surgery::query()
                ->with(['patient', 'surgeon', 'room'])
                ->orderBy('updated_at', 'desc')
                ->limit(5))
            ->paginated(false)
            ->columns([
                TextColumn::make('patient.name')->label('Patient'),
                TextColumn::make('surgeon.name')->label('Surgeon'),
                TextColumn::make('room.name')->label('Room'),
                TextColumn::make('scheduled_start')->label('Scheduled')->dateTime('M j, H:i'),
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
            ->recordActions([
                Action::make('view')
                    ->label('Open')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (Surgery $record) => SurgeryResource::getUrl('edit', ['record' => $record])),
            ]);
    }
}
