# Quickstart Validation Guide — E08 Wallet Funding

Runnable scenarios that prove E08 works end-to-end. Maps directly to success criteria SC-E08-001 through SC-E08-012.

## Prerequisites

- E01–E07 complete and seeded
- `php artisan migrate` has run (funding schema created)
- `php artisan db:seed --class=FundingPermissionsSeeder` has run
- `php artisan db:seed --class=FundingConfigurationSeeder` has run (seeds limit/fee config)
- `TBD-E07-001` resolved: `ledger.float_account.BWP` configuration value populated
- `TBD-E07-002` resolved: `account_type` for customer pockets confirmed
- A seeded customer with a BWP wallet pocket (customer ID: any from `platform.users`)
- A seeded agent with `funding.agent-cashin` permission and a funded float account
- All tests run from: `wallet-ecosystem/services/api/`

---

## Scenario 1 — Digital funding end-to-end (SC-E08-001)

```bash
# Step 1: Initiate
curl -X POST http://localhost/v1/funding/transactions \
  -H "Authorization: Bearer {customer_token}" \
  -H "Idempotency-Key: $(uuidgen)" \
  -H "Content-Type: application/json" \
  -d '{"wallet_id":"{wallet_id}","currency_code":"BWP","amount":10000,"funding_method":"mobile_money","correlation_id":"'$(uuidgen)'"}'
# Expected: 201, status=pending, fee_amount and net_amount in response

# Step 2: Confirm
TX_ID="{tx_id_from_step_1}"
curl -X POST http://localhost/v1/funding/transactions/$TX_ID/confirm \
  -H "Authorization: Bearer {customer_token}" \
  -H "Idempotency-Key: $(uuidgen)" \
  -H "Content-Type: application/json" \
  -d '{"quoted_fee_amount":50,"correlation_id":"'$(uuidgen)'"}'
# Expected: 201, status=processing

# Step 3: Simulate provider success callback (mock adapter)
curl -X POST http://localhost/v1/funding/callbacks/mock \
  -H "Content-Type: application/json" \
  -H "X-Mock-Signature: {computed_hmac}" \
  -d '{"provider_transaction_id":"MOCK-uuid","status":"success","amount":10000,"currency":"BWP"}'
# Expected: 200

# Step 4: Verify wallet balance
php artisan ledger:get-balance {pocket_ledger_ref}
# Expected: balance = net_amount (e.g., 9950 if fee=50)
```

**Automated**: `tests/Feature/Modules/Funding/DigitalFundingEndToEndTest.php` (SC-E08-001)

---

## Scenario 2 — Agent cash-in (SC-E08-002)

```bash
curl -X POST http://localhost/v1/funding/agent-transactions \
  -H "Authorization: Bearer {agent_token}" \
  -H "Idempotency-Key: $(uuidgen)" \
  -H "Content-Type: application/json" \
  -d '{"customer_identifier":"+26771234567","amount":5000,"currency_code":"BWP","transaction_pin":"1234","correlation_id":"'$(uuidgen)'"}'
# Expected: 201, status=completed, fee_amount=0, net_amount=amount
# Verify: agent float balance decreased by 5000; customer pocket balance increased by 5000
# Verify: receipt_reference present in response
```

**Automated**: `tests/Feature/Modules/Funding/AgentCashInTest.php` (SC-E08-002)

---

## Scenario 3 — Duplicate provider callback (SC-E08-003)

```bash
# Complete a funding (Scenario 1 steps 1–3)
SAME_PAYLOAD='{"provider_transaction_id":"MOCK-uuid","status":"success","amount":10000,"currency":"BWP"}'

# Send identical callback a second time
curl -X POST http://localhost/v1/funding/callbacks/mock \
  -H "Content-Type: application/json" \
  -H "X-Mock-Signature: {same_hmac}" \
  -d "$SAME_PAYLOAD"
# Expected: 200 (idempotent); wallet balance unchanged
```

**Automated**: `tests/Feature/Modules/Funding/ProviderCallbackIdempotencyTest.php` (SC-E08-003)

---

## Scenario 4 — Concurrent agent cash-in (SC-E08-004)

```bash
# Run from tests (not cURL — requires true concurrency)
php artisan test tests/Concurrency/Modules/Funding/AgentFloatConcurrencyTest.php
# Expected: exactly one of two concurrent cash-ins succeeds when float = one cash-in amount
```

---

## Scenario 5 — Failed funding, wallet unchanged (SC-E08-005)

```bash
# Initiate + Confirm (as Scenario 1)
# Send failure callback
curl -X POST http://localhost/v1/funding/callbacks/mock \
  -H "Content-Type: application/json" \
  -H "X-Mock-Signature: {hmac}" \
  -d '{"provider_transaction_id":"MOCK-fail-uuid","status":"failure","amount":10000,"currency":"BWP"}'
# Expected: 200; GET /v1/funding/transactions/{id} returns status=failed
# Verify: wallet balance unchanged (same as before initiation)
# Verify: Funding.Transaction.Failed event in outbox
```

**Automated**: `tests/Feature/Modules/Funding/FailedFundingTest.php` (SC-E08-005)

---

## Scenario 6 — Bank transfer end-to-end (SC-E08-006)

```bash
# Step 1: Initiate (method=bank_transfer)
# Step 2: Confirm → response includes bank_reference_code (e.g., OLK-20260918-A7BK3FPQ)

# Step 3: Send bank notification matching the reference
curl -X POST http://localhost/v1/funding/bank/notifications \
  -H "X-Bank-Api-Key: {api_key}" \
  -H "Content-Type: application/json" \
  -d '{"bank_notification_id":"BANK-001","amount":10000,"currency_code":"BWP","reference":"OLK-20260918-A7BK3FPQ","received_at":"2026-09-18T14:30:00Z"}'
# Expected: 200, matched=true
# Verify: FundingTransaction.status=completed; BankReconciliation record created
# Verify: wallet balance increased by net_amount
```

**Automated**: `tests/Feature/Modules/Funding/BankTransferEndToEndTest.php` (SC-E08-006)

---

## Scenario 7 — Unmatched bank deposit (SC-E08-007)

```bash
curl -X POST http://localhost/v1/funding/bank/notifications \
  -H "X-Bank-Api-Key: {api_key}" \
  -H "Content-Type: application/json" \
  -d '{"bank_notification_id":"BANK-002","amount":5000,"currency_code":"BWP","reference":"INVALID-REF","received_at":"2026-09-18T15:00:00Z"}'
# Expected: 200, matched=false, unmatched_deposit_id present
# Verify: unmatched_deposits record with resolution_status=pending
# Verify: Funding.UnmatchedDeposit.Received event in outbox
# Verify: no wallet credited
```

**Automated**: `tests/Feature/Modules/Funding/UnmatchedDepositTest.php` (SC-E08-007)

---

## Scenario 8 — Audit trail (SC-E08-008)

```bash
# After any operation in Scenarios 1–7
php artisan tinker
>>> DB::table('platform.audit_events')->where('action', 'like', 'funding.%')->orderBy('created_at')->get();
# Expected: records for funding.transaction.initiated, funding.transaction.completed, etc.
```

**Automated**: Each feature test asserts `assertAuditEventRecorded()` (pattern from E07).

---

## Scenario 9 — Funding limit enforcement (SC-E08-009)

```bash
# Set max amount to 1000 BWP for testing
php artisan config:set funding.limit.mobile_money.tier_2.max_amount 1000

# Try to fund 2000 BWP
curl -X POST http://localhost/v1/funding/transactions \
  ... -d '{"amount":2000,"funding_method":"mobile_money",...}'
# Expected: 422, error.code=AMOUNT_ABOVE_MAXIMUM

# Try to fund 5 BWP (below minimum of 100)
  ... -d '{"amount":5,...}'
# Expected: 422, error.code=AMOUNT_BELOW_MINIMUM
```

**Automated**: `tests/Feature/Modules/Funding/FundingLimitEnforcementTest.php` (SC-E08-009)

---

## Scenario 10 — Transaction cancellation (SC-E08-010)

```bash
# Initiate a bank_transfer → status=pending
TX_ID="{tx_id}"
curl -X POST http://localhost/v1/funding/transactions/$TX_ID/confirm ...
# status=awaiting_match; bank_reference_code assigned

# Cancel the awaiting_match transaction
curl -X POST http://localhost/v1/funding/transactions/$TX_ID/cancel \
  -H "Authorization: Bearer {customer_token}" \
  -H "Idempotency-Key: $(uuidgen)" \
  -H "Content-Type: application/json" \
  -d '{"correlation_id":"'$(uuidgen)'"}'
# Expected: 200, status=cancelled
# Verify: bank_reference_code is invalidated (subsequent bank notification with that reference = unmatched)
# Verify: wallet balance unchanged; Funding.Transaction.Cancelled event emitted
```

**Automated**: `tests/Feature/Modules/Funding/TransactionCancellationTest.php` (SC-E08-010)

---

## Scenario 11 — Fee mismatch at confirmation (SC-E08-011)

```bash
# 1. Initiate transaction → fee_amount=50 returned in response
# 2. Admin changes fee rate between initiation and confirmation
php artisan config:set funding.fee.mobile_money.BWP 100  # was 50 bps → now 100 bps

# 3. Confirm with old quoted fee
curl -X POST http://localhost/v1/funding/transactions/{tx_id}/confirm \
  -d '{"quoted_fee_amount":50,"correlation_id":"..."}'
# Expected: 422, error.code=FEE_CHANGED, data.fee_amount=<new_fee>

# 4. Confirm with correct quoted fee
  -d '{"quoted_fee_amount":<new_fee>,...}'
# Expected: 201
```

**Automated**: `tests/Feature/Modules/Funding/FeeChangedTest.php` (SC-E08-011)

---

## Scenario 12 — Invalid provider callback signature (SC-E08-012)

```bash
curl -X POST http://localhost/v1/funding/callbacks/mock \
  -H "Content-Type: application/json" \
  -H "X-Mock-Signature: INVALID_SIG" \
  -d '{"provider_transaction_id":"MOCK-uuid","status":"success","amount":10000,"currency":"BWP"}'
# Expected: 400, error.code=INVALID_CALLBACK_SIGNATURE
# Verify: no FundingTransaction status change; no ledger entry posted
# Verify: FundingProviderCallback record stored with signature_valid=false
```

**Automated**: `tests/Feature/Modules/Funding/CallbackSignatureTest.php` (SC-E08-012)

---

## Expiry Command Validation

```bash
# Seed a stale processing transaction (created_at = NOW() - 2 hours)
# Set timeout to 60 minutes
php artisan config:set funding.processing.timeout_minutes 60

php artisan funding:expire-stale --dry-run
# Expected: output lists the stale transaction ID

php artisan funding:expire-stale
# Expected: transaction status=failed; Funding.Transaction.Expired event emitted
# Re-run: no-op (idempotent)
```
