<!-- Latest checkpoint: prerequisite-closure.md -->
> Local prerequisite remediation is complete: 442 passing backend tests, 45-file PHPStan, real submission/crash and ledger/outbox verification, and audited package-only batch-0 schema adoption. Live processing remains disabled. See [prerequisite closure](prerequisite-closure.md). Earlier failed findings and operations below are retained history.

# P10 local readiness continuation — 22 October 2026

Status **PARTIAL**. This supplements and supersedes the earlier local prerequisite
and E25 failure counts in evidence.md; it does not certify live financial processing.
Branch `main`, base commit `a72ca74252e4ecb42e08b7a9db398491d04f4778`.
All changes remain uncommitted alongside existing predecessor work.

## Scope and traceability

Source remains the hash-verified architecture v1.1 recorded in [spec.md](spec.md).
FD06/LC01/LC03/LC05 → US2/US3 → T015–T018 → the following changes and evidence:

| Requirement | Code under services/api | Evidence |
|---|---|---|
| Unknown is neither failure nor success; preserve original operation | [orchestration](../../wallet-ecosystem/services/api/app/Modules/Payment/Application/PaymentOrchestrationService.php), [single recovery](../../wallet-ecosystem/services/api/app/Modules/Payment/Application/Jobs/PaymentAttemptSingleRecoveryJob.php), [timeout](../../wallet-ecosystem/services/api/app/Modules/Payment/Application/Jobs/PaymentAttemptTimeoutJob.php) | PersistBeforeExecuteTest, UnsafeProviderSwitchTest, TimeoutToUnknownTest, CrashRecoveryJobTest; real PostgreSQL [P10ReadinessTest](../../wallet-ecosystem/services/api/tests/Feature/Modules/Payment/P10ReadinessTest.php) |
| No duplicate operation across recovery/cache failures | [idempotency service](../../wallet-ecosystem/services/api/app/Modules/Payment/Application/Services/PaymentIdempotencyService.php) | IdempotencyServiceTest: cache outage, commit/read/lock race, stale lock owner, canonical hashes; PostgreSQL durable replay/unique record |
| Durable independent financial states | [constraint migration](../../wallet-ecosystem/services/api/app/Modules/Payment/Infrastructure/Migrations/2026_10_22_000002_align_payment_state_constraints.php) | Actual PostgreSQL submitting → unknown persistence, [runtime inventory](runtime-readiness.json), targeted migration log |
| Existing E25 security and retry compatibility | ReceiveWebhookController; DualRateLimitMiddleware; IntegrationQuickstartTest; RateLimitTest | [E25 JUnit](e25-readiness-results.xml), authenticated middleware test route, signed raw-body requests, durable retry intent dispatched through existing recovery job |

Adapter exceptions now preserve SUBMITTING and the processing intent for the
existing crash scanner, rather than asserting failure/resetting the intent.
Submission rechecks active/unknown attempts under an intent row lock inside the
payment connection transaction. Adapter execution stays outside that transaction.
The adapter-result update also locks the attempt and cannot overwrite an outcome
that advanced concurrently. Original-key replay remains available. New-key
same-provider initiation is blocked while the original result is UNKNOWN.

Recovery and timeout use PaymentOutcomeProcessor instead of depending directly
on the final orchestration class. UNKNOWN persistence and reconciliation evidence
share the payment transaction with a row lock. UNKNOWN retains the hold and the
original operation; repeated recovery does not create a second exception.

Idempotency rechecks the durable record after acquiring its lock, replays durable
records during cache read/write outages, and releases only its owned lease.
Hashes ignore object-key ordering but preserve list order and string whitespace.
New execution still fails closed if no usable lock infrastructure exists.

No new UI or provider endpoint was added in this continuation. Prior P10 admin
quarantine/test-only controls remain. E25 successful responses now expose quota
headers; invalid signatures retain INTEGRATION_WEBHOOK_001/401 via the existing
exception handler. Tests no longer flush the application Redis database: isolated
test IPs/client UUIDs use the real Lua limiter with its existing 61-second expiry.

## Local schema operation

Read-only preflight confirmed APP_ENV=local, database host 127.0.0.1, and zero
payment intents/attempts/refunds. P06 operation_id migration applied successfully.
An attempted module-directory migration then stopped at the already-existing
payment_providers table. See [initial failure log](readiness-migrations.log).
The platform migration repository lacks historic payment-table migration entries;
no history was fabricated, no existing table dropped, and no blanket retry used.
The IF NOT EXISTS payment-schema migration was recorded before that failure.

After reviewing each file, only the twelve 2026_10_21 Payment module migrations
and the new 2026_10_22 constraint migration were applied individually: **13 DONE**.
[Targeted log](readiness-migrations-targeted.log). These add predecessor state,
version/quote/approval/allocation/idempotency/repair fields and tables; no historic
financial row was backfilled. The idempotency migration now declares its payment
connection, matching its actual DDL transaction. The new check migration adds
submitting/unknown, rejected refunds and reconciliation types without collapsing
accounting, fulfilment, settlement or payout state. Its down path refuses to
narrow constraints while newer state rows remain. Reviewed destructive down
migrations were not run. Production rollout needs a separate schema/history audit.

## Verification actually run

PHP **8.4.15**, enabled PostgreSQL extensions per command, no dependency upgrades
or global php.ini changes. Default local PHP/server runtime was not changed.

| Final run | Pass | Fail | Error | Skip | Evidence |
|---|---:|---:|---:|---:|---|
| Repaired predecessor subset + new DB checks | 82 | 0 | 0 | 0 | [JUnit](readiness-results.xml), [log](readiness-tests.log); 210 assertions |
| Full affected E25 feature directory | 58 | 0 | 0 | 0 | [JUnit](e25-readiness-results.xml), [log](e25-readiness-tests.log); 233 assertions |
| P10 contracts/mock/ingress/two-process receipt race | 57 | 0 | 0 | 0 | [JUnit](p10-readiness-results.xml), [log](p10-readiness-tests.log); 162 assertions |
| Broader Payment module unit directory, diagnostic | 283 | 7 | 26 | 0 | [JUnit](payment-unit-readiness-results.xml), [log](payment-unit-readiness-tests.log); 316 total, 538 assertions |
| Scoped PHPStan, 39 files | PASS | — | — | — | [manifest](phpstan-readiness-files.txt), [serial log](phpstan-readiness-serial.log) |
| Additional entire orchestration PHPStan check | — | 5 findings | — | — | [log](phpstan-orchestration-readiness.log) |

The first three test rows are disjoint: **197 passing tests**. The broad diagnostic
overlaps predecessor tests and must not be added to that count. PHPUnit reports
one existing deprecation for the affected suites; PHPStan reports its existing
tool/config deprecations. No suppressions or dependency upgrades were added.
Earlier failed test/static snapshots remain diagnostic history, not final passes.
The two-process race initially failed because child PHP did not inherit per-command
PostgreSQL extensions; the Windows worker launcher now supplies those extensions
and the final race passed. Prior admin Jest 7-pass and TypeScript results remain
in evidence.md; no admin source changed during this continuation.

Example from services/api (each command also saves logs/JUnit as linked above):

```powershell
& 'C:/wamp64/bin/php/php8.4.15/php.exe' -d xdebug.mode=off -d extension=php_pdo_pgsql.dll -d extension=php_pgsql.dll vendor/phpunit/phpunit/phpunit --no-coverage tests/Feature/Modules/Integration
```

## Decisions, blockers and technical debt

1. **Safety decision P10-R1:** P06 FR-07 permits fresh-key same-provider retry
   while UNKNOWN; its story says “after reconciliation.” Without verified
   non-execution or a reviewed provider deduplication guarantee, a new operation
   can charge twice. The user's no-duplicate-charge instruction and v1.1 unknown
   outcome rule take precedence: fail closed for a fresh operation; keep original
   key replay/query. Architecture review must reconcile P06 wording before release.
2. Broader unit failures remain in callback-controller legacy tests, enriched
   obligation fixtures, org-scope/config-draft/optimistic-lock/quote fixtures,
   permission-seed tests, refund approval and step-up fixtures. Many depend on
   unbootstrapped facades or mocking final classes. They are not waived. P04/P15
   implementation packages were not started. See JUnit for exact cases.
3. Whole orchestration static analysis has five findings: three overly narrow
   createIntent return shapes; nullable provider-account assignment versus the
   non-null model/schema; PaymentCircuitOpenEvent not satisfying EventPublisher's
   integration-event contract. The 39-file P10/recovery scope passes; these broader
   findings remain a prerequisite/release gate, not a clean whole-module claim.
4. Real concurrent submission/crash/ledger end-to-end evidence remains incomplete.
   The real receipt race proves receipt/delivery-intent deduplication, not
   exactly-once provider charging or ledger/fulfilment completion. New guard tests
   exercise the post-lock race branch; they do not substitute for that live gate.
5. Historic migration bookkeeping differs from actual schema. Reconcile schema
   ownership/history before any blanket migrate, rollback, or environment rollout.
6. Real provider contracts, identity, endpoints, sandbox credentials, transport,
   certification, approved retention/rotation/break-glass and production release
   authorization remain absent. No Bank 1/2/3 protocol or evidence was invented.

## DoD and next permitted action

| Criterion | Result |
|---|---|
| Verified v1.1 source / predecessor inspection / traceability | PASS |
| Invalid callback causes no financial processing | PASS in P10 tests |
| Duplicate/account-mismatched receipt safety | PASS in P10 tests including real race |
| Durable acknowledgement, retry/quarantine, restricted evidence | PASS for implemented controls |
| Mock labels and production denial | PASS |
| UNKNOWN safety, original operation/hold, owner-scoped lock | PASS for repaired paths/tests |
| Separate financial lifecycle states preserved | PASS |
| Local targeted migrations and selected verification | PASS |
| All prerequisite unit/static/concurrent financial gates green | FAIL |
| Real-provider/security-policy/certification/live-release evidence | FAIL / BLOCKED |

Overall full-P10 DoD **FAIL**, independent development framework **PASS**.
Continue prerequisite fixes and real concurrent financial verification within this
package; obtain approved provider/security contracts before live adapter work.
Keep live_callback_processing_enabled disabled. Do not start P11 or deploy.
