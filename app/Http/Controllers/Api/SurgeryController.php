<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ScheduleSuggestionResource;
use App\Http\Resources\SurgeryResource;
use App\Models\Surgery;
use App\Models\SurgeryType;
use App\Services\SchedulingService;
use App\Support\ApiError;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

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
            'scheduled_start' => ['required', 'date', 'after_or_equal:now'],
            'estimated_duration_min' => ['nullable', 'integer', 'min:5'],
        ]);

        if (empty($data['estimated_duration_min'])) {
            $data['estimated_duration_min'] = SurgeryType::find($data['surgery_type_id'])->average_duration_min;
        }

        $start = Carbon::parse($data['scheduled_start']);
        $duration = $data['estimated_duration_min'];

        if ($conflict = $this->scheduler->findOverlappingSurgery($data['room_id'], $start, $duration)) {
            return $this->conflictResponse($conflict);
        }

        if (! $this->scheduler->isWithinRoomAvailability($data['room_id'], $start, $duration)) {
            return $this->outsideAvailabilityResponse();
        }

        $data['created_by'] = $request->user()->id;
        $data['status'] = 'scheduled';

        $surgery = Surgery::create($data);
        $surgery->load(['patient', 'surgeon', 'room', 'surgeryType']);
        return (new SurgeryResource($surgery))->response()->setStatusCode(201);
    }

    public function show(Request $request, Surgery $surgery)
    {
        $user = $request->user();

        // Admins and coordinators can view any surgery (read-only for admins —
        // enforced by route middleware/other endpoints, not here). Surgeons
        // may only view their own.
        if ($user->role === 'surgeon' && $surgery->surgeon_id !== $user->id) {
            return ApiError::make('Forbidden. You may only view your own surgeries.', 'surgery_not_owned', 403);
        }

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
            'scheduled_start' => ['sometimes', 'date', 'after_or_equal:now'],
            'estimated_duration_min' => ['sometimes', 'integer', 'min:5'],
            'status' => ['sometimes', 'in:scheduled,in_progress,completed,cancelled,delayed'],
        ]);

        // Only re-validate the conflict/availability window if the room,
        // start time, or duration actually changed.
        if (array_intersect_key($data, array_flip(['room_id', 'scheduled_start', 'estimated_duration_min']))) {
            $roomId = $data['room_id'] ?? $surgery->room_id;
            $start = isset($data['scheduled_start']) ? Carbon::parse($data['scheduled_start']) : $surgery->scheduled_start->copy();
            $duration = $data['estimated_duration_min'] ?? $surgery->estimated_duration_min;

            if ($conflict = $this->scheduler->findOverlappingSurgery($roomId, $start, $duration, excludeSurgeryId: $surgery->id)) {
                return $this->conflictResponse($conflict);
            }

            if (! $this->scheduler->isWithinRoomAvailability($roomId, $start, $duration)) {
                return $this->outsideAvailabilityResponse();
            }
        }

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

    /**
     * Surgeon-initiated delay report.
     *
     * Body: { "new_expected_end": "<datetime, must be > now>", "reason": "<string, min 3 chars>" }
     *
     * The surgery must be status=in_progress and owned by the requesting
     * surgeon. See SchedulingService::evaluateDelayRequest() for the
     * auto-approve-vs-escalate evaluation logic.
     */
    public function delay(Request $request, Surgery $surgery)
    {
        $user = $request->user();

        if ($surgery->surgeon_id !== $user->id) {
            return ApiError::make('Forbidden. You may only report a delay on your own surgery.', 'surgery_not_owned', 403);
        }

        if ($surgery->status !== 'in_progress') {
            return ApiError::make(
                "This surgery is not in progress (current status: {$surgery->status}). Only an in-progress surgery can have a delay reported.",
                'surgery_not_in_progress',
                422,
                ['current_status' => $surgery->status],
            );
        }

        $data = $request->validate([
            'new_expected_end' => ['required', 'date', 'after:now'],
            'reason' => ['required', 'string', 'min:3'],
        ]);

        $newExpectedEnd = Carbon::parse($data['new_expected_end']);

        $result = $this->scheduler->evaluateDelayRequest($surgery, $newExpectedEnd, $data['reason'], $user);

        if ($result['auto_approved']) {
            return response()->json([
                'message' => 'Delay auto-approved: the new expected end time does not conflict with any other surgery in this room.',
                'auto_approved' => true,
                'surgery' => new SurgeryResource($result['surgery']),
            ]);
        }

        $conflict = $result['conflict'];
        $suggestionCount = count($result['suggestions']);
        return response()->json([
            'message' => "Delay request is pending coordinator review: extending this surgery to {$newExpectedEnd->toDateTimeString()} would conflict with surgery #{$conflict->id} in the same room. {$suggestionCount} suggestion(s) generated for the affected downstream surgeries.",
            'auto_approved' => false,
            'conflict_with_surgery_id' => $conflict->id,
            'suggestions' => ScheduleSuggestionResource::collection(collect($result['suggestions'])),
        ]);
    }

    // ---------------------------------------------------------------------
    // Shared error-response helpers (consistent error_code + meta shape)
    // ---------------------------------------------------------------------

    private function conflictResponse(Surgery $conflict)
    {
        $conflictEnd = $conflict->scheduled_end;
        return ApiError::make(
            "This room is already booked from {$conflict->scheduled_start->toDateTimeString()} to {$conflictEnd->toDateTimeString()} (surgery #{$conflict->id}).",
            'surgery_time_conflict',
            422,
            [
                'conflicting_surgery_id' => $conflict->id,
                'conflicting_start' => $conflict->scheduled_start->toDateTimeString(),
                'conflicting_end' => $conflictEnd->toDateTimeString(),
            ],
            ['scheduled_start' => ["The room is unavailable at this time due to a conflicting surgery (#{$conflict->id}) from {$conflict->scheduled_start->toDateTimeString()} to {$conflictEnd->toDateTimeString()}."]],
        );
    }

    private function outsideAvailabilityResponse()
    {
        return ApiError::make(
            'The requested time falls outside this room\'s defined availability window.',
            'room_availability_window_violation',
            422,
            errors: ['scheduled_start' => ['The requested time falls outside this room\'s defined availability window for that day.']],
        );
    }
}
