<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SurgeryTypeResource;
use App\Models\SurgeryType;
use Illuminate\Http\Request;

class SurgeryTypeController extends Controller
{
    public function index()
    {
        return SurgeryTypeResource::collection(SurgeryType::orderBy('name')->get());
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'average_duration_min' => ['required', 'integer', 'min:5'],
            'required_specialty' => ['nullable', 'string', 'max:255'],
            'default_room_id' => ['nullable', 'exists:operating_rooms,id'],
        ]);

        $type = SurgeryType::create($data);
        return (new SurgeryTypeResource($type))->response()->setStatusCode(201);
    }

    public function show(SurgeryType $surgeryType)
    {
        return new SurgeryTypeResource($surgeryType);
    }

    public function update(Request $request, SurgeryType $surgeryType)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'average_duration_min' => ['sometimes', 'integer', 'min:5'],
            'required_specialty' => ['nullable', 'string', 'max:255'],
            'default_room_id' => ['nullable', 'exists:operating_rooms,id'],
        ]);

        $surgeryType->update($data);
        return new SurgeryTypeResource($surgeryType);
    }

    public function destroy(SurgeryType $surgeryType)
    {
        $surgeryType->delete();
        return response()->json(['message' => 'Surgery type deleted']);
    }
}
