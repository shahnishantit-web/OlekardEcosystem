# Quickstart Validation Guide — E27 Reliability & Transaction Integrity

## Prerequisites

- Docker Compose environment running (`docker compose up -d`)
- Admin API running at `http://localhost:8080`
- Seeded test data: at least one customer wallet with BWP balance
- Admin token with `operations.dead_letter.manage` permission

---

## SC-001 — Idempotency-Key Required on Financial Endpoints

**Goal**: Verify that state-changing financial endpoints reject requests with no `Idempotency-Key`.

```bash
# Should return HTTP 422 — missing Idempotency-Key
curl -s -X POST http://localhost:8080/v1/funding/transactions \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"amount":100,"currency":"BWP","provider":"test"}' \
  | jq '.error.code'
# Expected: "IDEMPOTENCY_KEY_REQUIRED"

# With key present — should proceed normally (may fail for other reasons like provider,
# but not with IDEMPOTENCY_KEY_REQUIRED)
curl -s -X POST http://localhost:8080/v1/funding/transactions \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "Idempotency-Key: $(uuidgen)" \
  -H "Content-Type: application/json" \
  -d '{"amount":100,"currency":"BWP","provider":"test"}' \
  | jq '.error.code // "ok"'
# Expected: not "IDEMPOTENCY_KEY_REQUIRED"
```

**Expected**: First call returns HTTP 422 with `IDEMPOTENCY_KEY_REQUIRED`. Second call proceeds past idempotency check.

---

## SC-002 — Duplicate Request with Same Idempotency-Key Returns Original Response

**Goal**: Verify duplicate submissions return the stored response, not a new operation.

```bash
IKEY=$(uuidgen)

# First call
R1=$(curl -s -X POST http://localhost:8080/v1/funding/transactions \
  -H "Authorization: Bearer $CUSTOMER_TOKEN" \
  -H "Idempotency-Key: $IKEY" \
  -H "Content-Type: application/json" \
  -d '{"amount":50,"currency":"BWP","provider":"test_provider"}')

# Second call — same key
R2=$(curl -s -X POST http://localhost:8080/v1/funding/transactions \
  -H "Authorization: Bearer $CUSTOMER_TOKEN" \
  -H "Idempotency-Key: $IKEY" \
  -H "Content-Type: application/json" \
  -d '{"amount":50,"currency":"BWP","provider":"test_provider"}')

# Transaction IDs should be identical
echo "R1 tx: $(echo $R1 | jq -r '.data.id')"
echo "R2 tx: $(echo $R2 | jq -r '.data.id')"
# Expected: both lines print the same UUID
```

**Expected**: Both calls return the same transaction ID. Ledger has exactly one entry pair, not two.

---

## SC-003 — Orphan Transaction Detection and Recovery

**Goal**: Verify the orphan scanner detects stuck transactions within the configured interval.

```bash
# 1. Seed a transaction directly in the DB with status='pending_provider'
#    and updated_at older than orphan_threshold_minutes
docker compose exec api php artisan tinker --execute="
  \App\Modules\Funding\Infrastructure\Models\FundingTransactionModel::insert([
    'id' => Ramsey\Uuid\Uuid::uuid7()->toString(),
    'status' => 'pending_provider',
    'amount' => 100,
    'currency' => 'BWP',
    'updated_at' => now()->subMinutes(10),
    'created_at' => now()->subMinutes(10),
  ]);
  echo 'Seeded orphan transaction';
"

# 2. Run the orphan detection job manually
docker compose exec api php artisan reliability:detect-orphans

# 3. Confirm the transaction was escalated (status changed)
docker compose exec api php artisan tinker --execute="
  \$tx = \App\Modules\Funding\Infrastructure\Models\FundingTransactionModel::where('status', 'pending_provider')
    ->where('updated_at', '<', now()->subMinutes(5))->first();
  echo \$tx ? 'FAIL: orphan still pending' : 'PASS: no stuck transactions remain';
"
```

**Expected**: After running the detection job, no funding transactions remain in `pending_provider` status beyond the threshold.

---

## SC-004 — Dead-Letter Item Visible and Replayable

**Goal**: Verify a failed operation appears in the admin dead-letter interface and can be replayed.

```bash
# 1. Force a job to fail by triggering a known-failing operation
#    (adjust to your test environment's failure injection mechanism)
docker compose exec api php artisan tinker --execute="
  \App\Modules\Platform\Application\Services\DeadLetterService::capture(
    operationType: 'funding.initiation',
    sourceId: Ramsey\Uuid\Uuid::uuid7()->toString(),
    payload: ['test' => true],
    failureReason: 'Test forced failure',
    retryCount: 3,
    retryHistory: [],
    correlationId: 'test-corr-'.uniqid(),
  );
  echo 'Dead-letter item seeded';
"

# 2. List via admin API
curl -s http://localhost:8080/v1/operations/dead-letter?status=pending \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  | jq '.data.items[0].id'
# Expected: UUID of the seeded item

# 3. Replay it
DL_ID=$(curl -s "http://localhost:8080/v1/operations/dead-letter?status=pending" \
  -H "Authorization: Bearer $ADMIN_TOKEN" | jq -r '.data.items[0].id')

curl -s -X POST "http://localhost:8080/v1/operations/dead-letter/$DL_ID/replay" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"note":"Manual replay from SC-004 test"}' \
  | jq '.data.status'
# Expected: "replayed"

# 4. Confirm replay cannot happen twice
curl -s -X POST "http://localhost:8080/v1/operations/dead-letter/$DL_ID/replay" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  | jq '.success'
# Expected: false (HTTP 409)
```

**Expected**: Item appears in list, replay succeeds once, second replay returns 409.

---

## SC-005 — Correlation ID in API Response and Logs

**Goal**: Verify `X-Correlation-ID` appears in responses and in background job logs.

```bash
# Send request with custom correlation ID
CORR="e27-test-$(date +%s)"

curl -s -I http://localhost:8080/v1/wallets \
  -H "Authorization: Bearer $CUSTOMER_TOKEN" \
  -H "X-Correlation-ID: $CORR" \
  | grep -i "x-correlation-id"
# Expected: x-correlation-id: e27-test-<timestamp>

# Check logs contain the correlation ID
docker compose logs api 2>&1 | grep "$CORR" | head -5
# Expected: log lines containing correlation_id = "$CORR"
```

**Expected**: Response header echoes the provided correlation ID. Log entries for that request contain the same ID.

---

## SC-006 — Ledger Integrity Scan

**Goal**: Verify the ledger scan runs and produces a clean snapshot.

```bash
# Run the scan manually
docker compose exec api php artisan reliability:scan-ledger-integrity

# Check the latest snapshot
curl -s "http://localhost:8080/v1/operations/ledger-integrity/snapshots?per_page=1" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  | jq '.data.items[0]'
# Expected: { "outcome": "clean", "discrepancy_count": 0, "wallets_checked": N }
```

**Expected**: Snapshot shows `clean` outcome with zero discrepancies for a correctly seeded environment.

---

## SC-007 — Configuration Changes Take Effect Without Restart

**Goal**: Verify reliability settings can be changed at runtime.

```bash
# Update orphan threshold via Platform Configuration
curl -s -X PUT http://localhost:8080/v1/identity/configuration/reliability.orphan_threshold_minutes \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"value":"10"}' | jq '.success'
# Expected: true

# Wait 60 seconds, then run orphan detection to confirm new threshold is active
sleep 60
docker compose exec api php artisan reliability:detect-orphans --dry-run
# Expected: output shows threshold = 10 minutes
```

**Expected**: Configuration update accepted; next orphan scan run uses the new threshold without service restart.

---

## Artisan Commands Reference

| Command | Description |
|---------|-------------|
| `php artisan reliability:detect-orphans` | Run orphan detection across all registered financial modules |
| `php artisan reliability:detect-orphans --dry-run` | Report orphans without modifying state |
| `php artisan reliability:scan-ledger-integrity` | Run ledger balance integrity scan once |
| `php artisan reliability:purge-dead-letter` | Purge expired dead-letter items (normally scheduled) |
| `php artisan reliability:audit-idempotency-coverage` | Report which financial endpoints lack Idempotency-Key enforcement |
