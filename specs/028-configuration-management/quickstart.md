# Quickstart Validation Guide — E28 Configuration Management

## Prerequisites

- Docker Compose stack running (`docker compose up -d`)
- Admin user seeded: `admin@olekard.local` / `Admin123!`
- A second admin user seeded with `configuration.approve` permission (created via E04 role seeder or manual seed)
- `BASE_URL=http://localhost:8080`

Obtain an admin token:
```bash
TOKEN=$(curl -s -X POST $BASE_URL/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@olekard.local","password":"Admin123!"}' \
  | jq -r '.data.access_token')
```

---

## SC-001 — 100% of changes pass through maker-checker before activation

**Goal**: No configuration change takes effect without an explicit checker approval.

```bash
# 1. Submit a change request (maker)
CR=$(curl -s -X POST $BASE_URL/v1/admin/configuration/change-requests \
  -H "Authorization: Bearer $TOKEN" \
  -H "Idempotency-Key: sc001-$(date +%s)" \
  -H "Content-Type: application/json" \
  -d '{
    "config_key": "financial_policy.max_single_transfer",
    "proposed_value": 50000
  }' | jq -r '.data')

CR_ID=$(echo $CR | jq -r '.id')
echo "Change request: $CR_ID, status=$(echo $CR | jq -r '.status')"
# Expected: status = pending_approval

# 2. Confirm live config has NOT changed yet
LIVE=$(curl -s $BASE_URL/v1/admin/configuration/keys/financial_policy.max_single_transfer \
  -H "Authorization: Bearer $TOKEN" | jq -r '.data.current_value')
echo "Live value (must be original, not 50000): $LIVE"
```

**Expected**: `status = pending_approval`; live value unchanged.

---

## SC-002 — Approved changes become active within 60 seconds

**Goal**: Timing assertion on activation SLA (FR-011).

```bash
# 1. Get checker token (second admin)
CHECKER_TOKEN=$(curl -s -X POST $BASE_URL/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"checker@olekard.local","password":"Admin123!"}' \
  | jq -r '.data.access_token')

# 2. Submit a change (use a different key to avoid SC-001 conflict)
CR_ID=$(curl -s -X POST $BASE_URL/v1/admin/configuration/change-requests \
  -H "Authorization: Bearer $TOKEN" \
  -H "Idempotency-Key: sc002-$(date +%s)" \
  -H "Content-Type: application/json" \
  -d '{"config_key":"financial_policy.daily_limit","proposed_value":200000}' \
  | jq -r '.data.id')

# 3. Approve immediately and record time
APPROVAL_TIME=$(date +%s)
curl -s -X POST $BASE_URL/v1/admin/configuration/change-requests/$CR_ID/approve \
  -H "Authorization: Bearer $CHECKER_TOKEN" \
  -H "Idempotency-Key: sc002-approve-$(date +%s)"

# 4. Poll until active (max 60s)
for i in $(seq 1 12); do
  sleep 5
  ELAPSED=$(( $(date +%s) - APPROVAL_TIME ))
  VALUE=$(curl -s $BASE_URL/v1/admin/configuration/keys/financial_policy.daily_limit \
    -H "Authorization: Bearer $TOKEN" | jq -r '.data.current_value')
  echo "t+${ELAPSED}s: current_value=$VALUE"
  [ "$VALUE" = "200000" ] && echo "✅ Activated in ${ELAPSED}s (must be ≤60)" && break
done
```

**Expected**: `current_value = 200000` within 60 seconds of approval.

---

## SC-003 — Every state transition produces an immutable version record

**Goal**: All 6 event types (submitted, approved, rejected, withdrawn, activated, failed) produce version records.

```bash
# Check version trail after SC-002 flow above (submitted + approved + activated)
HISTORY=$(curl -s "$BASE_URL/v1/admin/configuration/keys/financial_policy.daily_limit/history" \
  -H "Authorization: Bearer $TOKEN" | jq '.data')

echo "Event types in trail:"
echo $HISTORY | jq '.[].event_type'
# Expected: ["activated", "approved", "submitted"] (newest first)

# Verify immutability: attempt a direct DB DELETE (should fail with permission denied)
# docker compose exec api php artisan tinker --execute="
#   DB::connection('platform')->statement('DELETE FROM platform.configuration_versions WHERE id IS NOT NULL');
# "
# Expected: SQLSTATE[42501] permission denied
```

**Expected**: Trail contains `submitted`, `approved`, `activated` records; DB DELETE raises permission error.

---

## SC-004 — Version history for 100 records returned in under 2 seconds

**Goal**: Performance assertion on history query.

```bash
# Seed 100 historical versions (use Artisan factory or seeder)
# php artisan db:seed --class=ConfigurationVersionHistorySeeder

START=$(date +%s%N)
RESP=$(curl -s "$BASE_URL/v1/admin/configuration/keys/financial_policy.max_single_transfer/history?per_page=50" \
  -H "Authorization: Bearer $TOKEN")
END=$(date +%s%N)
ELAPSED_MS=$(( (END - START) / 1000000 ))

TOTAL=$(echo $RESP | jq '.meta.total')
echo "Total versions: $TOTAL, response time: ${ELAPSED_MS}ms (must be <2000)"
```

**Expected**: Response time < 2000ms with 100+ records.

---

## SC-005 — Invalid submissions rejected at submission time

**Goal**: No invalid value enters the approval queue (FR-003, FR-005).

```bash
# Test 1: Wrong type (string where integer expected)
HTTP_STATUS=$(curl -s -o /dev/null -w "%{http_code}" \
  -X POST $BASE_URL/v1/admin/configuration/change-requests \
  -H "Authorization: Bearer $TOKEN" \
  -H "Idempotency-Key: sc005-a-$(date +%s)" \
  -H "Content-Type: application/json" \
  -d '{"config_key":"financial_policy.max_single_transfer","proposed_value":"not_a_number"}')
echo "Wrong type → HTTP $HTTP_STATUS (must be 400)"

# Test 2: Negative transaction limit (below allowed range)
HTTP_STATUS=$(curl -s -o /dev/null -w "%{http_code}" \
  -X POST $BASE_URL/v1/admin/configuration/change-requests \
  -H "Authorization: Bearer $TOKEN" \
  -H "Idempotency-Key: sc005-b-$(date +%s)" \
  -H "Content-Type: application/json" \
  -d '{"config_key":"financial_policy.max_single_transfer","proposed_value":-100}')
echo "Negative value → HTTP $HTTP_STATUS (must be 400)"

# Test 3: Missing required placeholder in notification template
HTTP_STATUS=$(curl -s -o /dev/null -w "%{http_code}" \
  -X POST $BASE_URL/v1/admin/configuration/change-requests \
  -H "Authorization: Bearer $TOKEN" \
  -H "Idempotency-Key: sc005-c-$(date +%s)" \
  -H "Content-Type: application/json" \
  -d '{"config_key":"operational_policy.notification_template.otp","proposed_value":"Your code"}')
echo "Missing {{otp_code}} placeholder → HTTP $HTTP_STATUS (must be 400)"
```

**Expected**: All three return HTTP 400 with descriptive validation error; no change requests created.

---

## SC-006 — Zero concurrent dual-approval races succeed

**Goal**: Exactly one approval wins; second gets 409 (FR-009).

```bash
# Submit a change request
CR_ID=$(curl -s -X POST $BASE_URL/v1/admin/configuration/change-requests \
  -H "Authorization: Bearer $TOKEN" \
  -H "Idempotency-Key: sc006-$(date +%s)" \
  -H "Content-Type: application/json" \
  -d '{"config_key":"geographic.supported_currencies","proposed_value":["BWP","USD","ZAR"]}' \
  | jq -r '.data.id')

# Fire two simultaneous approval requests (requires two checker tokens or parallel curl)
IK="sc006-race-$(date +%s)"
RESULT1=$(curl -s -o /dev/null -w "%{http_code}" \
  -X POST $BASE_URL/v1/admin/configuration/change-requests/$CR_ID/approve \
  -H "Authorization: Bearer $CHECKER_TOKEN" \
  -H "Idempotency-Key: ${IK}-1" &)
RESULT2=$(curl -s -o /dev/null -w "%{http_code}" \
  -X POST $BASE_URL/v1/admin/configuration/change-requests/$CR_ID/approve \
  -H "Authorization: Bearer $CHECKER_TOKEN" \
  -H "Idempotency-Key: ${IK}-2" &)
wait

echo "Result 1: $RESULT1 | Result 2: $RESULT2"
# Expected: one 200, one 409

# Verify only one activation version record
ACTIVATIONS=$(curl -s "$BASE_URL/v1/admin/configuration/keys/geographic.supported_currencies/history" \
  -H "Authorization: Bearer $TOKEN" | jq '[.data[] | select(.event_type == "activated")] | length')
echo "Activation records: $ACTIVATIONS (must be 1)"
```

**Expected**: `one 200 + one 409`; exactly 1 `activated` version record.

---

## SC-007 — All operations are audit-logged; full change history reconstructable

**Goal**: Compliance officer can reconstruct the complete change history (FR-018, US4).

```bash
# After running SC-001 through SC-006 scenarios, verify audit trail
# This uses the existing platform.configuration_audit table + configuration_versions

# 1. Check audit log has entries for each action
docker compose exec api php artisan tinker --execute="
  \$count = DB::connection('platform')->table('platform.configuration_audit')
    ->where('action', 'LIKE', 'configuration.%')
    ->count();
  echo 'Audit entries: ' . \$count . PHP_EOL;
"

# 2. Reconstruct history via API
curl -s "$BASE_URL/v1/admin/configuration/keys/financial_policy.max_single_transfer/history" \
  -H "Authorization: Bearer $TOKEN" | jq '{
    total_versions: .meta.total,
    event_types: [.data[].event_type],
    makers: [.data[].maker_id] | unique,
    checkers: [.data[].checker_id | select(. != null)] | unique
  }'
```

**Expected**: Audit entries exist for all SC-001–SC-006 operations; history API returns complete trail with maker/checker identities.

---

## Self-Approval Block (FR-006)

```bash
# Maker attempts to approve their own request
CR_ID=$(curl -s -X POST $BASE_URL/v1/admin/configuration/change-requests \
  -H "Authorization: Bearer $TOKEN" \
  -H "Idempotency-Key: self-approve-$(date +%s)" \
  -H "Content-Type: application/json" \
  -d '{"config_key":"operational_policy.kyc_tier1_limit","proposed_value":5000}' \
  | jq -r '.data.id')

HTTP_STATUS=$(curl -s -o /dev/null -w "%{http_code}" \
  -X POST $BASE_URL/v1/admin/configuration/change-requests/$CR_ID/approve \
  -H "Authorization: Bearer $TOKEN" \
  -H "Idempotency-Key: self-approve-checker-$(date +%s)")
echo "Self-approval → HTTP $HTTP_STATUS (must be 403)"
```

**Expected**: HTTP 403 with message indicating self-approval is prohibited.

---

## One Pending Per Key (FR-007)

```bash
# First pending request (created above in self-approval test)
# Try to submit a second one for the same key
HTTP_STATUS=$(curl -s -o /dev/null -w "%{http_code}" \
  -X POST $BASE_URL/v1/admin/configuration/change-requests \
  -H "Authorization: Bearer $TOKEN" \
  -H "Idempotency-Key: duplicate-$(date +%s)" \
  -H "Content-Type: application/json" \
  -d '{"config_key":"operational_policy.kyc_tier1_limit","proposed_value":7500}')
echo "Duplicate pending → HTTP $HTTP_STATUS (must be 409)"
```

**Expected**: HTTP 409 Conflict.

---

## Withdraw (FR-008)

```bash
# Maker withdraws their own pending request
HTTP_STATUS=$(curl -s -o /dev/null -w "%{http_code}" \
  -X POST $BASE_URL/v1/admin/configuration/change-requests/$CR_ID/withdraw \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"reason":"Changed my mind — waiting for quarterly review"}')
echo "Withdrawal → HTTP $HTTP_STATUS (must be 200)"

# Verify live config unchanged
LIVE=$(curl -s $BASE_URL/v1/admin/configuration/keys/operational_policy.kyc_tier1_limit \
  -H "Authorization: Bearer $TOKEN" | jq -r '.data.current_value')
echo "Live value after withdrawal (unchanged): $LIVE"
```

**Expected**: HTTP 200; live value unchanged; `withdrawn` version record created.
