# Research — E06 Wallet Management

## Status: Complete
All NEEDS CLARIFICATION items resolved. No constitution violations.

---

## Decision 1 — PostgreSQL Schema and Connection

**Decision**: Use a dedicated `wallet` PostgreSQL schema and a dedicated `wallet` Laravel DB connection, consistent with the schema-per-module pattern established by E01–E05.

**Rationale**: E01 uses `identity.*`, E04 uses `identity.*` permission tables, E05 uses `kyc.*`. The platform migration (`2026_08_01_000001_create_all_schemas.php`) already creates all module schemas. Wallet tables are created under `wallet.*` using `$connection = 'wallet'` in migrations.

**Alternatives considered**: Shared `public` schema — rejected; violates constitution Principle 7 (domain isolation) and schema-per-module ADR.

---

## Decision 2 — LedgerServiceContract::getBalance() and ledger_account_reference Assignment

**Decision**: Add `getBalance(string $ledgerAccountReference): int` to `LedgerModuleContract`. This is the only method E06 needs from E07 at this stage. The existing null implementation (`NullLedgerModule`) will throw `ModuleNotImplementedException`, which is the expected behavior before E07 is implemented.

**ledger_account_reference generation**: E06 generates `ledger_account_reference` as a UUID v7 at the time the `currency_pockets` row is created (inside the atomic pocket-creation transaction). E07 does not need to be called during pocket creation. When E07 is implemented, it reads this pre-assigned reference from the `currency_pockets` row and uses it as the ledger account key. This avoids a circular dependency (E06 creating a pocket would otherwise require E07 to be available, which requires E06 pockets to already exist).

**Rationale**: The spec (Section 18.2) requires `LedgerServiceContract::getBalance(ledger_account_reference)` as the sole source of ledger balance (FR-E06-016, FR-E06-018). Extending the existing `LedgerModuleContract` avoids a second interface. E06 generates the reference UUID; E07 registers it when first encountered. This is the idiomatic "opaque reference" pattern — E06 owns the identity, E07 owns the entries.

**Alternatives considered**: Separate `LedgerServiceContract` interface — redundant; one interface per module boundary is the established pattern. E07 exposes `createAccount()` — creates an unnecessary synchronous call at pocket creation time, requires E07 availability, adds a new cross-module write-path before E07 is implemented.

---

## Decision 3 — wallet_reference Generation

**Decision**: Implement a `WalletReferenceGenerator` service with a default format of `WLT-{YYYYMMDD}-{8 random uppercase alphanum}` (e.g., `WLT-20261001-AB3D7F2K`). The format is marked TBD-E06-007 via a `wallet.reference_format` configuration key with `is_tbd = true`. The generator seeds a retry loop (up to 5 attempts) on collision (EC-E06-08).

**Rationale**: TBD-E06-007 must not be hard-coded. The generator reads the format from configuration; if TBD, it uses the interim default. This satisfies constitution Principle 2 (TBD governance) without breaking pocket creation.

**Alternatives considered**: UUID-as-reference — legible references are required for customer-facing statements (SRS); sequential integer — collision risk in distributed scenario.

---

## Decision 4 — Event Subscription for kyc.case.approved

**Decision**: Implement a Laravel queued job `ProcessKycApprovedForWalletJob` that is registered as a listener for the `Kyc.Case.Approved` outbox event. The job is dispatched by the existing `InProcessDriver` event bus when the event is processed from the outbox relay. The job calls `WalletProvisioningService::provisionForUser(userId, correlationId)`.

**Rationale**: The existing event bus architecture (`InProcessDriver`) delivers processed outbox events to registered listeners. Registered listener → queued job is the established pattern (consistent with `PerformAutomatedVerificationJob` in E05). Idempotency is enforced via `platform.idempotency_records` keyed on `kyc.case.{case_id}.wallet_provision`.

**Alternatives considered**: Direct synchronous call in KYC approval handler — violates module boundary; webhook polling — not applicable in monolith.

---

## Decision 5 — Architecture Integrity Test Tool

**Decision**: Use `pest-arch` (Pest's `arch()` helper, available in Pest v3) for the `WalletBoundaryTest` (FR-E06-049). The test asserts that classes outside `App\Modules\Wallet` do not reference `App\Modules\Wallet\Infrastructure\Models\*` or `App\Modules\Wallet\Domain` Eloquent models directly.

**Rationale**: Pest is the established test runner in this project (E05 uses Pest). `arch()` is zero-dependency and runs in the existing test suite without additional packages.

**Alternatives considered**: `php-arkitect` — requires additional dependency; custom regex scanner — less maintainable.

---

## Decision 6 — WalletHoldService Placement

**Decision**: `WalletHoldService` is an internal E06 Application-layer service (`app/Modules/Wallet/Application/Services/WalletHoldService.php`). It is NOT exposed via `WalletModuleContract`. E08/E09 will receive a separate cross-module interface for hold operations when those epics are designed. For E06, E08/E09 do not yet exist; the service is defined and tested as an internal E06 service only.

**Rationale**: FR-E06-048 and spec Section 11.3 require that `WalletHoldService::place()` is the only hold creation path. Exposing it cross-module prematurely would require an ADR (constitution Principle 14). The interface design for E08/E09 consumption is deferred to those epics.

**Alternatives considered**: Expose via `WalletModuleContract` immediately — premature; E08 scope is not yet designed.

---

## Decision 7 — wallet:expire-holds Schedule

**Decision**: Register `wallet:expire-holds` in `app/Console/Kernel.php` with a `->everyFiveMinutes()` default, controlled by configuration key `wallet.expire_holds_schedule` (TBD-E06-004, seeded with `is_tbd = true`). Until the TBD is resolved, the command runs every 5 minutes as a safe default; the schedule value is logged if TBD is active.

**Rationale**: TBD-E06-004 must not be hard-coded. Running every 5 minutes is conservative and safe; over-expiry-checking is harmless. The command is idempotent (FR-E06-048).

**Alternatives considered**: Horizon scheduled job — same outcome; cron-only — less flexible.

---

## Decision 8 — Balance Query Under E07 Dependency

**Decision**: When `LedgerServiceContract::getBalance()` throws `ModuleNotImplementedException` (pre-E07), balance endpoints return `ledger_balance = 0`, `available_balance = 0`, and include a `meta.ledger_unavailable: true` flag in the response. This behavior is gated behind a configuration flag `wallet.stub_ledger_balance` (default `false`; set to `true` only in non-production environments). In production, the exception propagates as HTTP 503.

**Rationale**: E06 must be testable before E07 is implemented. A stub-ledger mode allows E06 API tests and UI development to proceed. The flag is environment-scoped, preventing accidental stubbing in production.

**Alternatives considered**: Hard-code 0 in prod — dangerous (misleads users); skip balance field — breaks API contract.

---

## Dependency Confirmation

| Epic | Interface | Required Before | Status |
|------|-----------|-----------------|--------|
| E01 Identity | `identity.auth` middleware | API endpoints | Available |
| E04 Permissions | `identity.require-permission` middleware | API endpoints | Available |
| E05 KYC | `Kyc.Case.Approved` outbox event | wallet auto-provision | Available |
| E07 Ledger | `LedgerModuleContract::getBalance()` | Balance queries | Stub (NullLedgerModule) |
| Platform | `IdempotencyService`, `AuditService`, `OutboxEventPublisher` | All mutations | Available |

E07 is the only blocking dependency for production-quality balance queries. E06 proceeds with a null implementation.
