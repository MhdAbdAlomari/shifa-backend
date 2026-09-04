<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use App\Models\Notification;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        return NotificationResource::collection(
            Notification::where('user_id', $request->user()->id)
                ->orderBy('created_at', 'desc')
                ->get()
        );
    }

    public function markRead(Request $request, Notification $notification)
    {
        if ($notification->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        $notification->update(['read_at' => now()]);
        return new NotificationResource($notification);
    }
}
