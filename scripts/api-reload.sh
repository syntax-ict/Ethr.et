#!/usr/bin/env bash
#
# Apply PHP source edits to the running dev stack.
#
# The api container runs with `opcache.validate_timestamps = 0` (see
# docker/php/php.ini for the measurement behind that choice: with it on, every
# request re-stats the include graph across the Windows bind mount and takes
# 7-18 s instead of 0.5 s). The cost of that speed is that opcache never notices
# an edited file — so a fix you just made keeps returning the old result, with
# nothing to say why.
#
# This is the one command that makes an edit take effect. Run it after touching
# anything under api/.
#
#   bash scripts/api-reload.sh
#
# nginx is restarted too, and not incidentally: recreating or restarting `api`
# can change its IP, and nginx resolved the old one once at startup — every
# request through :8888 then answers 502 until nginx is restarted.

set -euo pipefail

CONTAINER="${ETHR_API_CONTAINER:-et-api-1}"

if ! docker ps --format '{{.Names}}' | grep -qx "$CONTAINER"; then
    printf 'Container %s is not running. Start the stack with: docker compose up -d\n' "$CONTAINER" >&2
    exit 1
fi

printf 'Restarting %s … ' "$CONTAINER"
docker restart "$CONTAINER" >/dev/null
printf 'done\n'

printf 'Restarting nginx … '
docker compose restart nginx >/dev/null 2>&1 || docker restart et-nginx-1 >/dev/null
printf 'done\n'

# The first request after a restart pays the cold-opcache cost (~20 s on a
# Windows bind mount). Absorbing it here means the next real request is fast and
# nobody mistakes the warm-up for a hang.
printf 'Warming opcache '
for _ in 1 2 3; do
    if curl -fsS --max-time 60 -o /dev/null http://localhost:8888/api/v1/ping 2>/dev/null; then
        printf ' ready\n'
        exit 0
    fi
    printf '.'
    sleep 2
done

printf '\nWarm-up request did not succeed — check `docker compose logs api nginx`.\n' >&2
exit 1
