<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SurgeryTypeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'average_duration_min' => $this->average_duration_min,
            'required_specialty' => $this->required_specialty,
        ];
    }
}
