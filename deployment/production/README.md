# Consulting Backend — Production

Production-only deployment files. Local `Dockerfile` and `compose.yaml` are unchanged.

## Build and publish

Run **Build production images** manually under GitHub Actions on the `production` branch.
It publishes two images to GHCR, tagged with the complete commit SHA:
- `ghcr.io/saeedsalimi92/consulting-backend-app:<sha>`
- `ghcr.io/saeedsalimi92/consulting-backend-web:<sha>`

This workflow does **not** deploy to the server.

## Server provisioning

Before deploying, ensure available disk capacity, access to GHCR (for private packages, use a read:packages token), a configured Nginx reverse proxy on the host, and a separate production database backup plan.

1. Place `compose.prod.yaml` in a dedicated server directory.
2. Create `.env.prod` *only on the server* (never commit it). Set at least `APP_KEY`, `APP_URL`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `MYSQL_ROOT_PASSWORD`, `APP_ENV=production`, `APP_DEBUG=false`, `LOG_CHANNEL=stderr`, `CACHE_STORE=file`, `SESSION_DRIVER=file`, `QUEUE_CONNECTION=database`. Copy other required integrations/settings from the application's `.env.example`. Generate the APP_KEY securely once and retain it across releases.
3. Set `IMAGE_TAG` to the exact published commit SHA in the shell or an untracked Compose interpolation `.env` file (it is not the application's `.env.prod`).
4. Authenticate Docker with GHCR if the packages are private.
5. Run `docker compose --env-file .env.prod -f compose.prod.yaml config` to validate configuration, then `docker compose --env-file .env.prod -f compose.prod.yaml pull`.
6. On first install, start database: `docker compose --env-file .env.prod -f compose.prod.yaml up -d database`; check readiness.
7. Before schema changes, back up MySQL and confirm migrations are safe. Run `docker compose --env-file .env.prod -f compose.prod.yaml run --rm app php artisan migrate --force` once per release under controlled deployment.
8. Start the stack: `docker compose --env-file .env.prod -f compose.prod.yaml up -d --no-build`.
9. Check `curl -fsS http://127.0.0.1:8010/api/health` and logs. Configure host Nginx to proxy the application domain to `127.0.0.1:8010` with HTTPS.

**Important:** The current app's local compose includes defaults intended only for development. Do not use those credentials in Production. Image rollback is via `IMAGE_TAG` and `docker compose up -d`; database schema rollback requires a separate plan. Persistent volumes are not deleted by image updates. The application `.env.prod` must never be included in an image or committed.
