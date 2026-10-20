# Module Dependency Matrix

## Purpose

This document defines which modules may depend on which within the OleKard platform. It is the human-readable complement to the runtime enforcement provided by `php artisan platform:check-scope` (FR-004, FR-180).

**Rationale**: See `docs/adrs/003-schema-per-module.md` for the schema-per-module decision. The dependency rules below ensure the modular monolith remains extraction-ready for the enterprise target state.

---

## Dependency Rules (Normative)

1. **Any module MAY depend on Platform** — Platform is the shared kernel (cross-cutting: audit, idempotency, events, configuration, observability).
2. **No module MAY import from another domain module's namespace directly** — Cross-module communication MUST go through application-layer contracts defined in `contracts/module-contracts.md`.
3. **Any module MAY depend on `packages/shared-utils/` and `packages/design-system/`** — These are explicitly shared libraries (FR-003).
4. **Infrastructure layer repositories MUST access only their own module's named PostgreSQL schema** — No cross-schema SQL JOINs (FR-002, FR-186).
5. **`packages/shared-utils/` and `packages/design-system/` MUST NOT depend on any domain module** — Shared libraries are dependency-free from domain code.

---

## Dependency Matrix

Legend: **A** = Allowed | **F** = Forbidden (cross-domain) | **P** = Platform-only (Platform is the shared kernel)

The rows are the **dependent** module; columns are the **dependency target**.

| Dependent ↓ / Dependency → | Platform | Identity | Wallet | Ledger | KYC | Transaction | Risk | Agent | Notification | shared-utils | design-system |
|----------------------------|----------|----------|--------|--------|-----|-------------|------|-------|--------------|--------------|---------------|
| **Platform** | — | F | F | F | F | F | F | F | F | A | A |
| **Identity** | A | — | F | F | F | F | F | F | F | A | A |
| **Wallet** | A | F | — | F | F | F | F | F | F | A | A |
| **Ledger** | A | F | F | — | F | F | F | F | F | A | A |
| **KYC** | A | F | F | F | — | F | F | F | F | A | A |
| **Transaction** | A | F | F | F | F | — | F | F | F | A | A |
| **Risk** | A | F | F | F | F | F | — | F | F | A | A |
| **Agent** | A | F | F | F | F | F | F | — | F | A | A |
| **Notification** | A | F | F | F | F | F | F | F | — | A | A |
| **shared-utils** | F | F | F | F | F | F | F | F | F | — | F |
| **design-system** | F | F | F | F | F | F | F | F | F | F | — |

---

## Cross-Module Communication (Allowed Pattern)

When a domain module needs data or behavior from another domain module, the ONLY allowed pattern is:

```
Module A  →  calls  →  Module B's Contract Interface
             (injected via Platform Foundation DI container)
```

**Example**: `TransactionModule` needs to know if a wallet is active.

```
// CORRECT — via contract
$this->walletContract->isActive($walletId);   // WalletModuleContract

// FORBIDDEN — direct cross-module import
use App\Modules\Wallet\Models\Wallet;         // cross-domain namespace violation
```

The Platform module `ServiceProvider` registers `NullImplementation` stubs for each domain contract during Platform Foundation. Concrete modules replace these in their own `ServiceProvider` (FR-015, FR-179, FR-185).

---

## Runtime Enforcement

The `platform:check-scope` Artisan command (FR-180) enforces these rules in CI:

```bash
php artisan platform:check-scope
# Scope: OK        (exit 0 — no violations)
# Scope: FAIL      (exit 1 — violations printed with file/line detail)
```

The CI pipeline (`scripts/ci/backend.sh`) gates merge to main on a clean `platform:check-scope` run.

---

## Schema Access Rules

Each module has exactly one named PostgreSQL connection. Repositories MUST use their module's connection exclusively:

| Module | Named Connection | PostgreSQL Schema |
|--------|-----------------|-------------------|
| Platform | `platform` | `platform` |
| Identity | `identity` | `identity` |
| Wallet | `wallet` | `wallet` |
| Ledger | `ledger` | `ledger` |
| KYC | `kyc` | `kyc` |
| Transaction | `transaction` | `transaction` |
| Risk | `risk` | `risk` |
| Agent | `agent` | `agent` |
| Notification | `notification` | `notification` |

Cross-schema SQL JOINs are forbidden. The `platform:check-scope` command detects `JOIN schema.table` patterns as violations.
