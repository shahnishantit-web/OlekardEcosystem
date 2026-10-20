# Quickstart Validation Guide — E18 Fraud, Risk & AML

## Prerequisites

1. Docker stack running: `cd wallet-ecosystem && docker compose up -d`
2. Migrations run: `docker compose exec api php artisan migrate`
3. Seed permissions: `docker compose exec api php artisan db:seed --class=RiskPermissionSeeder`
4. Obtain tokens:
   - **Risk Officer:** `POST /v1/auth/login` with `risk_officer@olekard.local / Admin123!` → `$OFFICER_TOKEN`
   - **System/Internal:** `$SYSTEM_TOKEN` (service-to-service token from auth seeder)
5. Seed a test customer with KYC tier `standard`: `$CUSTOMER_ID`
6. Seed a test wallet for that customer: `$WALLET_ID`

---

## SC-1 — Per-transaction limit blocks amount exceeding threshold

**Story:** US-RISK-010, US-RISK-001, US-RISK-008

**Setup:** Configure a per-transaction limit of BWP 1,000.

```bash
curl -X POST /v1/risk/configure-per-transaction-limit \
  -H "Authorization: Bearer $OFFICER_TOKEN" \
  -d '{
    "applies_to": "global",
    "threshold_amount": "1000.00",
    "action": "block"
  }'
# Expect: 201 Created — { "id": "...", "rule_type": "amount_limit" }
```

**Trigger an evaluation above the limit:**

```bash
curl -X POST /v1/risk/evaluate-transaction-risk \
  -H "Authorization: Bearer $SYSTEM_TOKEN" \
  -d '{
    "idempotency_key": "sc1-test-001",
    "transaction_reference": "TX-SC1-001",
    "transaction_type": "transfer",
    "amount": "1500.00",
    "currency": "BWP",
    "customer_id": "$CUSTOMER_ID"
  }'
```

**Expected outcome:**
- HTTP 200
- `decision = "block"`
- `risk_level` in `["high", "critical"]`
- `rules_applied` contains the configured limit rule with `triggered = true`
- `evaluation_ms < 200`
- DB: `risk_evaluations` contains one record with `decision = 'block'`
- Audit: `risk.evaluation.block` event recorded in audit log

---

## SC-2 — Idempotent re-evaluation returns same result

**Story:** US-RISK-001

**Prerequisite:** SC-1 completed; idempotency_key `sc1-test-001` exists.

```bash
curl -X POST /v1/risk/evaluate-transaction-risk \
  -H "Authorization: Bearer $SYSTEM_TOKEN" \
  -d '{
    "idempotency_key": "sc1-test-001",
    "transaction_reference": "TX-SC1-001",
    "transaction_type": "transfer",
    "amount": "1500.00",
    "currency": "BWP",
    "customer_id": "$CUSTOMER_ID"
  }'
```

**Expected outcome:**
- HTTP 200 — same `evaluation_id` as SC-1 call
- `decision = "block"` (unchanged)
- No new record in `risk_evaluations` (idempotent — same row returned)

**Conflict test (different payload, same key):**

```bash
curl -X POST /v1/risk/evaluate-transaction-risk \
  -H "Authorization: Bearer $SYSTEM_TOKEN" \
  -d '{
    "idempotency_key": "sc1-test-001",
    "transaction_reference": "TX-SC1-DIFFERENT",
    "transaction_type": "transfer",
    "amount": "200.00",
    "currency": "BWP",
    "customer_id": "$CUSTOMER_ID"
  }'
# Expect: 409 Conflict
```

---

## SC-3 — Daily velocity limit triggers REVIEW decision

**Story:** US-RISK-011, US-RISK-002, US-RISK-009

**Setup:** Configure daily limit of BWP 3,000 with `action = review`.

```bash
curl -X POST /v1/risk/configure-daily-limit \
  -H "Authorization: Bearer $OFFICER_TOKEN" \
  -d '{
    "applies_to": "customer",
    "subject_id": "$CUSTOMER_ID",
    "threshold_amount": "3000.00",
    "window_seconds": 86400,
    "action": "review"
  }'
```

**Simulate three transactions of BWP 1,100 each** (evaluate-transaction-risk × 3 with unique idempotency keys). Third call should accumulate past BWP 3,000.

**Expected outcome on 3rd call:**
- `decision = "review"`
- Calling `/v1/risk/send-transaction-for-manual-review` with the `evaluation_id` creates a `RiskAlert`:

```bash
curl -X POST /v1/risk/send-transaction-for-manual-review \
  -H "Authorization: Bearer $SYSTEM_TOKEN" \
  -d '{ "evaluation_id": "$EVAL_ID", "reason": "Daily velocity limit reached" }'
# Expect: 201 — alert created, status = open
```

---

## SC-4 — Watchlist screening creates alert on match

**Story:** US-RISK-006 (beneficiary risk), US-RISK-019

**Setup:** Insert a watchlist entry (sanctions) via direct seeder or admin API. Record `$WATCHLIST_SUBJECT_ID`.

**Trigger beneficiary risk evaluation:**

```bash
curl -X POST /v1/risk/evaluate-beneficiary-risk \
  -H "Authorization: Bearer $SYSTEM_TOKEN" \
  -d '{
    "beneficiary_id": "$WATCHLIST_SUBJECT_ID",
    "customer_id": "$CUSTOMER_ID",
    "is_new_beneficiary": true
  }'
```

**Expected outcome:**
- HTTP 200 — `decision = "block"` or `"review"` (depends on rule config for sanctions matches)
- DB: `watchlist_matches` contains a record with high `match_score`
- DB: `risk_alerts` contains a new alert with `alert_type = "watchlist"`, `severity = "critical"`
- Audit: `risk.watchlist.match` recorded

---

## SC-5 — Auth failure threshold triggers account alert

**Story:** US-RISK-018

**Setup:** Ensure auth failure rule exists with threshold = 5 in 10 minutes window.

**Simulate 5 consecutive failures:**

```powershell
# PowerShell (native on Windows dev environment)
foreach ($i in 1..5) {
    Invoke-RestMethod -Method POST `
        -Uri "http://localhost/v1/risk/detect-repeated-failed-authentication" `
        -Headers @{ Authorization = "Bearer $SYSTEM_TOKEN"; "Content-Type" = "application/json" } `
        -Body (@{ subject_type = "customer"; subject_id = $CUSTOMER_ID; device_id = "DEVICE-SC5"; failure_reason = "wrong_pin" } | ConvertTo-Json)
}
# Alternatively run in WSL/Git Bash: for i in 1 2 3 4 5; do curl ...; done
```

**Expected outcome on 5th call:**
- `alert_created = true`
- `alert_id` is not null
- DB: `risk_alerts` has new alert with `alert_type = "auth_failure"`, `severity = "high"`

---

## SC-6 — Officer investigates then resolves alert as false positive

**Story:** US-RISK-020, US-RISK-021

**Prerequisite:** SC-5 alert exists; `$ALERT_ID` from previous response. Retrieve current `version`:

```bash
curl /v1/risk/alerts/$ALERT_ID -H "Authorization: Bearer $OFFICER_TOKEN"
# Note: "version": 1
```

**Start investigation:**

```bash
curl -X POST /v1/risk/investigate-risk-alert \
  -H "Authorization: Bearer $OFFICER_TOKEN" \
  -d '{
    "alert_id": "$ALERT_ID",
    "version": 1,
    "notes": "Reviewing auth pattern for customer"
  }'
# Expect: 200 — status = under_investigation
```

**Resolve as false positive:**

```bash
curl -X POST /v1/risk/resolve-risk-alert \
  -H "Authorization: Bearer $OFFICER_TOKEN" \
  -d '{
    "alert_id": "$ALERT_ID",
    "version": 2,
    "decision": "false_positive",
    "rationale": "Customer confirmed they forgot their PIN and reset it through support. Auth failures are explained. No suspicious intent detected."
  }'
# Expect: 200 — status = resolved_false_positive
```

**Expected outcome:**
- DB: `risk_decisions` record with `decision = false_positive`
- DB: `risk_alerts.resolved_at` is not null
- Audit: `risk.alert.resolved` recorded with officer ID

---

## SC-7 — Concurrent alert update rejected with 409

**Story:** US-RISK-020 (optimistic locking)

**Prerequisite:** Open alert with `version = 1`.

**Simulate two concurrent updates with same version:**

```bash
# Request A (first)
curl -X POST /v1/risk/investigate-risk-alert \
  -H "Authorization: Bearer $OFFICER_TOKEN" \
  -d '{ "alert_id": "$ALERT_ID", "version": 1 }'
# Expect: 200 — version bumped to 2

# Request B (stale version)
curl -X POST /v1/risk/investigate-risk-alert \
  -H "Authorization: Bearer $OFFICER_TOKEN" \
  -d '{ "alert_id": "$ALERT_ID", "version": 1 }'
# Expect: 409 Conflict — { "message": "Alert was modified by another request" }
```

---

## SC-8 — Account freeze requires maker-checker

**Story:** US-RISK-022

**Prerequisite:** Alert `$ALERT_ID` is under investigation. Two officer tokens: `$MAKER_TOKEN`, `$CHECKER_TOKEN`.

**Maker initiates freeze:**

```bash
curl -X POST /v1/risk/freeze-account-due-to-risk \
  -H "Authorization: Bearer $MAKER_TOKEN" \
  -d '{
    "subject_type": "customer",
    "subject_id": "$CUSTOMER_ID",
    "hold_type": "risk_freeze",
    "reason": "Multiple high-risk signals detected pending investigation",
    "alert_id": "$ALERT_ID",
    "checker_id": "$CHECKER_OFFICER_ID"
  }'
# Expect: 201 — account_hold created, status = active
```

**Verify hold blocks new transaction evaluation:**

```bash
curl -X POST /v1/risk/evaluate-transaction-risk \
  -H "Authorization: Bearer $SYSTEM_TOKEN" \
  -d '{
    "idempotency_key": "sc8-post-freeze",
    "transaction_reference": "TX-SC8-001",
    "transaction_type": "transfer",
    "amount": "100.00",
    "currency": "BWP",
    "customer_id": "$CUSTOMER_ID"
  }'
# Expect: 200 — decision = "block" (account hold active)
```

**Expected outcome:**
- DB: `account_holds` record with `status = active`
- Audit: `risk.account.frozen` recorded with both maker and checker IDs

---

## SC-9 — Unusual pattern detection queued and alert raised

**Story:** US-RISK-016

```bash
curl -X POST /v1/risk/detect-unusual-transaction-pattern \
  -H "Authorization: Bearer $SYSTEM_TOKEN" \
  -d '{
    "subject_type": "customer",
    "subject_id": "$CUSTOMER_ID",
    "lookback_days": 30
  }'
# Expect: 202 — { "job_id": "...", "queued_at": "..." }
```

**Wait for async processing (or trigger queue worker directly):**

```bash
docker compose exec api php artisan queue:work --once
```

**Check for generated events and alerts:**

```bash
curl /v1/risk/alerts?subject_id=$CUSTOMER_ID \
  -H "Authorization: Bearer $OFFICER_TOKEN"
# Expect: if pattern detected, alert with alert_type = "pattern" exists
```

**Expected outcome:**
- DB: `risk_events` contains a `pattern.unusual` event (if threshold breached)
- DB: `risk_alerts` contains alert linked to that event

---

## SC-10 — Customer-scoped limit overrides global limit

**Story:** US-RISK-013

**Setup:** Global per-transaction limit = BWP 1,000. Customer-scoped limit = BWP 500.

```bash
curl -X POST /v1/risk/configure-customer-limits \
  -H "Authorization: Bearer $OFFICER_TOKEN" \
  -d '{
    "subject_id": "$CUSTOMER_ID",
    "per_transaction_limit": "500.00",
    "per_transaction_action": "block"
  }'
```

**Evaluate a transaction of BWP 750 (above customer limit but below global):**

```bash
curl -X POST /v1/risk/evaluate-transaction-risk \
  -H "Authorization: Bearer $SYSTEM_TOKEN" \
  -d '{
    "idempotency_key": "sc10-test-001",
    "transaction_reference": "TX-SC10-001",
    "transaction_type": "transfer",
    "amount": "750.00",
    "currency": "BWP",
    "customer_id": "$CUSTOMER_ID"
  }'
# Expect: decision = "block" (customer rule takes precedence over global)
```

**Verify global rule applies to different customer:**

```bash
curl -X POST /v1/risk/evaluate-transaction-risk \
  -H "Authorization: Bearer $SYSTEM_TOKEN" \
  -d '{
    "idempotency_key": "sc10-other-customer",
    "transaction_reference": "TX-SC10-OTHER",
    "transaction_type": "transfer",
    "amount": "750.00",
    "currency": "BWP",
    "customer_id": "$OTHER_CUSTOMER_ID"
  }'
# Expect: decision = "allow" (global limit = 1000, this is below it)
```

---

## Common Failure Modes

| Symptom | Check |
|---------|-------|
| All evaluations return `allow` | `risk_rules` table empty or no active rules — run seeder |
| 401 on all requests | Token expired or wrong auth seeder was run |
| 409 on first evaluation | `idempotency_key` already used — use a unique key |
| Pattern detection alert never appears | Queue worker not running — run `artisan queue:work --once` |
| Freeze 403 | Officer user missing `risk.freeze_account` permission |
| `evaluation_ms` consistently >200ms | Missing index on `risk_rules(rule_type, applies_to, is_active)` or too many rules without `is_active` filter |
