<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\OperatingRoomSlotResource;
use App\Models\OperatingRoom;
use App\Models\OperatingRoomSlot;
use App\Support\ApiError;
use Illuminate\Http\Request;

class OperatingRoomSlotController extends Controller
{
    public function index(OperatingRoom $room)
    {
        return OperatingRoomSlotResource::collection(
            $room->slots()->orderBy('day_of_week')->orderBy('start_time')->get()
        );
    }

    public function store(Request $request, OperatingRoom $room)
    {
        $data = $request->validate([
            'day_of_week' => ['required', 'integer', 'between:0,6'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
        ]);
        $data['room_id'] = $room->id;

        $slot = OperatingRoomSlot::create($data);
        return (new OperatingRoomSlotResource($slot))->response()->setStatusCode(201);
    }

    public function update(Request $request, OperatingRoom $room, OperatingRoomSlot $slot)
    {
        if ($slot->room_id !== $room->id) {
            return ApiError::make('Slot does not belong to this room.', 'slot_room_mismatch', 404);
        }

        $data = $request->validate([
            'day_of_week' => ['sometimes', 'integer', 'between:0,6'],
            'start_time' => ['sometimes', 'date_format:H:i'],
            'end_time' => ['sometimes', 'date_format:H:i'],
        ]);

        $newStart = $data['start_time'] ?? $slot->start_time;
        $newEnd = $data['end_time'] ?? $slot->end_time;
        if ($newEnd <= $newStart) {
            return ApiError::make(
                'The end time must be after the start time.',
                'slot_invalid_time_range',
                422,
                errors: ['end_time' => ['The end time must be after the start time.']],
            );
        }

        $slot->update($data);
        return new OperatingRoomSlotResource($slot);
    }

    public function destroy(OperatingRoom $room, OperatingRoomSlot $slot)
    {
        if ($slot->room_id !== $room->id) {
            return ApiError::make('Slot does not belong to this room.', 'slot_room_mismatch', 404);
        }

        $slot->delete();
        return response()->json(['message' => 'Slot deleted']);
    }
}
