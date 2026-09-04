<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ScheduleSuggestionResource;
use App\Models\ScheduleSuggestion;

class ScheduleSuggestionController extends Controller
{
    public function index()
    {
        return ScheduleSuggestionResource::collection(
            ScheduleSuggestion::with(['surgery', 'suggestedRoom'])
                ->where('status', 'pending')
                ->orderBy('created_at', 'desc')
                ->get()
        );
    }

    public function accept(ScheduleSuggestion $suggestion)
    {
        if ($suggestion->status !== 'pending') {
            return response()->json(['message' => 'Suggestion is not pending.'], 422);
        }

        // Apply the suggestion to the surgery.
        $suggestion->surgery->update([
            'room_id' => $suggestion->suggested_room_id,
            'scheduled_start' => $suggestion->suggested_start,
            'status' => 'scheduled',
        ]);

        $suggestion->update(['status' => 'accepted']);

        // Reject sibling suggestions for the same surgery.
        ScheduleSuggestion::where('surgery_id', $suggestion->surgery_id)
            ->where('id', '!=', $suggestion->id)
            ->where('status', 'pending')
            ->update(['status' => 'rejected']);

        return new ScheduleSuggestionResource($suggestion->fresh(['surgery', 'suggestedRoom']));
    }

    public function reject(ScheduleSuggestion $suggestion)
    {
        if ($suggestion->status !== 'pending') {
            return response()->json(['message' => 'Suggestion is not pending.'], 422);
        }
        $suggestion->update(['status' => 'rejected']);
        return new ScheduleSuggestionResource($suggestion);
    }
}
