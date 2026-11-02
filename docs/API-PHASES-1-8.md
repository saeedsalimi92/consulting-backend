# Backend API contract (phases 1–8)

All endpoints use `/api`. Times are ISO-8601 timestamps with an explicit offset; persisted booking times are UTC and schedule dates/times are interpreted in `Asia/Tehran`. Paginated resources use Laravel's `{data:{data,links,meta}}` shape. Validation errors are `422`, conflicts are `409`, unauthenticated calls are JSON `401`, and inaccessible client-owned resources return `404` where disclosure would be unsafe.

## Authentication and roles

- Public reads: `GET /health`, `/counselors`, `/counselors/{id-or-slug}`, `/specialties`, `/comments`, `/content/{type}`, `/content/{type}/{slug}`, `/booking/services`, `/booking/counselors/{counselor}/services`, `/booking/availability`.
- Client: `POST /client/register`, `POST /client/login`, then bearer token with `client` ability. Client-only operations are behind `EnsureClient`.
- Counselor: `POST /counselor/login`, then bearer token with `counselor` ability. Counselor scope is derived from `counselors.user_id`.
- Admin: `POST /admin/login`, then bearer token with `admin-panel` ability. Protected routes also require the named admin permission, such as `appointments.manage`, `notes.manage`, `finance.view`, `finance.manage`, or `content.manage`.

## Reception, booking and rooms

| Method | Path | Permission | Purpose |
| --- | --- | --- | --- |
| GET | `/admin/booking/reception/dashboard` | appointments.manage | Today's/upcoming appointments, held confirmations, cancellations, no-shows, available counselors and occupied rooms |
| GET | `/admin/booking/calendar?date=YYYY-MM-DD&view=day\|week\|month` | appointments.manage | Calendar; filters: `counselor_id`, `service_id`, `room_id`, `client_id`, `status`, `q` |
| GET/POST | `/admin/booking/rooms` | appointments.manage | Room list/availability for `date`, create room |
| PATCH | `/admin/booking/rooms/{room}` | appointments.manage | Update room |
| PATCH | `/admin/booking/reservations/{booking}/room` | appointments.manage | Assign/remove room with overlap check |
| GET/POST | `/admin/booking/reservations` | appointments.manage | Search bookings or create a reception booking; create fields include client, counselor, service, offset `starts_at`, mode, optional room, payment option |
| POST | `/admin/booking/reservations/{booking}/check-in` | appointments.manage | Confirmed → checked_in |
| PATCH | `/admin/booking/reservations/{booking}/status` | appointments.manage | `in_progress`, `completed`, or `no_show` with lifecycle validation |
| PATCH | `/admin/booking/reservations/{booking}/reschedule` | appointments.manage | Reschedule while rechecking counselor and room conflicts |
| POST | `/admin/booking/reservations/{booking}/cancel` | appointments.manage | Cancel with required reason; paid bookings require refund review first |
| GET | `/admin/booking/clients?q=...` | appointments.manage | Quick client search |
| POST | `/admin/booking/clients` | appointments.manage | Register client for reception booking |
| GET | `/admin/booking/waitlist` | appointments.manage | Filtered waiting list |
| PATCH | `/admin/booking/waitlist/{entry}` | appointments.manage | Assign an available slot and create a held booking |
| POST | `/booking/waitlist` | client | Join the waitlist for a valid occupied slot |

Existing work-shift and time-off APIs remain under `/admin/booking/counselors/{counselor}/shifts`, `/admin/booking/shifts/{shift}`, `/admin/booking/counselors/{counselor}/time-off`, and `/counselor/time-off`. `BookingSlots` is the shared availability service. Counselor row locks serialize concurrent reservation attempts; room row locks serialize room allocation.

## Clinical records and session notes

Reception can manage demographic/client booking records through the existing appointment-scoped client APIs. Clinical records, answers, consents and notes require `notes.manage` for admins or an authenticated counselor with a booking relationship to that client. Private attachment files are stored on Laravel's non-public `local` disk; API responses omit the storage path.

| Method | Path | Purpose |
| --- | --- | --- |
| GET/PUT | `/admin/booking/clinical/clients/{client}` | Read/update clinical concerns, goals, primary counselor and progress summary |
| GET/POST | `/admin/booking/clinical/forms` | List/create configurable intake form and first published version |
| POST | `/admin/booking/clinical/forms/{form}/versions` | Publish a new immutable schema version |
| GET | `/booking/intake-forms/{form}` | Authenticated client gets active form schema |
| POST | `/booking/intake-forms/{form}/answers` | Client submits validated answers against current published version |
| GET | `/admin/booking/clinical/clients/{client}/intake-answers` | Read versioned answers (clinical permission) |
| POST | `/client/consents` | Record accepted consent with version, server timestamp and IP |
| GET | `/admin/booking/clinical/clients/{client}/consents` | Read consent history |
| GET/POST | `/admin/booking/clinical/clients/{client}/follow-ups` | List/create counselor follow-up tasks |
| POST | `/admin/booking/clinical/follow-ups/{task}/complete` | Complete a follow-up |
| GET/POST | `/admin/booking/reservations/{booking}/session-notes` | Admin clinical review/create private session notes |
| GET/POST | `/counselor/appointments/{booking}/notes` | Counselor's private draft/finalized notes, scoped to own booking |
| PATCH | `/counselor/session-notes/{note}` | Update draft or finalize; finalized note is immutable |
| POST/GET | `/admin/booking/clinical/clients/{client}/attachments` | Upload/list private file metadata |
| GET | `/admin/booking/clinical/attachments/{attachment}` | Authorized private download |

Counselor equivalents are rooted at `/counselor/clients/...`; downloads use `/counselor/clinical/attachments/{attachment}`. Note versions are snapshotted and clinical reads/writes and file downloads are audited. Intake schema question types are `text`, `textarea`, `number`, `boolean`, `select`, `multiselect`, and `date`. Unknown question keys are rejected. Consent is append-only and only an accepted value is recorded; a rejected consent is not treated as acceptance.

## Session access

`session_mode` is `in_person`, `meet`, or `voice_call`. Counselors/admins manage connection through `PATCH /counselor/appointments/{booking}/session-details` and `PATCH /admin/booking/reservations/{booking}/session-details`. Meet URLs must use HTTPS on `google.com` or `meet.google.com`; voice numbers are format-checked. `booking:maintain` releases the connection to client/counselor in-app notifications at the configured threshold (default 10 minutes). Client detail endpoints strip the connection until the release time and a confirmed, checked-in, in-progress or completed session.

## Existing finance, notifications, reporting, settings and CMS APIs

- Finance: `/admin/booking/finance/dashboard`, `/admin/booking/payments`, `/admin/booking/payments/export`, `/admin/booking/refunds`, `/admin/booking/settlements`, `/admin/booking/reconciliation`, `/admin/booking/reports`, `/admin/booking/counselors/{counselor}/payable`. Finance writes require `finance.manage`; reads require `finance.view`. Reports include scheduled counselor minutes and utilization when both date bounds are supplied (maximum 366 days).
- Promotions/refunds: `GET/POST /admin/booking/promo-codes`, `PATCH /admin/booking/promo-codes/{promo}`; redemption is accepted on public/client or reception booking creation via `promo_code`. Percentage values are basis points; discounts are snapshotted and redemption limits are serialized. `POST /admin/booking/reservations/{booking}/refunds` creates a capped partial refund that does not cancel the booking; approval/transfer use the existing refund workflow.
- Payment: client booking payment initiation and receipts under `/booking/my-reservations/{booking}/payments` and `/receipts`; Fake Bank is the configured sandbox gateway. Zarinpal remains disabled; SMS provider remains unconfigured.
- Notifications: client `/client/notifications`, counselor `/counselor/notifications`, and admin `/admin/booking/notifications`. `booking:maintain` expires holds, sends reminder events, releases connection events, and makes waitlist availability notifications idempotently. Email uses queue jobs; SMS is logged as `not_configured`. Admins can manage `/admin/booking/notification-templates` and retry failed email logs with `/admin/booking/notifications/{notice}/retry`; real SMS remains disabled.
- Settings and content: `/admin/settings`, `/admin/booking/policy`, `/admin/booking/gateways`, `/admin/booking/tax-rules`, `/admin/content/{type}`, `/admin/gallery/upload`; public CMS reads use `/content/...`.
- CSV output: `/admin/booking/payments/export` and `/admin/booking/reports/export`. Booking summary reports use `/admin/booking/reports`.

## Data and rollout

Migrations `2026_10_13_000001_add_clinical_reception_tables`, `2026_10_13_000002_add_promo_codes_and_partial_refunds`, and `2026_10_13_000003_add_notification_templates` are additive. They add rooms and room assignment/session timestamps, clinical record/intake/consent/follow-up/private attachment/note-version tables, promotion/redemption and partial-refund data, notification templates and attempt metadata. Existing booking and payment records are retained. Apply migrations only after backing up and verifying the target database; do not run `migrate:fresh`, reset, rollback, or demo seed against the production database.

`php artisan test` is not installed in this project; run `./vendor/bin/phpunit`. Feature tests use the configured in-memory SQLite database. MySQL concurrency scripts are opt-in and refuse to run unless `DB_DATABASE=consulting_booking_test_20261012`.

## Known open verification

The login/reservation issue reported against `http://localhost:8010` remains **open / not retested**. No request was sent to 8010 in this implementation. Isolated SQLite tests do not establish runtime database migration state, account credentials, frontend token behavior, or production CORS behavior.

The following addendum supersedes older statements in this document about missing user roles, note history, room calendar, admin inbox, reporting and CMS publication workflow. MySQL concurrency and full frontend integration remain unverified.

## Addendum — remaining backend gaps closed (2026-10-14)

### Users, roles and permissions

- Super-admin-only routes: `GET /admin/roles`, `POST /admin/roles` (`{name,label,permissions[]}`), `PATCH /admin/roles/{role}` (`{label?,permissions[]?}`), `DELETE /admin/roles/{role}`, `GET /admin/permissions`, `GET /admin/users/{user}/roles`, and `PUT /admin/users/{user}/roles` (`{role_ids:[]}`). Built-in roles (`reception`, `finance`, `clinical`, `content`, `operations`) cannot be deleted. Permissions are restricted to server keys; no super-admin/client/counselor role assignment is accepted.
- `GET /admin/users?q=&role_id=&is_active=&per_page=` supports filtering, page size 1–100. `PATCH /admin/users/{user}` supports `is_active`. Changing roles/permissions or disabling access revokes the affected Sanctum tokens. Removing/deleting the last active `is_admin` super-admin returns 409.
- Legacy `is_admin` and `admin_permissions` remain recognized. A role can authorize an `admin-panel` Sanctum token through its granted permissions; token abilities are unchanged.

### Note history, rooms and inbox

- Counselor note history: `GET /counselor/session-notes/{note}/versions` and `GET /counselor/session-notes/{note}/versions/{version}`. Admin equivalents: `GET /admin/booking/session-notes/{note}/versions[/{version}]`, permission `notes.manage`. List response contains version, author id/name, timestamp. Detail returns the immutable snapshot plus `changed_fields`; all reads are audited. Only the booking's counselor or authorized clinical admin can read it.
- Room APIs: `GET /admin/booking/rooms/{room}/calendar?from=&to=&per_page=`, `GET /admin/booking/rooms/{room}/availability?starts_at=&ends_at=&attendee_count=`, `DELETE /admin/booking/rooms/{room}`. Range is limited to 366 days. A room with any booking history cannot be deleted; a room with upcoming appointments cannot be deactivated or reduced below booked attendee counts. Booking create/room assignment take optional `attendee_count` (default 1); it must not exceed room capacity.
- Admin inbox: `GET /admin/notifications?unread=&event=&from=&to=&per_page=` returns a paginated `data` plus `unread_count`; `GET /admin/notifications/{id}`; `POST /admin/notifications/{id}/read`; `POST /admin/notifications/read-all`. Inbox items are filtered by the user's permission. Reception booking notices reuse in-app booking notice flow and are copied to the admin inbox. Delivery history/retry stays at `/admin/booking/notifications`.

### Reports and CMS

- Operational report `GET /admin/booking/reports/operational?from=&to=&counselor_id=&service_id=` requires either `appointments.manage` or `finance.view`. It calculates counselor capacity after time-off overlap, de-duplicates overlapping work shifts, counselor utilization, cancellation/no-show counts, and staff-created reception bookings. Room-constrained capacity caps 15-minute counselor availability by active room count. Assumption: active rooms are open during counselor working intervals; room-specific working hours are not modeled. This response contains no finance totals. Range max: 366 days.
- CMS reuses `cms_entries` and supports types `pages`, `posts`, `categories`, `gallery`, `services`, `faqs`, `banners`, `homepage_sections`. Admin list supports `status=draft|published`, `q`, and `per_page` (max 100). Content accepts `status`, SEO title/description and order; publication timestamp is server-controlled. Public `GET /content/{type}[/{slug}]` only returns entries that are published, active, and have `published_at`.
- `POST /admin/gallery/upload` stores an image on private `local` disk and responds `{data:{id,path,url,mime_type,size}}`. Save returned `path` to entry `image`. `GET /content-media/{id}` works only after a published entry references the media; authorized preview is `GET /admin/content-media/{id}/preview` (`content.manage`).

### New database migrations

- `2026_10_14_000001_add_admin_access_and_inbox`: `users.is_active`, role/permission/pivot tables, `admin_notifications` and per-user `admin_notification_reads`.
- `2026_10_14_000002_extend_cms_publication`: CMS publication/SEO/order fields and private media metadata table; active legacy entries are backfilled as published.
- `2026_10_14_000003_add_booking_attendee_count`: `bookings.attendee_count`, default 1.

All three are additive. Migrations were exercised by SQLite PHPUnit migrations only; no MySQL migration or production connection is claimed here.
