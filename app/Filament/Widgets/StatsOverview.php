<?php

namespace App\Filament\Widgets;

use App\Models\OperatingRoom;
use App\Models\ScheduleSuggestion;
use App\Models\Surgery;
use Carbon\Carbon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StatsOverview extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $today = Carbon::today();
        $weekStart = Carbon::now()->startOfWeek();
        $weekEnd = Carbon::now()->endOfWeek();

        $surgeriesToday = Surgery::whereDate('scheduled_start', $today)->count();
        $roomsInUse = OperatingRoom::where('status', 'in_use')->count();
        $totalRooms = OperatingRoom::count();
        $surgeriesThisWeek = Surgery::whereBetween('scheduled_start', [$weekStart, $weekEnd])->count();
        $pendingSuggestions = ScheduleSuggestion::where('status', 'pending')->count();

        return [
            Stat::make('Surgeries today', $surgeriesToday)
                ->description('Scheduled for ' . $today->format('M j'))
                ->color('primary'),

            Stat::make('Rooms in use', "{$roomsInUse} / {$totalRooms}")
                ->description('Occupied operating rooms')
                ->color($roomsInUse >= $totalRooms ? 'danger' : 'success'),

            Stat::make('Surgeries this week', $surgeriesThisWeek)
                ->description($weekStart->format('M j') . ' – ' . $weekEnd->format('M j'))
                ->color('primary'),

            Stat::make('Pending suggestions', $pendingSuggestions)
                ->description('Awaiting coordinator review')
                ->color($pendingSuggestions > 0 ? 'warning' : 'gray'),
        ];
    }
}
