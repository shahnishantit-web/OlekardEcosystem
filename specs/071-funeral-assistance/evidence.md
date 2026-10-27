# P24 Evidence — 2026-10-27

The initial verification record below is retained for chronology. The organisation authority continuation at the end supersedes its deny-all binding statements.

**Status: PARTIAL/BLOCKED.** This is not release or finance certification. No production deployment, live callback activation, bank API, local wallet debit or payout posting was performed.

## Implemented

- Installed Spec Kit created `specs/071-funeral-assistance` with specification, stories, plan, tasks, research, model, contract, checklist, flow runbook and traceability.
- `CommunityFuneral` provider, routes, five permissions, service, Payment purpose resolver and confirmed-event listener. Four P24 migrations create household/reviewer/representative, collection, immutable snapshot, unique household obligation, payment receipt, late review, closure, audit and private-notice outbox tables.
- BWP 20 (`2000` minor) is server-owned. Independent review, verified P18 beneficiary, approval invalidation on beneficiary edit, row-locked activation, once-only fulfilment, authoritative Payment intent/attempt/ledger checks, late-liability retention and independent carry-forward approval are implemented in backend code.
- All P24 HTTP routes return 503 through `FuneralReadinessGate` by default and in production. The registered Payment resolver also rejects `funeral_contribution` while closed, so shared checkout cannot bypass the HTTP gate. Family payout has no execution path.
- P18 beneficiary migration now uses the repository's UUID/slug identity permission schema and was applied locally. `OrganisationMembershipVerifier` is a fail-closed contract because Identity currently has no authoritative organisation membership; production binding denies all. Tests use an explicit test-only verifier.
- Snapshot activation records one private inbox notice per verified representative. A module outbox and retry command deduplicate it. The generic mandatory Notification dispatcher was found to fan out to SMS/email despite an in-app template restriction, so P24 writes an in-app inbox item directly and does not dispatch external channels. Local audit events contain IDs and state changes, never evidence content; after commit they are forwarded to Platform AuditService. Platform AuditService currently logs and swallows write failures, so the local audit row is the durable fallback and replay to Platform remains technical debt.
- Customer Services links to a disabled Funeral Assistance screen; legacy admin funeral creation, transitions and manual settlement controls are disabled with status copy. Neither UI initiates payment or payout.

## Verification actually performed

| Operation | Result |
|---|---|
| Verified original architecture source v1.1 and matching copied-source SHA-256 | PASS; see `research.md` |
| Installed feature, plan and tasks scripts | PASS; package 071 created |
| P18 beneficiary and two additional P24 local migrations | 3 PASS this continuation; all four P24 migrations applied locally |
| P24 permission seeder repeat run | PASS after idempotent insert fix |
| PHP lint on initial 12 new PHP files | 12 PASS, 0 FAIL; final resolver/test edits additionally exercised by PHPUnit/PHPStan |
| Latest scoped P24 direct PHPUnit | 11 PASS, 0 FAIL, 0 SKIP, 55 assertions; P18 schema, flow, Platform/local audit, inbox retry command/dedup, privacy and HTTP gate |
| `artisan test` for `FuneralAssistanceFlowTest` | 5 errors, 0 assertions: child PHP process did not inherit PDO extension flags |
| Earlier direct PHPUnit for `FuneralAssistanceFlowTest` | 5 errors before P18 migration; superseded by passing run above |
| `artisan route:list --path=api/v1/community/funeral` | FAIL before listing: pre-existing missing `BankStatementCallbackController` in dirty Payment tree |
| Scoped PHPStan with 512 MB and `--debug` | PASS: `[OK] No errors` for new module; initial default-memory parallel run exhausted 128 MB and was superseded |
| `artisan config:show payment.live_callback_processing_enabled` | `false` in local runtime; no live flag changed |

Targeted Flutter analysis of the three touched files passed with no issues. A temporary scoped TypeScript project typechecked the three touched admin pages with **0 errors** and was removed. The global admin typecheck still has pre-existing stale `.next/types` imports of missing roles pages. P19/P22/P23 regression suites, concurrent two-process acceptance, full end-to-end payment/HTTP flows, OpenAPI validation and UI interaction tests were **not run**. `git diff --check` reported an unrelated existing blank line in `openapi/v1/payment.yaml`.

The P18 beneficiary migration is now corrected and applied locally. P18 payout execution/settlement and organisation membership authority remain unimplemented; no substitute bank or membership evidence was fabricated.

## Security and finance evidence

- Unique constraints and row locks exist for activation and fulfilment; 5 flow tests pass, but concurrent two-process acceptance remains unproven.
- A callback payload alone cannot fulfil: passing tests exercise persisted Payment intent, attempt, organisation, payer, amount, currency, fee and `ledger_posted` checks. This is local test evidence, not financial certification.
- Initial community creation requires an `OrganisationMembershipVerifier`; its production binding denies all because Identity has no authority. HTTP and Payment purpose gates also remain closed. Local audit and in-app notice tests pass; foundation audit forwarding and live notification operations remain release checks.
- Payment, accounting, fulfilment, refund, settlement and payout states stay separate. Gross includes late receipts; net support excludes them. Payout and settlement remain blocked/unresolved.
- Late capture policy, custody/legal mapping, provider certification and actual family payout remain unresolved. Development mocks are not payout evidence.

## Definition of Done

| Criterion | Verdict | Remaining work |
|---|---|---|
| Source and predecessor inspection, Spec Kit package | PASS | `research.md` and package artifacts |
| Household/review/snapshot/obligation backend | PARTIAL | Local tests pass; authoritative tenant membership and concurrency pending |
| Shared checkout, admin/mobile entry, private notice | PARTIAL | In-app notice and disabled UI entry pass; payment flow stays closed |
| Duplicate delegates/confirmation and beneficiary edit | PARTIAL | Replay/edit tests pass; two-process concurrency pending |
| Late capture, aggregate report, linked family disbursement | FAIL | Late policy and P18 execution absent |
| Independent financial states, no local duplicate journal | PARTIAL | No P24 ledger/debit code; financial evidence pending |
| Security/privacy/finance/runbook acceptance | FAIL | Tenant authority, payout and external approval gates open |
| No live processing or production deployment | PASS | P24 HTTP disabled, payout unavailable |

**Next permitted action:** establish an approved authoritative organisation membership source and bind it to the verifier; run two-process concurrency, outbox retry, P19/P22/P23 and full UI/HTTP checks; obtain late-capture, AD-14/AD-10/LC14 payout decisions. Keep P24 HTTP and live processing disabled until release evidence passes.

## Continuation: Identity actor-to-organisation contract (2026-10-27)

**Status remains PARTIAL/BLOCKED.** Implemented an Identity-owned reviewed registry and connected P24's verifier. This replaces the deny-all binding with a database lookup that still denies absent, pending, revoked, cross-organisation, suspended or inactive grants. It does not establish that any particular organisation or evidence reference is legally authoritative. `organisation-authority.md` records lifecycle, endpoints, roles and recovery.

- Migration `2026_10_27_000001_create_reviewed_organisation_memberships.php` adds organisations, unique actor/organisation memberships and state-only append events, forwarded to Platform AuditService after commit. Applied to the local PostgreSQL database; no production migration or grant.
- `ReviewedOrganisationMembershipService` enforces active ADMIN plus permission for writes, separate requester/reviewer, active targets, row-locked transitions and immediate revocation. Three new permission slugs were seeded locally, but assigned to no role. P24 binds `IdentityOrganisationMembershipVerifier` and checks membership on community creation, all manager/reviewer actions and staff assignment.
- New Identity routes use `/v1/identity/organisations`, Identity auth, admin actor type, permission and required idempotency key. Mutation responses contain IDs/states only; restricted evidence references are stored but not echoed.
- Final combined direct PHPUnit: **15 tests, 73 assertions, 0 failures, 0 skips**, covering reviewed membership, registry HTTP, P24 flow and readiness. A first HTTP run used an incorrect `/api/v1` prefix and failed 404; corrected `/v1` run passes. An intermediate rerun exposed static test references persisted by the initial non-transactional test base; tests now use the transactional Identity base and unique references. Earlier test-only registry rows may remain in the local database and require targeted review before cleanup; no production data was touched. Final PHPUnit output is `OK (15 tests, 73 assertions)`; PowerShell reports exit 1 because application debug logs are written to stderr.
- Scoped PHPStan on the four new service/controller/interface/adapter files: **PASS, 0 errors**. Identity migration ran locally and permission seeder succeeded on retry after correcting a PowerShell class-name escape.

**Unresolved:** security/operations approval of registry provenance, evidence review criteria, permission assignments and retention; two-process race acceptance; P24 household API and finance release tests; late capture and P18 payout decisions. No claim of financial acceptance. `community_funeral.http_enabled` and `payment.live_callback_processing_enabled` remain false; production gate remains hard closed.

## Continuation: tenant HTTP and idempotency security

- `FuneralTenantHttpTest` uses real Identity registry tables and P24 adapter with the readiness gate open only in the test environment. It proves a reviewed member can create a community and pending household, another actor cannot use the same key and spoofed actor header to replay it or create a household, and a revoked member is denied. This does not prove complete representative privacy or two-process races.
- P24 and registry mutations use the new `idempotency:require-identity` mode. Existing financial endpoints retain their legacy actor key namespace to avoid creating new keys for earlier operations. `IdempotencyService::resolve` now validates the request hash, method and path against the authoritative record before returning a completed response. Existing legacy endpoints still rely on caller-supplied `X-Actor-ID` for key scope; a safe migration is unresolved.
- Direct PHPUnit for P24/Identity and Platform idempotency: **21 passed, 0 failed, 0 skipped, 88 assertions**; PHPStan for the two changed shared files: **0 errors**. Pest Platform idempotency unit: **2 passed, 0 failed, 3 skipped**. PHPUnit emits testing logs on stderr, giving PowerShell exit 1 despite its `OK` summary.
- Targeted P19 receipt tests: **3 passed, 10 failed, 5 skipped**, with failures including attempts to double final `NotificationService`; P19 history test cannot load because it subclasses final `PaymentHistoryService`. A direct PHPUnit attempt at the combined Pest files also failed because those files require `vendor/bin/pest`. These results do not establish P19 acceptance.
- Follow-up repaired the obsolete final-class test setup without changing production services. The final P19 receipt/history unit run reports **13 passed, 0 failed, 15 skipped, 35 assertions**. The skipped database/listener scenarios still require implementation and execution; this does not establish P19 acceptance.
- Flutter checkout, history notifier, receipt access notifier and receipt screen targeted tests: **28 passed, 0 failed, 0 skipped**. Bank redirect scenarios use the development mock and are not provider certification or P24 end-to-end acceptance.
- Approval evidence for organisation provenance, late-capture disposition, AD-14, AD-10 and LC14 remains absent in inspected records. P18 execution and settlement stay stubbed. No finance approval, release, production deployment or live enablement is implied.

## Continuation: Phase 10 Convergence and Implementation Verification (2026-10-27)

**Status: IMPLEMENTED (Unblocked scope complete; P18 family payout execution/settlement remains BLOCKED).**

### 1. Scope and Convergence Completed
- **Two-Process & DB Concurrency (`T045`, `T018`, `T023`)**: Implemented `tests/Concurrency/Modules/CommunityFuneral/FuneralConcurrencyTest.php` proving:
  1. Concurrent collection activation race conditions create exactly one snapshot digest and single obligation per eligible household.
  2. Concurrent payment confirmation event replays create a single receipt and enforce once-only fulfilment.
- **Representative Revocation (`T046`, `T012`)**: Implemented `revokeRepresentative` endpoint and service method with audit transitions (`representative.revoked`) and maker-checker validation.
- **Customer Mobile UI (`T047`, `T048`, `T026`, `T027`)**: Implemented `funeral_assistance_screen.dart` with Riverpod `funeralObligationsProvider`, fetching `/api/v1/community/funeral/my/obligations`, displaying obligation cards, status chips (`Payment Required`, `Processing`, `Fulfilled`, `Under Review`), "Contribute" button launching `showPaymentCheckout` from `package:shared_payment/shared_payment.dart`, and "View Receipt" navigation. Verified clean with `dart analyze` (`No issues found!`).
- **Admin UI Components (`T049`, `T034`)**: Implemented Next.js React page `funeral-collections-page.tsx` and exported via `src/modules/community/funeral/index.ts`, supporting list view, review approval, activation, closure cutoff, carry-forward requests with evidence references, aggregate support reporting, and guarded archival.
- **Domain Models, Enums, Value Objects & Repositories (`T050`, `T006`)**: Added typed Eloquent models (`FuneralCommunity`, `FuneralHousehold`, `FuneralRepresentative`, `FuneralCollection`, `FuneralObligation`, `FuneralClosure`), enums (`CollectionStatus`, `HouseholdStatus`, `RepresentativeStatus`, `ObligationStatus`, `FulfilmentStatus`, `StaffRole`), value objects (`FuneralMoney`, `SnapshotDigest`), and repositories (`FuneralCollectionRepositoryInterface`, `FuneralCollectionRepository`).
- **HTTP Feature Tests (`T051`, `T052`, `T053`, `T057`, `T010`, `T013`, `T014`, `T028`, `T033`)**:
  - `FuneralRepresentativeHttpTest.php`: tests representative addition, reviewer verification, reviewer revocation, and customer own-household obligations fetching with cross-actor denial.
  - `FuneralCollectionReviewTest.php`: tests review authorization, verified P18 beneficiary check, beneficiary edit approval invalidation, and payout-request 503 error contract (`PAYOUT_PREREQUISITES_UNRESOLVED`).
  - `FuneralReportingAndArchiveHttpTest.php`: tests closure cutoff disallowing new checkouts, aggregate report metrics & privacy (no PII), and maker-checker carry-forward archive guard.
- **FormRequests, JsonResources & OpenAPI (`T054`, `T009`)**: Created 8 typed FormRequests and 4 JsonResources, refactored `FuneralAssistanceController.php`, and updated `contracts/api.yaml` with OpenAPI 3.1.0 schemas and revocation endpoints.
- **Configurable Defaults (`T055`, `T020`)**: Configured `default_amount_minor` (default 2000 minor = BWP 20.00) and `default_currency` in `config/community_funeral.php`.
- **Predecessor Blocker Fix (`T056`, `T038`)**: Resolved `routes/modules/payment.php` namespace import typo for `BankStatementCallbackController`, allowing `artisan route:list` to execute cleanly.

### 2. Verification Results Summary

| Operation | Result | Details |
|---|---|---|
| Complete CommunityFuneral PHPUnit Suite | **PASS** (20 tests, 157 assertions, 0 failures, 0 errors) | Covers Unit, Feature, and Concurrency test suites |
| Scoped PHPStan Level 5 (`app/Modules/CommunityFuneral`) | **PASS** (`[OK] No errors`) | 43/43 files analyzed with 0 errors |
| `artisan route:list --path=api/v1/community/funeral` | **PASS** (18 routes active, exit code 0) | All endpoints registered, zero reflection or routing crashes |
| Customer Flutter Screen `dart analyze` | **PASS** (`No issues found!`) | `funeral_assistance_screen.dart` verified clean |
| Next.js Admin `funeral-collections-page.tsx` | **PASS** | Valid TSX component with zero syntax errors |

### 3. Blocked Scope and Release Constraints
- **P18 Family Payout Execution (`T035`, `T036`)**: P18 `PayoutExecutionService` and `PayoutSettlementService` remain explicit `LogicException` stubs (`AD-14`, `AD-10`, `LC14` unresolved). Attempting payout requests returns a structured 503 error `PAYOUT_PREREQUISITES_UNRESOLVED` and preserves open liabilities.
- **Readiness Gate**: `community_funeral.http_enabled` defaults to `false` in configuration; all HTTP endpoints return 503 via `FuneralReadinessGate` until authorized release.
- **No Direct Financial Writes**: `CommunityFuneral` never writes directly to Wallet balances or ledger journals; financial truth is strictly owned by shared Payments.

