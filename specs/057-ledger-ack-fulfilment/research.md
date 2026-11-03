# Research: P08 — Ledger Acknowledgement and Fulfilment Handoff

**Date**: 2026-10-21
**Status**: Prior implementation research complete; 2026-10-28 evidence reconciliation found source and contract gaps; AD-08/AD-09 and independent review remain unresolved

---

## Decision 1: Outbox Pattern

**Decision**: Use `OutboxEventPublisher::publish()` (already wired in the container as `EventPublisherInterface`). Call it inside a DB transaction with `DB::connection('platform')`.

**Rationale**: `OutboxEventPublisher` enforces transactional safety — it throws `LogicException` if called outside a transaction. This is the canonical implementation of Principle 9 (event reliability/outbox). The `platform.outbox_events` table already exists.

**Alternatives considered**: Dispatching a Laravel `ShouldQueue` job — rejected because job dispatch is not atomic with the DB write; a crash between the DB commit and the job dispatch would lose the event.

---

## Decision 2: Capture-Accounting Decoupling

**Decision**: Restructure `handleOutcome()` so the capture transaction (`PaymentAttempt.status = succeeded` + intent `status = Succeeded` + hold release) commits first. After the commit, run ledger posting in a separate phase (not inside any DB transaction from the capture phase). If ledger posting succeeds, open a new platform+payment transaction to write `journal_id`, update `accounting_status = ledger_posted`, and publish `payment.confirmed` via outbox.

**Rationale**: Principle 4 — "the consumer has already paid; a ledger failure must not undo that fact." The capture DB commit is the financial truth; the ledger acknowledgement is the accounting record of that truth. These must be independent.

**Alternatives considered**: Two-phase commit — rejected; overkill for a modular monolith. Saga pattern — rejected; over-engineered for a single-service scenario. Compensating transaction — rejected; there is nothing to compensate once capture is committed.

---

## Decision 3: Idempotency Key for Ledger

**Decision**: `payment:{payment_intent_id}:ack` — the intent ID, not the attempt ID.

**Rationale**: An intent can have multiple attempts (e.g., retry after crash recovery). The ledger entry must be exactly one per intent, regardless of how many attempts it took to achieve `succeeded`. Using the attempt ID would allow a second attempt (after crash recovery) to post a duplicate journal.

**Alternatives considered**: Attempt ID — rejected; breaks idempotency across attempt retries. Correlation ID — rejected; a new correlation ID is generated per API call, making replay unsafe.

---

## Decision 4: Credit Account Reference (AD-08 Deferral)

**Decision**: Read from config key `payment.credit_account_{currency_code}` (e.g., `payment.credit_account_BWP`) via `ConfigurationServiceInterface::getOrDefault()`. If null/missing: skip journal posting, set `accounting_status = pending_ledger`, log warning with `{reason: 'ad-08-unresolved'}`.

**Rationale**: Principle 2 (TBD Governance) — do not invent accounting decisions. The config key is a placeholder that Finance Lead activates by setting the value. No code change required to activate wallet accounting once AD-08 is resolved.

**Alternatives considered**: Hardcode a suspense account — rejected; violates Principle 2. Use `null` as credit reference and let the ledger reject it — rejected; a `postEntry()` exception would be indistinguishable from a ledger outage.

---

## Decision 5: Bank Payment Deferral (AD-09)

**Decision**: Bank payments are identified by `$attempt->wallet_hold_id === null`. When this is true, skip journal posting entirely, set `accounting_status = pending_ledger`, log warning with `{reason: 'ad-09-unresolved'}`.

**Rationale**: AD-09 (bank payment chart of accounts) is UNRESOLVED. The debit account for a bank payment is not known. Attempting to post a partial journal would violate Principle 4 (balanced journals). The `pending_ledger` path preserves capture and queues accounting for when AD-09 is resolved.

**Alternatives considered**: Post debit-only journal for bank payments — rejected; violates Principle 4 (partial debit/credit outcomes are prohibited).

---

## Decision 6: Repair Job Architecture

**Decision**: Two-job pattern: `RepairPendingAccountingJob` (scan) dispatches `RepairSingleAccountingJob` per intent. The scan job has a `uniqueId()` to prevent overlapping scans.

**Rationale**: Matches the existing `PaymentAttemptCrashRecoveryJob` + `PaymentAttemptSingleRecoveryJob` pattern already in the codebase. Scan jobs are cheap and fast; per-intent jobs are independently retried by Horizon.

**Alternatives considered**: Single job that processes all pending intents inline — rejected; a failure in one intent's processing would prevent others from being retried independently.

---

## Decision 7: Repair Retry Logic and Exhaustion

**Decision**: Track retry count in `payment_intents.accounting_repair_attempts` (new column). Max retries from `payment.accounting_repair_max_retries` (default 5). On exhaustion: set `accounting_status = ledger_failed`, create `ReconciliationException(AccountingFailure)`, emit `Log::critical`.

**Rationale**: The spec (FR-014) requires exhaustion → `AccountingFailure` reconciliation exception + critical log. Storing the count on the intent row is consistent with the existing `ledger_repair_at` approach and avoids a separate attempts tracking table.

**Alternatives considered**: Track retries in Redis — rejected; not durable; a Redis flush would reset retry counts and allow infinite retries. Use job `attempts()` from Horizon — rejected; each dispatch of `RepairSingleAccountingJob` starts a new job with `attempts()=1`; the intent-level count needs to persist across dispatches.

---

## Decision 8: `allocation_reference` in `payment.confirmed`

**Decision**: Add `allocation_reference = payment_intent_id` as a new field in `PaymentConfirmedEvent`. This is a non-breaking addition to the event payload.

**Rationale**: FR-008 requires this field so consuming modules can use it as their domain allocation idempotency key. Using `payment_intent_id` directly (not a new UUID) avoids a separate mapping table and is stable across event retries.

**Alternatives considered**: Use `payment_attempt_id` — rejected; a consumer receiving a retry from a different attempt would use a different allocation key, enabling duplicate allocation. Use a separate `allocation_id` UUID — rejected; introduces an extra step with no benefit since `payment_intent_id` is already a UUID v7.

---

## 2026-10-28 authorized implementation reconciliation

- **Source discrepancy resolved**: `docs/sources/Olekard_Shared_Payment_Architecture_v1.1.docx` exists (283,257 bytes; file modified 2026-10-20). The document body identifies version 1.1. Its generic core properties are not treated as version evidence. It supports shared Payment/Ledger, outbox and separate fulfilment ownership; it explicitly says bank names are placeholders and no bank API/live contract was inspected. AD-08 and AD-09 remain unresolved.
- **Capture authority**: Payment attempt capture fields are nullable. Only a validated E25 verified webhook receipt may provide capture amount/currency and its receipt ID as provenance. The expected intent amount is never copied into the captured amount. Succeeded outcomes from query/recovery without capture facts remain accounting-pending and cannot publish `payment.confirmed`.
- **Ledger evidence**: Ledger owns `findPostedJournalEvidence(idempotencyKey)`, returning typed immutable posted journal facts and individual account lines. Payment checks journal ID, posted state/time (Ledger query), type, transaction reference, deterministic idempotency key, currency, and one exact debit plus one exact credit to the references used to post. It compares each line separately; it does not sum all debit/credit lines.
- **Retry window**: P08 posts using stable `payment:{intent}:ack`, then resolves the posted journal by that key. If Ledger committed and the Payment connection did not persist, repair repeats the same Ledger key and obtains that journal, then persists `ledger_journal_id`, account references, accounting state and Payment outbox event together. This is not a distributed transaction; Ledger idempotency is the recovery mechanism.
- **Internal contract**: `PaymentInternalConfirmationContract` accepts an Identity-resolved `ServiceActor`; only service `events` with exact organisation/purpose-scoped read/ack scopes can query or acknowledge `paid_event_ticket`. Public payer status API is unchanged. No Events resolver is bound. Events must call acknowledgement only after its own durable fulfilment commit; P08 does not implement an Events inbox or ticket issuance.
- **Acknowledgement**: one Payment-owned row per intent preserves organisation/purpose, consumer service, durable fulfilment reference and idempotency key. Payment state and row are committed together; exact replay is a no-op, conflicting replay is rejected, and only `pending → fulfilled` is allowed. Existing terminal statuses are not rewritten.
- **External accounting**: no provider/AD-09 mapping is inferred. External attempts without an approved accounting path remain `pending_ledger` and cannot produce confirmation evidence. AD-08 also remains independently unverified; a non-null config value is not approval evidence.
- **Verification environment**: P08 targeted tests run without refreshing/migrating schemas. The broader `RefreshDatabase` setup previously collided on the already-existing `identity.users` table (`SQLSTATE 42P07`). No reset or migration-history change was made. P08/P11 migration application and database-backed acknowledgement/cross-module tests remain pending a schema-correct isolated test environment.

## 2026-10-28 isolated PostgreSQL verification update

- Disposable environment: repository `docker-compose.test.yml`, unique Compose project `p08-isolated-20261028`, private test network and project-specific volume. PostgreSQL 15.19; `pg_trgm` 1.6 installed by project migrations. Laravel runner explicitly connected to `wallet_ecosystem_test`. Read-only checks showed the existing dev DB is a separate healthy `wallet-ecosystem-postgres-1` / `wallet_ecosystem`; no schema operation touched it.
- Migration chain: Laravel initially required the empty `platform` schema for its migration repository; only that schema was bootstrapped in the disposable database. First chain error was the Profile permission seeder executing before the later Identity permission-table migrations. The actual Identity role/permission/role-permission migrations were executed in prerequisite order on the test DB, without synthetic migration records. The next chain blocker was `2026_10_24_000002_add_lifecycle_to_provider_accounts`: PostgreSQL 15 rejects its `ALTER TABLE ... ADD CONSTRAINT IF NOT EXISTS` syntax (SQLSTATE 42601). No migration source or existing development DB history was changed.
- P08's additive migration was then run from its real path against migration-created Payment intent/attempt predecessor tables and succeeded. `information_schema` confirmed the five capture/journal fields are nullable and `payment.fulfilment_acknowledgements` exists. This verifies migration DDL only; it does not prove service persistence, idempotency races, transaction rollback, or cross-module crash recovery.
- P11 `OutboxDeadLetterServiceTest` now has four actual Eloquent/database test cases, not skips; **4 passed, 8 assertions** against `platform.outbox_events`.
- New `PaymentFulfilmentAcknowledgementDatabaseTest` ran against the migrated Payment schema: **6 passed, 40 assertions**. It verifies nullable capture/journal value persistence, successful exact-reference replay, conflicting acknowledgement denial without overwrite, distinct concurrent PostgreSQL sessions for the same reference, service-scope denial, organisation/purpose isolation with valid scoped grants, terminal/ineligible states, and rollback when a test trigger aborts the intent-state update after acknowledgement insertion.
- Final combined P08/P11/P06/P07 command: **39 passed, 0 failed, 134 assertions, 0 skipped**. P08 orchestration/verifier and P06/P07 behavior remains fixture-based; only acknowledgement persistence/races and dead-letter service replay are PostgreSQL-backed. Do not label the fixture Ledger post/recovery test as real crash acceptance.
- Capture source inspection distinguishes internal wallet from external methods. Wallet adapter creates a hold with intent amount/currency and reports synchronous success, but Wallet contract offers no committed debit/capture query. A hold is not an authoritative captured-money fact. No fake E25 callback is introduced; wallet confirmation remains suppressed pending Wallet/Ledger evidence and AD-08 review. External/provider capture amount/currency/provenance comes only from E25's validated receipt path; Payment preserves mismatches, while AD-09 external accounting stays pending until Finance supplies approved mappings.
- Ledger implementation/unit fixtures verify exact posted journal ID, transaction reference, stable idempotency key, currency and individual debit/credit lines. The isolated DB has no approved production mapping or journal examples. No bank account mapping or independent Finance acceptance is inferred.
- Static analysis: selected changed P08 production files and the two changed tests at PHPStan level 8 have **0 findings**; full Payment/Ledger modules currently show **485 findings** (the prior record was 490). The module gate remains failed. The full finding delta is not treated as proof of baseline clearance.
- Remaining DB verification: the real Ledger-commit/Payment-reference crash window, actual shared commit of accounting state + returned journal ref + Payment outbox, and authenticated P11 replay route/re-dispatch were not verified. Existing failed full migration run remains blocking to a full clean-chain suite; the P08 additive migration itself is applied only in the isolated test DB.

## Decision 9: Metrics Pattern

**Decision**: Use structured `Log::info()` with metric-named keys following the existing codebase pattern (no Prometheus SDK found in the Payment module). The repair scan job logs `{payment.accounting.pending_count: N, payment.accounting.repair_success_count: M}` after each run.

**Rationale**: The existing `PaymentAttemptCrashRecoveryJob` uses `Log::info()` with structured arrays. A log-based metrics approach is consistent with the rest of the module. Adding a Prometheus SDK would require an ADR (Principle 14 — no silent architecture changes).

**Alternatives considered**: Add `prometheus/client_php` — rejected; requires an ADR and is out of scope for P08.

## 2026-10-28 migration and integration verification continuation

### Migration history and compatibility choice

- Repository deployment history was inspected: Git history contains only the repository's initial commit for the affected migrations, `specs/061-bank-integration-config/tasks.md` marks the provider-account migration implementation task complete, but no environment deployment record or migration ledger was available as source evidence. A checked implementation task is not evidence that a migration was deployed.
- To preserve migration names for environments that may already have recorded them, the Profile migration filename remains unchanged. It now detects the missing Identity `permissions`, `roles`, or `role_permissions` table, logs the exact deferred seed and follow-up migration, and returns. New Profile migration `2026_09_09_010000_seed_profile_permissions_after_identity` runs after Identity's 009000 prerequisites and guarantees the four permission rows and admin links with `insertOrIgnore`; repeated execution preserves existing rows. Its `down()` is intentionally a no-op; the original Profile migration owns seed removal when its dependencies still exist.
- PostgreSQL does not implement `ALTER TABLE ... ADD CONSTRAINT IF NOT EXISTS` on the tested PostgreSQL 15 server. Payment migration `2026_10_24_000002` now checks `pg_constraint` for the exact relation and name before adding its check. The next clean-chain migration, `2026_10_24_000005`, had two instances of the same unsupported syntax; it now applies equivalent exact-relation guards for its two foreign keys. No constraint is dropped or replaced.
- Clean disposable database `wallet_ecosystem_p08_clean_20261028` completed **310 project migrations** on PostgreSQL 15.19. Laravel needs the `platform` schema before it can create its migration repository; the project schema bootstrap also omits `community_services`, required by the 2026-10-27 Community Funeral migration. Only those two empty schemas were created in this disposable DB; no table or migration row was hand-created. Direct repeated `up()` invocations for both Payment compatibility migrations and the Profile deferred seed succeeded without changing the expected constraint count or seed counts.

### Actual Ledger path and limits of P08 recovery verification

- New PostgreSQL feature tests call the actual `LedgerModuleContract::postEntry` implementation with test-only account references. A controlled exception after the method commits proves the posted journal and one Ledger outbox event survive; retry by the real idempotency key returns the same journal. A PostgreSQL trigger rejecting the actual Ledger outbox insert proves journal, lines and idempotency row roll back with that Ledger transaction. Test account references are not Finance approval.
- This is not an end-to-end P08 Payment recovery test. Wallet currently persists holds only (`active`, `released`, `expired`); `WalletModuleContract` can create/release a hold and query wallet/pocket data, but there is no Wallet capture/commit operation, captured state, Wallet transaction record, or contract query associating a hold with a posted debit journal. Wallet balance is read from Ledger minus active holds. A hold is not capture evidence. No fabricated capture value or E25 callback was used.
- Minimum Wallet-owned dependency proposed in `contracts/confirmation-evidence.md`: immutable typed committed-capture evidence derived from Wallet's own durable capture operation and the posted Ledger debit; bind the hold/transaction reference, wallet owner, exact Wallet amount/currency, posted journal and stable idempotency key. The Wallet/Finance-approved operation and destination-account semantics must precede implementation. Payments must consume the evidence without causing a second wallet debit. AD-08 and AD-09 remain unresolved.
- Payment and Ledger transactions are separate and use stable Ledger idempotency for recovery. The actual Ledger transaction/outbox atomicity is verified. P08 Payment status + journal reference + Payment outbox atomicity, recovery through `repairAccountingForIntent`, Payment commit before delivery, and a real consumer commit before acknowledgement remain unverified because no supported capture input exists; P08 also does not implement the future Events order consumer/inbox.

### P11 route and scope

- `OutboxReplayFeatureTest` now exercises the actual HTTP route, Identity permission resolution, audit writer, replay service, dispatcher job and in-process event bus in the isolated DB. Authentication/permission denial, one audit row, duplicate pending replay and delivered status are verified.
- The established P11 route is a global operations control protected by `operations.dead_letter.manage`. `platform.outbox_events` has no tenant scope, and the API contract has no tenant/event/resource assignment. Therefore a cross-tenant denial guarantee is not available to test; this is a scope characteristic of P11, not a passing isolation claim.
