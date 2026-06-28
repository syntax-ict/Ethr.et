#!/bin/bash
set -euo pipefail

echo "=== ETHR Demo Seed ==="

cd "$(dirname "$0")/../api"

echo ">> Running demo tenant seeder..."
php artisan db:seed --class=DemoTenantSeeder --force

echo "=== Demo tenant created ==="
echo "URL:      http://demo.ethr.et"
echo "Login:    admin@demo.ethr.et"
echo "Password: password"
