# Laravel 12 API update for Elham Nuxt 4

This is an **overlay**, not a complete Laravel installation. The existing project and its database must be preserved.

## Safe install (from your Laravel project root in WSL)

1. Back up the existing project files, especially `app/`, `bootstrap/`, `routes/`, and `database/migrations/`.
2. Extract the archive to a **temporary directory** first. Compare any existing files before overwriting, especially `bootstrap/app.php`, `bootstrap/providers.php`, and service providers. The included `bootstrap/app.php` matches the version you pasted and adds `api:`; if you have other local middleware edits, merge that single line instead.
3. Copy `app/Modules/Counselor`, `app/Providers`, `routes/api.php`, and `bootstrap/providers.php` to their corresponding locations, preserving any unrelated code. Merge `bootstrap/app.php` if customized. Copy migration files only after checking existing migration filenames and tables; do not re-create existing tables.
4. `docker compose exec laravel composer dump-autoload`
5. `docker compose exec laravel php artisan optimize:clear`
6. `docker compose exec laravel php artisan route:list --path=api`
7. `curl -i http://localhost:8010/api/counselors`

Database migrations are **not automatically run**. Review with `docker compose exec laravel php artisan migrate:status` and `docker compose exec laravel php artisan migrate --pretend` before applying any new migration. Do not run `migrate:fresh`.

### Public API (Nuxt)
- GET /api/counselors?search=&specialty_id=&per_page=
- GET /api/counselors/{slug}
- GET /api/specialties
- GET /api/comments?type=counselor&id=1

### Admin API (Sanctum; requires authentication setup)
- POST /api/admin/counselors
- PUT or POST /api/admin/counselors/{id}
- DELETE /api/admin/counselors/{id}
- POST /api/admin/specialties
- PUT /api/admin/specialties/{id}
- DELETE /api/admin/specialties/{id}
- POST /api/admin/comments
- PATCH /api/admin/comments/{id}
- DELETE /api/admin/comments/{id}

### Not implemented
Blog/news, gallery, appointment booking, OTP login, and site settings endpoints are **not** implemented in the provided Laravel module. Do not treat these as operational. Admin endpoints require Sanctum to be installed/configured; no login endpoint is provided. If Nuxt runs on a different origin, configure CORS for that origin. For Nuxt on the same Windows host, set `NUXT_PUBLIC_API_BASE=http://localhost:8010/api`.

This package has not been integration-tested against your live local database or Docker environment.
