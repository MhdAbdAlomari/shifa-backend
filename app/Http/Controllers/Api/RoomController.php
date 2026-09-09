<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\OperatingRoomResource;
use App\Http\Resources\SurgeryResource;
use App\Models\OperatingRoom;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class RoomController extends Controller
{
    public function index()
    {
        return OperatingRoomResource::collection(OperatingRoom::orderBy('name')->get());
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'status' => ['nullable', 'in:free,preparing,in_use,cleaning'],
            'supported_specialty' => ['nullable', 'string', 'max:255'],
            'image' => ['nullable', 'image', 'max:5120'],
        ]);

        if ($request->hasFile('image')) {
            $data['image_path'] = $request->file('image')->store('rooms', 'public');
        }
        unset($data['image']);

        $room = OperatingRoom::create($data);
        return (new OperatingRoomResource($room))->response()->setStatusCode(201);
    }

    public function show(OperatingRoom $room)
    {
        return new OperatingRoomResource($room);
    }

    public function update(Request $request, OperatingRoom $room)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', 'in:free,preparing,in_use,cleaning'],
            'supported_specialty' => ['nullable', 'string', 'max:255'],
            'image' => ['nullable', 'image', 'max:5120'],
        ]);

        if ($request->hasFile('image')) {
            if ($room->image_path) {
                Storage::disk('public')->delete($room->image_path);
            }
            $data['image_path'] = $request->file('image')->store('rooms', 'public');
        }
        unset($data['image']);

        $room->update($data);
        return new OperatingRoomResource($room);
    }

    public function destroy(OperatingRoom $room)
    {
        if ($room->image_path) {
            Storage::disk('public')->delete($room->image_path);
        }
        $room->delete();
        return response()->json(['message' => 'Room deleted']);
    }

    /**
     * Full surgery history/schedule for a room within a date range.
     * Query: ?from=YYYY-MM-DD&to=YYYY-MM-DD (default: today only)
     */
    public function surgeries(Request $request, OperatingRoom $room)
    {
        $from = $request->query('from') ? Carbon::parse($request->query('from'))->startOfDay() : Carbon::today();
        $to = $request->query('to') ? Carbon::parse($request->query('to'))->endOfDay() : Carbon::today()->endOfDay();

        $surgeries = $room->surgeries()
            ->with(['patient', 'surgeon', 'surgeryType'])
            ->whereBetween('scheduled_start', [$from, $to])
            ->orderBy('scheduled_start')
            ->get();

        return response()->json([
            'room' => new OperatingRoomResource($room),
            'from' => $from->toDateTimeString(),
            'to' => $to->toDateTimeString(),
            'surgeries' => SurgeryResource::collection($surgeries),
        ]);
    }
}
