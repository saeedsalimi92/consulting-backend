# Consulting Center API — Laravel 12 module package

This archive contains a **Laravel 12 application overlay**, not the Laravel framework/vendor directory. Install a clean Laravel 12 application, then copy the contents of this archive over its root. No existing project files are required.

```bash
composer create-project laravel/laravel consulting-backend "^12.0"
cd consulting-backend
# Copy archive contents here, preserving folders
composer dump-autoload
php artisan install:api # only if routes/api.php is absent; this may install Sanctum
# set DB_* in .env and create MySQL database
php artisan migrate
php artisan storage:link
php artisan serve
```

The overlay includes `routes/api.php` and `bootstrap/providers.php`. If using an existing Laravel app, **merge** those files instead of blindly overwriting. Ensure `bootstrap/app.php` has `api: __DIR__.'/../routes/api.php'` in `withRouting(...)`. The install:api command may also edit bootstrap/app.php. `config/app.php` must NOT receive a custom `providers` array.

Public reads include `GET /api/counselors`, `GET /api/counselors/{slug}`, `GET /api/specialties`, and `GET /api/comments?type=counselor&id=1`. Client authentication uses `POST /api/client/register` and `POST /api/client/login`; both return a Sanctum bearer token scoped to `client`. Send it as `Authorization: Bearer <token>` to `/api/client/*` and `/api/booking/*` client routes. The client booking endpoint also requires `EnsureClient`, which rejects admin and counselor accounts. Admin and counselor login use their own endpoints and token abilities; admin booking routes require `auth:sanctum` plus the relevant admin permission. See [docs/BOOKING.md](docs/BOOKING.md) for the current booking API contract and test commands.

See [docs/API-PHASES-1-8.md](docs/API-PHASES-1-8.md) for the reception, clinical, session, finance, notification, reporting, settings and CMS contracts, along with rollout and verification notes.

Run focused tests with `./vendor/bin/phpunit`. Feature tests use the in-memory SQLite configuration in `phpunit.xml`; the MySQL integration scripts require the dedicated `consulting_booking_test_20261012` database and refuse to run with another database name.
# Booking backend

See [booking API, deployment, and test documentation](docs/BOOKING.md).
