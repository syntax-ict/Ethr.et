#!/bin/bash
set -euo pipefail

echo "=== ETHR Rollback ==="

STEPS=${1:-1}

cd "$(dirname "$0")/.."

echo ">> Rolling back $STEPS migration(s)..."
cd api
php artisan migrate:rollback --step="$STEPS" --force

echo ">> Reverting to previous commit..."
cd ..
git checkout HEAD~1

echo ">> Reinstalling dependencies..."
cd api
composer install --no-dev --optimize-autoloader

echo ">> Clearing caches..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo ">> Restarting services..."
cd ..
sudo supervisorctl restart ethr-worker:*

echo "=== Rollback complete ==="
