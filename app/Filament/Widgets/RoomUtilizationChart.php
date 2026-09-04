<?php

namespace App\Filament\Widgets;

use App\Models\OperatingRoom;
use App\Models\Surgery;
use Carbon\Carbon;
use Filament\Widgets\ChartWidget;

class RoomUtilizationChart extends ChartWidget
{
    protected ?string $heading = 'Completed surgeries per room (this week)';

    protected function getData(): array
    {
        $weekStart = Carbon::now()->startOfWeek();
        $weekEnd = Carbon::now()->endOfWeek();

        $rooms = OperatingRoom::orderBy('name')->get();
        $labels = $rooms->pluck('name')->all();

        $counts = $rooms->map(function ($room) use ($weekStart, $weekEnd) {
            return Surgery::where('room_id', $room->id)
                ->where('status', 'completed')
                ->whereBetween('scheduled_start', [$weekStart, $weekEnd])
                ->count();
        })->all();

        return [
            'datasets' => [
                [
                    'label' => 'Completed surgeries',
                    'data' => $counts,
                    'backgroundColor' => '#0F6E56',   // brand primary teal
                    'borderColor'     => '#1D9E75',   // brand secondary teal
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
