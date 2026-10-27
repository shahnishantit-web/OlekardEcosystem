# P10 completion evidence

**Current checkpoint:** [prerequisite-closure.md](prerequisite-closure.md) supersedes
the continuation findings below. All 442 final backend tests pass (1081 assertions,
0 fail/error/skip), 45-file PHPStan passes including entire orchestration, real
submission/crash and ledger/outbox gates pass, and audited local package schema
baseline verifies “Nothing to migrate.” Live processing remains disabled. Full
P10 remains PARTIAL pending actual provider/policy/release evidence.

**Latest continuation:** [readiness.md](readiness.md) records the local prerequisite
repairs, migrations and current verification: 82 predecessor/recovery + 58 E25 +
57 P10 tests pass on PHP 8.4.15. Broader Payment units: 283 pass / 7 fail / 26
errors / 0 skip. Scoped 39-file PHPStan passes; whole orchestration has 5 findings.
Earlier counts below are retained historical evidence, not current gate results.

Date: 2026-10-22. **Status: PARTIAL.** Independent development framework is
implemented; real financial/provider activation remains BLOCKED. Specification
and implementation evidence are available; certification and release are not.

## Source and prerequisite verification

Source v1.1 and SHA256 verified at the actual Downloads path; full text and prompt
pack retained with provenance in [sources](../../docs/sources/README.md). No
applicable AGENTS.md exists in repository/ancestors inspected. Constitution,
CLAUDE.md, E25, E48 and P05/P06/P07 artifacts/code were inspected. Installed
specify/plan/tasks/implement/analyze skill instructions and actual PowerShell
scripts were used; no guessed slash commands or workflow version claims.
`create-new-feature`, `setup-plan`, `setup-tasks` and `check-prerequisites` resolved
this package. No extensions.yml or package checklists required an approval gate.
Requirement/story/task consistency was checked against FR01–FR10; blocked work
remains unchecked. No unrelated reviewer checklist was altered.

| Predecessor | Contracts inspected | Actual readiness |
|---|---|---|
| P05 / 054 | EligibilityPipeline, ProviderRegistryService, provider/account models, allowlist and versioned configuration | Present; test failures include final-class mocks and configuration resolution. Checked tasks do not prove readiness. |
| P06 / 055 | durable attempts/operation IDs, UNKNOWN state, handleOutcome locks and contradictory/excess-capture handling | Present; PurposeNotPayableException calls have incompatible arguments; end-to-end readiness not established. |
| P07 / 056 | PaymentIdempotencyService/repository, recovery jobs, deadlock/replay tests | Present; recovery/deadlock/idempotency evidence has test/runtime errors and pending migrations. Not certified. |

Fresh prerequisite subset: **66 tests: 39 pass, 2 fail, 25 error, 0 skip**;
1 PHPUnit deprecation. Error types: 14 final-class mock errors, 5 invalid mock
method configurations, 2 runtime exceptions, 2 container binding errors, 1 Error,
1 mock runtime error. See [JUnit](predecessor-results.xml) and
[log](predecessor-tests.log). Actual migration status also showed P02–P08 additions
pending. Only P10's additive receipt migration was applied locally.

## Implemented scope and changes

- Payments-owned separate initiate/query/refund/optional payout contracts, preserving
  the existing composite. Execution/refund/recovery resolve only the required
  capability; missing query support leaves the attempt unresolved for manual
  resolution. Payout has an independent outcome type; no payout API.
- Account-scoped development parser uses exact raw HMAC bytes and the installed
  foundation SecretProviderInterface. E25 owns durable verified receipt, encrypted
  evidence, scoped uniqueness/advisory lock, collision checks and transactional
  recovery delivery intent. No bank HTTP endpoint or credentials invented.
- Payments validates mapping, merchant, reference and integer money before the
  existing outcome processor. E25 owns atomic quarantine/status/outbox changes.
- Canonical `POST /v1/webhooks/{slug}` retained; legacy
  `POST /api/v1/payment/callbacks/{providerCode}` retained as E25 facade with
  explicit unique mapping. Unsafe legacy queued rows go to `dead_lettered` with
  a quarantine reason. They require verified re-delivery rather than blind replay.
- Existing retry API retained; added audited, authenticated
  `POST /v1/admin/webhooks/deliveries/{id}/quarantine`. Existing admin monitor now
  displays quarantine/test-only labels and action. OpenAPI files updated.
- Migration `2026_10_22_000001_add_verified_webhook_receipts.php`: adds three nullable
  receipt columns and partial unique index; no financial/balance migration.
- Strict mock environment guard at construction and operation time, production
  activation guard and discovery exclusion. Success/failed/pending/unknown/timeout
  scenario controls; test-only metadata and labels. Old mock test bootstrap and
  missing constructor dependency repaired; previously skipped production test runs.
- Supporting model PHPDocs/enum assignments/paginator typing corrected for scoped
  static analysis; no new ignore/suppression rules or dependency upgrades.
- OpenAPI YAML validation exposed an existing duplicate `components` block in
  integration.yaml. Merged its response definitions into the original block and
  corrected the documented E25 server prefix to the actual `/v1` routes.

## Source to story task code and test traceability

Code links below are API paths under the existing shared module; tests are real
test files, not checkbox evidence. Source coordinates refer to retained v1.1 text.

| Requirement/source | Story/tasks | Code | Test evidence |
|---|---|---|---|
| FR01–02; section 1 / FD06:124 | US1 T003; US2 T005 | [contracts](../../wallet-ecosystem/services/api/app/Modules/Payment/Domain/Contracts/PaymentAdapterInterface.php), [capabilities](../../wallet-ecosystem/services/api/app/Modules/Payment/Domain/ValueObjects/PaymentCapabilities.php), [factory](../../wallet-ecosystem/services/api/app/Modules/Payment/Infrastructure/Adapters/PaymentWebhookFactory.php) | [contract tests](../../wallet-ecosystem/services/api/tests/Unit/Modules/Payment/P10CallbackContractTest.php): existing composite and explicit unsupported payout/operations |
| FR03; FD06, LC01:359, LC05:404 | US2 T005–07 | [account parser](../../wallet-ecosystem/services/api/app/Modules/Payment/Infrastructure/Adapters/AccountMockWebhookParser.php), [E25 registry](../../wallet-ecosystem/services/api/app/Modules/Integration/Infrastructure/Providers/IntegrationRegistry.php) | contract raw-byte/signature/identity tests; [ingress tests](../../wallet-ecosystem/services/api/tests/Feature/Modules/Payment/P10WebhookIngressTest.php) signature rejection, inactive resolution and legacy facade |
| FR04–05; FD06 / LC05 | US2 T006–07 | [receipt service](../../wallet-ecosystem/services/api/app/Modules/Integration/Application/Services/WebhookApplicationService.php), [migration](../../wallet-ecosystem/services/api/app/Modules/Integration/Infrastructure/Migrations/2026_10_22_000001_add_verified_webhook_receipts.php) | ingress durability/storage failure/queue outage-recovery/collision/alias dedup; [two-process PostgreSQL race](../../wallet-ecosystem/services/api/tests/Feature/Modules/Payment/P10ConcurrentReceiptTest.php) |
| FR06,09; LC03:381 / LC05 | US3 T008,10–11 | [validator](../../wallet-ecosystem/services/api/app/Modules/Payment/Application/Services/PaymentNotificationValidator.php), [consumer](../../wallet-ecosystem/services/api/app/Modules/Payment/Application/Services/PaymentWebhookHandler.php), [existing orchestration](../../wallet-ecosystem/services/api/app/Modules/Payment/Application/PaymentOrchestrationService.php) | ingress money/currency/merchant/account-A-to-account-B rejection and pending/unknown quarantine; matched receipt delivered once to outcome interface; full financial proof BLOCKED by P06/P07 |
| FR07; FD06 / LC05 | US2 T006; US3 T009–10 | [receipt model](../../wallet-ecosystem/services/api/app/Modules/Integration/Infrastructure/Models/WebhookEventModel.php), [E25 processing](../../wallet-ecosystem/services/api/app/Modules/Integration/Infrastructure/Jobs/ProcessWebhookEventJob.php), [admin](../../wallet-ecosystem/services/api/app/Modules/Integration/Application/Services/AdminIntegrationService.php) | encrypted body differs from plaintext, hidden serialization, no effects on mismatch; auth-required quarantine/retry; retention/break-glass approval pending |
| FR08; section 8:558 / LC01 | US1 T004, US3 T010,12 | [guard](../../wallet-ecosystem/services/api/app/Modules/Payment/Infrastructure/Adapters/DevelopmentPaymentGuard.php), [mock](../../wallet-ecosystem/services/api/app/Modules/Payment/Infrastructure/Adapters/MockBankPaymentAdapter.php), [activation guard](../../wallet-ecosystem/services/api/app/Modules/Payment/Infrastructure/Models/PaymentProviderModel.php) | operation-time guard tests, parser guard, activation rejection, explicit mock outcomes; [legacy mock tests](../../wallet-ecosystem/services/api/tests/Unit/Modules/Payment/Adapters/MockBankPaymentAdapterTest.php) no production skip |
| FR10; LC01 / LC03 / LC05 | US3 T009; T013–14 | [admin monitor](../../wallet-ecosystem/apps/admin/src/modules/integrations/pages/webhook-monitor.tsx), [boundary decision](../../docs/architecture/decisions/ADR-E25-P10-payment-callback-boundary.md), [runbook](quickstart.md) | [admin tests](../../wallet-ecosystem/apps/admin/__tests__/features/integrations/p1020.test.tsx): labels/quarantine/retry and permission gate |

## Tests actually run

| Run | Pass | Fail | Error | Skip | Evidence |
|---|---:|---:|---:|---:|---|
| Final focused backend: P10 contracts/ingress/real race, repaired mock suite and E25 receive/retry/sequential dedup/admin | 77 | 0 | 0 | 0 | [final-results.xml](final-results.xml), [log](final-tests.log); 257 assertions |
| Admin integration Jest | 7 | 0 | 0 | 0 | [JSON](admin-tests.json); includes new test-only/quarantine cases |
| Prerequisite unit subset | 39 | 2 | 25 | 0 | [JUnit](predecessor-results.xml) |
| Broader E25 + then-current P10 run, retained diagnostic snapshot | 80 | 13 | 0 | 0 | [JUnit](p10-e25-results.xml), [log](p10-e25-tests.log); quickstart/rate-limit legacy tests fail |

Focused backend command from services/api:

```powershell
php -d xdebug.mode=off vendor/phpunit/phpunit/phpunit --no-coverage tests/Unit/Modules/Payment/P10CallbackContractTest.php tests/Unit/Modules/Payment/Adapters/MockBankPaymentAdapterTest.php tests/Feature/Modules/Payment/P10WebhookIngressTest.php tests/Feature/Modules/Payment/P10ConcurrentReceiptTest.php tests/Feature/Modules/Integration/WebhookReceiveTest.php tests/Feature/Modules/Integration/WebhookRetryTest.php tests/Feature/Modules/Integration/WebhookConcurrencyTest.php tests/Feature/Modules/Integration/AdminIntegrationTest.php
```

Admin: `node node_modules/jest/bin/jest.js --runInBand --no-coverage __tests__/features/integrations/p1020.test.tsx`.
`node node_modules/typescript/bin/tsc --noEmit --pretty false`: PASS, empty
[log](typescript.log). PHPStan level 8 using existing config and the 30-file
[manifest](phpstan-files.txt): PASS, [log](phpstan-final.log). Existing tool version
and deprecated-option notices remain; no ignore rules added. All three updated
OpenAPI files parse with installed js-yaml. The four changed orchestration/refund/
recovery call sites also passed PHP syntax checks; full predecessor readiness is
not implied by the 30-file PHPStan scope. No full admin build,
Flutter build or production tests claimed. Earlier intermediate failed test/static
attempts are retained; final results supersede those specific failures.

The broad failing tests include stale `/api/v1/integrations/...` assumptions where
registered E25 routes use `/v1/...`, legacy quickstart assumptions, and rate-limit
expectations. Those route definitions were not changed by P10. The broader test
debt is reported rather than hidden; it is not a passing release suite.

## Security financial evidence and DoD

| Criterion | Result | Evidence / limitation |
|---|---|---|
| Actual source/version and prerequisite checks | PASS | Provenance, fresh JUnit and pending migration inspection |
| Shared Payments/E25/Wallet/Ledger boundaries | PASS | Boundary decision; existing outcome engine retained with interface conformance and capability-specific adapter resolution; no direct balance or journal writes |
| Independent capability contracts / explicit unsupported payout | PASS | Contract tests; no payout implementation implied |
| Invalid callbacks cannot mutate money | PASS | Invalid signature test expects zero processor calls and zero receipts |
| Duplicate/account-mismatched receipt safety | PASS for P10 ingress | Real race one receipt/intent; alias dedup; mismatch zero processor calls. Full operation-level financial proof remains a predecessor gate |
| Durable ACK and queue recovery | PASS | Receipt/outbox/delivery intent transaction; storage failure and restored-dispatch tests |
| Restricted evidence / audit / recovery routes | PASS for implemented controls | Encryption, hidden resources, validated quarantine, audited admin retry; duration and break-glass policy pending |
| Mock visibly test-only; production activation/execution denied | PASS | No skipped guard test; constructor, operation, parser and activation tests; admin labels |
| Independent financial lifecycle states preserved | PASS | No collapsing/migration of accounting, fulfilment, refund, settlement or payout state |
| In-scope targeted tests and static checks | PASS | 77 backend + 7 UI; TypeScript/PHPStan |
| All prerequisite and broad regression gates green | FAIL | Selected repairs and local migrations pass; broader Payment has 7 failures + 26 errors and whole orchestration has 5 static findings. See readiness.md |
| Real provider contracts/transport/certification and operating evidence | FAIL / BLOCKED | No Bank 1/2/3 contracts/credentials or certification; no invented bank API |
| Approved evidence retention/rotation/break-glass and live release | FAIL / BLOCKED | Security/Finance policy and explicit release authorization outstanding |

Overall DoD: **FAIL for complete/live P10 readiness; PASS for independently
implemented development framework.** This is a PARTIAL package, not a production
release. No charge, ledger posting or entitlement was claimed as externally
certified. Matched handoff tests use an explicit outcome-interface spy, clearly
separate from real financial end-to-end evidence.

## Next permitted action

Review/fix P05/P06/P07 prerequisite failures and migration rollout in this package's
dependency gate; re-run crash/concurrency/ledger evidence on the approved PHP
baseline. Obtain real provider contracts and Security/Finance policy before
installing/activating a live adapter. No automatic P11 or production deployment.
See [restart notes](restart.md) for exact branch/commit and unresolved operations.
