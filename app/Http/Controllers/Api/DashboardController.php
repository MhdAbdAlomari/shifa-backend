<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OperatingRoom;
use App\Models\Surgery;
use Carbon\Carbon;

class DashboardController extends Controller
{
    public function stats()
    {
        $today = Carbon::today();
        $weekStart = Carbon::now()->startOfWeek();
        $weekEnd = Carbon::now()->endOfWeek();

        return response()->json([
            'surgeries_today' => Surgery::whereDate('scheduled_start', $today)->count(),
            'rooms_in_use' => OperatingRoom::where('status', 'in_use')->count(),
            'total_rooms' => OperatingRoom::count(),
            'surgeries_this_week' => Surgery::whereBetween('scheduled_start', [$weekStart, $weekEnd])->count(),
            'surgeries_completed_today' => Surgery::whereDate('scheduled_start', $today)->where('status', 'completed')->count(),
            'surgeries_in_progress' => Surgery::where('status', 'in_progress')->count(),
        ]);
    }
}
