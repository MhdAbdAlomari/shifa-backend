<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SurgeryResource;
use App\Models\Surgery;
use Illuminate\Http\Request;

class SurgeonController extends Controller
{
    public function mySurgeries(Request $request)
    {
        return SurgeryResource::collection(
            Surgery::with(['patient', 'room', 'surgeryType'])
                ->where('surgeon_id', $request->user()->id)
                ->orderBy('scheduled_start')
                ->get()
        );
    }
}
