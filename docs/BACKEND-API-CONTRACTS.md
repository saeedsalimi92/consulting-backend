# Backend API contracts

All routes are prefixed `/api`. Authentication uses `Authorization: Bearer <Sanctum token>`. Persisted booking instants are UTC; availability dates and operational day boundaries use `Asia/Tehran`. Request validation errors use HTTP 422, authorization errors 403, state/conflict errors 409, and missing or cross-owner resources may return 404.

The registered-route inventory is generated in [openapi.json](openapi.json), with machine endpoint `/api/docs/openapi.json`. This index is route-complete; detailed legacy booking, clinical, finance, reception and CMS fields are maintained in [API-PHASES-1-8.md](API-PHASES-1-8.md). The generated OpenAPI request bodies are intentionally permissive and should not be treated as a replacement for those specific contracts.

## Authentication/account

| Method | Path | Auth | Contract |
|---|---|---|---|
| POST | `/client/register` | Public, throttled | `{name,email,phone,password,password_confirmation}`; returns client data and Sanctum token with `client` ability. |
| POST | `/client/login` | Public, throttled | `{email,password}`; returns token and client data. |
| POST | `/counselor/login` | Public, throttled | `{email,password}`; returns counselor token. |
| POST | `/admin/login` | Public | `{email,password}`; returns admin token and effective permissions. |
| GET | `/admin/session`, `/client/session`, `/counselor/session` | Corresponding authenticated role | Current account and token metadata. |
| PUT | `/admin/password`, `/client/password`, `/counselor/password` | Corresponding role | `{current_password,password,password_confirmation}`; minimum 12 chars; changes password and revokes every token. |
| POST | `/admin/logout-all`, `/client/logout-all`, `/counselor/logout-all` | Corresponding role | Revokes all issued personal access tokens. |

Sanctum bearer token expiration defaults to 10,080 minutes (7 days); override with `SANCTUM_EXPIRATION`.

## Key existing workflows

- Public booking catalog and slots: `GET /booking/services`, `/booking/counselors/{id}/services`, `/booking/availability`.
- Client booking and payment: `POST /booking/reservations`, then `POST /booking/my-reservations/{booking}/payments` with `{amount_irr,idempotency_key}`. Response contains `payment_id`, `url`, `checkout_token`, `expires_at`.
- Reception: `/admin/booking/reception/dashboard`, `/admin/booking/calendar`, `/admin/booking/rooms`, `/admin/booking/reservations`, check-in/status/reschedule/cancel routes; permission `appointments.manage`.
- Clinical: `/admin/booking/clinical/...` and `/counselor/...`; admin permission `notes.manage`, counselor is limited to assigned clients. Private attachments use authorized download routes.
- Finance: `/admin/booking/finance/dashboard`, `/payments`, `/refunds`, `/settlements`, `/reports`, promo and tax routes; finance permissions are enforced.
- CMS public reads: `GET /content/{type}` and `/content/{type}/{slug}` return published entries only. Admin write routes require `content.manage`.
- Admin notification inbox: `GET /admin/notifications?unread=true&page=1&per_page=20`; `GET /admin/notifications/{id}`, `POST /admin/notifications/{id}/read`, `POST /admin/notifications/read-all`.

## Fake Bank

Local/testing only. Successful initiation returns a backend URL at `/api/booking/fake-bank/checkout/{payment}?token=...`. The page displays reference and amount, then offers exactly the success and failure actions. The opaque credential authorizes the result; browser-supplied amount/provider/return URL are not trusted. Results redirect to configured `BOOKING_FAKE_BANK_RETURN_URL` with `payment_id` and `status`. Verify final status through `GET /booking/my-reservations/{booking}/payments`. Payment callback query values alone cannot prove payment.

## Errors and pagination

Laravel paginator responses include `data`, `links`, `meta`; pagination parameters are endpoint-specific. Responses include standard JSON `message` and `errors` for validation. API consumers should use status and persisted resource state rather than assume a queued notification was delivered.

## Reminder administration and consent

The configurable SMS reminder contracts and validation are described in [SMS-REMINDERS.md](SMS-REMINDERS.md). Administration requires `settings.manage`; account preference endpoints require the current authenticated user. Scheduler times use Asia/Tehran; stored instants are UTC. `simulated` means Fake SMS recorded a local simulation and is not a real delivery receipt.

## Center/room schedule and settings additions

- `GET|PUT /admin/settings/center-profile` — settings permission; center name/contact/address/timezone fields.
- `GET|PUT /admin/settings/working-hours` — settings permission; PUT writes one `{weekday,is_open,opens_at,closes_at}` row per request, in Asia/Tehran. Updates conflicting with future appointments return 409.
- `GET|POST /admin/settings/holidays`, `DELETE /admin/settings/holidays/{holiday}` — settings permission; holidays are date-based in Tehran local time; cannot close a date with scheduled appointments.
- `GET|PUT /admin/settings/sms` — exposes only `log` or local/testing `fake`; real delivery remains disabled.
- `GET|PUT /admin/settings/seo` — SEO defaults; title, description, canonical base, and Open Graph image URL.
- `GET|PUT /admin/booking/policy` — `finance.manage`; cancellation/reschedule policy, hold duration, and connection-release minutes.
- `GET|PUT /admin/booking/gateways` — `finance.manage`; Fake Bank only is configurable in local/testing. Zarinpal and SnappPay remain fail-closed.
- Room working hours use `GET|PUT /admin/booking/rooms/{room}/working-hours` with PUT body `{hours:[{weekday,opens_at,closes_at},...]}` (empty array means no room-specific schedule). Maintenance uses `GET|POST /admin/booking/rooms/{room}/unavailability` and `DELETE /admin/booking/room-unavailability/{window}`. `GET /admin/booking/rooms/{room}/availability?starts_at=...&ends_at=...&attendee_count=...` includes active state, capacity, schedules, holidays, maintenance, and overlap result.

## XLSX exports

`GET /admin/booking/exports/{type}.xlsx?from=YYYY-MM-DD&to=YYYY-MM-DD` supports `bookings`, `payments`, `refunds`, `settlements`, `counselor-earnings`, `reception-performance`, `room-utilization`, and `capacity`. Financial exports require `finance.view`; operational exports require `appointments.manage`. Optional applicable filters are `counselor_id`, `service_id`, and `status`. Date range is required and limited to 366 days. Amount columns are integer IRR; booking dates are rendered in Asia/Tehran, while transaction timestamps are labeled UTC. Response is `application/vnd.openxmlformats-officedocument.spreadsheetml.sheet` and requires PHP `zip` plus `xmlwriter`.

## CMS media and counselor visibility

- `GET /admin/content-media?page=1&per_page=20` — `content.manage`; paginated private media metadata, never public file paths.
- `DELETE /admin/content-media/{media}` — `content.manage`; deletes only unreferenced uploaded media, returns 409 while an entry references it, and records an audit event.
- `POST /admin/gallery/upload` (legacy CMS upload route) — `content.manage`; accepts validated image upload and returns `{data:{id,path,url,mime_type,size}}`. The stored object is private; public media delivery is permitted only when referenced by active, published content whose publication time has arrived.
- `PUT /admin/counselors/{counselor}` accepts `is_public`; `GET /counselors` and `GET /counselors/{counselor}` expose active, public counselors only. Staff access requires `counselors.manage`.
- `PUT /admin/booking/counselors/{counselor}/services` creates/updates counselor-service settings including configured price and commission fields. `DELETE /admin/booking/counselors/{counselor}/services/{service}` removes the association only when no future active booking depends on it; conflict is 409. Changes are audited without recording confidential payloads.
- `GET /content/{type}?q=...&category_slug=...&per_page=...` returns only currently published content and supports public search/category filtering. Category assignment is validated through `extra.category_slug` against an existing CMS category. Slug uniqueness is scoped by content type and enforced by the existing database constraint.

## OpenAPI scope

`/api/docs/openapi.json` is generated by `python3 scripts/generate_openapi.py` from registered routes and Laravel FormRequest/inline validation rules. The generator maps core booking, payment/refund/settlement, clinical, CMS, counselor, room, reminder and export responses to named schemas; includes pagination, common 401/403/404/409/422/429 errors and binary XLSX/media responses. Some legacy response paths remain generic because the controllers return inconsistent shapes. Treat the actual route implementation as authoritative for those paths; coverage is not represented as 100%. Swagger UI is `/api/docs` in local/testing. See [SWAGGER-SETUP.md](SWAGGER-SETUP.md).
# Authentication and profile contracts

The OTP, profile and booking gate contracts are now implemented and maintained in [`AUTH-OTP-SMSIR-CONTRACT.md`](AUTH-OTP-SMSIR-CONTRACT.md), [`AUTH-PROFILE-COMPLETION.md`](AUTH-PROFILE-COMPLETION.md), and [`FRONTEND-OTP-INTEGRATION-GUIDE.md`](FRONTEND-OTP-INTEGRATION-GUIDE.md). OpenAPI is generated from Laravel routes and includes explicit auth schemas.

Unified mobile-first client login, including the safe deprecated response for public registration, is specified in [`UNIFIED-MOBILE-LOGIN-FLOW.md`](UNIFIED-MOBILE-LOGIN-FLOW.md). The anonymous API intentionally does not classify a supplied mobile as password-configured or registered.
