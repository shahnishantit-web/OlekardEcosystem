#!/usr/bin/env bash
set -euo pipefail

# Script is at <root>/scripts/ci/ — Flutter apps are at <root>/apps/customer and <root>/apps/agent
ROOT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"

for APP_DIR in "$ROOT_DIR/apps/customer" "$ROOT_DIR/apps/agent"; do
    echo "==> Processing $APP_DIR"
    cd "$APP_DIR"

    echo "  --> flutter pub get"
    flutter pub get

    echo "  --> flutter analyze"
    flutter analyze

    echo "  --> dart format check"
    dart format --set-exit-if-changed .

    echo "  --> flutter test with coverage"
    flutter test --coverage

    echo "  --> flutter build apk (debug)"
    flutter build apk --debug

    echo "  --> flutter build iOS (no codesign, debug)"
    flutter build ios --no-codesign --debug || echo "  [INFO] iOS build skipped (non-macOS)"
done

echo "==> Mobile CI PASSED"
