# Consulting Backend — Direct production deployment

The preferred deployment uses GitHub Actions to build two local images, exports a compressed archive, uploads it over SSH, then loads and restarts **only** the consulting stack. No GHCR pull is used. Older `compose.prod.yaml` and `production-images.yml` are legacy alternatives; **do not use them on the shared VPS**.

## Resource footprint
- `app`: single PHP image with supervised php-fpm, one database queue worker and Laravel `schedule:work`.
- `web`: nginx proxy and static public assets; binds **127.0.0.1:8010**.
- Existing `dr_chizari_bot_db` (MySQL) and `dr_chizari_bot_redis` (Redis) are **not created or restarted** by Compose.
- Existing bridge network `dr-chizari-store-bot-v310-docker_bot-network` is joined by app; existing containers need no network changes.
- Queue: `database` using **consulting's own database**; Redis eviction policy `allkeys-lru` is unsafe for queues.
- Cache: use `CACHE_STORE=file` initially. The shared Redis has an unauthenticated default ACL; only enable Redis cache after reviewing access isolation and key eviction. Redis extension and prefixed connection variables are ready.
- Image retention: preserve current and one rollback image. Never prune other projects' images/volumes.

## One-time server setup
1. Create dedicated `consulting` database and least-privileged MySQL user on existing server. Do not alter existing DBs.
2. Prepare `/etc/consulting/backend.env` as root-owned mode 0600 with stable `APP_KEY`, `APP_ENV=production`, `APP_DEBUG=false`, `LOG_CHANNEL=stderr`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `DB_CONNECTION=mysql`, `QUEUE_CONNECTION=database`, `DB_QUEUE=default`, `CACHE_STORE=file`, `SESSION_DRIVER=file`, `APP_PORT=8010`, safe payment/OTP settings. Avoid publishing development credentials; keep file out of Git.
3. Install `compose.direct.yaml` as root-owned file in `/var/www/consulting/backend`, and `deploy-direct.sh` as root-owned executable `/usr/local/sbin/consulting-backend-deploy`. Configure restricted sudoers for only this command for `deploy-consulting`.
4. Provision `/var/www/consulting/backend/incoming` writable only by `deploy-consulting`. GitHub repo Secrets: `DEPLOY_HOST`, `DEPLOY_USER`, `DEPLOY_SSH_KEY`, `DEPLOY_KNOWN_HOSTS` (per repository; they are not copied automatically from front/panel).
5. Run `Consulting backend production (direct transfer)` on `production` with `deploy=true`; default is `false`. GitHub Actions copies compressed images and the SHA, then invokes the restricted deploy wrapper.
6. Once services start, review migration SQL and run `docker compose --env-file /etc/consulting/backend.env -f /var/www/consulting/backend/compose.direct.yaml exec app php artisan migrate --force` deliberately (not automated). Test health `curl -fsS http://127.0.0.1:8010/api/health`. Production domain and reverse proxy are separate follow-up configuration.
7. To restart only consulting: `docker compose --env-file /etc/consulting/backend.env -f /var/www/consulting/backend/compose.direct.yaml restart app web`, exporting `IMAGE_TAG` with the currently deployed SHA for Compose interpolation.

No docker build, image prune, volume prune, `down -v`, or changes to the bot's MySQL/Redis configuration are part of this procedure.

## Operational caution
Application migrations and payment/SMS configuration require separate approval. Process-level health checks for php-fpm do not prove queue/scheduler are healthy; inspect supervisor status and application logs after first deployment. Redis ACL is currently `default on nopass +@all` and must not be treated as isolated tenancy.
