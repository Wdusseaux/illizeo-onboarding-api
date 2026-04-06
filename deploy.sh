#!/bin/bash
# ═══════════════════════════════════════════════════════
# Illizeo Onboarding — Production Deployment Script
# Target: Infomaniak Jelastic Cloud (NGINX + PHP 8.4 + MariaDB)
# ═══════════════════════════════════════════════════════

set -e

echo "═══ Illizeo Onboarding — Deploying to Production ═══"

APP_DIR="/var/www/webroot/ROOT"
cd "$APP_DIR"

# ─── 1. Pull latest code ─────────────────────────────
echo "1. Pulling latest code..."
git pull origin main

# ─── 2. Install PHP dependencies ─────────────────────
echo "2. Installing Composer dependencies..."
composer install --no-dev --optimize-autoloader --no-interaction

# ─── 3. Copy production .env if not exists ───────────
if [ ! -f .env ]; then
    echo "3. Creating .env from .env.production..."
    cp .env.production .env
    php artisan key:generate --force
    echo "   ⚠️  IMPORTANT: Update DB_PASSWORD and other secrets in .env!"
else
    echo "3. .env already exists, skipping..."
fi

# ─── 4. Run migrations ──────────────────────────────
echo "4. Running central migrations..."
php artisan migrate --force

echo "   Running tenant migrations..."
php artisan tenants:migrate --force

# ─── 5. Seed default data for all tenants ────────────
echo "5. Seeding default data..."
php artisan tenants:seed --class=DefaultDataSeeder --force 2>/dev/null || true

# ─── 6. Clear and rebuild caches ─────────────────────
echo "6. Optimizing..."
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

# ─── 7. Build frontend ──────────────────────────────
echo "7. Building frontend..."
if [ -d "../illizeo-onboarding" ]; then
    cd ../illizeo-onboarding
    npm ci
    npm run build
    # Copy build output to Laravel public
    rm -rf "$APP_DIR/public/build"
    mkdir -p "$APP_DIR/public/build"
    cp -r dist/* "$APP_DIR/public/build/"
    cd "$APP_DIR"
    echo "   Frontend built and copied to public/build/"
else
    echo "   ⚠️  Frontend directory not found. Build manually."
fi

# ─── 8. Set permissions ──────────────────────────────
echo "8. Setting permissions..."
chmod -R 775 storage bootstrap/cache
chown -R jelastic:jelastic storage bootstrap/cache 2>/dev/null || true

# ─── 9. Restart services ─────────────────────────────
echo "9. Restarting PHP-FPM..."
sudo systemctl restart php-fpm 2>/dev/null || sudo service php-fpm restart 2>/dev/null || true

echo ""
echo "═══ Deployment complete! ═══"
echo "URL: https://onboarding.illizeo.com"
echo ""
echo "Post-deploy checklist:"
echo "  □ Verify .env DB_PASSWORD is set"
echo "  □ Verify NGINX config points to /var/www/webroot/ROOT/public"
echo "  □ Verify SSL certificate is active"
echo "  □ Test: curl -I https://onboarding.illizeo.com/api/v1/health"
echo "  □ Create initial tenant: php artisan tinker → Tenant::create(['id' => 'illizeo'])"
