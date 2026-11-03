#!/usr/bin/env bash
# Install root-owned at /usr/local/sbin/consulting-backend-deploy
# Sudoers: deploy-consulting ALL=(root) NOPASSWD: /usr/local/sbin/consulting-backend-deploy
set -euo pipefail
umask 027
BASE=/var/www/consulting/backend
ARCHIVE="$BASE/incoming/backend-images.tar.gz"
COMPOSE="$BASE/compose.direct.yaml"
ENV_FILE=/etc/consulting/backend.env

[[ -f "$ARCHIVE" && -f "$COMPOSE" && -f "$ENV_FILE" ]] || { echo "Missing archive, compose, or env"; exit 1; }
[[ -L "$ARCHIVE" ]] && { echo "Refusing symlink archive"; exit 1; }
[[ -L "$COMPOSE" ]] && { echo "Refusing symlink compose"; exit 1; }
IMAGE_TAG="$(cat "$BASE/incoming/image-tag")"
[[ "$IMAGE_TAG" =~ ^[a-f0-9]{40}$ ]] || { echo "Invalid image tag"; exit 1; }
export IMAGE_TAG
cd "$BASE"
# Verify compressed artifact before loading; local build uses image tag equal to git SHA.
(cd incoming && sha256sum -c backend-images.tar.gz.sha256)
gzip -dc "$ARCHIVE" | /usr/bin/docker load
/usr/bin/docker image inspect "consulting-backend-app:$IMAGE_TAG" >/dev/null
/usr/bin/docker image inspect "consulting-backend-web:$IMAGE_TAG" >/dev/null
/usr/bin/docker compose --env-file "$ENV_FILE" -f "$COMPOSE" config -q
/usr/bin/docker compose --env-file "$ENV_FILE" -f "$COMPOSE" up -d --no-build --no-deps app web
# Do not run migrations automatically; initial and subsequent migrations are reviewed separately.
for i in $(seq 1 30); do
  if /usr/bin/curl -fsS --max-time 3 http://127.0.0.1:8010/api/health >/dev/null; then
    rm -f "$ARCHIVE" "$BASE/incoming/backend-images.tar.gz.sha256" "$BASE/incoming/image-tag"
    echo "Backend healthy at image $IMAGE_TAG"
    exit 0
  fi
  sleep 2
done
echo "Backend healthcheck failed; archive preserved for investigation" >&2
exit 1
