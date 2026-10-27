# Quickstart Validation Guide: P16 — Reversals, Disputes and Chargebacks

**Prerequisites**:
- Docker environment running (`docker compose up -d` from `wallet-ecosystem/`)
- Database migrated (`php artisan migrate`)
- P15 (Shared Refund Engine) fully deployed and passing
- Admin account seeded: `admin@olekard.local / Admin123!`
- Mock bank adapter active (development environment)

**Service directory**: `wallet-ecosystem/services/api/`

---

## SC-001: Provider event ingestion and deduplication

**Goal**: Verify a valid dispute event creates exactly one `PaymentDisputeCase`; a duplicate is silently acknowledged.

**Setup**: Have a completed `PaymentAttempt` ID available. Identify its `provider_code`.

```bash
# Step 1: Post a dispute event for the completed attempt
curl -s -X POST http://localhost:8000/api/v1/payment/bank/dispute-callback \
  -H "Content-Type: application/json" \
  -H "X-Provider-Signature: <valid-mock-sig>" \
  -d '{
    "provider_code": "mock_bank",
    "provider_dispute_reference": "DISP-TEST-001",
    "payment_attempt_id": "<attempt_uuid>",
    "case_type": "chargeback",
    "disputed_amount_minor": 5000,
    "currency_code": "BWP",
    "reason_code": "4853",
    "response_deadline": "2026-11-10T12:00:00Z",
    "correlation_id": "test-sc001"
  }'
# Expected: 201 Created, action = "created", dispute_case_id present

# Step 2: Post identical event again
curl -s -X POST http://localhost:8000/api/v1/payment/bank/dispute-callback \
  -H "Content-Type: application/json" \
  -H "X-Provider-Signature: <valid-mock-sig>" \
  -d '{ ... same payload ... }'
# Expected: 201 OK, action = "duplicate_acknowledged", SAME dispute_case_id

# Step 3: Verify only one case in DB
php artisan tinker --execute "echo App\Modules\Payment\Infrastructure\Models\PaymentDisputeCaseModel::where('provider_dispute_reference','DISP-TEST-001')->count();"
# Expected: 1
```

**Pass criteria**: Single `PaymentDisputeCase` record; `status = received`; `DisputeCaseReceivedEvent` in outbox; second call returns `duplicate_acknowledged`.

---

## SC-002: Unknown payment attempt rejected

**Goal**: Verify an event referencing a non-existent attempt is rejected and a ReconciliationException is created.

```bash
curl -s -X POST http://localhost:8000/api/v1/payment/bank/dispute-callback \
  -H "Content-Type: application/json" \
  -H "X-Provider-Signature: <valid-mock-sig>" \
  -d '{
    "provider_code": "mock_bank",
    "provider_dispute_reference": "DISP-TEST-002",
    "payment_attempt_id": "00000000-0000-7000-0000-000000000000",
    "case_type": "dispute",
    "disputed_amount_minor": 1000,
    "currency_code": "BWP",
    "correlation_id": "test-sc002"
  }'
# Expected: 422 Unprocessable Entity

# Verify ReconciliationException created with type unmatched_dispute_event
php artisan tinker --execute "echo App\Modules\Payment\Infrastructure\Models\ReconciliationExceptionModel::where('type','unmatched_dispute_event')->count();"
# Expected: >= 1
```

**Pass criteria**: 422 response; `ReconciliationException(unmatched_dispute_event)` created; no `PaymentDisputeCase` created.

---

## SC-003: Exposure assessment and hold authorisation

**Goal**: Verify operator can authorise a hold; case moves to `under_review`; hold recorded; `DisputeHoldAuthorisedEvent` emitted.

**Setup**: Use `dispute_case_id` from SC-001. Get admin token.

```bash
# Get admin token
ADMIN_TOKEN=$(curl -s -X POST http://localhost:8000/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@olekard.local","password":"Admin123!"}' | jq -r '.data.access_token')

# Authorise hold
curl -s -X POST "http://localhost:8000/api/v1/admin/payment/dispute-cases/<case_id>/hold" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: hold-sc003-$(date +%s)" \
  -d '{"hold_amount_minor": 5000, "exposure_type": "hold"}'
# Expected: 200 OK, case status = "under_review"

# Verify DisputeHold record
php artisan tinker --execute "App\Modules\Payment\Infrastructure\Models\DisputeHoldModel::where('dispute_case_id','<case_id>')->first()->toArray();"
# Expected: hold_amount_minor = 5000, released_at = null
```

**Pass criteria**: Case in `under_review`; `DisputeHold` record created; `DisputeHoldAuthorisedEvent` in outbox.

---

## SC-004: Prior refund double-compensation guard

**Goal**: Verify net exposure accounts for prior confirmed refunds.

**Setup**: Payment attempt with a confirmed refund of 2000 minor units; dispute for same attempt arrives with `disputed_amount_minor = 5000`.

```bash
# After ingesting the dispute, verify net_exposure_minor = 3000 (5000 - 2000)
php artisan tinker --execute "
  \$case = App\Modules\Payment\Infrastructure\Models\PaymentDisputeCaseModel
    ::where('payment_attempt_id','<attempt_uuid>')->first();
  echo 'net_exposure: ' . \$case->net_exposure_minor . PHP_EOL;
  echo 'prior_refunds: ' . \$case->prior_refunds_minor . PHP_EOL;
"
# Expected: net_exposure = 3000, prior_refunds = 2000
```

**Pass criteria**: `net_exposure_minor = disputed_amount_minor - prior_confirmed_refunds`; hold cannot be authorised for more than `net_exposure_minor`.

---

## SC-005: Won outcome — hold released

**Goal**: Verify a `won` outcome releases the hold without posting a correcting journal.

**Setup**: Case in `under_review` with an authorised hold.

```bash
curl -s -X POST "http://localhost:8000/api/v1/admin/payment/dispute-cases/<case_id>/outcome" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: outcome-won-sc005" \
  -d '{"outcome": "won", "provider_decision_reference": "BANK-DEC-WON-001", "chargeback_fee_minor": 0}'
# Expected: 200 OK, case status = "won", closed_at populated

# Verify hold released
php artisan tinker --execute "
  \$hold = App\Modules\Payment\Infrastructure\Models\DisputeHoldModel::where('dispute_case_id','<case_id>')->first();
  echo 'released_at: ' . \$hold->released_at . PHP_EOL;
"
# Expected: released_at is NOT null

# Verify DisputeWonEvent in outbox
php artisan tinker --execute "
  DB::connection('payment')->table('payment.outbox_events')
    ->where('event_name','payment.dispute_case.won')
    ->where('payload','like','%<case_id>%')
    ->count();
"
# Expected: >= 1
```

**Pass criteria**: Case `won`; hold `released_at` set; no correcting journal posted; `DisputeWonEvent` in outbox.

---

## SC-006: Lost outcome — correcting journal posted

**Goal**: Verify a `lost` outcome posts a balanced correcting journal via the ledger.

**Setup**: Case in `under_review`, no prior refunds, disputed_amount_minor = 5000.

```bash
curl -s -X POST "http://localhost:8000/api/v1/admin/payment/dispute-cases/<case_id>/outcome" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: outcome-lost-sc006" \
  -d '{"outcome": "lost", "provider_decision_reference": "BANK-DEC-LOST-001", "chargeback_fee_minor": 200}'
# Expected: 200 OK, case status = "lost"

# Verify correcting journal
php artisan tinker --execute "
  \$case = App\Modules\Payment\Infrastructure\Models\PaymentDisputeCaseModel::find('<case_id>');
  echo 'chargeback_journal: ' . \$case->chargeback_ledger_journal_id . PHP_EOL;
  echo 'fee_journal: ' . \$case->fee_ledger_journal_id . PHP_EOL;
  echo 'accounting_status: ' . \$case->accounting_status . PHP_EOL;
"
# Expected: both journal IDs present; accounting_status = 'correcting_journal_posted' or 'fee_posted'

# Verify DisputeLostEvent in outbox with correct amounts
# Expected payload: net_chargeback_minor = 5000, chargeback_fee_minor = 200
```

**Pass criteria**: Case `lost`; two ledger journal IDs recorded; `DisputeLostEvent` in outbox with correct `net_chargeback_minor` and `chargeback_fee_minor`.

---

## SC-007: Contradictory outcome — no automatic adjustment

**Goal**: Verify a `won` outcome after a `lost` is stored as investigation event without financial side effect.

**Setup**: Case already in `lost` state.

```bash
# Attempt to post contradictory outcome
curl -s -X POST "http://localhost:8000/api/v1/admin/payment/dispute-cases/<case_id>/outcome" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: outcome-contradictory-sc007" \
  -d '{"outcome": "won", "provider_decision_reference": "BANK-DEC-WON-LATE"}'
# Expected: 200 OK (stored as contradictory), case status moves to "investigation_required"

# Verify no new ledger journals posted (journal IDs unchanged from step 6)
# Verify DisputeLateContradictoryOutcomeEvent in outbox
```

**Pass criteria**: Case in `investigation_required`; original `chargeback_ledger_journal_id` unchanged; `DisputeLateContradictoryOutcomeEvent` in outbox.

---

## SC-008: Customer dispute summary — ownership enforced

**Goal**: Verify customer can see their own dispute summary; another customer cannot.

```bash
# As the payer customer (token from DevCustomerSeeder)
CUSTOMER_TOKEN=$(curl -s -X POST http://localhost:8000/api/v1/customer/auth/login \
  -H "Content-Type: application/json" \
  -d '{"phone":"+26771000001","pin":"123456"}' | jq -r '.data.access_token')

curl -s "http://localhost:8000/api/v1/payment/intents/<intent_id>/disputes" \
  -H "Authorization: Bearer $CUSTOMER_TOKEN"
# Expected: 200 OK with dispute summary (no provider_dispute_reference, no fee amounts)

# As a different customer — expect 403
curl -s "http://localhost:8000/api/v1/payment/intents/<intent_id>/disputes" \
  -H "Authorization: Bearer $OTHER_CUSTOMER_TOKEN"
# Expected: 403 Forbidden
```

**Pass criteria**: Payer sees summary without internal data; other customer gets 403.

---

## Running unit tests (no Docker required)

```bash
cd wallet-ecosystem/services/api

# All P16 unit tests
php artisan test --filter="DisputeCase" --no-interaction

# Specific test classes
php artisan test Tests/Unit/DisputeCaseStateMachineTest.php
php artisan test Tests/Unit/DisputeDoubleCompensationTest.php
php artisan test Tests/Unit/DisputeEntitlementCoordinationTest.php
php artisan test Tests/Unit/DisputeIdempotencyTest.php
```

## Running PHPStan

```bash
cd wallet-ecosystem/services/api
./vendor/bin/phpstan analyse app/Modules/Payment/ --level=8
# Expected: 0 errors
```
