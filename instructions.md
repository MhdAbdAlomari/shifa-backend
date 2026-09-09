# Shifa Backend — Instructions

Laravel 13 REST API for a hospital operating-room scheduling system.

## Prerequisites

- PHP 8.3+, Composer 2.x
- XAMPP (Apache + MySQL) running. This project was set up against XAMPP at `D:\xampp`.
- Database `shifa_db` created on `127.0.0.1:3306` (user `root`, empty password).

## Fresh setup from scratch

```bash
composer install
php artisan migrate:fresh --seed
php artisan storage:link
php artisan serve
```

Server runs at `http://127.0.0.1:8000`. `storage:link` is required for uploaded room images (`POST`/`PUT /api/rooms` with an `image` field) to be publicly reachable at `/storage/rooms/...`.

## Seeded test credentials

All accounts use the password: `password`

| Role        | Email                  |
|-------------|------------------------|
| Admin       | admin@shifa.test       |
| Coordinator | coord1@shifa.test      |
| Coordinator | coord2@shifa.test      |
| Surgeon (Cardiology)  | surgeon1@shifa.test |
| Surgeon (Orthopedics) | surgeon2@shifa.test |
| Surgeon (Neurology)   | surgeon3@shifa.test |
| Surgeon (General)     | surgeon4@shifa.test |

## Auth flow

Send `Accept: application/json` on every request. Use the returned token as `Authorization: Bearer <token>`.

```bash
curl -X POST http://127.0.0.1:8000/api/login \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"email":"coord1@shifa.test","password":"password"}'
```

## Example: auto-schedule (coordinator)

```bash
TOKEN="<coord token from /api/login>"

curl -X POST http://127.0.0.1:8000/api/surgeries/auto-schedule \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{
    "pending": [
      {"patient_id":5,"surgeon_id":4,"surgery_type_id":4,"priority":"emergency"},
      {"patient_id":6,"surgeon_id":6,"surgery_type_id":2,"priority":"normal"}
    ]
  }'
```

Returns a `proposals` array; each item has `room_id`, `scheduled_start`, and a human-readable `reason`. Nothing is persisted — coordinator confirms by calling `POST /api/surgeries` for each proposal.

## Example: full surgeon flow

```bash
STOKEN="<surgeon token>"

# List my surgeries
curl -H "Authorization: Bearer $STOKEN" -H "Accept: application/json" \
  http://127.0.0.1:8000/api/my-surgeries

# Start surgery 2
curl -X POST -H "Authorization: Bearer $STOKEN" -H "Accept: application/json" \
  http://127.0.0.1:8000/api/surgeries/2/start

# Report a delay: surgeon proposes a new expected end time + reason.
# The surgery must be status=in_progress. If the new end time doesn't
# overlap another surgery in the same room, it's auto-approved immediately
# (status -> delayed, delayed_end_at set). If it conflicts, the surgery's
# status is left untouched and ScheduleSuggestion rows + Notifications are
# created for coordinator review instead.
curl -X POST -H "Authorization: Bearer $STOKEN" -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"new_expected_end":"2026-09-10 12:00:00","reason":"Unexpected complication"}' \
  http://127.0.0.1:8000/api/surgeries/2/delay
```

## Route index

Run `php artisan route:list` for the full list (48 routes total).

Groups:
- **Public:** `POST /api/register`, `POST /api/login`
- **Any authed:** `POST /api/logout`, `GET /api/me`, notifications, `GET /api/surgery-types`
- **Admin + coordinator:** rooms CRUD (incl. image upload), room slots CRUD, room surgery history, staff CRUD, patients CRUD (with `?search=`), surgery-types writes
- **Admin only:** `GET /api/dashboard/stats`
- **Coordinator only:** `GET /api/surgeries` (index), surgeries write (create/update/cancel), calendar, auto-schedule, accept/reject suggestions
- **Admin + coordinator + surgeon:** `GET /api/surgeries/{id}` — admins and coordinators see any surgery; surgeons see only their own (403 otherwise)
- **Surgeon only:** my-surgeries, start/complete, delay (report-a-delay workflow, own surgery only)

**Changed from the original mapping:**
- Rooms/staff reads were opened to coordinators first (scheduling dropdowns need them), then rooms/staff/patients/surgery-types **writes** were also opened to coordinators (day-to-day data entry doesn't need an admin).
- `GET /api/surgeries/{id}` was coordinator-only, then opened to `role:surgeon` (with an ownership check) so surgeons can view their own surgery detail from the mobile app, then opened to `role:admin` too (read-only, same as coordinator — no ownership restriction) so admins aren't blocked viewing a surgery card from the Room Detail screen.

**Server-side scheduling guarantees:** `POST`/`PUT`/`PATCH /api/surgeries` reject `scheduled_start` in the past, reject any time range that overlaps an existing non-cancelled surgery in the same room, and reject times outside a room's defined availability slots (rooms with no slots are unrestricted). The overlap check compares against each existing surgery's **effective end** — `delayed_end_at` when a delay was auto-approved for it, otherwise `scheduled_start + estimated_duration_min` — so a surgery that ran long still correctly blocks the room. `SchedulingService::autoSchedule()` and `handleDelay()`/`evaluateDelayRequest()` all respect the same rules when proposing times.

**Delay-reporting workflow (redesigned):** `POST /api/surgeries/{id}/delay` no longer just flips status — a surgeon submits `{new_expected_end, reason}` for an `in_progress` surgery. If extending to that end time doesn't conflict with another surgery in the same room, it's **auto-approved immediately** (`status -> delayed`, `delayed_end_at` set, `delay_reason` stored). If it conflicts, the surgery is **left untouched** (`in_progress`) and `ScheduleSuggestion`s are generated for the affected downstream surgeries for a coordinator to review via the existing accept/reject endpoints. Every request, whichever way it resolves, is logged to a `delay_requests` audit table.

**Error responses now carry a stable `error_code`:** every error response — from Laravel's own 401/403/404/405/422s to this app's custom checks (role, ownership, conflicts, business rules) — includes a machine-readable `error_code` (snake_case) alongside the English `message`, intended for the Flutter client to map to localized strings. See [API_REFERENCE.md](API_REFERENCE.md) for the full code table.

## Where the interesting code lives

- Scheduling algorithm: [app/Services/SchedulingService.php](app/Services/SchedulingService.php)
- Role gate middleware: [app/Http/Middleware/EnsureRole.php](app/Http/Middleware/EnsureRole.php) (alias `role`)
- Route table: [routes/api.php](routes/api.php)
- Filament panel: [app/Providers/Filament/AdminPanelProvider.php](app/Providers/Filament/AdminPanelProvider.php), resources under [app/Filament/Resources/](app/Filament/Resources/), dashboard widgets under [app/Filament/Widgets/](app/Filament/Widgets/)

## Admin panel (Filament)

The desktop admin panel lives at:

```
http://127.0.0.1:8000/admin
```

**Who can log in:** only users whose `role` is `admin` or `coordinator`. Surgeons are denied access — the panel is desktop-only for scheduling and audit; surgeons use the mobile app that consumes `/api`.

Access is enforced by `User::canAccessPanel()` in [app/Models/User.php](app/Models/User.php).

**Try each role (all use password `password`):**

| Email | Result at /admin |
|---|---|
| admin@shifa.test     | ✅ full access (all resources, can delete surgeries) |
| coord1@shifa.test    | ✅ access (can create/edit but cannot hard-delete surgeries) |
| surgeon1@shifa.test  | ❌ 403 Forbidden |

**Panel structure:**
- **Dashboard** — stats overview (surgeries today, rooms in use, weekly count, pending suggestions) + weekly room-utilization bar chart + recent-surgeries table
- **Scheduling** group — Surgeries (full CRUD with filters), Schedule Suggestions (read-only audit)
- **People** group — Staff (with role-conditional specialty field), Patients
- **Facilities** group — Operating Rooms, Surgery Types

The panel and the `/api` routes are fully independent — nothing in the panel calls the API and vice versa.
