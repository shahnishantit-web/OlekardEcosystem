# Quickstart Validation Guide — E14 Agent Commission
**Date**: 2026-09-23

This guide documents the runnable validation scenarios that prove E14 works end-to-end. Use this alongside `data-model.md` (schema) and `contracts/api.yaml` (request/response shapes).

---

## Prerequisites

1. Docker stack running: `docker compose up` from `wallet-ecosystem/`
2. Database seeded: `php artisan migrate --seed`
3. Valid admin JWT token (seeded admin: `admin@olekard.local` / `Admin123!`)
4. An active agent with a provisioned float wallet (E13 prerequisite)
5. Commission expense ledger account configured:
   ```bash
   # Set TBD-E14-001 via admin config endpoint or direct DB seed
   INSERT INTO platform.configurations (key, value)
   VALUES ('ledger.commission_expense_account.BWP', 'commission_expense:BWP');
   ```
6. Commission module permissions seeded (from `2026_09_30_014500_seed_commission_permissions.php`)

---

## Scenario 1 — Commission Rule Creation

**Goal**: Verify an admin can create a flat-rate commission rule and retrieve it.

```bash
# Create flat BWP 2.00 rule for cash_in
curl -X POST http://localhost/v1/commission-rules \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "Idempotency-Key: $(uuidgen)" \
  -H "Content-Type: application/json" \
  -d '{
    "rule_type": "flat",
    "rate": 200,
    "applies_to": "cash_in",
    "agent_tier": null,
    "valid_from": "2026-01-01"
  }'
```

**Expected**:
- HTTP 201
- Response `data.status = "active"`, `data.rate = 200`, `data.rule_type = "flat"`
- Audit record created for `CommissionRuleCreated` action

**Check**:
```bash
# Retrieve the rule
curl http://localhost/v1/commission-rules/$RULE_ID \
  -H "Authorization: Bearer $ADMIN_TOKEN"
```

---

## Scenario 2 — Commission Calculation (Cash-In Event)

**Goal**: Verify a commission is automatically calculated when an E13 `FloatCashIn.Completed` event fires.

**Setup**: Active flat BWP 2.00 cash-in rule from Scenario 1.

```bash
# Trigger a cash-in for the test agent (E13 endpoint)
curl -X POST "http://localhost/v1/agents/$AGENT_ID/customers/$CUSTOMER_ID/cash-in" \
  -H "Authorization: Bearer $AGENT_TOKEN" \
  -H "Idempotency-Key: $(uuidgen)" \
  -H "Content-Type: application/json" \
  -d '{"amount": 50000, "currency_code": "BWP"}'
```

**Expected** (within 5 seconds — SC-E14-001):
1. `FloatCashIn.Completed` event is published to outbox by E13
2. `FloatCashInCommissionListener` processes the event
3. Commission record created: `status = "pending"`, `amount = 200`, `currency_code = "BWP"`, non-null `journal_id`
4. Ledger journal posted: `debit commission_expense:BWP` / `credit commission_payable:{agentId}`
5. `CommissionCalculated` outbox event published

**Check**:
```bash
# Verify commission appears in pending list
curl "http://localhost/v1/agents/$AGENT_ID/commissions/pending" \
  -H "Authorization: Bearer $ADMIN_TOKEN"
# Expect: pending_total = 200, 1 record in data
```

**Idempotency check** (SC-E14-002):
```bash
# Replay the same float transaction ID via a second listener invocation
# Expect: no duplicate commission record created; DB UNIQUE constraint on idempotency_key
```

---

## Scenario 3 — Tier-Based Commission

**Goal**: Verify tier-scoped rule applies to a tiered agent; global rule applies to untiered agents.

```bash
# Assign tier to agent
curl -X PATCH "http://localhost/v1/agents/$AGENT_ID/commission-tier" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "Idempotency-Key: $(uuidgen)" \
  -H "Content-Type: application/json" \
  -d '{"agent_tier": "premium"}'

# Create premium tier rule (BWP 3.00 flat, cash_in)
curl -X POST http://localhost/v1/commission-rules \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "Idempotency-Key: $(uuidgen)" \
  -H "Content-Type: application/json" \
  -d '{
    "rule_type": "flat",
    "rate": 300,
    "applies_to": "cash_in",
    "agent_tier": "premium",
    "valid_from": "2026-01-01"
  }'
```

**Expected**: Next cash-in for the premium agent uses BWP 3.00 (300 minor units); untiered agent still uses BWP 2.00.

---

## Scenario 4 — Approve and Execute Payout (Active Agent)

**Goal**: Verify payout approval credits the agent's float wallet and transitions all commissions to `paid`.

```bash
# Approve payout for the 2 pending commission IDs
curl -X POST http://localhost/v1/commission-payouts \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "Idempotency-Key: $(uuidgen)" \
  -H "Content-Type: application/json" \
  -d "{
    \"agent_id\": \"$AGENT_ID\",
    \"commission_ids\": [\"$COMMISSION_ID_1\", \"$COMMISSION_ID_2\"]
  }"
```

**Expected** (under 10 seconds — SC-E14-003):
- HTTP 201
- `data.status = "completed"`
- `data.total_amount = 400` (2 × BWP 2.00)
- Non-null `data.journal_id` and `data.executed_at`
- Both commissions now have `status = "paid"` and non-null `payout_id`
- Agent's float wallet balance increased by 400 minor units (verify via E13 float wallet GET)
- `CommissionPayoutApproved` and `CommissionPaid` events in outbox

**Idempotency check** (FR-E14-011):
```bash
# Replay with same Idempotency-Key
# Expect: HTTP 201 with identical response; agent float NOT double-credited
```

---

## Scenario 5 — Deferred Payout (Suspended Agent)

**Goal**: Verify payout is deferred when the agent is suspended; auto-resumes on reactivation.

```bash
# Suspend the agent (E12 endpoint)
curl -X PATCH "http://localhost/v1/agents/$AGENT_ID/status" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -d '{"status": "suspended", "reason": "QA test"}'

# Attempt payout approval
curl -X POST http://localhost/v1/commission-payouts \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "Idempotency-Key: $(uuidgen)" \
  -d "{\"agent_id\": \"$AGENT_ID\", \"commission_ids\": [\"$COMMISSION_ID_3\"]}"
```

**Expected**:
- HTTP 201, `data.status = "deferred"`
- Agent float balance unchanged
- `CommissionPayoutDeferred` event in outbox

```bash
# Reactivate agent (E12 endpoint)
curl -X PATCH "http://localhost/v1/agents/$AGENT_ID/status" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -d '{"status": "active"}'
```

**Expected** (within one event cycle):
- `AgentReactivatedEvent` emitted by E12
- `AgentReactivatedCommissionListener` processes event
- Deferred payout transitions to `completed`
- Agent float balance credited
- Commission transitions to `paid`

---

## Scenario 6 — Cancel Deferred Payout

**Goal**: Verify admin can cancel a deferred payout, reverting commissions to pending.

```bash
# Cancel the deferred payout from Scenario 5
curl -X DELETE "http://localhost/v1/commission-payouts/$DEFERRED_PAYOUT_ID" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "Idempotency-Key: $(uuidgen)"
```

**Expected**:
- HTTP 200, `data.status = "cancelled"`, non-null `cancelled_at`
- Linked commission reverts to `status = "pending"`
- `CommissionPayoutCancelled` event in outbox
- No ledger entries posted

**Guard check**:
```bash
# Attempt to cancel a completed payout
# Expect: HTTP 409, error.code = PAYOUT_CANCELLATION_NOT_ALLOWED
```

---

## Scenario 7 — Commission Reversal (Pending)

**Goal**: Verify compensating ledger entry is posted and commission transitions to `reversed`.

```bash
curl -X POST "http://localhost/v1/commissions/$COMMISSION_ID/reverse" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "Idempotency-Key: $(uuidgen)" \
  -H "Content-Type: application/json" \
  -d '{"reason": "Float transaction reversed by customer"}'
```

**Expected**:
- HTTP 200, `data.status = "reversed"`, non-null `reversal_journal_id`
- Compensating journal posted: `debit commission_payable:{agentId}` / `credit commission_expense:BWP`
- Agent float wallet unchanged (commission was not paid)
- `CommissionReversed` event in outbox

---

## Scenario 8 — Commission Reversal (Paid)

**Goal**: Verify both compensating accrual entry AND float wallet clawback are posted.

**Setup**: Ensure the commission has `status = "paid"` (has been through Scenario 4).

```bash
curl -X POST "http://localhost/v1/commissions/$PAID_COMMISSION_ID/reverse" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "Idempotency-Key: $(uuidgen)" \
  -d '{"reason": "Originating float transaction reversed"}'
```

**Expected**:
- HTTP 200, `data.status = "reversed"`
- Journal 1: `debit commission_payable` / `credit commission_expense` (reverses accrual)
- Journal 2: `debit agent_float:{walletId}` / `credit commission_payable:{agentId}` (clawback)
- Agent float balance decreases by the commission amount
- `CommissionReversed` event in outbox

---

## Scenario 9 — Commission Report and Totals

**Goal**: Verify report aggregations are correct.

```bash
# Get commission report for agent (all statuses)
curl "http://localhost/v1/agents/$AGENT_ID/commissions?page=1&per_page=100" \
  -H "Authorization: Bearer $ADMIN_TOKEN"
```

**Expected** (SC-E14-004 — under 500ms for up to 10,000 records):
- `totals_by_status`: counts per status match DB counts
- `totals_by_type`: sums per type match DB sums
- Pagination metadata present

---

## Ledger Balance Reconciliation

**Goal**: Verify SC-E14-006 — total commission_expense debits = total commission_payable credits.

```bash
# Via Laravel Tinker or a test command:
php artisan tinker
>>> \App\Modules\Ledger\Domain\Contracts\LedgerModuleContract::class
```

Check that for each agent:
- `getBalance('commission_payable:{agentId}')` reflects only unpaid commission (pending after reversals)
- After all payouts executed: balance approaches zero (fully settled)

---

## Performance Targets (Success Criteria)

| Criterion | Target | How to verify |
|-----------|--------|---------------|
| SC-E14-001 | Commission calculated < 5s post-event | Queue worker processing time log |
| SC-E14-002 | Zero duplicate commissions | UNIQUE constraint + concurrent replay test |
| SC-E14-003 | Payout execution < 10s | Response time measurement |
| SC-E14-004 | Report < 500ms for 10k records | Seed 10,000 commissions; measure query |
| SC-E14-005 | All commissions have non-null transaction_id, rule_id, journal_id | DB integrity check query |
| SC-E14-006 | Ledger balance: expense debits = payable credits | LedgerService balance reconciliation |

---

## Concurrency Tests

1. **Duplicate commission calculation**: Fire `FloatCashIn.Completed` for the same `floatTransactionId` concurrently from 5 goroutines/processes. Expect: exactly 1 commission record in DB.
2. **Concurrent payout approval**: Submit the same `Idempotency-Key` payout from 5 concurrent requests. Expect: exactly 1 `CommissionPayout` created; agent float credited exactly once.
3. **Race on deferred auto-resume**: Emit `AgentReactivatedEvent` multiple times rapidly. Expect: `ProcessedEventRepositoryInterface` prevents double-execution per `event_id`.
