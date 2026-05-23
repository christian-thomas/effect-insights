#!/usr/bin/env bash
# Post-deploy hook for the Laravel API. Wire into Forge / GitHub Actions /
# whatever runs after `git pull`.
#
# Safe to re-run — every step is idempotent. Exits non-zero on any failure
# so the deploy pipeline marks the release as failed.
set -euo pipefail

# Run from this script's directory so paths are stable regardless of how
# the deploy tool invokes us.
cd "$(dirname "$0")"

echo "→ composer install (no dev, optimised autoloader)"
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

echo "→ artisan migrate (force = no prompt)"
php artisan migrate --force

# Re-sign every published banner config against the current
# BANNER_CONFIG_SIGNING_KEY. Crucial on the first deploy of HMAC signing
# (pre-existing KV records have no signature → the config worker
# refuses to serve them), and on any subsequent key rotation. Idempotent
# the rest of the time — just rewrites the same payload.
echo "→ artisan banner-configs:re-sign"
php artisan banner-configs:re-sign

echo "→ artisan config:cache + route:cache + event:cache"
php artisan config:cache
php artisan route:cache
php artisan event:cache

# Tell the running Horizon master to drain + exit so the supervisor
# restarts it on the new code. Does nothing if Horizon isn't running.
echo "→ artisan horizon:terminate"
php artisan horizon:terminate || true

echo "✓ deploy complete"
