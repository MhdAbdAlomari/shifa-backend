<?php

namespace App\Services;

use App\Models\DelayRequest;
use App\Models\Notification;
use App\Models\OperatingRoom;
use App\Models\OperatingRoomSlot;
use App\Models\ScheduleSuggestion;
use App\Models\Surgery;
use App\Models\SurgeryType;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;

/**
 * Core scheduling logic for Shifa.
 *
 * Responsibilities:
 *  1. autoSchedule()        — build a proposed schedule from a batch of pending surgeries.
 *  2. handleDelay()         — when a running surgery overruns, propose shifts for the rest of the day.
 *  3. evaluateDelayRequest() — the surgeon-initiated delay-report workflow (auto-approve or escalate).
 *
 * IMPORTANT: every occupancy calculation in this class uses a surgery's
 * *effective end* — Surgery::$scheduled_end, which is delayed_end_at when
 * set, otherwise scheduled_start + estimated_duration_min — never
 * estimated_duration_min alone. This is what makes delayed surgeries'
 * extended end times correctly block new bookings/proposals in the same
 * room (see the autoSchedule regression fix below).
 */
class SchedulingService
{
    /**
     * Business hours the scheduler is allowed to place surgeries in.
     * Start is used as the "earliest slot" when no other constraints apply.
     */
    private const DAY_START_HOUR = 8;   // 08:00
    private const DAY_END_HOUR   = 20;  // 20:00

    /**
     * Buffer between surgeries in the same room (cleaning / prep).
     */
    private const ROOM_TURNOVER_MIN = 30;

    /**
     * Greedy scheduler for a batch of pending surgeries.
     *
     * Input format (each item):
     *   [
     *     'patient_id'      => int,
     *     'surgeon_id'      => int,
     *     'surgery_type_id' => int,
     *     'priority'        => 'normal' | 'emergency',
     *   ]
     *
     * Output: an array of proposed surgeries with room_id and scheduled_start
     * assigned. Nothing is persisted — the coordinator confirms via a separate
     * endpoint.
     *
     * Algorithm:
     *   1. Load rooms + surgery types + already-scheduled surgeries for today.
     *   2. Sort input: emergencies first, then shortest-duration first
     *      (Shortest-Job-First reduces idle gaps).
     *   3. For each surgery, pick the earliest room+slot where:
     *        - room specialty matches surgery type (or room has none)
     *        - no conflict with other surgeries in that room
     *        - no conflict with the surgeon's other bookings
     *   4. If nothing fits today, push to the next business day at DAY_START.
     */
    public function autoSchedule(array $pendingSurgeries): array
    {
        // 1. Load reference data
        $rooms = OperatingRoom::all();
        $typesById = SurgeryType::whereIn('id', array_column($pendingSurgeries, 'surgery_type_id'))
            ->get()
            ->keyBy('id');

        // Today's already-committed surgeries (used as the base occupancy map).
        $today = CarbonImmutable::today();
        $existing = Surgery::with('room')
            ->whereDate('scheduled_start', $today)
            ->whereIn('status', ['scheduled', 'in_progress', 'delayed'])
            ->get();

        // Occupancy: per-room list of [start, end] intervals, and per-surgeon list.
        // Uses each surgery's EFFECTIVE end (scheduled_end accessor), which
        // accounts for delayed_end_at when a delay was auto-approved — NOT
        // estimated_duration_min alone. This is the fix for the bug where
        // auto-schedule proposed a slot that overlapped a delayed surgery
        // whose real end time had been extended past its original estimate.
        $roomBusy = [];
        $surgeonBusy = [];
        foreach ($existing as $s) {
            $interval = [$s->scheduled_start->copy(), $s->scheduled_end->copy()];
            $roomBusy[$s->room_id][] = $interval;
            $surgeonBusy[$s->surgeon_id][] = $interval;
        }

        // 2. Sort: emergency first, then shortest average_duration_min first.
        usort($pendingSurgeries, function ($a, $b) use ($typesById) {
            $priA = $a['priority'] === 'emergency' ? 0 : 1;
            $priB = $b['priority'] === 'emergency' ? 0 : 1;
            if ($priA !== $priB) {
                return $priA <=> $priB;
            }
            $dA = $typesById[$a['surgery_type_id']]->average_duration_min ?? PHP_INT_MAX;
            $dB = $typesById[$b['surgery_type_id']]->average_duration_min ?? PHP_INT_MAX;
            return $dA <=> $dB;
        });

        // Floor of "now" — never propose a slot earlier than the current moment.
        // (Bug fix: previously this always started from today at DAY_START_HOUR,
        // which is in the past for any request made after 08:00.)
        $now = CarbonImmutable::now();
        $earliestSlot = $now->greaterThan($today->setHour(self::DAY_START_HOUR)->setMinute(0)->setSecond(0))
            ? $now
            : $today->setHour(self::DAY_START_HOUR)->setMinute(0)->setSecond(0);

        // 3. Greedy placement
        $proposals = [];
        foreach ($pendingSurgeries as $req) {
            $type = $typesById[$req['surgery_type_id']] ?? null;
            $duration = $type?->average_duration_min ?? 60;
            $requiredSpecialty = $type?->required_specialty;

            $placement = $this->findEarliestSlot(
                rooms: $rooms,
                requiredSpecialty: $requiredSpecialty,
                durationMin: $duration,
                surgeonId: $req['surgeon_id'],
                roomBusy: $roomBusy,
                surgeonBusy: $surgeonBusy,
                notBefore: $earliestSlot,
            );

            // 4. Record the placement and update the busy map so subsequent
            //    surgeries in this batch see it.
            $roomBusy[$placement['room_id']][] = [$placement['start'], $placement['start']->copy()->addMinutes($duration)];
            $surgeonBusy[$req['surgeon_id']][] = [$placement['start'], $placement['start']->copy()->addMinutes($duration)];

            $proposals[] = [
                'patient_id'             => $req['patient_id'],
                'surgeon_id'             => $req['surgeon_id'],
                'surgery_type_id'        => $req['surgery_type_id'],
                'priority'               => $req['priority'],
                'room_id'                => $placement['room_id'],
                'scheduled_start'        => $placement['start']->toDateTimeString(),
                'estimated_duration_min' => $duration,
                'reason'                 => $placement['reason'],
            ];
        }

        return $proposals;
    }

    /**
     * Handle a surgery that has overrun its estimated end time.
     *
     * For every OTHER surgery scheduled in the SAME room LATER today, create a
     * ScheduleSuggestion proposing either:
     *   (a) a delayed start time in the same room (pushed after the overrun), or
     *   (b) a move to a different free room that supports the required specialty.
     *
     * Also notifies all coordinators about each suggestion.
     *
     * Returns the array of ScheduleSuggestion models created.
     */
    public function handleDelay(Surgery $surgery): array
    {
        // If no actual_start, we can't compute an overrun.
        $actualStart = $surgery->actual_start ?? Carbon::now();
        $originalEnd = $actualStart->copy()->addMinutes($surgery->estimated_duration_min);

        // Assume the surgery is still in progress "now"; the shift needed is
        // (now - originalEnd) rounded up, floored at 15 min.
        $now = Carbon::now();
        $overrunMin = max(15, (int) ceil($now->diffInMinutes($originalEnd, false) * -1));

        // If somehow not actually overrun yet, assume a 30-min slip.
        if ($overrunMin <= 0) {
            $overrunMin = 30;
        }

        $suggestions = $this->generateDownstreamSuggestions(
            surgery: $surgery,
            pushMinutes: $overrunMin,
            reasonPrefix: "overrun of surgery #{$surgery->id} in the same room",
        );

        $this->notifyCoordinatorsOfSuggestions($suggestions);

        return $suggestions;
    }

    /**
     * Surgeon-initiated delay-report workflow.
     *
     * Input: the in_progress Surgery, the surgeon's proposed new_expected_end,
     * and their reason. Evaluates whether extending this surgery's effective
     * end to new_expected_end would overlap any OTHER non-cancelled surgery in
     * the same room:
     *
     *   - NO CONFLICT: auto-approve immediately. Sets status=delayed,
     *     delayed_end_at=new_expected_end, delay_reason=reason. No downstream
     *     surgeries are touched.
     *   - CONFLICT: the surgery's status and timing are left untouched (stays
     *     in_progress). ScheduleSuggestion rows are generated for every
     *     downstream surgery in the room (reusing the handleDelay pattern),
     *     coordinators are notified, and the surgeon is told it's pending review.
     *
     * Every call is logged to delay_requests regardless of outcome.
     *
     * Returns ['auto_approved' => bool, 'surgery' => Surgery, 'suggestions' => ScheduleSuggestion[], 'conflict' => ?Surgery]
     */
    public function evaluateDelayRequest(Surgery $surgery, Carbon $newExpectedEnd, string $reason, User $requestedBy): array
    {
        $conflict = $this->findOverlappingSurgery(
            roomId: $surgery->room_id,
            start: $surgery->scheduled_start->copy(),
            durationMin: null,
            excludeSurgeryId: $surgery->id,
            endOverride: $newExpectedEnd,
        );

        $autoApproved = $conflict === null;

        DelayRequest::create([
            'surgery_id' => $surgery->id,
            'requested_by' => $requestedBy->id,
            'new_expected_end' => $newExpectedEnd,
            'reason' => $reason,
            'auto_approved' => $autoApproved,
        ]);

        if ($autoApproved) {
            $surgery->update([
                'status' => 'delayed',
                'delayed_end_at' => $newExpectedEnd,
                'delay_reason' => $reason,
            ]);

            return [
                'auto_approved' => true,
                'surgery' => $surgery->fresh(['patient', 'surgeon', 'room', 'surgeryType']),
                'suggestions' => [],
                'conflict' => null,
            ];
        }

        // Conflict exists — do NOT change this surgery's status/timing.
        // Push overrun = gap between the surgeon's requested new end and the
        // surgery's currently-effective end, so downstream suggestions shift
        // by exactly the additional time being requested.
        $currentEffectiveEnd = $surgery->scheduled_end;
        $pushMinutes = max(1, (int) ceil($currentEffectiveEnd->diffInMinutes($newExpectedEnd, false)));

        $suggestions = $this->generateDownstreamSuggestions(
            surgery: $surgery,
            pushMinutes: $pushMinutes,
            reasonPrefix: "a pending delay request on surgery #{$surgery->id} in the same room",
        );

        $this->notifyCoordinatorsOfSuggestions($suggestions);

        return [
            'auto_approved' => false,
            'surgery' => $surgery->fresh(['patient', 'surgeon', 'room', 'surgeryType']),
            'suggestions' => $suggestions,
            'conflict' => $conflict,
        ];
    }

    // ---------------------------------------------------------------------
    // Conflict validation (used by SurgeryController for manual scheduling,
    // and internally by the auto-scheduler / delay handler).
    // ---------------------------------------------------------------------

    /**
     * Check whether a candidate window overlaps any existing non-cancelled
     * surgery in $roomId. Returns the conflicting Surgery, or null if free.
     *
     * The candidate window is [$start, $end) where $end is either:
     *   - $endOverride, if given (used by evaluateDelayRequest, which knows
     *     an exact proposed end time rather than a duration), or
     *   - $start + $durationMin otherwise.
     *
     * Every existing surgery's own window is its EFFECTIVE end
     * (Surgery::$scheduled_end — respects delayed_end_at), never
     * estimated_duration_min alone, so a delayed surgery's extended end
     * correctly blocks new overlapping bookings/proposals.
     *
     * @param int|null $excludeSurgeryId Exclude this surgery's own row (used on update).
     */
    public function findOverlappingSurgery(
        int $roomId,
        Carbon $start,
        ?int $durationMin = null,
        ?int $excludeSurgeryId = null,
        ?Carbon $endOverride = null,
    ): ?Surgery {
        $end = $endOverride ?? $start->copy()->addMinutes($durationMin ?? 0);

        $query = Surgery::where('room_id', $roomId)
            ->where('status', '!=', 'cancelled')
            ->where('scheduled_start', '<', $end);

        if ($excludeSurgeryId !== null) {
            $query->where('id', '!=', $excludeSurgeryId);
        }

        // A surgery overlaps if its own EFFECTIVE [start, end) intersects ours.
        // We can't compare the computed `scheduled_end` in SQL, so fetch
        // candidates that start before our end and check their effective end
        // in PHP via the accessor (which honors delayed_end_at).
        foreach ($query->get() as $candidate) {
            $candidateEnd = $candidate->scheduled_end;
            if ($start->lessThan($candidateEnd) && $end->greaterThan($candidate->scheduled_start)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Check whether [start, start+durationMin) falls entirely within one of
     * the room's defined availability slots for that day of week. Rooms with
     * NO slots defined at all are treated as unrestricted (backward
     * compatibility with rooms seeded before this feature existed).
     */
    public function isWithinRoomAvailability(int $roomId, Carbon $start, int $durationMin): bool
    {
        $slots = OperatingRoomSlot::where('room_id', $roomId)->get();

        if ($slots->isEmpty()) {
            return true;
        }

        $end = $start->copy()->addMinutes($durationMin);
        // A booking must fit within a single slot window (no crossing midnight).
        if ($end->dayOfWeek !== $start->dayOfWeek) {
            return false;
        }

        $daySlots = $slots->where('day_of_week', $start->dayOfWeek);
        if ($daySlots->isEmpty()) {
            return false;
        }

        foreach ($daySlots as $slot) {
            $slotStart = $start->copy()->setTimeFromTimeString($slot->start_time);
            $slotEnd = $start->copy()->setTimeFromTimeString($slot->end_time);
            if ($start->greaterThanOrEqualTo($slotStart) && $end->lessThanOrEqualTo($slotEnd)) {
                return true;
            }
        }

        return false;
    }

    // ---------------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------------

    /**
     * Shared downstream-suggestion generator used by both handleDelay() (surgeon
     * marks in_progress surgery delayed with no specific end time — assumes an
     * overrun) and evaluateDelayRequest() (surgeon reports a specific new end
     * time that conflicts with a later surgery in the room).
     *
     * For every OTHER non-cancelled surgery scheduled in the SAME room LATER
     * than $surgery, propose (a) delaying it by $pushMinutes in the same room
     * (only if that still fits the room's availability window), and (b)
     * moving it to an alternative room that fits at its original start time.
     */
    private function generateDownstreamSuggestions(Surgery $surgery, int $pushMinutes, string $reasonPrefix): array
    {
        $suggestions = [];

        $affected = Surgery::with('surgeryType')
            ->where('room_id', $surgery->room_id)
            ->where('id', '!=', $surgery->id)
            ->where('status', 'scheduled')
            ->whereDate('scheduled_start', $surgery->scheduled_start->toDateString())
            ->where('scheduled_start', '>', $surgery->scheduled_start)
            ->orderBy('scheduled_start')
            ->get();

        if ($affected->isEmpty()) {
            return [];
        }

        // Snapshot rooms + occupancy (using effective ends) for option (b).
        $rooms = OperatingRoom::all();
        $today = CarbonImmutable::parse($surgery->scheduled_start)->startOfDay();
        $occupied = Surgery::whereDate('scheduled_start', $today)
            ->whereIn('status', ['scheduled', 'in_progress', 'delayed'])
            ->get();

        $roomBusy = [];
        foreach ($occupied as $s) {
            $roomBusy[$s->room_id][] = [$s->scheduled_start->copy(), $s->scheduled_end->copy()];
        }

        foreach ($affected as $next) {
            $newStart = $next->scheduled_start->copy()->addMinutes($pushMinutes);
            $duration = $next->estimated_duration_min;
            $requiredSpecialty = $next->surgeryType?->required_specialty;

            // Option (a): delay in same room — only offer it if the pushed-back
            // start still falls within the room's availability window (rooms
            // with no slots defined are unrestricted).
            if ($this->isWithinRoomAvailability($next->room_id, $newStart, $duration)) {
                $suggestions[] = ScheduleSuggestion::create([
                    'surgery_id'        => $next->id,
                    'suggested_room_id' => $next->room_id,
                    'suggested_start'   => $newStart,
                    'reason'            => "Delayed by {$pushMinutes} min due to {$reasonPrefix}.",
                    'status'            => 'pending',
                ]);
            }

            // Option (b): if another free room fits at the ORIGINAL start, offer it too.
            $alt = $this->findAlternativeRoom(
                rooms: $rooms,
                excludeRoomId: $next->room_id,
                requiredSpecialty: $requiredSpecialty,
                start: $next->scheduled_start,
                durationMin: $duration,
                roomBusy: $roomBusy,
            );
            if ($alt !== null) {
                $suggestions[] = ScheduleSuggestion::create([
                    'surgery_id'        => $next->id,
                    'suggested_room_id' => $alt->id,
                    'suggested_start'   => $next->scheduled_start,
                    'reason'            => "Move to room '{$alt->name}' to keep original start time despite {$reasonPrefix}.",
                    'status'            => 'pending',
                ]);
            }
        }

        return $suggestions;
    }

    private function notifyCoordinatorsOfSuggestions(array $suggestions): void
    {
        $coordinators = User::where('role', 'coordinator')->get();
        foreach ($suggestions as $sug) {
            foreach ($coordinators as $coord) {
                Notification::create([
                    'user_id' => $coord->id,
                    'title'   => 'Schedule adjustment suggested',
                    'body'    => "Suggestion #{$sug->id} for surgery #{$sug->surgery_id}: {$sug->reason}",
                    'type'    => 'schedule_suggestion',
                ]);
            }
        }
    }

    /**
     * Walk candidate rooms and time slots to find the earliest fit.
     */
    private function findEarliestSlot(
        $rooms,
        ?string $requiredSpecialty,
        int $durationMin,
        int $surgeonId,
        array $roomBusy,
        array $surgeonBusy,
        CarbonImmutable $notBefore,
    ): array {
        // Prefer rooms whose specialty matches; fall back to unspecialized rooms.
        $candidateRooms = $rooms->filter(function (OperatingRoom $r) use ($requiredSpecialty) {
            if ($requiredSpecialty === null) {
                return true;
            }
            return $r->supported_specialty === null || $r->supported_specialty === $requiredSpecialty;
        });

        $bestStart = null;
        $bestRoomId = null;
        $bestReason = '';

        foreach ($candidateRooms as $room) {
            $slot = $this->earliestSlotInRoom(
                room: $room,
                durationMin: $durationMin,
                surgeonId: $surgeonId,
                roomBusy: $roomBusy,
                surgeonBusy: $surgeonBusy,
                notBefore: $notBefore,
            );

            if ($bestStart === null || $slot < $bestStart) {
                $bestStart = $slot;
                $bestRoomId = $room->id;
                $bestReason = $requiredSpecialty && $room->supported_specialty === $requiredSpecialty
                    ? "Room specialty '{$requiredSpecialty}' matched."
                    : 'Earliest available room.';
            }
        }

        return [
            'room_id' => $bestRoomId,
            'start'   => $bestStart,
            'reason'  => $bestReason,
        ];
    }

    /**
     * Walk forward in the given room until we find a gap that fits durationMin
     * AND does not clash with the surgeon's other bookings. Never fails — it
     * will keep pushing to the next business day if needed.
     */
    private function earliestSlotInRoom(
        OperatingRoom $room,
        int $durationMin,
        int $surgeonId,
        array $roomBusy,
        array $surgeonBusy,
        CarbonImmutable $notBefore,
    ): Carbon {
        $intervals = $roomBusy[$room->id] ?? [];
        usort($intervals, fn ($a, $b) => $a[0] <=> $b[0]);

        $surgeonIntervals = $surgeonBusy[$surgeonId] ?? [];

        $candidate = Carbon::parse($notBefore);
        $candidate = $this->clampToBusinessHours($candidate);

        $roomSlots = OperatingRoomSlot::where('room_id', $room->id)->get();

        // Try up to 500 iterations (bounded search) — production would tune this.
        for ($safety = 0; $safety < 500; $safety++) {
            $end = $candidate->copy()->addMinutes($durationMin);

            // Must fit within business hours; if not, jump to next day 08:00.
            if ($end->hour >= self::DAY_END_HOUR || $end->day !== $candidate->day) {
                $candidate = $candidate->copy()->addDay()->setHour(self::DAY_START_HOUR)->setMinute(0)->setSecond(0);
                continue;
            }

            // Must fit within one of the room's defined availability slots for
            // that day of week (rooms with zero slots defined are unrestricted).
            if ($roomSlots->isNotEmpty()) {
                $fits = $this->fitsInAnySlot($roomSlots, $candidate, $end);
                if (! $fits) {
                    // Jump to next day 08:00 and try again — simplest safe
                    // advance that guarantees forward progress.
                    $candidate = $candidate->copy()->addDay()->setHour(self::DAY_START_HOUR)->setMinute(0)->setSecond(0);
                    continue;
                }
            }

            // Check room conflicts (with turnover buffer). Intervals already
            // use each surgery's EFFECTIVE end (see caller).
            $conflict = null;
            foreach ($intervals as [$bStart, $bEnd]) {
                $bufferedEnd = $bEnd->copy()->addMinutes(self::ROOM_TURNOVER_MIN);
                if ($candidate < $bufferedEnd && $end > $bStart) {
                    $conflict = $bufferedEnd;
                    break;
                }
            }
            if ($conflict !== null) {
                $candidate = $conflict->copy();
                continue;
            }

            // Check surgeon conflicts.
            $sconflict = null;
            foreach ($surgeonIntervals as [$bStart, $bEnd]) {
                if ($candidate < $bEnd && $end > $bStart) {
                    $sconflict = $bEnd;
                    break;
                }
            }
            if ($sconflict !== null) {
                $candidate = $sconflict->copy();
                continue;
            }

            return $candidate;
        }

        // Safety fallback — return the candidate as-is.
        return $candidate;
    }

    /**
     * True if [start, end) fits entirely within one of the given slots on
     * start's day of week.
     */
    private function fitsInAnySlot($roomSlots, Carbon $start, Carbon $end): bool
    {
        $daySlots = $roomSlots->where('day_of_week', $start->dayOfWeek);
        foreach ($daySlots as $slot) {
            $slotStart = $start->copy()->setTimeFromTimeString($slot->start_time);
            $slotEnd = $start->copy()->setTimeFromTimeString($slot->end_time);
            if ($start->greaterThanOrEqualTo($slotStart) && $end->lessThanOrEqualTo($slotEnd)) {
                return true;
            }
        }
        return false;
    }

    private function clampToBusinessHours(Carbon $t): Carbon
    {
        // Defensive floor: never return a moment before "now", regardless of
        // what the caller passed in.
        $now = Carbon::now();
        if ($t->lessThan($now)) {
            $t = $now->copy();
        }

        if ($t->hour < self::DAY_START_HOUR) {
            return $t->setHour(self::DAY_START_HOUR)->setMinute(0)->setSecond(0);
        }
        if ($t->hour >= self::DAY_END_HOUR) {
            return $t->copy()->addDay()->setHour(self::DAY_START_HOUR)->setMinute(0)->setSecond(0);
        }
        return $t;
    }

    /**
     * Look for a room (other than $excludeRoomId) that supports the specialty and
     * has no conflict during [start, start+duration]. Returns the room or null.
     */
    private function findAlternativeRoom(
        $rooms,
        int $excludeRoomId,
        ?string $requiredSpecialty,
        Carbon $start,
        int $durationMin,
        array $roomBusy,
    ): ?OperatingRoom {
        $end = $start->copy()->addMinutes($durationMin);
        foreach ($rooms as $room) {
            if ($room->id === $excludeRoomId) {
                continue;
            }
            if ($requiredSpecialty !== null
                && $room->supported_specialty !== null
                && $room->supported_specialty !== $requiredSpecialty) {
                continue;
            }

            // Must fit within the candidate room's availability slots (if any
            // are defined for it).
            if (! $this->isWithinRoomAvailability($room->id, $start, $durationMin)) {
                continue;
            }

            $intervals = $roomBusy[$room->id] ?? [];
            $ok = true;
            foreach ($intervals as [$bStart, $bEnd]) {
                if ($start < $bEnd && $end > $bStart) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                return $room;
            }
        }
        return null;
    }
}
