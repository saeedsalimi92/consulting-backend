# Booking backend

All money values are integer IRR. Appointment requests and responses use ISO 8601 with a timezone offset. Booking and time-off database timestamps are UTC. Weekly shifts and availability dates use Asia/Tehran; weekdays are 0=Sunday through 6=Saturday.

## Deployment

1. Back up the existing database and review `php artisan migrate --pretend`.
2. Review `php artisan migrate --pretend`, then run `php artisan migrate` against the intended deployment database. Booking schema is additive across migrations `2026_10_12_000001` through `000003`; do not use `migrate:fresh` or `db:wipe` outside disposable test databases.
3. Set `QUEUE_CONNECTION=database` and run `php artisan queue:work database --tries=3 --backoff=30`.
4. Run `php artisan schedule:work` or cron `* * * * * php artisan schedule:run`. The scheduler runs `booking:maintain` every minute so the ten-minute connection release and reminders are not delayed by a ten-minute cadence. Compose defines queue and scheduler services; restart Compose after review.
5. Configure mail before using password reset or email notices. SMS remains `not_configured` without a real provider; the log driver never claims delivery. A real SMS adapter and counselor contact source are required before production SMS.

## Configuration

| Variable | Default | Purpose |
|---|---|---|
| `BOOKING_PAYMENT_DRIVER` | `fake_bank` | Default provider if no enabled database provider is selected. `fake_bank` is a clearly labeled test-only gateway. Zarinpal is disabled until a real integration is implemented and verified. |
| `BOOKING_CHECKOUT_URL` | unset | Optional public Nuxt fake-checkout page base URL. The backend appends the issued payment ID and opaque checkout token. |
| `BOOKING_CHECKOUT_TOKEN_MINUTES` | `20` | Checkout credential lifetime. |
| `BOOKING_HOLD_MINUTES` | `20` | Pending-payment slot reservation lifetime. |
| `BOOKING_TIMEZONE` | `Asia/Tehran` | Session and release policy timezone. |
| `BOOKING_CANCELLATION_PENALTY_PERCENT` | `10` | Proposed deduction on **paid IRR**; obtain business approval. |
| `BOOKING_CANCELLATION_PENALTY_BASIS` | `paid` | `paid` or `total`; penalty is capped at actual paid IRR. |
| `BOOKING_CANCELLATION_CUTOFF_HOURS` | `0` | Minimum hours before start for client cancellation requests. |
| `BOOKING_SMS_DRIVER` | `log` | Safe development driver. |
| `QUEUE_CONNECTION` | Compose: `database` | Queue jobs and retries. |
| `MAIL_MAILER` | Laravel setting | Password reset and optional email notices. |
| `BOOKING_CLIENT_RESET_URL` | unset | Absolute public frontend reset page URL. Required before sending reset mail. |

The price API limits new services to 1,000,000,000 IRR so integer commission and prorating calculations stay within signed 64-bit arithmetic. Percentage commissions have up to two decimal places and round half up to whole IRR. Fixed commissions are whole IRR. The commission is snapshotted when a booking is created.

## Public and client API

Responses use `data` for single resources and most lists; validation errors use Laravel's standard 422 response. A client bearer token has the `client` ability. Admin tokens cannot use client profile or booking endpoints.

| Method | Path | Purpose |
|---|---|---|
| GET | `/api/booking/services` | Active services |
| GET | `/api/booking/availability?counselor_id=1&service_id=1&date=2026-10-20` | Local free and occupied slots |
| POST | `/api/client/register` | Name, email, phone, password and confirmation |
| POST | `/api/client/login` | Email/password; scoped token |
| POST | `/api/client/password/forgot` | Request reset link |
| POST | `/api/client/password/reset` | Token, email, new password |
| GET/PATCH | `/api/client/me` | Profile |
| POST | `/api/client/logout` | Revoke current token |
| POST | `/api/booking/reservations` | Hold a generated slot |
| GET | `/api/booking/my-reservations` | Booking history |
| GET | `/api/booking/my-reservations/{booking}` | Owned booking detail |
| GET | `/api/booking/my-reservations/{booking}/payments` | Owned payment attempts |
| GET | `/api/booking/my-reservations/{booking}/notes` | Only notes marked visible |
| POST | `/api/booking/my-reservations/{booking}/payments` | Initiate online attempt |
| GET/POST | `/api/booking/payments/callback` | Provider callback verification; fake bank is explicitly rejected here |
| GET | `/api/booking/fake-bank/payments/{payment}?token=...` | Token-protected test checkout summary |
| POST | `/api/booking/fake-bank/payments/{payment}/result` | Test-only success/failure response; requires issued token |
| GET | `/api/booking/my-reservations/{booking}/receipts` | Receipts for the owned booking |
| POST | `/api/booking/my-reservations/{booking}/cancellation-request` | Request policy based cancellation |
| PATCH | `/api/booking/my-reservations/{booking}/reschedule` | Reschedule an owned held/confirmed booking after policy and availability checks |

Reservation example:

```json
{"counselor_id":1,"service_id":1,"starts_at":"2026-10-20T09:00:00+03:30","mode":"online","payment_option":"deposit"}
```

The selected start must exactly match an available slot. A hold lasts `BOOKING_HOLD_MINUTES` (20 minutes by default) and does not block availability after expiry. The first payment must meet `required_irr`; later payments may cover the remaining balance. Fake-bank checkout initiation returns a payment ID, opaque token and expiry. The checkout result accepts only the stored attempt plus that token; the browser cannot submit a booking amount as proof. Fake-bank success/failure is simulated at the test checkout endpoint, while internal booking and accounting state is persisted by the normal payment service.

## Admin API

All paths below start with `/api/admin/booking` and require `auth:sanctum`, the `admin-panel` token ability, and the indicated permission. A super admin has all permissions.

| Permission | Routes |
|---|---|
| `appointments.manage` | GET/POST `services`; PATCH `services/{service}`; PUT `counselors/{counselor}/services`; GET/POST `counselors/{counselor}/shifts`; PATCH `shifts/{shift}`; GET/POST `counselors/{counselor}/time-off`; DELETE `time-off/{id}`; GET `calendar`; GET/POST `clients`; GET/POST `reservations`; PATCH `reservations/{booking}/reschedule`; POST `reservations/{booking}/cancel`; POST `reservations/{booking}/payments/in-person`; GET `notifications` |
| `notes.manage` | GET/POST `reservations/{booking}/notes` |
| `finance.view` | GET `payments`, `refunds`, `reports`, `reconciliation`, `settlements`, `counselors/{counselor}/payable` |
| `finance.manage` | PATCH `refunds/{refund}`; POST `refunds/{refund}/record-transfer`; POST `settlements` |

Useful request examples:

```json
{"title":"Individual session","duration_minutes":60,"price_irr":1000000,"deposit_irr":300000}
```

```json
{"service_id":1,"price_irr":1200000,"commission_type":"percentage","commission_value":"25.00"}
```

```json
{"weekday":2,"starts_at":"09:00","ends_at":"12:00"}
```

```json
{"amount_irr":300000,"payment_method":"pos","idempotency_key":"unique-business-key","reference":"POS-123"}
```

## Financial and cancellation behavior

Verified payments add balanced immutable ledger entries: debit cash, credit counselor payable, center revenue and (when configured) tax liability. Deposit and balance payments accrue commission and tax proportionally from the booking snapshot. A cancellation request computes the proposed penalty from the configured basis, capped at actual paid IRR. A finance reviewer approves or rejects; approval finalizes cancellation and releases the slot. Recording an externally completed refund reverses revenue and payable proportionally and credits cash; amounts already settled to a counselor use a recoverable balance. The transfer endpoint **records** a reference; it does not call a bank or send funds. Settlement amounts are checked against earnings net of reversals and previous settlements, and are allocated across booking ledger balances.

Online gateway adapters are intentionally fail closed. Fake bank is the only active provider and simulates the external response only. Production Zarinpal needs current merchant API specifications, credentials, amount unit confirmation, callback authentication, sandbox verification and reconciliation tests. Iranian Snappay installment integration needs the actual merchant contract and API specification; similarly named international SnapPay APIs must not be used.

Reference for a future Zarinpal integration: [Zarinpal's official Postman workspace](https://www.postman.com/zarinpal-team/zarinpal/overview). Confirm the exact API version and settlement units with the merchant account before implementing it.

## Security and limits

- Client notes are filtered by booking ownership and `visible_to_client=true`; private clinical notes require `notes.manage`.
- Client login and password reset requests are throttled. Staff created clients receive a random unusable password until reset mail is configured and completed.
- The counselor row is locked inside the booking transaction. Real concurrent MySQL testing still requires a dedicated test database and two connections.
- Existing booking rows created before the new migration may have a null commission snapshot; payment logic treats these as zero commission. Historical backfill needs a reviewed accounting decision.
- Cash/POS recording is permitted only for in-person bookings and requires `appointments.manage`.
- Current reports are summary level. They are not a complete journal export or a bank reconciliation feed.
- SMS without a configured provider remains `not_configured`; no delivery is claimed. No real SMS provider or counselor SMS contact mapping is configured.
- Password reset depends on configured outbound mail. Email verification is not enforced until the product policy and mail channel are agreed.

## Frontend integration

Nuxt public frontend should obtain a `client` token from `/api/client/login`, query services and availability, submit an offset-qualified slot start, and show `remaining_irr` from booking detail. Modernize admin should use the existing admin login and `admin-panel` token, then call the admin booking routes according to permissions. Both frontends should treat 409 as a conflict requiring refresh and render IRR explicitly; neither frontend should infer payment success from a redirect.

## Validation

`vendor/bin/phpunit` runs Laravel feature and unit tests in an in-memory SQLite database. `php tests/booking_smoke.php` is an additional SQLite integration smoke test.

For MySQL, create and migrate a dedicated `consulting_booking_test_20261012` database first. The guarded scripts `tests/booking_mysql.php`, `tests/booking_mysql_regression.php`, and `tests/booking_mysql_concurrency.php` refuse any other database name. They cover the API, financial flows, access control, and two genuinely simultaneous booking requests using separate MySQL connections. `tests/booking_mysql_queue.php seed`, `php artisan queue:work database --once`, and `tests/booking_mysql_queue.php check` verify a database queue job with `BOOKING_SMS_DRIVER=log`. Supply `DB_DATABASE=consulting_booking_test_20261012` and `APP_ENV=testing` explicitly to each command. Do not point these scripts at `consulting`.

Start and monitor the queue and scheduler services before deployment. A one-off queue worker or `schedule:list` check does not prove continuous operation.

## Lifecycle integration updates

- Fake-bank result retries with the same valid, unexpired checkout token return the already persisted result. They do not create another receipt or ledger posting. A failed attempt stays failed; retry by creating a new idempotency key while the booking hold is valid.
- Client rescheduling is available through the owned-booking endpoint above. It uses the existing counselor lock, slot validation, cutoff policy, audit history and notifications.
- Rescheduling, cancellation and session-detail edits invalidate prior in-app notifications that contained connection data. The scheduler only attempts connection release for Meet/voice sessions; in-person appointments do not generate a false missing-link alert.
- Standard booking notices create in-app notifications as well as an SMS/email delivery record. SMS with no configured provider remains `not_configured`; it is never marked delivered.
- `tests/Feature/FakeBankLifecycleTest.php` runs with PHPUnit's isolated in-memory SQLite database and covers successful payment posting plus duplicate success, and failed attempt plus retry. It does not replace the dedicated disposable MySQL concurrency and full acceptance scripts described above.
- Admin and public frontend repositories were not changed because the requested frontend explanation/documentation file has not yet been provided.

## Verified backend test coverage

PHPUnit feature tests use `DB_CONNECTION=sqlite` with `DB_DATABASE=:memory:` in `phpunit.xml`. The dedicated `tests/booking_smoke.php` also explicitly bootstraps an in-memory SQLite database and runs migrations there. These commands do not connect to the configured local MySQL `consulting` database:

```sh
APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: vendor/bin/phpunit
APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: php tests/booking_smoke.php
```

MySQL scripts refuse to run unless `DB_DATABASE=consulting_booking_test_20261012`, then verify Laravel's active connection is MySQL pointed to exactly that database. Create and migrate this disposable database explicitly before running them:

```sh
APP_ENV=testing DB_CONNECTION=mysql DB_DATABASE=consulting_booking_test_20261012 php artisan migrate --force
APP_ENV=testing DB_CONNECTION=mysql DB_DATABASE=consulting_booking_test_20261012 php tests/booking_mysql.php
APP_ENV=testing DB_CONNECTION=mysql DB_DATABASE=consulting_booking_test_20261012 php tests/booking_mysql_regression.php
APP_ENV=testing DB_CONNECTION=mysql DB_DATABASE=consulting_booking_test_20261012 php tests/booking_mysql_concurrency.php
```

The queue integration script uses an email notice and array mail transport (SMS in log mode is deliberately never marked sent):

```sh
APP_ENV=testing DB_CONNECTION=mysql DB_DATABASE=consulting_booking_test_20261012 QUEUE_CONNECTION=database MAIL_MAILER=array php tests/booking_mysql_queue.php seed
APP_ENV=testing DB_CONNECTION=mysql DB_DATABASE=consulting_booking_test_20261012 QUEUE_CONNECTION=database MAIL_MAILER=array php artisan queue:work database --once
APP_ENV=testing DB_CONNECTION=mysql DB_DATABASE=consulting_booking_test_20261012 QUEUE_CONNECTION=database MAIL_MAILER=array php tests/booking_mysql_queue.php check
```

The automated SQLite suite covers booking and schedule APIs, time-off, client rescheduling, role boundaries, fake-bank success/failure/retry/replay/token expiry, tax snapshots, balanced payment/refund/settlement ledger events, connection release and invalidation at controlled times, notifications, and selected dashboard/report contracts. MySQL-specific slot and payment concurrency, database queue processing and lock behavior require the guarded scripts above. Gateway fees, partial refunds, provider delivery for SMS, real Zarinpal, browser/UI contracts and real concurrent behavior have no verified coverage here. Backend response contracts are documented above; no frontend explanation file was provided for contract comparison.

Latest isolated run in the backend workspace: PHPUnit **30 tests / 261 assertions passed**; `booking_smoke.php` **47 checks passed**. MySQL scripts were syntax-checked but not executed in this environment because Docker is unavailable in the WSL distro and no local MySQL server responded. No MySQL result is claimed.
