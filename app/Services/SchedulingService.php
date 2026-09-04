<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\OperatingRoom;
use App\Models\ScheduleSuggestion;
use App\Models\Surgery;
use App\Models\SurgeryType;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;

/**
 * Core scheduling logic for Shifa.
 *
 * Two responsibilities:
 *  1. autoSchedule()  — build a proposed schedule from a batch of pending surgeries.
 *  2. handleDelay()   — when a running surgery overruns, propose shifts for the rest of the day.
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
        $roomBusy = [];
        $surgeonBusy = [];
        foreach ($existing as $s) {
            $roomBusy[$s->room_id][] = [$s->scheduled_start->copy(), $s->scheduled_start->copy()->addMinutes($s->estimated_duration_min)];
            $surgeonBusy[$s->surgeon_id][] = [$s->scheduled_start->copy(), $s->scheduled_start->copy()->addMinutes($s->estimated_duration_min)];
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

        $earliestSlot = $today->setHour(self::DAY_START_HOUR)->setMinute(0)->setSecond(0);

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

        $suggestions = [];

        // Find affected surgeries in the same room, later today.
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

        // Snapshot rooms + occupancy for option (b) evaluation.
        $rooms = OperatingRoom::all();
        $today = CarbonImmutable::parse($surgery->scheduled_start)->startOfDay();
        $occupied = Surgery::whereDate('scheduled_start', $today)
            ->whereIn('status', ['scheduled', 'in_progress', 'delayed'])
            ->get();

        $roomBusy = [];
        foreach ($occupied as $s) {
            $roomBusy[$s->room_id][] = [$s->scheduled_start->copy(), $s->scheduled_start->copy()->addMinutes($s->estimated_duration_min)];
        }

        foreach ($affected as $next) {
            $newStart = $next->scheduled_start->copy()->addMinutes($overrunMin);
            $duration = $next->estimated_duration_min;
            $requiredSpecialty = $next->surgeryType?->required_specialty;

            // Option (a): delay in same room.
            $suggestions[] = ScheduleSuggestion::create([
                'surgery_id'        => $next->id,
                'suggested_room_id' => $next->room_id,
                'suggested_start'   => $newStart,
                'reason'            => "Delayed by {$overrunMin} min due to overrun of surgery #{$surgery->id} in the same room.",
                'status'            => 'pending',
            ]);

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
                    'reason'            => "Move to room '{$alt->name}' to keep original start time despite overrun of surgery #{$surgery->id}.",
                    'status'            => 'pending',
                ]);
            }
        }

        // Notify all coordinators once per suggestion.
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

        return $suggestions;
    }

    // ---------------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------------

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

        // Try up to 14 days ahead — production would tune this.
        for ($safety = 0; $safety < 500; $safety++) {
            $end = $candidate->copy()->addMinutes($durationMin);

            // Must fit within business hours; if not, jump to next day 08:00.
            if ($end->hour >= self::DAY_END_HOUR || $end->day !== $candidate->day) {
                $candidate = $candidate->copy()->addDay()->setHour(self::DAY_START_HOUR)->setMinute(0)->setSecond(0);
                continue;
            }

            // Check room conflicts (with turnover buffer).
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

    private function clampToBusinessHours(Carbon $t): Carbon
    {
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
