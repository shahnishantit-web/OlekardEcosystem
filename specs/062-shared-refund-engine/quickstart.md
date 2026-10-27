# Quickstart: P15 — Shared Refund Engine Validation

## Prerequisites

- Docker Compose stack running: `docker compose up -d` from `wallet-ecosystem/`
- Migrations run: `php artisan migrate`
- Seeded test data: `php artisan db:seed --class=DevPaymentSeeder` (creates a succeeded payment intent)
- Admin JWT obtained: `POST /v1/admin/auth/login` with `admin@olekard.local / Admin123!`
- Consumer JWT obtained: `POST /v1/customer/auth/login` with `+26771000001 / PIN 123456`

## Scenario 1 — Wallet Refund Full Lifecycle (SC-001, SC-002, SC-003)

```bash
# 1. Find the seeded succeeded wallet payment intent
INTENT_ID=$(php artisan tinker --execute="echo \App\Modules\Payment\Infrastructure\Models\PaymentIntentModel::where('status','succeeded')->where('provider_code','wallet')->first()->id;")

# 2. Request a partial refund (50% of original)
curl -X POST http://localhost:8001/v1/payment/intents/$INTENT_ID/refunds \
  -H "Authorization: Bearer $ADMIN_JWT" \
  -H "Idempotency-Key: test-refund-$(date +%s)" \
  -H "Content-Type: application/json" \
  -d '{"amount_minor": 5000, "reason": "P15 validation test"}'
# Expected: 201 Created; status = "processing" (below approval threshold seed)

REFUND_ID=$(# extract id from response)

# 3. Replay same request with same idempotency key — must be no-op
# Expected: 201 (same response body); no second DB record created

# 4. Verify compensating ledger entry
php artisan tinker --execute="
  \$r = \App\Modules\Payment\Infrastructure\Models\PaymentRefundModel::find('$REFUND_ID');
  echo 'accounting_status: ' . \$r->refund_accounting_status . PHP_EOL;
  echo 'journal_id: ' . \$r->refund_ledger_journal_id . PHP_EOL;
"
# Expected: accounting_status = ledger_refund_posted; journal_id = non-null UUID

# 5. Check consumer mobile endpoint
curl http://localhost:8001/v1/customer/payments/$INTENT_ID/refunds \
  -H "Authorization: Bearer $CUSTOMER_JWT"
# Expected: funds_returned = true; status_label = "Funds returned"
```

## Scenario 2 — Concurrent Partial Refunds Cannot Over-Refund (SC-001)

```bash
# Use a 10,000 minor unit payment. Submit two requests for 6,000 each simultaneously.
php artisan test --filter=ConcurrentPartialRefundTest
# Expected: Test passes. One refund accepted, one rejected with REFUND_LIMIT_EXCEEDED.
# Combined reserved amount never exceeds 10,000.
```

## Scenario 3 — Unsupported Refund Method (SC-004)

```bash
# Register a mock provider without refund capability and attach a succeeded intent to it
php artisan tinker --execute="
  // Create a test intent with provider_code = 'no_refund_provider'
  // ... (test setup omitted; see UnsupportedRefundMethodTest.php)
"
php artisan test --filter=UnsupportedRefundMethodTest
# Expected: CapabilityUnsupportedException raised; ReconciliationException created;
#           no PaymentRefund record in 'confirmed' state; no wallet credit issued.
```

## Scenario 4 — Async Bank Refund Callback (SC-006)

```bash
# Simulate a bank refund callback via the P10/P11 inbox
php artisan test --filter=BankRefundCallbackHandlerTest
# Expected: Single callback advances refund to confirmed; duplicate delivery is no-op;
#           only one refund.confirmed outbox event written.
```

## Scenario 5 — UNKNOWN State Keeps Reservation (spec FR-014)

```bash
php artisan test --filter=RefundStateTransitionTest::test_unknown_refund_blocks_further_refund_request
# Expected: A refund in 'unknown' state is included in sumReservedRefundsForAttempt();
#           a new refund request for the same funds is rejected with REFUND_LIMIT_EXCEEDED.
```

## Scenario 6 — Admin Unknown Refund List (SC-008)

```bash
curl "http://localhost:8001/v1/admin/payment/refunds?status=unknown" \
  -H "Authorization: Bearer $ADMIN_JWT"
# Expected: 200 OK; array of refunds in unknown state with reserved_since timestamp.
```

## Scenario 7 — Bank Refund Fee Policy BLOCKED (SC-007, AD-16)

```bash
php artisan test --filter=RefundLedgerEntryTest::test_bank_refund_accounting_blocked_pending_ad16
# Expected: Bank refund transitions to 'confirmed'; refund_accounting_status =
#           'pending_ledger_refund_fee_blocked'; NO journal posted; warning log emitted
#           referencing AD-16.
```

## Scenario 8 — PHPStan Level 8

```bash
cd wallet-ecosystem/services/api
./vendor/bin/phpstan analyse app/Modules/Payment/ --level=8
# Expected: 0 errors
```

## Definition of Done

| Criterion | Verification |
|-----------|-------------|
| SC-001 concurrent over-refund blocked | `ConcurrentPartialRefundTest` PASS |
| SC-002 one compensating journal on replay | `RefundLedgerEntryTest` PASS |
| SC-003 pending/unknown = funds_returned false | Mobile API test PASS |
| SC-004 unsupported method → ReconciliationException | `UnsupportedRefundMethodTest` PASS |
| SC-005 all transitions audited within 1s | `RefundStateTransitionTest` integration PASS |
| SC-006 duplicate callback = one event | `BankRefundCallbackHandlerTest` PASS |
| SC-007 AD-16 blocked → fee_blocked status | `RefundLedgerEntryTest::bank_fee_blocked` PASS |
| SC-008 admin unknown filter works | Admin API test PASS |
| PHPStan 0 errors | CI run PASS |
