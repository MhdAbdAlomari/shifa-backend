# Shifa API Reference

All endpoints are prefixed with `/api` and expect `Accept: application/json`. Protected endpoints require `Authorization: Bearer <token>`. All responses shown below were captured against a freshly seeded database (`php artisan migrate:fresh --seed`) — dates and IDs reflect what the seeder produces.

**Base URL:** `http://127.0.0.1:8000`

**Total routes:** 48

**Conflict/validation error shape:** `scheduled_start` must always be in the future, and a room's schedule can never have two overlapping non-cancelled surgeries. Both are enforced server-side on `POST /api/surgeries` and `PUT/PATCH /api/surgeries/{id}` (see those sections for exact 422 shapes), and are respected by `SchedulingService::autoSchedule()` / `handleDelay()` / `evaluateDelayRequest()` so proposals never violate them either — including against a surgery whose effective end time was extended via an auto-approved delay (`delayed_end_at`), not just its original `estimated_duration_min`.

## Error response shape & localization (`error_code`)

**Every error response** — both Laravel's own framework-generated errors (401/403/404/405/422) and this app's custom error responses (role checks, ownership checks, conflict errors, business-rule violations) — includes a stable, snake_case `error_code` field alongside the human-readable English `message`. The Flutter client should switch on `error_code` to pick a localized string, and treat `message` as an English fallback / debug aid only, never displayed directly to non-English users.

Some error responses also include a `meta` object with structured context (e.g. which role was required, which surgery conflicted), and/or Laravel's standard `errors: {field: [messages]}` object for `422` field-validation failures — `errors` keys are the field names themselves (e.g. `patient_id`, `scheduled_start`), which the client maps to localized field labels independently of `error_code`.

**Generic shape:**

```json
{
  "message": "English description of what went wrong",
  "error_code": "stable_snake_case_code",
  "meta": { "...optional structured context..." },
  "errors": { "...optional, only on 422 field-validation failures..." }
}
```

### Error codes reference

| `error_code` | HTTP | Meaning | Source |
|---|---|---|---|
| `unauthenticated` | 401 | No/invalid/expired bearer token | Global (Laravel `AuthenticationException`) + `EnsureRole` when there's no user at all |
| `role_forbidden` | 403 | Authenticated, but role not in the route's allowed list | `EnsureRole` middleware; `meta.required_role` is the role string, or an array if multiple roles are allowed |
| `unauthorized` | 403 | Laravel policy/gate denial (not currently used by any endpoint, reserved) | Global (Laravel `AuthorizationException`) |
| `not_found` | 404 | Route-model-binding failed, or an unmatched route | Global; `meta.model` names the Eloquent model when known |
| `method_not_allowed` | 405 | HTTP verb not supported on that path | Global |
| `validation_failed` | 422 | A `$request->validate()` rule failed (missing/malformed field) | Global (Laravel `ValidationException`); field messages are in `errors` |
| `invalid_credentials` | 422 | `POST /api/login` with a wrong email/password | `AuthController` |
| `surgery_not_owned` | 403 | A surgeon tried to view or delay a surgery that isn't theirs | `SurgeryController::show`, `SurgeryController::delay` |
| `surgery_time_conflict` | 422 | Requested room+time overlaps another non-cancelled surgery | `SurgeryController::store`/`update`; `meta` has the conflicting surgery's id/start/end |
| `room_availability_window_violation` | 422 | Requested time falls outside the room's defined availability slots | `SurgeryController::store`/`update` |
| `surgery_not_in_progress` | 422 | Delay reported on a surgery that isn't `status=in_progress` | `SurgeryController::delay`; `meta.current_status` is the actual status |
| `suggestion_not_pending` | 422 | Accept/reject called on a suggestion already actioned | `ScheduleSuggestionController`; `meta.current_status` is the actual status |
| `notification_not_owned` | 403 | Tried to mark another user's notification as read | `NotificationController::markRead` |
| `slot_room_mismatch` | 404 | `{slot}` in the URL doesn't belong to `{room}` | `OperatingRoomSlotController` |
| `slot_invalid_time_range` | 422 | A room-slot update leaves `end_time <= start_time` | `OperatingRoomSlotController::update` |

**Role model:** every user has `role ∈ {admin, coordinator, surgeon}`. Endpoints are gated by the `role:<name>` middleware ([app/Http/Middleware/EnsureRole.php](app/Http/Middleware/EnsureRole.php)). Attempting an endpoint with the wrong role returns:

```json
HTTP 403
{"message":"Forbidden. Requires role: <role>","error_code":"role_forbidden","meta":{"required_role":"<role>"}}
```

Any authenticated endpoint hit without a token (or with an expired token) returns:

```json
HTTP 401
{"message":"Unauthenticated.","error_code":"unauthenticated"}
```

Note on 404s: with `APP_DEBUG=true` (the default in development), 404s from route-model-binding include a stack trace in addition to the `error_code`/`meta` shape. The shapes below show the response when `APP_DEBUG=false` (the way clients see it).

---

## Auth

### POST `/api/register`

Register a new user and return an API token.

**Access:** public.

**Body:**

| Field | Type | Required | Notes |
|---|---|---|---|
| `name` | string | yes | max 255 |
| `email` | string | yes | valid email, unique |
| `password` | string | yes | min 8, must include `password_confirmation` |
| `password_confirmation` | string | yes | must match `password` |
| `role` | string | yes | one of `admin`, `coordinator`, `surgeon` |
| `specialty` | string | conditional | required when `role=surgeon` |

**Example success (HTTP 201):**

```json
{
  "user": {
    "id": 8,
    "name": "New Coord",
    "email": "newcoord@shifa.test",
    "role": "coordinator",
    "specialty": null,
    "created_at": "2026-09-04T20:34:56.000000Z"
  },
  "token": "1|gSOHXpcPuSe3KogJDZRATNuCJqNVES8xK6ZQbQjP094b16f7"
}
```

**Validation failure (HTTP 422):**

```json
{
  "message": "The name field is required. (and 4 more errors)",
  "error_code": "validation_failed",
  "errors": {
    "name": ["The name field is required."],
    "email": ["The email field must be a valid email address."],
    "password": [
      "The password field confirmation does not match.",
      "The password field must be at least 8 characters."
    ],
    "role": ["The role field is required."]
  }
}
```

---

### POST `/api/login`

Exchange credentials for a token.

**Access:** public.

**Body:**

| Field | Type | Required |
|---|---|---|
| `email` | string | yes |
| `password` | string | yes |

**Example success (HTTP 200):**

```json
{
  "user": {
    "id": 1,
    "name": "Admin User",
    "email": "admin@shifa.test",
    "role": "admin",
    "specialty": null,
    "created_at": "2026-09-04T20:34:46.000000Z"
  },
  "token": "2|bdcwyRf2m9Zc6HBvgOg3Y9eF6U8STjczn9us8xYD73f04ec5"
}
```

**Bad credentials (HTTP 422):**

```json
{
  "message": "The provided credentials are incorrect.",
  "error_code": "invalid_credentials",
  "errors": {
    "email": ["The provided credentials are incorrect."]
  }
}
```

---

### POST `/api/logout`

Revoke the current bearer token.

**Access:** any authenticated user.

**Body:** no body.

**Example success (HTTP 200):**

```json
{"message": "Logged out"}
```

Subsequent calls with the revoked token return `HTTP 401 {"message":"Unauthenticated.","error_code":"unauthenticated"}`.

---

### GET `/api/me`

Return the currently authenticated user.

**Access:** any authenticated user.

**Body:** no body.

**Example success (HTTP 200):**

```json
{
  "user": {
    "id": 1,
    "name": "Admin User",
    "email": "admin@shifa.test",
    "role": "admin",
    "specialty": null,
    "created_at": "2026-09-04T20:32:06.000000Z"
  }
}
```

**Unauthenticated (HTTP 401):**

```json
{"message": "Unauthenticated.", "error_code": "unauthenticated"}
```

---

## Admin

Endpoints in this section require `role=admin` **unless explicitly marked "admin, coordinator"**. Rooms and staff reads, and now also rooms/staff/patients/surgery-types **writes**, are open to coordinators too because the surgery-scheduling workflow (room picker, surgeon dropdown, patient/type management) needs them. Only `GET /api/dashboard/stats` remains admin-only.

- **Admin-or-coordinator forbidden shape:** `HTTP 403 {"message":"Forbidden. Requires role: admin or coordinator","error_code":"role_forbidden","meta":{"required_role":["admin","coordinator"]}}`
- **Admin-only forbidden shape:** `HTTP 403 {"message":"Forbidden. Requires role: admin","error_code":"role_forbidden","meta":{"required_role":"admin"}}`

### GET `/api/rooms`

**Access:** admin, coordinator.

List all operating rooms, ordered by name.

**Body:** no body.

**Example success (HTTP 200):**

```json
{
  "data": [
    {"id": 1, "name": "OR-1", "status": "free", "supported_specialty": "Cardiology", "image_url": null, "created_at": "2026-09-04T20:32:09.000000Z", "updated_at": "2026-09-04T20:32:09.000000Z"},
    {"id": 2, "name": "OR-2", "status": "free", "supported_specialty": "Orthopedics", "image_url": null, "created_at": "2026-09-04T20:32:09.000000Z", "updated_at": "2026-09-04T20:32:09.000000Z"},
    {"id": 3, "name": "OR-3", "status": "free", "supported_specialty": "Neurology", "image_url": null, "created_at": "2026-09-04T20:32:09.000000Z", "updated_at": "2026-09-04T20:32:09.000000Z"},
    {"id": 4, "name": "OR-4", "status": "free", "supported_specialty": "General", "image_url": null, "created_at": "2026-09-04T20:32:09.000000Z", "updated_at": "2026-09-04T20:32:09.000000Z"},
    {"id": 5, "name": "OR-5", "status": "free", "supported_specialty": null, "image_url": null, "created_at": "2026-09-04T20:32:09.000000Z", "updated_at": "2026-09-04T20:32:09.000000Z"}
  ]
}
```

`image_url` is `null` for rooms with no uploaded image, or a full public URL (e.g. `http://127.0.0.1:8000/storage/rooms/<hash>.png`) once one is uploaded — see `POST`/`PUT /api/rooms` below.

---

### GET `/api/rooms/{room}`

**Access:** admin, coordinator.

Show a single room.

**Body:** no body.

**Example success (HTTP 200):**

```json
{
  "data": {
    "id": 1,
    "name": "OR-1",
    "status": "free",
    "supported_specialty": "Cardiology",
    "image_url": null,
    "created_at": "2026-09-04T20:32:09.000000Z",
    "updated_at": "2026-09-04T20:32:09.000000Z"
  }
}
```

**Not found (HTTP 404):**

```json
{"message": "No query results for model [App\\Models\\OperatingRoom] 9999"}
```

---

### POST `/api/rooms`

**Access:** admin, coordinator. *(Changed — was admin-only.)*

Create a room. Accepts either JSON or `multipart/form-data` (use multipart when uploading an image).

**Body:**

| Field | Type | Required | Notes |
|---|---|---|---|
| `name` | string | yes | max 255 |
| `status` | string | no | one of `free`, `preparing`, `in_use`, `cleaning` |
| `supported_specialty` | string \| null | no | max 255 |
| `image` | file | no | must be an image, max 5MB; stored on the `public` disk under `rooms/`, requires `php artisan storage:link` |

**Example: JSON body, no image (HTTP 201):**

```json
{
  "data": {
    "id": 6,
    "name": "OR-Probe",
    "status": null,
    "supported_specialty": "General",
    "image_url": null,
    "created_at": "2026-09-04T20:33:21.000000Z",
    "updated_at": "2026-09-04T20:33:21.000000Z"
  }
}
```

Note: `status` is `null` here because the request did not supply it — the DB default (`free`) is only applied when the column is entirely omitted at the SQL level. Prefer sending `status: "free"` explicitly.

**Example: `multipart/form-data` with `image` field (HTTP 201):**

```bash
curl -X POST /api/rooms \
  -H "Authorization: Bearer <token>" -H "Accept: application/json" \
  -F "name=OR-Image-Test" -F "supported_specialty=General" -F "image=@room-photo.png"
```

```json
{
  "data": {
    "id": 6,
    "name": "OR-Image-Test",
    "status": null,
    "supported_specialty": "General",
    "image_url": "http://localhost:8000/storage/rooms/ZAnTPH2HDXNLqImxQNhCMBWjAQVnDswED5JYeyuP.png",
    "created_at": "2026-09-08T23:55:40.000000Z",
    "updated_at": "2026-09-08T23:55:40.000000Z"
  }
}
```

**Validation failure — missing name (HTTP 422):**

```json
{
  "message": "The name field is required.",
  "errors": {"name": ["The name field is required."]}
}
```

**Validation failure — non-image file uploaded (HTTP 422):**

```json
{
  "message": "The image field must be an image.",
  "errors": {"image": ["The image field must be an image."]}
}
```

**Wrong role (HTTP 403):**

```json
{"message": "Forbidden. Requires role: admin or coordinator", "error_code": "role_forbidden", "meta": {"required_role": ["admin", "coordinator"]}}
```

---

### PUT `/api/rooms/{room}` · PATCH `/api/rooms/{room}`

**Access:** admin, coordinator. *(Changed — was admin-only.)*

Update a room. All fields optional (`sometimes`). Uploading a new `image` deletes the room's previous image file from storage.

**Important:** native `PUT`/`PATCH` requests cannot carry `multipart/form-data` bodies in PHP. To update with an image, send a `POST` request with a `_method=PUT` field (Laravel's method-spoofing) instead of a real `PUT`:

```bash
curl -X POST /api/rooms/6 \
  -H "Authorization: Bearer <token>" -H "Accept: application/json" \
  -F "_method=PUT" -F "name=OR-Image-Renamed" -F "image=@new-photo.png"
```

**Body:**

| Field | Type | Notes |
|---|---|---|
| `name` | string | max 255 |
| `status` | string | one of `free`, `preparing`, `in_use`, `cleaning` |
| `supported_specialty` | string \| null | max 255 |
| `image` | file | must be an image, max 5MB |

**Example success, JSON body (HTTP 200):**

```json
{
  "data": {
    "id": 6,
    "name": "OR-Probe",
    "status": "preparing",
    "supported_specialty": "General",
    "image_url": null,
    "created_at": "2026-09-04T20:33:21.000000Z",
    "updated_at": "2026-09-04T20:33:22.000000Z"
  }
}
```

**Example success, with `_method=PUT` + image (HTTP 200):**

```json
{
  "data": {
    "id": 6,
    "name": "OR-Image-Renamed",
    "status": "free",
    "supported_specialty": null,
    "image_url": "http://localhost:8000/storage/rooms/pX7Y5pDERcmsO4BOMSJFc3l7tCqIbQpRxeSpkl4a.png",
    "created_at": "2026-09-08T23:47:28.000000Z",
    "updated_at": "2026-09-08T23:47:41.000000Z"
  }
}
```

---

### DELETE `/api/rooms/{room}`

**Access:** admin, coordinator. *(Changed — was admin-only.)*

Hard-delete a room (also deletes its uploaded image file from storage, if any).

**Body:** no body.

**Example success (HTTP 200):**

```json
{"message": "Room deleted"}
```

---

### GET `/api/rooms/{room}/surgeries`

**Access:** admin, coordinator.

Full surgery history/schedule for a room within a date range — not just today's timeline (contrast with `GET /api/surgeries/calendar`, which is global across all rooms).

**Query parameters:**

| Param | Format | Default |
|---|---|---|
| `from` | `YYYY-MM-DD` | today |
| `to`   | `YYYY-MM-DD` | today (i.e. defaults to today only if both are omitted) |

**Example: `GET /api/rooms/5/surgeries?from=2026-09-01&to=2026-09-30` (HTTP 200):**

```json
{
  "room": {"id": 5, "name": "OR-5", "status": "free", "supported_specialty": null, "image_url": null, "created_at": "...", "updated_at": "..."},
  "from": "2026-09-01 00:00:00",
  "to": "2026-09-30 23:59:59",
  "surgeries": [
    {"id": 6, "patient_id": 1, "surgeon_id": 4, "room_id": 5, "surgery_type_id": 4, "created_by": 2, "priority": "normal", "scheduled_start": "2026-09-15T08:00:00.000000Z", "scheduled_end": "2026-09-15T09:00:00.000000Z", "estimated_duration_min": 60, "actual_start": null, "actual_end": null, "status": "scheduled", "patient": {...}, "surgeon": {...}, "surgery_type": {...}}
  ]
}
```

Note the items here do **not** include a nested `room` object (it would be redundant with the top-level `room` key), unlike `SurgeryResource` responses elsewhere.

**Example: no `from`/`to` supplied — defaults to today only (HTTP 200):**

```json
{
  "room": {"id": 5, "name": "OR-5", "status": "free", "supported_specialty": null, "image_url": null, "created_at": "...", "updated_at": "..."},
  "from": "2026-09-08 00:00:00",
  "to": "2026-09-08 23:59:59",
  "surgeries": []
}
```

---

### Operating room availability slots

Room slots represent a room's recurring weekly availability window (e.g. "OR-1 available Wednesdays 08:00–16:00"). All 4 endpoints require **admin, coordinator**.

A room with **zero slots defined** is treated as unrestricted — any time is bookable there (this preserves backward compatibility with rooms seeded before this feature existed). Once a room has at least one slot, any requested surgery time must fall entirely within one of that room's slots for the matching day of week, or it is rejected — see the conflict rules under `POST /api/surgeries` below.

#### GET `/api/rooms/{room}/slots`

List all slots for a room, ordered by day of week then start time.

**Example success (HTTP 200):**

```json
{"data": [{"id": 1, "room_id": 1, "day_of_week": 3, "start_time": "08:00", "end_time": "16:00"}]}
```

`day_of_week` follows Carbon/PHP convention: `0` = Sunday .. `6` = Saturday.

#### POST `/api/rooms/{room}/slots`

Create a slot.

**Body:**

| Field | Type | Required | Notes |
|---|---|---|---|
| `day_of_week` | integer | yes | `0`–`6` (Sun–Sat) |
| `start_time` | string | yes | `H:i` format, e.g. `08:00` |
| `end_time` | string | yes | `H:i` format; must be after `start_time` |

**Example: `POST /api/rooms/1/slots` `{"day_of_week":3,"start_time":"08:00","end_time":"16:00"}` (HTTP 201):**

```json
{"data": {"id": 1, "room_id": 1, "day_of_week": 3, "start_time": "08:00", "end_time": "16:00"}}
```

#### PUT `/api/rooms/{room}/slots/{slot}` · PATCH `/api/rooms/{room}/slots/{slot}`

Update a slot. All fields optional.

**Example: `PUT /api/rooms/1/slots/1` `{"end_time":"18:00"}` (HTTP 200):**

```json
{"data": {"id": 1, "room_id": 1, "day_of_week": 3, "start_time": "08:00", "end_time": "18:00"}}
```

**Invalid range — end before/equal to start (HTTP 422):**

```json
{"message": "The end time must be after the start time.", "error_code": "slot_invalid_time_range", "errors": {"end_time": ["The end time must be after the start time."]}}
```

#### DELETE `/api/rooms/{room}/slots/{slot}`

**Example success (HTTP 200):**

```json
{"message": "Slot deleted"}
```

If `{slot}` does not belong to `{room}`, returns `HTTP 404 {"message": "Slot does not belong to this room.", "error_code": "slot_room_mismatch"}`.

---

### GET `/api/staff`

**Access:** admin, coordinator.

List all users whose role is `coordinator` or `surgeon` (admins are excluded), ordered by name. Supports search.

**Query parameters:**

| Param | Notes |
|---|---|
| `search` | optional; case-insensitive partial match against `name` |

**Body:** no body.

**Example success, no search (HTTP 200):**

```json
{
  "data": [
    {"id": 2, "name": "Coordinator One", "email": "coord1@shifa.test", "role": "coordinator", "specialty": null, "created_at": "2026-09-04T20:32:07.000000Z"},
    {"id": 3, "name": "Coordinator Two", "email": "coord2@shifa.test", "role": "coordinator", "specialty": null, "created_at": "2026-09-04T20:32:07.000000Z"},
    {"id": 6, "name": "Dr. Baumbach", "email": "surgeon3@shifa.test", "role": "surgeon", "specialty": "Neurology", "created_at": "2026-09-04T20:32:09.000000Z"},
    {"id": 7, "name": "Dr. Dach", "email": "surgeon4@shifa.test", "role": "surgeon", "specialty": "General", "created_at": "2026-09-04T20:32:09.000000Z"},
    {"id": 5, "name": "Dr. Prohaska", "email": "surgeon2@shifa.test", "role": "surgeon", "specialty": "Orthopedics", "created_at": "2026-09-04T20:32:08.000000Z"},
    {"id": 4, "name": "Dr. Ziemann", "email": "surgeon1@shifa.test", "role": "surgeon", "specialty": "Cardiology", "created_at": "2026-09-04T20:32:08.000000Z"}
  ]
}
```

**Example: `GET /api/staff?search=dr.` (HTTP 200) — matches every surgeon (all seeded as "Dr. X"):**

```json
{"data": [
  {"id": 5, "name": "Dr. Kertzmann", "email": "surgeon2@shifa.test", "role": "surgeon", "specialty": "Orthopedics", "created_at": "2026-09-08T23:54:56.000000Z"},
  {"id": 7, "name": "Dr. Mueller", "email": "surgeon4@shifa.test", "role": "surgeon", "specialty": "General", "created_at": "2026-09-08T23:54:56.000000Z"},
  {"id": 6, "name": "Dr. Rath", "email": "surgeon3@shifa.test", "role": "surgeon", "specialty": "Neurology", "created_at": "2026-09-08T23:54:56.000000Z"},
  {"id": 4, "name": "Dr. Zboncak", "email": "surgeon1@shifa.test", "role": "surgeon", "specialty": "Cardiology", "created_at": "2026-09-08T23:54:56.000000Z"}
]}
```

---

### GET `/api/staff/{staff}`

**Access:** admin, coordinator.

Show a single user by ID (any user, not filtered by role — the URL is called `staff` but it hits the underlying `users` table).

**Example success (HTTP 200):**

```json
{
  "data": {
    "id": 2,
    "name": "Coordinator One",
    "email": "coord1@shifa.test",
    "role": "coordinator",
    "specialty": null,
    "created_at": "2026-09-04T20:32:07.000000Z"
  }
}
```

---

### POST `/api/staff`

**Access:** admin, coordinator. *(Changed — was admin-only; coordinators now create staff too, matching the patients-write pattern.)*

Create a coordinator or surgeon (admins cannot be created through this endpoint).

**Body:**

| Field | Type | Required | Notes |
|---|---|---|---|
| `name` | string | yes | max 255 |
| `email` | string | yes | valid email, unique |
| `password` | string | yes | min 8 |
| `role` | string | yes | one of `coordinator`, `surgeon` |
| `specialty` | string \| null | conditional | required when `role=surgeon` |

**Example success (HTTP 201):**

```json
{
  "data": {
    "id": 8,
    "name": "Probe Surgeon",
    "email": "probe.surg@shifa.test",
    "role": "surgeon",
    "specialty": "General",
    "created_at": "2026-09-04T20:33:27.000000Z"
  }
}
```

---

### PUT `/api/staff/{staff}` · PATCH `/api/staff/{staff}`

**Access:** admin, coordinator. *(Changed — was admin-only.)*

Update a user. All fields optional.

**Body:**

| Field | Type | Notes |
|---|---|---|
| `name` | string | max 255 |
| `email` | string | unique (ignoring current record) |
| `password` | string | min 8, re-hashed |
| `role` | string | one of `coordinator`, `surgeon` |
| `specialty` | string \| null | |

**Example success (HTTP 200):**

```json
{
  "data": {
    "id": 2,
    "name": "Coordinator One Renamed",
    "email": "coord1@shifa.test",
    "role": "coordinator",
    "specialty": null,
    "created_at": "2026-09-04T20:32:07.000000Z"
  }
}
```

---

### DELETE `/api/staff/{staff}`

**Access:** admin, coordinator. *(Changed — was admin-only.)*

Hard-delete a user. If the user has FK-referenced rows (e.g. surgeries where they are the surgeon or the creator) the underlying DB will reject the delete with an SQL constraint error.

**Example success (HTTP 200):**

```json
{"message": "Staff deleted"}
```

---

### GET `/api/dashboard/stats`

**Access:** admin only.

Return dashboard counters for the admin overview.

**Body:** no body.

**Example success (HTTP 200):**

```json
{
  "surgeries_today": 2,
  "rooms_in_use": 0,
  "total_rooms": 5,
  "surgeries_this_week": 5,
  "surgeries_completed_today": 0,
  "surgeries_in_progress": 1
}
```

**Wrong role (HTTP 403):**

```json
{"message": "Forbidden. Requires role: admin", "error_code": "role_forbidden", "meta": {"required_role": "admin"}}
```

---

## Coordinator

Most endpoints in this section require `role=coordinator`. A few (patients CRUD, surgery-types writes) are marked **admin, coordinator** — see each endpoint. Requests from a disallowed role return `HTTP 403 {"message":"Forbidden. Requires role: <allowed roles>"}`.

### GET `/api/patients`

**Access:** admin, coordinator.

List all patients ordered by name. Supports search.

**Query parameters:**

| Param | Notes |
|---|---|
| `search` | optional; case-insensitive partial match against `name` |

**Body:** no body.

**Example: `GET /api/patients?search=jul` (HTTP 200):**

```json
{"data": [{"id": 1, "name": "Lempi McGlynn", "mrn": "MRN-00001", "medical_notes": "...", "created_at": "...", "updated_at": "..."}]}
```

(Matches because the seeded patient's name contains "Jul" — actual seeded names vary run to run since they come from Faker. Returns `{"data":[]}` when nothing matches.)

**Example success, no search (HTTP 200):** (truncated to 3 rows for brevity — the actual response includes all patients)

```json
{
  "data": [
    {"id": 5, "name": "Bryon Hauck", "mrn": "MRN-00005", "medical_notes": null, "created_at": "2026-09-04T20:32:10.000000Z", "updated_at": "2026-09-04T20:32:10.000000Z"},
    {"id": 2, "name": "Cleve White", "mrn": "MRN-00002", "medical_notes": "Voluptatum consectetur ipsum doloremque a soluta provident blanditiis dicta.", "created_at": "2026-09-04T20:32:10.000000Z", "updated_at": "2026-09-04T20:32:10.000000Z"},
    {"id": 4, "name": "Dayna Roob III", "mrn": "MRN-00004", "medical_notes": null, "created_at": "2026-09-04T20:32:10.000000Z", "updated_at": "2026-09-04T20:32:10.000000Z"}
  ]
}
```

---

### GET `/api/patients/{patient}`

**Access:** admin, coordinator.

Show a single patient.

**Example success (HTTP 200):**

```json
{
  "data": {
    "id": 1,
    "name": "Kelton King",
    "mrn": "MRN-00001",
    "medical_notes": null,
    "created_at": "2026-09-04T20:32:10.000000Z",
    "updated_at": "2026-09-04T20:32:10.000000Z"
  }
}
```

---

### POST `/api/patients`

**Access:** admin, coordinator.

Create a patient.

**Body:**

| Field | Type | Required | Notes |
|---|---|---|---|
| `name` | string | yes | max 255 |
| `mrn` | string | yes | max 50, unique |
| `medical_notes` | string \| null | no | free text |

**Example success (HTTP 201):**

```json
{
  "data": {
    "id": 11,
    "name": "Probe Patient",
    "mrn": "MRN-PRB01",
    "medical_notes": "Test notes",
    "created_at": "2026-09-04T20:33:32.000000Z",
    "updated_at": "2026-09-04T20:33:32.000000Z"
  }
}
```

**Validation failure (HTTP 422):**

```json
{
  "message": "The name field is required. (and 1 more error)",
  "errors": {
    "name": ["The name field is required."],
    "mrn": ["The mrn field is required."]
  }
}
```

---

### PUT `/api/patients/{patient}` · PATCH `/api/patients/{patient}`

**Access:** admin, coordinator.

Update a patient. All fields optional (`sometimes`).

**Body:**

| Field | Type |
|---|---|
| `name` | string |
| `mrn` | string (unique, ignoring current record) |
| `medical_notes` | string \| null |

**Example success (HTTP 200):**

```json
{
  "data": {
    "id": 1,
    "name": "Kelton King",
    "mrn": "MRN-00001",
    "medical_notes": "Updated",
    "created_at": "2026-09-04T20:32:10.000000Z",
    "updated_at": "2026-09-04T20:33:33.000000Z"
  }
}
```

---

### DELETE `/api/patients/{patient}`

**Access:** admin, coordinator.

Hard-delete a patient (cascade-deletes their surgeries — the FK is `cascadeOnDelete`).

**Example success (HTTP 200):**

```json
{"message": "Patient deleted"}
```

---

### GET `/api/surgeries`

**Access:** coordinator only.

List all surgeries ordered by `scheduled_start` (asc), with `patient`, `surgeon`, `room`, `surgery_type` eager-loaded.

**Body:** no body.

**Example success (HTTP 200):** (one item shown — the full response is an array of all surgeries in the DB)

```json
{
  "data": [
    {
      "id": 1,
      "patient_id": 1,
      "surgeon_id": 4,
      "room_id": 1,
      "surgery_type_id": 1,
      "created_by": 2,
      "priority": "normal",
      "scheduled_start": "2026-09-04T14:00:00.000000Z",
      "scheduled_end": "2026-09-04T18:00:00.000000Z",
      "estimated_duration_min": 240,
      "actual_start": null,
      "actual_end": null,
      "status": "scheduled",
      "patient": {"id": 1, "name": "Kelton King", "mrn": "MRN-00001", "medical_notes": null, "created_at": "2026-09-04T20:32:10.000000Z", "updated_at": "2026-09-04T20:32:10.000000Z"},
      "surgeon": {"id": 4, "name": "Dr. Ziemann", "email": "surgeon1@shifa.test", "role": "surgeon", "specialty": "Cardiology", "created_at": "2026-09-04T20:32:08.000000Z"},
      "room":    {"id": 1, "name": "OR-1", "status": "free", "supported_specialty": "Cardiology", "image_url": null, "created_at": "2026-09-04T20:32:09.000000Z", "updated_at": "2026-09-04T20:32:09.000000Z"},
      "surgery_type": {"id": 1, "name": "Coronary Bypass", "average_duration_min": 240, "required_specialty": "Cardiology", "default_room_id": null}
    }
  ]
}
```

`scheduled_end` is a computed field (`scheduled_start + estimated_duration_min`) — new field, not stored in the database. It's what the overlap-conflict check (see `POST /api/surgeries` below) compares against.

---

### GET `/api/surgeries/{surgery}`

**Access:** admin (any surgery, read-only), coordinator (any surgery), surgeon (**own surgeries only** — see below).

Show a single surgery. Same shape as an item in the index, **plus** a `creator` (the coordinator who created it) — the show endpoint additionally eager-loads `creator`.

**Bug fix history:**
- This endpoint was originally coordinator-only, which meant a surgeon got `403` viewing their own surgery's detail — needed by the surgeon's mobile app. It was opened to `role:surgeon`, with the controller enforcing that a surgeon may only view a surgery where `surgery.surgeon_id` matches their own user ID; viewing another surgeon's surgery still returns `403`.
- It was then discovered admins were also blocked (`403`) viewing a surgery's card from the Room Detail screen, even though admins can already see room/surgery data elsewhere (dashboard, rooms). The route now also allows `role:admin`; admins are treated like coordinators — no ownership restriction, any surgery is viewable.

**Example success — admin or coordinator viewing any surgery, or a surgeon viewing their own (HTTP 200):**

```json
{
  "data": {
    "id": 1,
    "patient_id": 1,
    "surgeon_id": 4,
    "room_id": 1,
    "surgery_type_id": 1,
    "created_by": 2,
    "priority": "normal",
    "scheduled_start": "2026-09-08T14:00:00.000000Z",
    "scheduled_end": "2026-09-08T18:00:00.000000Z",
    "estimated_duration_min": 240,
    "actual_start": null,
    "actual_end": null,
    "status": "scheduled",
    "patient": {"id": 1, "name": "Lempi McGlynn", "mrn": "MRN-00001", "medical_notes": "...", "created_at": "...", "updated_at": "..."},
    "surgeon": {"id": 4, "name": "Dr. Zboncak", "email": "surgeon1@shifa.test", "role": "surgeon", "specialty": "Cardiology", "created_at": "..."},
    "room":    {"id": 1, "name": "OR-1", "status": "free", "supported_specialty": "Cardiology", "image_url": null, "created_at": "...", "updated_at": "..."},
    "surgery_type": {"id": 1, "name": "Coronary Bypass", "average_duration_min": 240, "required_specialty": "Cardiology", "default_room_id": null},
    "creator": {"id": 2, "name": "Coordinator One", "email": "coord1@shifa.test", "role": "coordinator", "specialty": null, "created_at": "..."}
  }
}
```

**Example — surgeon1 (user id 4) viewing surgery #2, which belongs to surgeon2 (id 5) — HTTP 403:**

```json
{"message": "Forbidden. You may only view your own surgeries.", "error_code": "surgery_not_owned"}
```

---

### GET `/api/surgeries/calendar`

**Access:** coordinator only.

Return all surgeries between two dates, grouped by room, for the timeline view.

**Query parameters:**

| Param | Format | Default |
|---|---|---|
| `from` | `YYYY-MM-DD` | today (start of day) |
| `to`   | `YYYY-MM-DD` | today + 7 days (end of day) |

**Example success (HTTP 200):**

```json
{
  "from": "2026-09-01 00:00:00",
  "to": "2026-09-30 23:59:59",
  "rooms": [
    {
      "room_id": 4,
      "room_name": "OR-4",
      "surgeries": [
        { "id": 3, "patient_id": 3, "surgeon_id": 7, "room_id": 4, "surgery_type_id": 4, "created_by": 3, "priority": "emergency", "scheduled_start": "2026-09-03T10:00:00.000000Z", "estimated_duration_min": 60, "actual_start": "2026-09-03T10:00:00.000000Z", "actual_end": "2026-09-03T11:00:00.000000Z", "status": "completed", "patient": {"id": 3, "name": "Jeremy Maggio", "mrn": "MRN-00003", "medical_notes": null, "created_at": "2026-09-04T20:32:10.000000Z", "updated_at": "2026-09-04T20:32:10.000000Z"}, "surgeon": {"id": 7, "name": "Dr. Dach", "email": "surgeon4@shifa.test", "role": "surgeon", "specialty": "General", "created_at": "2026-09-04T20:32:09.000000Z"}, "room": {"id": 4, "name": "OR-4", "status": "free", "supported_specialty": "General", "created_at": "2026-09-04T20:32:09.000000Z", "updated_at": "2026-09-04T20:32:09.000000Z"}, "surgery_type": {"id": 4, "name": "Appendectomy", "average_duration_min": 60, "required_specialty": "General"} }
      ]
    }
  ]
}
```

(Only the first room+surgery shown for brevity — every room that has surgeries in the range is included, each with its full surgery list using the same surgery item shape as `/api/surgeries`.)

---

### POST `/api/surgeries`

**Access:** admin, coordinator.

Schedule a surgery. Enforces three server-side rules in addition to normal field validation:

1. **`scheduled_start` cannot be in the past** (`after_or_equal:now`) — rejects with `422` if the client sends a past datetime.
2. **No overlap in the same room.** Two surgeries in the same room can never have overlapping time ranges. A surgery's effective window is `[scheduled_start, scheduled_start + estimated_duration_min)`; cancelled surgeries are excluded from the check. Back-to-back bookings are allowed — if surgery A ends at 09:00, surgery B may start at exactly 09:00 in the same room.
3. **Room availability window.** If the target room has any `OperatingRoomSlot`s defined, the requested `[start, end)` must fall entirely within one of that room's slots for the matching day of week. Rooms with **no** slots defined at all are unrestricted (backward compatible with rooms that predate this feature).

**Body:**

| Field | Type | Required | Notes |
|---|---|---|---|
| `patient_id` | integer | yes | must exist in `patients` |
| `surgeon_id` | integer | yes | must exist in `users` |
| `room_id` | integer | yes | must exist in `operating_rooms` |
| `surgery_type_id` | integer | yes | must exist in `surgery_types` |
| `priority` | string | yes | `normal` or `emergency` |
| `scheduled_start` | datetime string | yes | any format Carbon accepts, e.g. `2026-09-10 10:00:00`; must be `>= now` |
| `estimated_duration_min` | integer | no | defaults to the surgery type's `average_duration_min` |

`created_by` is set automatically to the authenticated user. `status` is set to `scheduled`.

**Example success (HTTP 201):**

```json
{
  "data": {
    "id": 6,
    "patient_id": 1,
    "surgeon_id": 4,
    "room_id": 5,
    "surgery_type_id": 4,
    "created_by": 2,
    "priority": "normal",
    "scheduled_start": "2026-10-01T08:00:00.000000Z",
    "scheduled_end": "2026-10-01T09:00:00.000000Z",
    "estimated_duration_min": 60,
    "actual_start": null,
    "actual_end": null,
    "status": "scheduled",
    "patient": {"id": 1, "name": "Lempi McGlynn", "mrn": "MRN-00001", "medical_notes": "...", "created_at": "...", "updated_at": "..."},
    "surgeon": {"id": 4, "name": "Dr. Zboncak", "email": "surgeon1@shifa.test", "role": "surgeon", "specialty": "Cardiology", "created_at": "..."},
    "room":    {"id": 5, "name": "OR-5", "status": "free", "supported_specialty": null, "image_url": null, "created_at": "...", "updated_at": "..."},
    "surgery_type": {"id": 4, "name": "Appendectomy", "average_duration_min": 60, "required_specialty": "General", "default_room_id": null}
  }
}
```

**Validation failure — `scheduled_start` in the past (HTTP 422):**

```json
{
  "message": "The scheduled start field must be a date after or equal to now.",
  "error_code": "validation_failed",
  "errors": {"scheduled_start": ["The scheduled start field must be a date after or equal to now."]}
}
```

**Conflict — overlapping surgery in the same room (HTTP 422):** (this is booking 08:30–09:30 in a room that already has a confirmed 08:00–09:00 booking, surgery #6)

```json
{
  "message": "This room is already booked from 2026-10-01 08:00:00 to 2026-10-01 09:00:00 (surgery #6).",
  "error_code": "surgery_time_conflict",
  "meta": {
    "conflicting_surgery_id": 6,
    "conflicting_start": "2026-10-01 08:00:00",
    "conflicting_end": "2026-10-01 09:00:00"
  },
  "errors": {
    "scheduled_start": ["The room is unavailable at this time due to a conflicting surgery (#6) from 2026-10-01 08:00:00 to 2026-10-01 09:00:00."]
  }
}
```

The conflict check compares against each existing surgery's **effective end** (`scheduled_end`, which is `delayed_end_at` when a delay was auto-approved for that surgery, otherwise `scheduled_start + estimated_duration_min`) — never `estimated_duration_min` alone. This means a surgery whose delay was auto-approved correctly continues to block the room for its true extended duration, not just its original plan. See `POST /api/surgeries/{surgery}/delay` below.

**Boundary case — booking starts exactly when the prior surgery ends: succeeds (HTTP 201), not a conflict.** (continuing the example above, booking the same room at exactly `09:00:00` succeeds)

**Conflict — outside the room's defined availability window (HTTP 422):** (room has a Wednesday-only 08:00–16:00 slot; this request targets 19:00, which is both the wrong hours and past `end_time`)

```json
{
  "message": "The requested time falls outside this room's defined availability window.",
  "error_code": "room_availability_window_violation",
  "errors": {"scheduled_start": ["The requested time falls outside this room's defined availability window for that day."]}
}
```

---

### PUT `/api/surgeries/{surgery}` · PATCH `/api/surgeries/{surgery}`

**Access:** coordinator only.

Update a surgery. All fields optional (`sometimes`). The same three rules from `POST /api/surgeries` (future-only start, no room overlap, room availability window) are re-validated **only if** `room_id`, `scheduled_start`, or `estimated_duration_min` is present in the request — the surgery's own row is excluded from the overlap check via `excludeSurgeryId`, so updating a surgery's other fields (e.g. `status`) without touching its time/room never triggers a false self-conflict.

**Body:** same fields as create, plus `status ∈ {scheduled, in_progress, completed, cancelled, delayed}`.

**Example success (HTTP 200):**

```json
{
  "data": {
    "id": 1,
    "patient_id": 1,
    "surgeon_id": 4,
    "room_id": 1,
    "surgery_type_id": 1,
    "created_by": 2,
    "priority": "emergency",
    "scheduled_start": "2026-09-04T14:00:00.000000Z",
    "scheduled_end": "2026-09-04T18:00:00.000000Z",
    "estimated_duration_min": 240,
    "actual_start": null,
    "actual_end": null,
    "status": "scheduled",
    "patient": {"id": 1, "name": "Kelton King", "mrn": "MRN-00001", "medical_notes": "Updated", "created_at": "2026-09-04T20:32:10.000000Z", "updated_at": "2026-09-04T20:33:33.000000Z"},
    "surgeon": {"id": 4, "name": "Dr. Ziemann", "email": "surgeon1@shifa.test", "role": "surgeon", "specialty": "Cardiology", "created_at": "2026-09-04T20:32:08.000000Z"},
    "room":    {"id": 1, "name": "OR-1", "status": "free", "supported_specialty": "Cardiology", "image_url": null, "created_at": "2026-09-04T20:32:09.000000Z", "updated_at": "2026-09-04T20:32:09.000000Z"},
    "surgery_type": {"id": 1, "name": "Coronary Bypass", "average_duration_min": 240, "required_specialty": "Cardiology", "default_room_id": null}
  }
}
```

Conflict and past-date error shapes are identical to `POST /api/surgeries` above.

---

### DELETE `/api/surgeries/{surgery}`

**Access:** coordinator only.

**Does not hard-delete** — updates the surgery's status to `cancelled`.

**Example success (HTTP 200):**

```json
{"message": "Surgery cancelled"}
```

---

### POST `/api/surgeries/auto-schedule`

**Access:** coordinator only.

Ask the [SchedulingService](app/Services/SchedulingService.php) to build a proposed schedule from a batch of pending surgeries. **Nothing is persisted** — the coordinator reviews and then confirms by calling `POST /api/surgeries` per accepted proposal.

**Bug fix — past-date proposals:** the scheduler used to compute its search floor as "today at 08:00", which is in the past for any request made after 08:00, causing it to propose start times before "now" (and in edge cases, effectively yesterday once other conflict-avoidance logic pushed the search backward). It now floors the search at `max(now, today at 08:00)`, and the internal business-hours clamp additionally guards against ever returning a moment before "now" regardless of caller. The scheduler also now skips rooms whose defined availability slots don't cover the candidate day/time, and never proposes a slot that overlaps an existing non-cancelled surgery in that room — the exact same two hard rules enforced on `POST /api/surgeries`.

**Bug fix — proposing an already-booked time (regression from the delay-workflow work):** once `delayed_end_at` was introduced, every occupancy calculation in `SchedulingService` needed to stop reading a surgery's end as `estimated_duration_min` alone and instead use its *effective* end (`Surgery::$scheduled_end`, which is `delayed_end_at` when set). The root cause: `autoSchedule()`'s initial room/surgeon occupancy map, and the downstream-suggestion generator shared with `handleDelay()`, both still built their `[start, end]` intervals from `scheduled_start->addMinutes(estimated_duration_min)` even after `delayed_end_at` existed — so a surgery whose delay had been auto-approved (real end pushed far into the future) looked "free" again the moment its *original* estimated duration elapsed, even though the room was still actually occupied. Fixed by having every occupancy map in the class (auto-schedule's initial snapshot, the downstream-suggestion generator, and `findOverlappingSurgery`'s own comparison) read `$surgery->scheduled_end` instead of recomputing from `estimated_duration_min`.

Concrete repro used to verify the fix: surgeon2's in-progress surgery #2 in OR-2 (Orthopedics) had its delay auto-approved with `new_expected_end` several months out. Calling `auto-schedule` for another Orthopedics surgery afterward correctly proposed OR-2 starting exactly at that far-future moment (not the room's original ~2-hour estimated end), and a manual `POST /api/surgeries` attempt to book OR-2 any time before that moment was correctly rejected with `surgery_time_conflict`.

**Body:**

```json
{
  "pending": [
    {"patient_id": 7, "surgeon_id": 4, "surgery_type_id": 4, "priority": "emergency"},
    {"patient_id": 8, "surgeon_id": 6, "surgery_type_id": 2, "priority": "normal"}
  ]
}
```

Each item requires `patient_id`, `surgeon_id`, `surgery_type_id` (all must exist), and `priority ∈ {normal, emergency}`.

**Example success (HTTP 200):**

```json
{
  "proposals": [
    {
      "patient_id": 7,
      "surgeon_id": 4,
      "surgery_type_id": 4,
      "priority": "emergency",
      "room_id": 4,
      "scheduled_start": "2026-09-04 08:00:00",
      "estimated_duration_min": 60,
      "reason": "Room specialty 'General' matched."
    },
    {
      "patient_id": 8,
      "surgeon_id": 6,
      "surgery_type_id": 2,
      "priority": "normal",
      "room_id": 2,
      "scheduled_start": "2026-09-04 08:00:00",
      "estimated_duration_min": 120,
      "reason": "Room specialty 'Orthopedics' matched."
    }
  ]
}
```

**Validation failure (HTTP 422):**

```json
{
  "message": "The pending field is required.",
  "errors": {"pending": ["The pending field is required."]}
}
```

---

### GET `/api/schedule-suggestions`

List all `pending` schedule suggestions, most recent first, with `surgery` and `suggested_room` eager-loaded.

**Body:** no body.

**Example success (HTTP 200):**

```json
{
  "data": [
    {
      "id": 1,
      "surgery_id": 4,
      "suggested_room_id": 5,
      "suggested_start": "2026-09-05T20:32:13.000000Z",
      "reason": "Manual probe suggestion for API doc.",
      "status": "pending",
      "surgery": {"id": 4, "patient_id": 4, "surgeon_id": 6, "room_id": 3, "surgery_type_id": 3, "created_by": 3, "priority": "normal", "scheduled_start": "2026-09-05T09:00:00.000000Z", "estimated_duration_min": 300, "actual_start": null, "actual_end": null, "status": "scheduled"},
      "suggested_room": {"id": 5, "name": "OR-5", "status": "free", "supported_specialty": null, "created_at": "2026-09-04T20:32:09.000000Z", "updated_at": "2026-09-04T20:32:09.000000Z"},
      "created_at": "2026-09-04T20:32:13.000000Z"
    }
  ]
}
```

Returns `{"data":[]}` when there are no pending suggestions.

---

### POST `/api/schedule-suggestions/{suggestion}/accept`

Accept a pending suggestion. Applies the suggestion's `suggested_room_id` and `suggested_start` to the underlying surgery, marks the suggestion `accepted`, and rejects any sibling pending suggestions for the same surgery.

**Body:** no body.

**Example success (HTTP 200):**

```json
{
  "data": {
    "id": 1,
    "surgery_id": 4,
    "suggested_room_id": 5,
    "suggested_start": "2026-09-05T20:32:13.000000Z",
    "reason": "Manual probe suggestion for API doc.",
    "status": "accepted",
    "surgery": {"id": 4, "patient_id": 4, "surgeon_id": 6, "room_id": 5, "surgery_type_id": 3, "created_by": 3, "priority": "normal", "scheduled_start": "2026-09-05T20:32:13.000000Z", "estimated_duration_min": 300, "actual_start": null, "actual_end": null, "status": "scheduled"},
    "suggested_room": {"id": 5, "name": "OR-5", "status": "free", "supported_specialty": null, "created_at": "2026-09-04T20:32:09.000000Z", "updated_at": "2026-09-04T20:32:09.000000Z"},
    "created_at": "2026-09-04T20:32:13.000000Z"
  }
}
```

**Suggestion already accepted or rejected (HTTP 422):**

```json
{"message": "Suggestion is not pending.", "error_code": "suggestion_not_pending", "meta": {"current_status": "accepted"}}
```

**Not found (HTTP 404):**

```json
{"message": "No query results for model [App\\Models\\ScheduleSuggestion] 9999", "error_code": "not_found", "meta": {"model": "ScheduleSuggestion"}}
```

---

### POST `/api/schedule-suggestions/{suggestion}/reject`

Mark a pending suggestion `rejected`. Does not touch the underlying surgery.

**Body:** no body.

**Example success (HTTP 200):**

```json
{
  "data": {
    "id": 2,
    "surgery_id": 4,
    "suggested_room_id": 3,
    "suggested_start": "2026-09-06T20:32:13.000000Z",
    "reason": "Alternate probe suggestion.",
    "status": "rejected",
    "created_at": "2026-09-04T20:32:13.000000Z"
  }
}
```

**Already actioned (HTTP 422):**

```json
{"message": "Suggestion is not pending.", "error_code": "suggestion_not_pending", "meta": {"current_status": "rejected"}}
```

(In the observed run this is the response for suggestion #2 after suggestion #1 was accepted — accepting one suggestion automatically rejects sibling pending suggestions for the same surgery, so a subsequent explicit reject on #2 returns "not pending".)

---

## Surgeon

All endpoints in this section require `role=surgeon`. Requests from other roles return `HTTP 403 {"message":"Forbidden. Requires role: surgeon"}`.

### GET `/api/my-surgeries`

List all surgeries where `surgeon_id = current user`, ordered by `scheduled_start`. Eager-loads `patient`, `room`, `surgery_type` (but **not** `surgeon`, since it's implicit).

**Body:** no body.

**Example success (HTTP 200):** (as surgeon1 / id=4)

```json
{
  "data": [
    {
      "id": 1,
      "patient_id": 1,
      "surgeon_id": 4,
      "room_id": 1,
      "surgery_type_id": 1,
      "created_by": 2,
      "priority": "emergency",
      "scheduled_start": "2026-09-04T14:00:00.000000Z",
      "estimated_duration_min": 240,
      "actual_start": null,
      "actual_end": null,
      "status": "cancelled",
      "patient": {"id": 1, "name": "Kelton King", "mrn": "MRN-00001", "medical_notes": "Updated", "created_at": "2026-09-04T20:32:10.000000Z", "updated_at": "2026-09-04T20:33:33.000000Z"},
      "room":    {"id": 1, "name": "OR-1", "status": "free", "supported_specialty": "Cardiology", "created_at": "2026-09-04T20:32:09.000000Z", "updated_at": "2026-09-04T20:32:09.000000Z"},
      "surgery_type": {"id": 1, "name": "Coronary Bypass", "average_duration_min": 240, "required_specialty": "Cardiology"}
    }
  ]
}
```

Returns `{"data":[]}` for a surgeon with no assigned surgeries.

---

### POST `/api/surgeries/{surgery}/start`

Mark a surgery as started. Sets `actual_start = now()`, `status = in_progress`, and sets the room's `status = in_use`.

**Body:** no body.

**Example success (HTTP 200):**

```json
{
  "data": {
    "id": 5,
    "patient_id": 5,
    "surgeon_id": 7,
    "room_id": 4,
    "surgery_type_id": 5,
    "created_by": 2,
    "priority": "normal",
    "scheduled_start": "2026-09-05T14:00:00.000000Z",
    "estimated_duration_min": 90,
    "actual_start": "2026-09-04T20:33:51.000000Z",
    "actual_end": null,
    "status": "in_progress",
    "patient": {"id": 5, "name": "Bryon Hauck", "mrn": "MRN-00005", "medical_notes": null, "created_at": "2026-09-04T20:32:10.000000Z", "updated_at": "2026-09-04T20:32:10.000000Z"},
    "surgeon": {"id": 7, "name": "Dr. Dach", "email": "surgeon4@shifa.test", "role": "surgeon", "specialty": "General", "created_at": "2026-09-04T20:32:09.000000Z"},
    "room":    {"id": 4, "name": "OR-4", "status": "in_use", "supported_specialty": "General", "created_at": "2026-09-04T20:32:09.000000Z", "updated_at": "2026-09-04T20:33:51.000000Z"},
    "surgery_type": {"id": 5, "name": "Hernia Repair", "average_duration_min": 90, "required_specialty": "General"}
  }
}
```

---

### POST `/api/surgeries/{surgery}/complete`

Mark a surgery as completed. Sets `actual_end = now()`, `status = completed`, and sets the room's `status = cleaning`.

**Body:** no body.

**Example success (HTTP 200):**

```json
{
  "data": {
    "id": 2,
    "patient_id": 2,
    "surgeon_id": 5,
    "room_id": 2,
    "surgery_type_id": 2,
    "created_by": 2,
    "priority": "normal",
    "scheduled_start": "2026-09-04T19:32:10.000000Z",
    "estimated_duration_min": 120,
    "actual_start": "2026-09-04T19:32:10.000000Z",
    "actual_end": "2026-09-04T20:33:54.000000Z",
    "status": "completed",
    "patient": {"id": 2, "name": "Cleve White", "mrn": "MRN-00002", "medical_notes": "Voluptatum consectetur ipsum doloremque a soluta provident blanditiis dicta.", "created_at": "2026-09-04T20:32:10.000000Z", "updated_at": "2026-09-04T20:32:10.000000Z"},
    "surgeon": {"id": 5, "name": "Dr. Prohaska", "email": "surgeon2@shifa.test", "role": "surgeon", "specialty": "Orthopedics", "created_at": "2026-09-04T20:32:08.000000Z"},
    "room":    {"id": 2, "name": "OR-2", "status": "cleaning", "supported_specialty": "Orthopedics", "created_at": "2026-09-04T20:32:09.000000Z", "updated_at": "2026-09-04T20:33:54.000000Z"},
    "surgery_type": {"id": 2, "name": "Knee Replacement", "average_duration_min": 120, "required_specialty": "Orthopedics"}
  }
}
```

---

### POST `/api/surgeries/{surgery}/delay`

**Access:** surgeon, own surgery only.

**Redesigned delay-reporting workflow** (replaces the old no-body "mark as delayed" behavior). The surgeon reports a **specific new expected end time** and a reason; the surgery's status does **not** change immediately — the API evaluates whether the new end time is safe and either auto-approves or escalates to coordinator review.

**Body:**

| Field | Type | Required | Notes |
|---|---|---|---|
| `new_expected_end` | datetime string | yes | must be strictly after "now" |
| `reason` | string | yes | min 3 characters |

**Preconditions (checked in this order):**
1. The surgery must belong to the requesting surgeon (`surgery.surgeon_id === auth user id`), else `403 surgery_not_owned`.
2. The surgery must be `status=in_progress`, else `422 surgery_not_in_progress`.
3. `new_expected_end`/`reason` must pass validation, else `422 validation_failed`.

**Evaluation logic** ([`SchedulingService::evaluateDelayRequest`](app/Services/SchedulingService.php)): reuses `findOverlappingSurgery` to check whether extending this surgery's effective end to `new_expected_end` would overlap any OTHER non-cancelled surgery in the same room.

- **No conflict → auto-approved immediately.** The surgery's `status` becomes `delayed`, `delayed_end_at` is set to `new_expected_end` (this becomes its new effective `scheduled_end` for all future overlap/availability checks — `estimated_duration_min` is left untouched as a record of the original plan), and `delay_reason` stores the surgeon's reason. No downstream surgeries are touched.
- **Conflict exists → left pending, no status change.** The surgery stays exactly as it was (`in_progress`, `delayed_end_at` untouched). `ScheduleSuggestion` rows are generated for every downstream surgery in the room using the same delay-and-relocate pattern as the legacy `handleDelay()` behavior, and all coordinators are notified. The surgeon is told the request is pending review.

Every call — auto-approved or not — is logged to the `delay_requests` table (`surgery_id`, `requested_by`, `new_expected_end`, `reason`, `auto_approved`) as a permanent audit trail, independent of the live `schedule_suggestions` workflow.

**Example — auto-approved, no downstream conflict (HTTP 200):**

```bash
curl -X POST /api/surgeries/2/delay -H "Authorization: Bearer <surgeon token>" \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"new_expected_end":"2026-12-31 23:00:00","reason":"Unexpected bleeding required additional time"}'
```

```json
{
  "message": "Delay auto-approved: the new expected end time does not conflict with any other surgery in this room.",
  "auto_approved": true,
  "surgery": {
    "id": 2,
    "patient_id": 2,
    "surgeon_id": 5,
    "room_id": 2,
    "surgery_type_id": 2,
    "created_by": 2,
    "priority": "normal",
    "scheduled_start": "2026-09-09T00:46:29.000000Z",
    "scheduled_end": "2026-12-31T23:00:00.000000Z",
    "estimated_duration_min": 120,
    "actual_start": "2026-09-09T00:46:29.000000Z",
    "actual_end": null,
    "status": "delayed",
    "patient": {"id": 2, "name": "Miss Jordane Schowalter Jr.", "mrn": "MRN-00002", "medical_notes": "...", "created_at": "...", "updated_at": "..."},
    "surgeon": {"id": 5, "name": "Dr. Keeling", "email": "surgeon2@shifa.test", "role": "surgeon", "specialty": "Orthopedics", "created_at": "..."},
    "room": {"id": 2, "name": "OR-2", "status": "free", "supported_specialty": "Orthopedics", "image_url": null, "created_at": "...", "updated_at": "..."},
    "surgery_type": {"id": 2, "name": "Knee Replacement", "average_duration_min": 120, "required_specialty": "Orthopedics", "default_room_id": null}
  }
}
```

Note `scheduled_end` now equals `new_expected_end` (2026-12-31), not `scheduled_start + estimated_duration_min` — `estimated_duration_min` stays `120`, unchanged, as the audit-trail of the original plan.

**Example — pending coordinator review, downstream conflict (HTTP 200):** (surgery #1 requests extending to 19:30, but surgery #6 is already booked 19:00–20:00 in the same room)

```json
{
  "message": "Delay request is pending coordinator review: extending this surgery to 2026-09-09 19:30:00 would conflict with surgery #6 in the same room. 2 suggestion(s) generated for the affected downstream surgeries.",
  "auto_approved": false,
  "conflict_with_surgery_id": 6,
  "suggestions": [
    {
      "id": 1,
      "surgery_id": 6,
      "suggested_room_id": 1,
      "suggested_start": "2026-09-09T20:30:00.000000Z",
      "reason": "Delayed by 90 min due to a pending delay request on surgery #1 in the same room.",
      "status": "pending",
      "created_at": "2026-09-09T01:44:30.000000Z"
    },
    {
      "id": 2,
      "surgery_id": 6,
      "suggested_room_id": 5,
      "suggested_start": "2026-09-09T19:00:00.000000Z",
      "reason": "Move to room 'OR-5' to keep original start time despite a pending delay request on surgery #1 in the same room.",
      "status": "pending",
      "created_at": "2026-09-09T01:44:30.000000Z"
    }
  ]
}
```

In this outcome, `GET /api/surgeries/1` immediately afterward still shows `"status": "in_progress"` and `"delayed_end_at": null` — the surgery is completely untouched pending a coordinator's decision via `POST /api/schedule-suggestions/{id}/accept|reject`.

**Not your surgery (HTTP 403):**

```json
{"message": "Forbidden. You may only report a delay on your own surgery.", "error_code": "surgery_not_owned"}
```

**Surgery not in progress (HTTP 422):** (e.g. the surgery is `completed`, or already `delayed` from a prior auto-approved request)

```json
{
  "message": "This surgery is not in progress (current status: completed). Only an in-progress surgery can have a delay reported.",
  "error_code": "surgery_not_in_progress",
  "meta": {"current_status": "completed"}
}
```

**Validation — `new_expected_end` not in the future (HTTP 422):**

```json
{
  "message": "The new expected end field must be a date after now.",
  "error_code": "validation_failed",
  "errors": {"new_expected_end": ["The new expected end field must be a date after now."]}
}
```

**Validation — `reason` too short (HTTP 422):**

```json
{
  "message": "The reason field must be at least 3 characters.",
  "error_code": "validation_failed",
  "errors": {"reason": ["The reason field must be at least 3 characters."]}
}
```

---

## Shared (any authenticated role)

### GET `/api/notifications`

List notifications for the current user, newest first.

**Body:** no body.

**Example success (HTTP 200):**

```json
{
  "data": [
    {
      "id": 1,
      "user_id": 2,
      "title": "Schedule adjustment suggested",
      "body": "Suggestion #1 for surgery #4: Manual probe suggestion for API doc.",
      "type": "schedule_suggestion",
      "read_at": null,
      "created_at": "2026-09-04T20:32:13.000000Z"
    }
  ]
}
```

Returns `{"data":[]}` for a user with no notifications.

---

### GET `/api/surgery-types`

**Access:** any authenticated role.

List all surgery types ordered by name. Provided in the shared group because every role needs it to populate the surgery-type dropdown when scheduling.

**Body:** no body.

**Example success (HTTP 200):**

```json
{
  "data": [
    {"id": 4, "name": "Appendectomy", "average_duration_min": 60, "required_specialty": "General", "default_room_id": null},
    {"id": 3, "name": "Brain Tumor Resection", "average_duration_min": 300, "required_specialty": "Neurology", "default_room_id": null},
    {"id": 1, "name": "Coronary Bypass", "average_duration_min": 240, "required_specialty": "Cardiology", "default_room_id": null},
    {"id": 5, "name": "Hernia Repair", "average_duration_min": 90, "required_specialty": "General", "default_room_id": null},
    {"id": 2, "name": "Knee Replacement", "average_duration_min": 120, "required_specialty": "Orthopedics", "default_room_id": null}
  ]
}
```

`default_room_id` is a new nullable field — a suggested default room for the surgery type (not a hard constraint; the scheduler and manual booking are free to use any compatible room).

**Unauthenticated (HTTP 401):**

```json
{"message": "Unauthenticated."}
```

---

### Surgery types — write operations (new: full CRUD)

Previously `GET /api/surgery-types` was the only endpoint. Surgery types now support full CRUD; the three write endpoints below require **admin, coordinator** (unlike the shared-access `GET` above and `GET /api/surgery-types/{id}`, which is also admin/coordinator since detail lookups aren't needed by the mobile app's dropdown).

#### GET `/api/surgery-types/{surgeryType}`

**Access:** admin, coordinator.

```json
{"data": {"id": 6, "name": "Cataract Surgery", "average_duration_min": 45, "required_specialty": "Ophthalmology", "default_room_id": 1}}
```

#### POST `/api/surgery-types`

**Access:** admin, coordinator.

**Body:**

| Field | Type | Required | Notes |
|---|---|---|---|
| `name` | string | yes | max 255 |
| `average_duration_min` | integer | yes | min 5 |
| `required_specialty` | string \| null | no | max 255 |
| `default_room_id` | integer \| null | no | must exist in `operating_rooms` |

**Example: `POST /api/surgery-types` `{"name":"Cataract Surgery","average_duration_min":45,"required_specialty":"Ophthalmology","default_room_id":1}` (HTTP 201):**

```json
{"data": {"id": 6, "name": "Cataract Surgery", "average_duration_min": 45, "required_specialty": "Ophthalmology", "default_room_id": 1}}
```

#### PUT `/api/surgery-types/{surgeryType}` · PATCH `/api/surgery-types/{surgeryType}`

**Access:** admin, coordinator.

All fields optional (`sometimes`).

**Example: `PUT /api/surgery-types/6` `{"average_duration_min":50}` (HTTP 200):**

```json
{"data": {"id": 6, "name": "Cataract Surgery", "average_duration_min": 50, "required_specialty": "Ophthalmology", "default_room_id": 1}}
```

#### DELETE `/api/surgery-types/{surgeryType}`

**Access:** admin, coordinator.

**Example success (HTTP 200):**

```json
{"message": "Surgery type deleted"}
```

**Wrong role, e.g. surgeon (HTTP 403):**

```json
{"message": "Forbidden. Requires role: admin or coordinator", "error_code": "role_forbidden", "meta": {"required_role": ["admin", "coordinator"]}}
```

---

### POST `/api/notifications/{notification}/read`

Mark one of the current user's notifications as read (sets `read_at = now()`).

**Body:** no body.

**Example success (HTTP 200):**

```json
{
  "data": {
    "id": 1,
    "user_id": 2,
    "title": "Schedule adjustment suggested",
    "body": "Suggestion #1 for surgery #4: Manual probe suggestion for API doc.",
    "type": "schedule_suggestion",
    "read_at": "2026-09-04T20:33:55.000000Z",
    "created_at": "2026-09-04T20:32:13.000000Z"
  }
}
```

**Trying to mark another user's notification (HTTP 403):**

```json
{"message": "Forbidden. You may only mark your own notifications as read.", "error_code": "notification_not_owned"}
```

**Not found (HTTP 404):**

```json
{"message": "No query results for model [App\\Models\\Notification] 9999", "error_code": "not_found", "meta": {"model": "Notification"}}
```

---

## Appendix: seeded IDs used above

Running `php artisan migrate:fresh --seed` produces this fixed layout (patient/surgeon names vary run-to-run since they come from Faker, but IDs and roles are stable):

**Users**
| id | email | role | specialty |
|---|---|---|---|
| 1 | admin@shifa.test | admin | — |
| 2 | coord1@shifa.test | coordinator | — |
| 3 | coord2@shifa.test | coordinator | — |
| 4 | surgeon1@shifa.test | surgeon | Cardiology |
| 5 | surgeon2@shifa.test | surgeon | Orthopedics |
| 6 | surgeon3@shifa.test | surgeon | Neurology |
| 7 | surgeon4@shifa.test | surgeon | General |

All accounts use password `password`.

**Operating rooms:** OR-1 Cardiology, OR-2 Orthopedics, OR-3 Neurology, OR-4 General, OR-5 (no specialty).

**Surgery types:** 1 Coronary Bypass (240 min / Cardiology), 2 Knee Replacement (120 min / Orthopedics), 3 Brain Tumor Resection (300 min / Neurology), 4 Appendectomy (60 min / General), 5 Hernia Repair (90 min / General).

**Patients:** IDs 1–10.

**Surgeries:** 5 pre-seeded (id 1 today scheduled, id 2 today in_progress, id 3 yesterday completed, id 4 tomorrow scheduled, id 5 tomorrow scheduled).

**Operating room slots:** none seeded by default — all rooms are unrestricted out of the box. Add slots via `POST /api/rooms/{room}/slots` to start enforcing availability windows for a given room.

**Delay requests:** `delay_requests` is a plain audit table (no dedicated API endpoint) — every call to `POST /api/surgeries/{id}/delay` inserts one row (`surgery_id`, `requested_by`, `new_expected_end`, `reason`, `auto_approved`), whether or not it was auto-approved. It exists purely for history/audit; the live workflow for pending requests is `schedule_suggestions`, exposed via `GET /api/schedule-suggestions` and the accept/reject endpoints.


