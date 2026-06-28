#!/bin/bash
set -euo pipefail

echo "=== ETHR Deployment ==="
echo "$(date '+%Y-%m-%d %H:%M:%S')"

cd "$(dirname "$0")/.."

echo ">> Pulling latest code..."
git pull origin main

echo ">> Installing PHP dependencies..."
cd api
composer install --no-dev --optimize-autoloader

echo ">> Running migrations..."
php artisan migrate --force

echo ">> Clearing caches..."
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

echo ">> Installing frontend dependencies..."
cd ../src
npm ci --production
npm run build

echo ">> Restarting services..."
cd ..
sudo supervisorctl restart ethr-worker:*
sudo supervisorctl restart ethr-reverb

echo ">> Reloading Nginx..."
sudo nginx -t && sudo systemctl reload nginx

echo "=== Deployment complete ==="
