# Quickstart: P17 — Reconciliation Clearing and Provider Settlement

Validation scenarios for proving P17 works end-to-end. All scenarios require the full Docker stack
(`docker-compose up`). Environment: `APP_ENV=testing`, seeded fixtures.

**Prerequisites**:
- Docker stack running: `cd wallet-ecosystem && docker-compose up -d`
- Database migrated: `docker exec api php artisan migrate`
- Seeded: `docker exec api php artisan db:seed --class=PaymentPermissionRoleSeeder`
- Admin JWT: `ADMIN_TOKEN=$(docker exec api php artisan tinker --execute="echo app(\App\Modules\Auth\Application\Services\TokenService::class)->forUser(App\Modules\Auth\Infrastructure\Models\AdminUserModel::where('email','admin@olekard.local')->first());")`

---

## SC-001: Duplicate statement import is rejected

**Story**: US1 Provider Statement Ingestion — deduplication by content hash

**Steps**:
```bash
# 1. Ingest a statement the first time
curl -s -X POST http://localhost:8080/api/v1/payment/provider/statement-callback \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: test-stmt-001" \
  -H "X-Provider-Webhook-Secret: $PROVIDER_SECRET" \
  -d '{
    "provider_code":"airtel_momo","account_id":"AIRTEL-BWP-001","environment":"production",
    "currency_code":"BWP","period_start":"2026-10-01T00:00:00Z","period_end":"2026-10-07T23:59:59Z",
    "lines":[{"line_type":"capture","provider_reference_id":"PAY-001","amount_minor":10000,"currency_code":"BWP","direction":"credit","settled_at":"2026-10-02T10:00:00Z"}]
  }'
# Expected: 202 { "data": { "status": "pending_match", "source_hash": "<64-char-hex>" } }

# 2. Re-submit same payload
curl -s -X POST http://localhost:8080/api/v1/payment/provider/statement-callback \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: test-stmt-001b" \
  -H "X-Provider-Webhook-Secret: $PROVIDER_SECRET" \
  -d '{ ... same payload ... }'
# Expected: 409 { "message": "...", "existing_statement_id": "<uuid>" }
```

**Pass criteria**: Second request returns 409; exactly 1 row in `provider_statements` with that source_hash.

---

## SC-002: Statement lines matched against platform payment attempts

**Story**: US2 Statement Line Matching — capture lines matched to payment_attempts

**Prerequisites**: At least 1 succeeded `payment_attempt` with a known `provider_reference_id` in the database.

**Steps**:
```bash
# 1. Ingest statement with a capture line matching an existing payment_attempt
ATTEMPT_REF=$(docker exec api php artisan tinker --execute="echo \App\Modules\Payment\Infrastructure\Models\PaymentAttemptModel::where('status','succeeded')->first()->provider_reference_id;")

curl -s -X POST http://localhost:8080/api/v1/payment/provider/statement-callback \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: test-stmt-002" \
  -H "X-Provider-Webhook-Secret: $PROVIDER_SECRET" \
  -d "{...lines:[{line_type:capture,provider_reference_id:\"$ATTEMPT_REF\",...}]...}"
# Expected: 202 with statement_id

# 2. Wait for StatementMatchingJob to complete (or dispatch manually)
docker exec api php artisan queue:work --once

# 3. Check line match status
STMT_ID="<from step 1>"
curl -s http://localhost:8080/api/v1/admin/payment/provider-statements/$STMT_ID \
  -H "Authorization: Bearer $ADMIN_TOKEN"
# Expected: line.match_status = "matched"; line.matched_entity_type = "payment_attempt"
```

**Pass criteria**: Capture line `match_status = matched`; `matched_entity_id` equals the PaymentAttempt ID; a `ClearingRecord` row exists for the attempt.

---

## SC-003: Unmatched capture line creates OrphanProvider exception

**Story**: US2 Statement Line Matching — exception creation for unmatched lines

**Steps**:
```bash
# Ingest with a capture line whose provider_reference_id has no matching payment_attempt
curl -s -X POST http://localhost:8080/api/v1/payment/provider/statement-callback \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: test-stmt-003" \
  -H "X-Provider-Webhook-Secret: $PROVIDER_SECRET" \
  -d '{"lines":[{"line_type":"capture","provider_reference_id":"ORPHAN-REF-999",...}],...}'

docker exec api php artisan queue:work --once

# Verify exception was raised
docker exec api php artisan tinker --execute="\$stmt = \App\Modules\Payment\Infrastructure\Models\ProviderStatementModel::where('source_hash','<hash>')->first(); echo \$stmt->lines()->where('match_status','exception_raised')->count();"
# Expected: 1

# Verify ReconciliationException created with type = orphan_provider
docker exec api php artisan tinker --execute="echo \App\Modules\Payment\Infrastructure\Models\ReconciliationExceptionModel::where('type','orphan_provider')->latest()->first()?->type;"
# Expected: orphan_provider
```

**Pass criteria**: Line `match_status = exception_raised`; `ReconciliationException` row with `type = orphan_provider` exists; `owner` field populated.

---

## SC-004: Fee line with no capture creates OrphanFee exception

**Story**: US2 Statement Line Matching — new OrphanFee exception type

**Steps**:
```bash
# Ingest with a fee line whose provider_reference_id has no matching capture attempt
curl -s -X POST http://localhost:8080/api/v1/payment/provider/statement-callback \
  -H "..." \
  -d '{"lines":[{"line_type":"fee","provider_reference_id":"FEE-ORPHAN-001",...}],...}'

docker exec api php artisan queue:work --once

docker exec api php artisan tinker --execute="echo \App\Modules\Payment\Infrastructure\Models\ReconciliationExceptionModel::where('type','orphan_fee')->latest()->first()?->type;"
# Expected: orphan_fee
```

**Pass criteria**: `ReconciliationException` with `type = orphan_fee`; DB CHECK constraint does not reject the insert.

---

## SC-005: Wallet internal transfer line excluded from clearing

**Story**: US3 Capture Clearing vs Provider Settlement — internal transfers excluded

**Steps**:
```bash
# Ingest with a line of type internal_transfer
curl -s -X POST http://localhost:8080/api/v1/payment/provider/statement-callback \
  -H "..." \
  -d '{"lines":[{"line_type":"internal_transfer","provider_reference_id":"INT-001",...}],...}'

docker exec api php artisan queue:work --once

# Verify no ClearingRecord was created for this line
docker exec api php artisan tinker --execute="echo \App\Modules\Payment\Infrastructure\Models\ClearingRecordModel::count();"
# Expected: 0 (if only this statement was ingested)

# Verify line match_status = internal_transfer (not matched, not exception_raised)
```

**Pass criteria**: No `ClearingRecord` for `internal_transfer` line; line `match_status = internal_transfer`.

---

## SC-006: Correction request requires dual approval — self-approval rejected

**Story**: US4 Exception Management and Correction Approval — dual-approval guard

**Prerequisites**: A `ReconciliationException` exists in the database (created from SC-003 or manually seeded).

**Steps**:
```bash
EXCEPTION_ID="<id from SC-003>"

# 1. Submit correction request as admin user A
curl -s -X POST http://localhost:8080/api/v1/admin/payment/reconciliation-corrections \
  -H "Authorization: Bearer $ADMIN_TOKEN_USER_A" \
  -H "Idempotency-Key: test-corr-006" \
  -H "Content-Type: application/json" \
  -d "{\"exception_id\":\"$EXCEPTION_ID\",\"correction_type\":\"amount_mismatch_correction\",\"description\":\"Provider confirmed BWP 5 discrepancy\",\"amount_minor\":500,\"currency_code\":\"BWP\",\"evidence_reference\":\"https://evidence.example/doc-001\"}"
# Expected: 201 { "data": { "status": "pending_approval" } }

CORRECTION_ID="<id from response>"

# 2. Try to approve as the same user (self-approval)
curl -s -X POST http://localhost:8080/api/v1/admin/payment/reconciliation-corrections/$CORRECTION_ID/approve \
  -H "Authorization: Bearer $ADMIN_TOKEN_USER_A" \
  -H "Idempotency-Key: test-corr-006-approve"
# Expected: 403 Forbidden — self-approval not permitted

# 3. Approve as a different user (user B with payment.reconciliation.approve_correction permission)
curl -s -X POST http://localhost:8080/api/v1/admin/payment/reconciliation-corrections/$CORRECTION_ID/approve \
  -H "Authorization: Bearer $ADMIN_TOKEN_USER_B" \
  -H "Idempotency-Key: test-corr-006-approve-b"
# Expected: 200 { "data": { "status": "posted", "ledger_entry_id": "..." } }
```

**Pass criteria**: Self-approval returns 403; second actor approval returns 200; `correction_requests.status = posted`; `ledger_entry_id` populated.

---

## SC-007: Payout qualification publishes correct net_qualified_minor

**Story**: US5 Payout Qualification and Totals Publishing

**Prerequisites**: A matched statement with several `qualified` clearing records and at least one open `DisputeHold`.

**Steps**:
```bash
STMT_ID="<matched statement id>"

# 1. Trigger qualification
curl -s -X POST http://localhost:8080/api/v1/admin/payment/provider-statements/$STMT_ID/qualify \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "Idempotency-Key: test-qual-007"
# Expected: 202

docker exec api php artisan queue:work --once

# 2. Verify outbox event published
docker exec api php artisan tinker --execute="echo \App\Modules\Platform\Infrastructure\Models\OutboxEventModel::where('event_name','payment.reconciliation.payout_qualified')->where('payload->statement_id','$STMT_ID')->latest()->first()?->payload;"
# Expected: JSON with gross_cleared_minor, net_qualified_minor (< gross_cleared), dispute_hold_deductions_minor > 0
```

**Pass criteria**: `PayoutQualifiedEvent` published to outbox; `net_qualified_minor = gross_cleared - fee_deductions - dispute_hold_deductions`; `net_qualified_minor >= 0`; `excluded_amount_minor = 0` for airtel_momo (no AD-08/09 impact).

---

## SC-008: Ageing check raises partial_settlement exception

**Story**: US3 — settlement ageing

**Prerequisites**: A `ClearingRecord` with `bank_settled_at = NULL` and `cleared_at` older than the configured ageing window. Can be created by manipulating `cleared_at` in a test fixture.

**Steps**:
```bash
# 1. Create a clearing record with cleared_at = 30 days ago (past any ageing window)
docker exec api php artisan tinker --execute="
  \$cr = \App\Modules\Payment\Infrastructure\Models\ClearingRecordModel::factory()->create(['cleared_at' => now()->subDays(30), 'bank_settled_at' => null, 'aged_at' => null]);
  echo \$cr->id;
"
CR_ID="<from above>"

# 2. Run the ageing check job
docker exec api php artisan schedule:run
# (or) docker exec api php artisan tinker --execute="dispatch(new \App\Modules\Payment\Application\Jobs\SettlementAgeingCheckJob());"

docker exec api php artisan queue:work --once

# 3. Verify exception raised and clearing record updated
docker exec api php artisan tinker --execute="
  \$cr = \App\Modules\Payment\Infrastructure\Models\ClearingRecordModel::find('$CR_ID');
  echo \$cr->aged_at;
"
# Expected: non-null timestamp

docker exec api php artisan tinker --execute="echo \App\Modules\Payment\Infrastructure\Models\ReconciliationExceptionModel::where('type','partial_settlement')->where('payload->clearing_record_id','$CR_ID')->exists();"
# Expected: 1 (true)
```

**Pass criteria**: `ClearingRecord.aged_at` set; `ReconciliationException` with `type = partial_settlement` exists; outbox event `payment.reconciliation.ageing_exception_raised` published.

---

## Notes

- T-prefix task numbers referenced in `tasks.md` (generated by `/speckit-tasks`).
- All scenarios tagged `@group docker` in feature tests — skipped in non-Docker CI.
- Admin Token User A vs User B: use seeded admin accounts or create with `php artisan tinker`.
- `PROVIDER_SECRET` webhook secret: set in `config/payment.php` under `providers.airtel_momo.webhook_secret`.
