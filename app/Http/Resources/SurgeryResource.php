<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SurgeryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'patient_id' => $this->patient_id,
            'surgeon_id' => $this->surgeon_id,
            'room_id' => $this->room_id,
            'surgery_type_id' => $this->surgery_type_id,
            'created_by' => $this->created_by,
            'priority' => $this->priority,
            'scheduled_start' => $this->scheduled_start,
            'scheduled_end' => $this->scheduled_end,
            'estimated_duration_min' => $this->estimated_duration_min,
            'actual_start' => $this->actual_start,
            'actual_end' => $this->actual_end,
            'status' => $this->status,
            'patient' => new PatientResource($this->whenLoaded('patient')),
            'surgeon' => new UserResource($this->whenLoaded('surgeon')),
            'room' => new OperatingRoomResource($this->whenLoaded('room')),
            'surgery_type' => new SurgeryTypeResource($this->whenLoaded('surgeryType')),
            'creator' => new UserResource($this->whenLoaded('creator')),
        ];
    }
}
