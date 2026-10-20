#!/usr/bin/env bash
set -euo pipefail

# Script is at <root>/scripts/ci/ — admin app is at <root>/apps/admin/
cd "$(dirname "$0")/../../apps/admin"

echo "==> Installing npm dependencies"
npm ci

echo "==> ESLint"
npx eslint .

echo "==> TypeScript type check"
npx tsc --noEmit

echo "==> Jest tests with coverage"
npx jest --coverage

echo "==> Next.js build"
npx next build

echo "==> npm security audit"
npm audit --audit-level=high

echo "==> Frontend CI PASSED"
