<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ScheduleSuggestionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'surgery_id' => $this->surgery_id,
            'suggested_room_id' => $this->suggested_room_id,
            'suggested_start' => $this->suggested_start,
            'reason' => $this->reason,
            'status' => $this->status,
            'surgery' => new SurgeryResource($this->whenLoaded('surgery')),
            'suggested_room' => new OperatingRoomResource($this->whenLoaded('suggestedRoom')),
            'created_at' => $this->created_at,
        ];
    }
}
