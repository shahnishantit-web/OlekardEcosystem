#!/usr/bin/env bash
set -euo pipefail

# Script is at <root>/scripts/ci/ — API is at <root>/wallet-ecosystem/services/api/
cd "$(dirname "$0")/../../wallet-ecosystem/services/api"

echo "==> Installing Composer dependencies (no-dev)"
composer install --no-dev --no-interaction --prefer-dist

echo "==> Caching config"
php artisan config:cache

echo "==> PHP CodeSniffer (PSR-12)"
vendor/bin/phpcs

echo "==> PHPStan (level 8)"
vendor/bin/phpstan analyse --no-progress

echo "==> Pest test suite (line coverage ≥80%, branch coverage reported)"
vendor/bin/pest --coverage --coverage-clover=build/logs/clover.xml --min=80

echo "==> Branch coverage report (target ≥70% per SC-006)"
vendor/bin/pest --coverage --coverage-text

echo "==> Pest architecture tests"
vendor/bin/pest --filter=ArchitectureTest

echo "==> Module boundary check"
php artisan platform:check-scope

echo "==> Generate OpenAPI spec"
php artisan l5-swagger:generate

echo "==> Composer security audit"
composer audit

echo "==> Backend CI PASSED"
