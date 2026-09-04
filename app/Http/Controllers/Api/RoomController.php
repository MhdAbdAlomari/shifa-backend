<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\OperatingRoomResource;
use App\Models\OperatingRoom;
use Illuminate\Http\Request;

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
        ]);
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
        ]);
        $room->update($data);
        return new OperatingRoomResource($room);
    }

    public function destroy(OperatingRoom $room)
    {
        $room->delete();
        return response()->json(['message' => 'Room deleted']);
    }
}
