#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# T175 — E02 OpenAPI Coverage Gate
#
# Verifies that all 19 E02 profile endpoints are documented in the generated
# OpenAPI specification. Run this in CI after `php artisan l5-swagger:generate`.
#
# Usage:
#   cd wallet-ecosystem/services/api
#   php artisan l5-swagger:generate
#   bash scripts/verify-e02-openapi.sh
#
# Exit codes:
#   0  All 19 E02 paths found in the spec
#   1  One or more paths missing — add @OA annotations to the controller action
# ---------------------------------------------------------------------------
set -euo pipefail

SPEC_JSON="${L5_SWAGGER_DOCS:-storage/api-docs/api-docs.json}"

if [[ ! -f "$SPEC_JSON" ]]; then
    echo "ERROR: OpenAPI spec not found at '$SPEC_JSON'."
    echo "       Run: php artisan l5-swagger:generate"
    exit 1
fi

# E02 path prefixes that MUST appear in the generated spec.
# Parameterised segments use {param} notation as emitted by l5-swagger.
REQUIRED_PATHS=(
    "/v1/users/profile"
    "/v1/users/profile/personal"
    "/v1/users/profile/photo"
    "/v1/users/profile/email/change"
    "/v1/users/profile/email/verify"
    "/v1/users/profile/mobile/change"
    "/v1/users/profile/mobile/verify"
    "/v1/users/profile/status"
    "/v1/users/profile/closure/request"
    "/v1/users/profile/data-export"
    "/v1/users/profile/data-export/{export_id}"
    "/v1/admin/users/{user_id}/profile"
    "/v1/admin/users/{user_id}/profile/personal"
    "/v1/admin/users/{user_id}/profile/status"
    "/v1/admin/users/{user_id}/suspend"
    "/v1/admin/users/{user_id}/reactivate"
    "/v1/admin/users/{user_id}/cancel-closure"
    "/v1/admin/users/{user_id}/close"
)

MISSING=0

for PATH_ENTRY in "${REQUIRED_PATHS[@]}"; do
    # Escape forward-slashes and curly braces for grep
    ESCAPED=$(printf '%s' "$PATH_ENTRY" | sed 's/[\/{}]/\\&/g')
    if ! grep -q "\"${PATH_ENTRY}\"" "$SPEC_JSON" 2>/dev/null; then
        echo "  MISSING: $PATH_ENTRY"
        MISSING=$((MISSING + 1))
    fi
done

if [[ $MISSING -gt 0 ]]; then
    echo ""
    echo "ERROR: $MISSING E02 path(s) are missing from '$SPEC_JSON'."
    echo "       Add @OA\\Get / @OA\\Post / @OA\\Put / @OA\\Delete annotations to the"
    echo "       corresponding controller action and re-run l5-swagger:generate."
    exit 1
fi

echo "OK: All ${#REQUIRED_PATHS[@]} E02 paths are documented in '$SPEC_JSON'."
exit 0
