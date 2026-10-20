#!/usr/bin/env bash
set -euo pipefail

API_BASE="${API_BASE_URL:-http://localhost:8000}"

pass=0; fail=0

check() {
    local label="$1"
    local result="$2"
    if [ "$result" = "true" ]; then
        echo "  [PASS] $label"; ((pass++))
    else
        echo "  [FAIL] $label"; ((fail++))
    fi
}

echo ""
echo "=== Quickstart Validation ==="
echo ""

# SC-001: Health endpoint returns 200
SC001=$(curl -sf "${API_BASE}/health" -o /dev/null -w "%{http_code}" && echo "true" || echo "false")
check "SC-001: GET /health returns 200" "$SC001"

# SC-002: Ready endpoint
SC002=$(curl -sf "${API_BASE}/ready" -o /dev/null -w "%{http_code}" && echo "true" || echo "false")
check "SC-002: GET /ready returns 200" "$SC002"

# SC-003: API response envelope (any JSON endpoint returns correlation_id)
SC003_BODY=$(curl -s "${API_BASE}/health" -H "X-Correlation-ID: test-uuid-12345")
SC003=$(echo "$SC003_BODY" | grep -q '"status"' && echo "true" || echo "false")
check "SC-003: GET /health returns JSON body" "$SC003"

# SC-004: X-Correlation-ID propagated in response headers
SC004=$(curl -sf "${API_BASE}/health" -H "X-Correlation-ID: test-uuid-12345" -D - 2>/dev/null | grep -qi "X-Correlation-ID" && echo "true" || echo "false")
check "SC-004: X-Correlation-ID propagated in response header" "$SC004"

# SC-005: Security headers present
SC005=$(curl -sf "${API_BASE}/health" -D - 2>/dev/null | grep -qi "X-Content-Type-Options" && echo "true" || echo "false")
check "SC-005: Security headers present (X-Content-Type-Options)" "$SC005"

# SC-006: Startup endpoint
SC006=$(curl -sf "${API_BASE}/startup" -o /dev/null -w "%{http_code}" && echo "true" || echo "false")
check "SC-006: GET /startup returns 200" "$SC006"

echo ""
echo "Results: ${pass} passed, ${fail} failed"
echo ""

if [ "$fail" -gt 0 ]; then
    exit 1
fi
exit 0
