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
php artisan serve
```

Server runs at `http://127.0.0.1:8000`.

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

# Mark it as delayed → triggers SchedulingService::handleDelay,
# which creates ScheduleSuggestion rows + Notifications for coordinators.
curl -X POST -H "Authorization: Bearer $STOKEN" -H "Accept: application/json" \
  http://127.0.0.1:8000/api/surgeries/2/delay
```

## Route index

Run `php artisan route:list` for the full list (41 routes total).

Groups:
- **Public:** `POST /api/register`, `POST /api/login`
- **Any authed:** `POST /api/logout`, `GET /api/me`, notifications
- **Admin only:** rooms CRUD, staff CRUD, dashboard/stats
- **Coordinator only:** patients CRUD, surgeries CRUD, calendar, auto-schedule, accept/reject suggestions
- **Surgeon only:** my-surgeries, start/complete/delay

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
