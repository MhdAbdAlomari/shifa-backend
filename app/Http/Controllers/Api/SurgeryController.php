<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SurgeryResource;
use App\Models\Surgery;
use App\Models\SurgeryType;
use App\Services\SchedulingService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class SurgeryController extends Controller
{
    public function __construct(private readonly SchedulingService $scheduler) {}

    public function index()
    {
        return SurgeryResource::collection(
            Surgery::with(['patient', 'surgeon', 'room', 'surgeryType'])
                ->orderBy('scheduled_start')
                ->get()
        );
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'patient_id' => ['required', 'exists:patients,id'],
            'surgeon_id' => ['required', 'exists:users,id'],
            'room_id' => ['required', 'exists:operating_rooms,id'],
            'surgery_type_id' => ['required', 'exists:surgery_types,id'],
            'priority' => ['required', 'in:normal,emergency'],
            'scheduled_start' => ['required', 'date'],
            'estimated_duration_min' => ['nullable', 'integer', 'min:5'],
        ]);

        if (empty($data['estimated_duration_min'])) {
            $data['estimated_duration_min'] = SurgeryType::find($data['surgery_type_id'])->average_duration_min;
        }
        $data['created_by'] = $request->user()->id;
        $data['status'] = 'scheduled';

        $surgery = Surgery::create($data);
        $surgery->load(['patient', 'surgeon', 'room', 'surgeryType']);
        return (new SurgeryResource($surgery))->response()->setStatusCode(201);
    }

    public function show(Surgery $surgery)
    {
        return new SurgeryResource($surgery->load(['patient', 'surgeon', 'room', 'surgeryType', 'creator']));
    }

    public function update(Request $request, Surgery $surgery)
    {
        $data = $request->validate([
            'patient_id' => ['sometimes', 'exists:patients,id'],
            'surgeon_id' => ['sometimes', 'exists:users,id'],
            'room_id' => ['sometimes', 'exists:operating_rooms,id'],
            'surgery_type_id' => ['sometimes', 'exists:surgery_types,id'],
            'priority' => ['sometimes', 'in:normal,emergency'],
            'scheduled_start' => ['sometimes', 'date'],
            'estimated_duration_min' => ['sometimes', 'integer', 'min:5'],
            'status' => ['sometimes', 'in:scheduled,in_progress,completed,cancelled,delayed'],
        ]);
        $surgery->update($data);
        return new SurgeryResource($surgery->fresh(['patient', 'surgeon', 'room', 'surgeryType']));
    }

    public function destroy(Surgery $surgery)
    {
        // Coordinator cancels rather than hard-deletes.
        $surgery->update(['status' => 'cancelled']);
        return response()->json(['message' => 'Surgery cancelled']);
    }

    /**
     * Group surgeries by room, over a date range, for the timeline view.
     * Query: ?from=YYYY-MM-DD&to=YYYY-MM-DD (defaults: today .. +7 days)
     */
    public function calendar(Request $request)
    {
        $from = $request->query('from') ? Carbon::parse($request->query('from'))->startOfDay() : Carbon::today();
        $to = $request->query('to') ? Carbon::parse($request->query('to'))->endOfDay() : Carbon::today()->addDays(7)->endOfDay();

        $surgeries = Surgery::with(['patient', 'surgeon', 'room', 'surgeryType'])
            ->whereBetween('scheduled_start', [$from, $to])
            ->orderBy('scheduled_start')
            ->get()
            ->groupBy('room_id');

        $out = [];
        foreach ($surgeries as $roomId => $items) {
            $out[] = [
                'room_id' => $roomId,
                'room_name' => $items->first()->room->name ?? null,
                'surgeries' => SurgeryResource::collection($items),
            ];
        }

        return response()->json([
            'from' => $from->toDateTimeString(),
            'to' => $to->toDateTimeString(),
            'rooms' => $out,
        ]);
    }

    /**
     * Ask the SchedulingService to build a proposed schedule (no persistence).
     * Body: { "pending": [ { patient_id, surgeon_id, surgery_type_id, priority }, ... ] }
     */
    public function autoSchedule(Request $request)
    {
        $request->validate([
            'pending' => ['required', 'array', 'min:1'],
            'pending.*.patient_id' => ['required', 'exists:patients,id'],
            'pending.*.surgeon_id' => ['required', 'exists:users,id'],
            'pending.*.surgery_type_id' => ['required', 'exists:surgery_types,id'],
            'pending.*.priority' => ['required', 'in:normal,emergency'],
        ]);

        $proposals = $this->scheduler->autoSchedule($request->input('pending'));
        return response()->json(['proposals' => $proposals]);
    }

    // Surgeon actions ----------------------------------------------------

    public function start(Surgery $surgery)
    {
        $surgery->update([
            'actual_start' => Carbon::now(),
            'status' => 'in_progress',
        ]);
        // Mark room as in use.
        $surgery->room()->update(['status' => 'in_use']);
        return new SurgeryResource($surgery->fresh(['patient', 'surgeon', 'room', 'surgeryType']));
    }

    public function complete(Surgery $surgery)
    {
        $surgery->update([
            'actual_end' => Carbon::now(),
            'status' => 'completed',
        ]);
        $surgery->room()->update(['status' => 'cleaning']);
        return new SurgeryResource($surgery->fresh(['patient', 'surgeon', 'room', 'surgeryType']));
    }

    public function delay(Surgery $surgery)
    {
        $surgery->update(['status' => 'delayed']);
        $suggestions = $this->scheduler->handleDelay($surgery);
        return response()->json([
            'message' => 'Delay handled — ' . count($suggestions) . ' suggestion(s) generated.',
            'suggestions' => \App\Http\Resources\ScheduleSuggestionResource::collection(collect($suggestions)),
        ]);
    }
}
