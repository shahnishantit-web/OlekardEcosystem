# Research Notes — E27 Reliability & Transaction Integrity

## Status: ✓ COMPLETE

All technical decisions resolved. No NEEDS CLARIFICATION items remain.

---

## Decision 1 — Idempotency Enforcement Model

**Decision**: Extend `IdempotencyMiddleware` to accept a `require` parameter. Apply `idempotency:require` on financial mutation routes to enforce mandatory key presence (HTTP 422 if missing). The existing `idempotency` middleware (no parameter) remains opt-in for non-financial endpoints.

**Rationale**: The current `IdempotencyMiddleware` at `app/Http/Middleware/IdempotencyMiddleware.php` passes through requests with no `Idempotency-Key`. FR-001 requires financial endpoints to REJECT such requests. Adding a parameter avoids creating a duplicate class while preserving backward compatibility for existing non-financial routes.

**Alternatives considered**:
- New `StrictIdempotencyMiddleware` class — rejected: duplicates logic.
- Always require key globally — rejected: many admin/non-financial endpoints don't need idempotency.

---

## Decision 2 — Provider Callback Deduplication

**Decision**: Provider callback deduplication is already implemented in `ProviderCallbackService` via `provider_reference_id` uniqueness check (line 28: "Idempotency guard: duplicate provider_reference_id → DuplicateCallbackException"). FR-005 is satisfied for the Funding module. E27 audits coverage in Withdrawal (`WithdrawalConfirmationService`) and QR (`QrPaymentService`) and adds the same guard where missing.

**Rationale**: Reusing the existing `provider_reference_id` pattern avoids introducing a new deduplication mechanism; it's already tested and battle-hardened from E08.

---

## Decision 3 — Transaction Orphan Detection

**Decision**: Each financial module that processes asynchronous operations implements a module-specific timeout/orphan detection job following the pattern established in `App\Modules\Service\Application\Jobs\TransitionTimedOutPaymentsJob`. The Platform module provides a new `OrphanDetectableInterface` contract that each financial module's service implements. E27 creates timeout jobs for the three modules that currently lack them: Funding, Transfer, Withdrawal.

**Modules with existing timeout handling**:
- Service (Bill Payments): `TransitionTimedOutPaymentsJob` + `ReQueryTimeoutTransactionJob` ✓
- QR: `ReleaseExpiredQrReservationsJob` (handles reservation expiry) ✓ (partial)

**Modules needing new timeout jobs**:
- Funding: No `FundingTransactionTimeoutJob` ✗ → **create**
- Transfer: No timeout job ✗ → **create**
- Withdrawal: No timeout job ✗ → **create**

**Rationale**: The module-specific pattern is already proven in E15 (Service). A central Platform-level scanner that queries across all financial tables was rejected because it creates tight coupling from Platform to each financial module's schema.

---

## Decision 4 — Ledger Integrity Scan

**Decision**: New `App\Jobs\LedgerIntegrityJob` (Platform-level scheduled job, hourly) queries `ledger.journal_entries` to verify that `SUM(debit_amount) == SUM(credit_amount)` for each wallet across all completed journal entries. Discrepancies emit an `ALERT_LEDGER_INTEGRITY_FAILURE` audit event and are recorded in a new `platform.ledger_integrity_snapshots` table.

**Rationale**: The `LedgerPostingService` already enforces double-entry on write; the integrity scan provides a periodic verification layer that catches any data anomalies or migration errors that bypassed the service layer.

**Note**: In-flight transactions (status = pending/processing) are excluded from the scan to avoid false positives.

---

## Decision 5 — Correlation ID Propagation in Background Jobs

**Decision**: Create a `HasCorrelationId` trait at `App\Modules\Platform\Concerns\HasCorrelationId`. Jobs using this trait store `public readonly string $correlationId` in their constructor and call `Log::withContext(['correlation_id' => $this->correlationId])` at the start of `handle()`. Existing jobs that already accept `correlationId` (e.g., Reporting module jobs) adopt the trait for consistency.

**Current state**: `CorrelationIdMiddleware` already injects `correlation_id` into `Log::withContext()` for HTTP requests and sets the `X-Correlation-ID` response header (FR-018 and FR-019 already satisfied for HTTP). The gap is background jobs: when a job is dispatched, the correlation ID from the originating HTTP request context must be explicitly included in the job's constructor and re-injected into the log context when the job runs.

**Rationale**: Explicit constructor injection (rather than relying on Laravel's shared context) makes the correlation ID first-class in the job's serializable state — it survives serialization/deserialization and works reliably whether the job runs immediately or is deferred.

**Scope**: Audit all existing queued jobs in financial modules (Funding, Transfer, Withdrawal, Service, QR, Commission, AgentFloat, Risk) and add the trait + correlationId constructor parameter.

---

## Decision 6 — Dead-Letter Store for Financial Operations

**Decision**: New `platform.dead_letter_items` table and `App\Modules\Platform\Application\Services\DeadLetterService`. This is separate from Laravel's built-in `failed_jobs` table and the Integration module's webhook dead-letter event.

**Rationale**: `failed_jobs` is framework-owned and cannot carry domain semantics (operation type, operator replay tracking, dismissal state). The Integration module's `WebhookEventDeadLetteredEvent` covers external webhook failures, not internal financial job failures. A domain dead-letter table provides: rich metadata, admin UI, audit-logged replay, dismissal with justification, and configurable TTL.

**Scope**: Financial jobs that exhaust retries call `DeadLetterService::capture()` in their `failed()` method. Admin API + portal page exposes list/replay/dismiss to authorized operators.

**Permission**: New `operations.dead_letter.manage` permission, seeded by `E27ReliabilityPermissionsSeeder`.

---

## Decision 7 — Retry Configuration

**Decision**: New `config/reliability.php` with env-var defaults for all tunable reliability parameters, backed by `ConfigurationServiceInterface` for runtime overrides without restart.

```php
// config/reliability.php (representative)
'transaction_timeout_seconds'  => env('RELIABILITY_TRANSACTION_TIMEOUT_SECONDS', 30),
'orphan_threshold_minutes'     => env('RELIABILITY_ORPHAN_THRESHOLD_MINUTES', 5),
'max_retry_attempts'           => env('RELIABILITY_MAX_RETRY_ATTEMPTS', 3),
'retry_base_delay_seconds'     => env('RELIABILITY_RETRY_BASE_DELAY_SECONDS', 5),
'dead_letter_ttl_days'         => env('RELIABILITY_DEAD_LETTER_TTL_DAYS', 30),
'idempotency_ttl_days'         => env('RELIABILITY_IDEMPOTENCY_TTL_DAYS', 7),
```

**Rationale**: Centralizing in `config/reliability.php` makes the defaults visible in code review and provides a clear documentation point. Platform Configuration service overrides allow operations teams to adjust values without deployment.

---

## Dependency Map

| Story | Key dependency | Status |
|-------|---------------|--------|
| US1 idempotency | `IdempotencyMiddleware` + `IdempotencyService` | Exists — extend |
| US1 callback dedup | `ProviderCallbackService` + `provider_reference_id` | Exists in Funding — audit others |
| US2 atomicity | `DB::transaction()` in `LedgerPostingService` | Exists — audit coverage |
| US2 ledger scan | `LedgerIntegrityJob` | **New** |
| US3 orphan detection | `TransitionTimedOutPaymentsJob` pattern | Exists in Service — extend to Funding/Transfer/Withdrawal |
| US4 dead-letter | `DeadLetterService` + admin table | **New** |
| US5 correlation jobs | `CorrelationIdMiddleware` (HTTP) | Exists — extend to jobs |
