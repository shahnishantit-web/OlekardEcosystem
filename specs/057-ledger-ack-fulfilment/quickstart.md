# Quickstart: P08 — Ledger Acknowledgement and Fulfilment Handoff

**Date**: 2026-10-21

This guide documents the validation scenarios that prove P08 works end-to-end. Each scenario can be executed in a Docker environment with `docker compose up -d` from `wallet-ecosystem/`.

---

## Prerequisites

```bash
# Start the environment
cd wallet-ecosystem
docker compose up -d

# Run DB migrations (includes P08 migration for ledger_repair_at)
docker compose exec api php artisan migrate

# Seed dev data (creates customers, wallets, and a seeded admin)
docker compose exec api php artisan db:seed --class=DevPaymentSeeder
```

---

## SC-01: Balanced journal posted on wallet payment confirmation

**Goal**: Verify that a wallet payment confirmation posts exactly one balanced journal entry.

```bash
# 1. Create a payment intent (wallet provider)
POST /v1/payment/intents
Body: { "amount_minor": 5000, "currency_code": "BWP", "provider_code": "wallet", ... }
# → Intent ID captured as $INTENT_ID

# 2. Create an attempt
POST /v1/payment/intents/$INTENT_ID/attempts
# → Attempt ID captured as $ATTEMPT_ID

# 3. Record outcome (success)
POST /v1/payment/intents/$INTENT_ID/attempts/$ATTEMPT_ID/outcome
Headers: Idempotency-Key: <uuid>
Body: { "outcome": "succeeded", "provider_reference_id": "prov-ref-001" }
# → 204 No Content

# 4. Verify: one journal entry exists for the intent
docker compose exec api php artisan tinker --execute="
  echo app(\App\Modules\Ledger\Domain\Contracts\LedgerModuleContract::class)
    ->entryExists('payment:{$INTENT_ID}:ack') ? 'EXISTS' : 'NOT FOUND';
"
# Expected: EXISTS

# 5. Verify: accounting_status = ledger_posted
docker compose exec api php artisan tinker --execute="
  echo \DB::connection('payment')
    ->table('payment.payment_intents')
    ->where('id', '$INTENT_ID')
    ->value('accounting_status');
"
# Expected: ledger_posted
```

**Scenario passes when**:
- `entryExists('payment:{intentId}:ack')` returns `true`
- `accounting_status = ledger_posted`
- `payment_attempts.ledger_journal_id` is a non-null UUID

---

## SC-02: Ledger outage — capture preserved, accounting pending

**Goal**: Verify that a ledger service failure does not roll back capture.

```bash
# Simulate ledger outage by binding a failing stub in the test or using feature flags
# In unit test: mock LedgerModuleContract::postEntry() to throw \RuntimeException

# After simulating outcome = succeeded with ledger throwing:
# Expected:
#   payment_attempts.status = succeeded  ← capture preserved
#   payment_intents.accounting_status = pending_ledger
#   No journal entry exists (entryExists returns false)
```

**Scenario passes when**:
- `payment_attempts.status = succeeded`
- `accounting_status = pending_ledger`
- `entryExists()` returns `false`

---

## SC-03: Replay idempotency — same intent key, single journal

**Goal**: Verify that replaying the outcome callback does not create a second journal.

```bash
# 1. Record outcome (first time)
POST /v1/payment/intents/$INTENT_ID/attempts/$ATTEMPT_ID/outcome
Headers: Idempotency-Key: <same-key>
# → 204

# 2. Record outcome again (replay)
POST /v1/payment/intents/$INTENT_ID/attempts/$ATTEMPT_ID/outcome
Headers: Idempotency-Key: <same-key>
# → 204

# 3. Verify: LedgerModuleContract returns same journal ID both times
#    (no second INSERT in ledger)
```

**Scenario passes when**:
- Both calls return 204
- Only one journal entry exists for `payment:{intentId}:ack`
- `accounting_status = ledger_posted` (not changed on second call)

---

## SC-04: RepairPendingAccountingJob restores ledger_posted

**Goal**: Verify the repair job can post accounting for a previously-pending intent.

```bash
# 1. Create intent in pending_ledger state (via ledger outage scenario)
#    $INTENT_ID has accounting_status = pending_ledger

# 2. Run the repair scan job
docker compose exec api php artisan queue:work --queue=default --once
# OR dispatch directly:
docker compose exec api php artisan tinker --execute="
  \App\Modules\Payment\Application\Jobs\RepairPendingAccountingJob::dispatch();
"

# 3. Process repair job
docker compose exec api php artisan queue:work --queue=default --once

# 4. Verify
docker compose exec api php artisan tinker --execute="
  echo \DB::connection('payment')
    ->table('payment.payment_intents')
    ->where('id', '$INTENT_ID')
    ->value('accounting_status');
"
# Expected: ledger_posted
```

**Scenario passes when**:
- `accounting_status = ledger_posted`
- `ledger_repair_at` is non-null
- `payment.confirmed` outbox event exists for the intent

---

## SC-05: payment.confirmed event includes allocation_reference

**Goal**: Verify the outbox event contains `allocation_reference = payment_intent_id`.

```bash
docker compose exec api php artisan tinker --execute="
  \$row = \DB::connection('platform')
    ->table('platform.outbox_events')
    ->where('aggregate_id', '$INTENT_ID')
    ->where('event_name', 'payment.confirmed')
    ->first();

  \$payload = json_decode(\$row->payload, true);
  echo \$payload['allocation_reference'] === '$INTENT_ID' ? 'PASS' : 'FAIL';
"
# Expected: PASS
```

---

## SC-06: Admin pending-accounting list returns pending intents

**Goal**: Verify the admin endpoint lists intents with unresolved accounting.

```bash
# Authenticate as admin
GET /v1/admin/payment/intents/pending-accounting
Headers: Authorization: Bearer <admin-token>

# Expected response:
# {
#   "data": [
#     {
#       "id": "<intent-id>",
#       "accounting_status": "pending_ledger",
#       "seconds_since_capture": <N>
#       ...
#     }
#   ],
#   "meta": { "total": 1, "page": 1 ... }
# }
```

**Scenario passes when**:
- HTTP 200 with correct pagination
- `accounting_status` ∈ `{pending_ledger, ledger_failed}` for all returned records
- Access denied (403) when called without `payment.intents.admin` permission

---

## SC-07: Repair exhaustion → ledger_failed + AccountingFailure reconciliation exception

**Goal**: Verify that exhausting max retries transitions to `ledger_failed` and creates a reconciliation exception.

```bash
# Configure max retries = 1 for test
docker compose exec api php artisan tinker --execute="
  // Assume config is database-driven via ConfigurationService
  \DB::connection('platform')
    ->table('platform.configuration')
    ->updateOrInsert(
      ['key' => 'payment.accounting_repair_max_retries'],
      ['value' => '1']
    );
"

# Run repair job against a permanently failing ledger mock
# After exhaustion:

docker compose exec api php artisan tinker --execute="
  echo \DB::connection('payment')
    ->table('payment.payment_intents')
    ->where('id', '$INTENT_ID')
    ->value('accounting_status');
"
# Expected: ledger_failed

docker compose exec api php artisan tinker --execute="
  echo \DB::connection('payment')
    ->table('payment.reconciliation_exceptions')
    ->where('payment_intent_id', '$INTENT_ID')
    ->where('exception_type', 'accounting_failure')
    ->count();
"
# Expected: 1
```

---

## Unit Test Quick Check

```bash
# Run P08 unit tests (no Docker required beyond PHP)
docker compose exec api php artisan test \
  app/Modules/Payment/Tests/Unit/LedgerAcknowledgementTest.php \
  app/Modules/Payment/Tests/Unit/RepairPendingAccountingJobTest.php \
  --stop-on-failure
```

Expected: All tests pass.

---

## References

- [Data model](data-model.md)
- [Admin API contract](contracts/pending-accounting.yaml)
- [Spec](spec.md)

## Authorized evidence-extension verification (2026-10-28)

P08 extension acceptance scenarios are added in [verification-traceability.md](verification-traceability.md). The schema-backed scenarios are intentionally **not recorded as executed** because the shared local migration setup fails on the existing `identity.users` schema/history conflict. No database reset or migration-history modification is authorized.

Executed targeted development checks from `wallet-ecosystem/services/api`:

```text
php artisan test tests/Feature/Modules/Payment/LedgerAcknowledgementTest.php tests/Unit/Modules/Payment/Jobs/RepairPendingAccountingJobTest.php --stop-on-failure --no-coverage
13 passed, 0 failed, 31 assertions

php artisan test tests/Unit/Modules/Payment/PaymentLedgerEvidenceVerifierTest.php tests/Unit/Modules/Payment/EventHandlers/PaymentConfirmedListenerDedupTest.php tests/Unit/Modules/Platform/Services/OutboxDeadLetterServiceTest.php --no-coverage
5 passed, 4 skipped (dead-letter replay cases)
```

Full Payment/Ledger module PHPStan level 8 reported 490 findings; changed-file analysis passed. See restart notes for exact blockers and next safe step.

## Verification correction - 2026-10-28

The earlier note below stating that schema-backed checks were not executed because of the populated Identity schema conflict is superseded for the disposable PostgreSQL environment only. A clean `platform:bootstrap-schemas` run followed by two complete `php artisan migrate --force` executions passed with 313 migration rows. The existing development database/history was not touched.

The automated scenario coverage for SC-01 through SC-07 now passes in the isolated suite: committed Wallet capture and posted-journal binding; pending accounting on a Ledger outage; idempotent retry; repair scan/recovery; intent-scoped allocation reference; pending-accounting route authorization and response; and repair exhaustion with a provider-linked accounting reconciliation exception. Exact tests and results are listed in `verification-traceability.md`. This is development/test evidence, not a manual live-provider run. Wallet operational capture remains disabled pending AD-08; external methods remain pending_ledger pending AD-09.

### P08 SC-06/SC-07 and repair-exhaustion correction - 2026-10-28

Added `PendingAccountingFeatureTest` for SC-06: unauthenticated requests return 401, operators without `payment.intents.admin` return 403, and an authorized operator receives unresolved accounting rows. Added PostgreSQL integration coverage for SC-07: repair exhaustion writes `ledger_failed` and exactly one `accounting_failure` reconciliation record carrying the authoritative succeeded attempt and provider code; a second invocation is idempotent. If succeeded-attempt evidence or the reconciliation service is absent, the Payment transaction rolls back and remains `pending_ledger` instead of falsely closing recovery.

The first SC-07 run exposed a production bug: reconciliation exception schema requires `provider_code`, but repair exhaustion omitted it. `PaymentOrchestrationService::repairAccountingForIntent` now obtains it from the succeeded PaymentAttempt and fails closed when that evidence is unavailable. Targeted rerun: PendingAccountingFeatureTest + WalletCapturePaymentIntegrationTest **8 passed, 0 failed, 0 skipped, 51 assertions**. This is a regression fix, not a newly approved financial policy.

### Disposable developer walkthrough execution - 2026-10-28

The seven walkthrough scenarios were executed using the isolated PostgreSQL 15.19 database `wallet_ecosystem_bootstrap_20261028`, with test-only Wallet/Ledger account references. This is developer verification; no live provider call or independent operator acceptance occurred. Because the original curl-style examples predate the committed-capture contract, the scenarios were run through the current service/route acceptance tests rather than invoking the obsolete `provider_code: wallet` API sequence.

| Scenario | Executed current path | Result |
|---|---|---|
| SC-01 | Wallet capture → posted Ledger journal → Payment capture/journal persistence and confirmation outbox: `WalletCapturePaymentIntegrationTest::test_wallet_capture_evidence_is_persisted_and_confirmation_outbox_is_atomic_and_replay_safe`; `WalletCommittedCaptureTest::test_wallet_consumes_hold_and_returns_journal_bound_capture_evidence_idempotently` | PASS |
| SC-02 | Ledger outage preserves captured Wallet outcome and leaves accounting pending: `LedgerAcknowledgementTest::test_ledger_outage_defers_accounting_without_failing_capture` | PASS |
| SC-03 | Repeated successful outcome uses the same Ledger idempotency key: `LedgerAcknowledgementTest::test_idempotent_success_replay_does_not_double_post` | PASS |
| SC-04 | Repair scan dispatches per pending intent (`RepairPendingAccountingJobTest`, 5 tests / 8 assertions) and the integration suite verifies recovery/retry with the same capture/journal evidence | PASS |
| SC-05 | Confirmation carries intent-scoped allocation reference and does not create duplicate allocations: `LedgerAcknowledgementTest::test_confirmed_event_carries_intent_id_as_allocation_reference`; `PaymentConfirmedListenerDedupTest` | PASS |
| SC-06 | Pending-accounting route: unauthenticated 401, missing permission 403, authorized unresolved rows: `PendingAccountingFeatureTest` | PASS |
| SC-07 | Repair exhaustion creates one provider-linked accounting reconciliation; missing succeeded-attempt evidence remains pending: `WalletCapturePaymentIntegrationTest` repair-exhaustion cases | PASS |

Execution result: P08/P11/P25 scoped suite **81 passed, 0 failed, 0 skipped, 446 assertions**, plus SC-04 repair-scan suite **5 passed, 0 failed, 0 skipped, 8 assertions**. The full Payment/Ledger PHPStan run found **485** module findings, matching the latest historical baseline; changed-slice analysis remains 0 findings. `AdminFxManagementTest` passed separately in the same disposable schema-complete database: **22 passed, 0 failed, 0 skipped, 118 assertions**. The test class uses database transactions; no migrations were run against the legacy development database.

Final combined suite correction - 2026-10-28: after adding persisted Payment confirmation outbox dispatch retry coverage, the P08/P11/P25 combined regression is **81 passed, 0 failed, 0 skipped, 446 assertions**. `WalletCapturePaymentIntegrationTest::test_durable_confirmation_outbox_delivers_after_payment_commit_when_dispatch_is_retried` asserts the outbox row remains pending and no notification exists after Payment commit, then dispatches that row in a later worker invocation and asserts one delivered event/notification. This is restart/re-dispatch evidence, not a forced OS process termination. The remaining consumer commit-before-ack flow needs a P25 durable order/entitlement effect.
