#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "$SCRIPT_DIR/../.." && pwd)"
API_DIR="$ROOT_DIR/wallet-ecosystem/services/api"

echo "==> OleKard Platform Foundation — Dev Setup"

# 1. Copy .env if missing (idempotent)
if [[ ! -f "$API_DIR/.env" ]]; then
  echo "--> Copying .env.example → .env"
  cp "$API_DIR/.env.example" "$API_DIR/.env"
else
  echo "--> .env already exists, skipping copy"
fi

# 2. Start Docker services (idempotent — --wait blocks until healthy)
echo "--> Starting Docker services"
docker compose -f "$ROOT_DIR/docker-compose.yml" up -d --wait

# 3. Install Composer dependencies if vendor/ is absent
if [[ ! -d "$API_DIR/vendor" ]]; then
  echo "--> Installing Composer dependencies"
  composer install --working-dir="$API_DIR" --no-interaction
else
  echo "--> vendor/ already exists, skipping composer install"
fi

# 4. Run migrations (idempotent — Laravel skips already-run migrations)
echo "--> Running database migrations"
php "$API_DIR/artisan" migrate --force

# 5. Seed configuration defaults (idempotent seeder)
echo "--> Seeding platform configuration defaults"
php "$API_DIR/artisan" db:seed --class=PlatformConfigurationSeeder --force

# 6. Generate OpenAPI docs
echo "--> Generating OpenAPI specification"
php "$API_DIR/artisan" l5-swagger:generate

echo ""
echo "✓ Setup complete. Run 'php artisan serve' in $API_DIR to start the API."
