# Implementation Plan — E07 Wallet Ledger

## Constitution Check

- [x] Approved Laravel/Next.js/Flutter stack respected — no new technologies introduced; Pest, PHPStan, Eloquent, raw SQL all existing project tools
- [x] No TBD value invented — TBD-E07-001 (system accounts) left as config stubs; TBD-E07-002 (account type) preserved as provisional default; TBD-E07-003–007 deferred
- [x] Authorization remains server-side — all five endpoints guarded by `identity.require-permission` middleware (E04)
- [x] Financial integrity preserved for financial stories — double-entry enforced at application layer; unique DB constraint on `idempotency_key`; `JournalLine` LogicException on save/delete; transaction wraps journal + lines + outbox + idempotency atomically
- [x] Security/audit requirements incorporated — SR-E07-001 through SR-E07-010 mapped; audit event on every mutation; IDOR returns 404; no PII in ledger
- [x] UX/design-system requirements incorporated — E07 is service-to-service; no Admin UI screens in scope (Admin views belong to E19/E23)
- [x] QA strategy incorporated — unit, feature, integration, architecture, concurrency tests specified; SC-E07-001 through SC-E07-009 map directly to test cases

---

## Technical Context

| Item | Decision | Source |
|---|---|---|
| Backend | Laravel / PHP 8.4+ | Constitution |
| Database | PostgreSQL; `ledger` schema on same physical DB as `platform`, `wallet` | `config/database.php` |
| DB Connection for models | `platform` (for OutboxEventPublisher atomicity) | `research.md §1` |
| DB Connection for migrations | `ledger` | `research.md §8` |
| Explicit table prefix | `ledger.journals`, `ledger.journal_lines`, `ledger.ledger_accounts` | `research.md §1` |
| Transaction scope | `DB::connection('platform')->transaction()` wrapping journal + lines + outbox + idempotency | `research.md §2` |
| UUID | UUID v7 via `Ramsey\Uuid\Uuid::uuid7()` | `research.md §3` |
| Balance query | Raw SQL via `DB::connection('platform')->selectOne()` with direction-conditional SUM | `research.md §4` |
| Idempotency | Dual-layer: `IdempotencyService` fast path + `journals.idempotency_key` UNIQUE race guard | `research.md §5` |
| Events | Extend `BaseIntegrationEvent`; emit via `OutboxEventPublisher` inside transaction | `research.md §7` |
| Immutability | `JournalLineModel::save()` / `delete()` throw `LogicException` on existing record | FR-E07-012 |
| Architecture test | Pest `arch()` in `tests/Architecture/Modules/LedgerBoundaryTest.php` | `research.md §9` |
| Concurrency tests | `tests/Concurrency/Modules/Ledger/` | `research.md §13` |
| Per-currency balance | `ledger:check-balance` groups by `currency_code` before comparing sums | `research.md §10` |
| Metrics | Structured log entries (scraped by observability pipeline) | `research.md §14` |

---

## Module Structure

```
app/Modules/Ledger/
├── Application/Services/
│   ├── LedgerPostingService.php
│   ├── LedgerBalanceService.php
│   ├── LedgerReverseService.php
│   └── LedgerAccountService.php
├── Domain/
│   ├── Contracts/LedgerModuleContract.php   (5-method interface)
│   ├── Enums/
│   │   ├── AccountType.php
│   │   ├── NormalBalance.php
│   │   ├── JournalEntryType.php
│   │   ├── JournalStatus.php
│   │   ├── JournalLineDirection.php
│   │   └── LedgerAccountStatus.php
│   ├── Events/
│   │   ├── LedgerEntryPostedEvent.php
│   │   ├── LedgerEntryReversedEvent.php
│   │   ├── LedgerAccountRegisteredEvent.php
│   │   └── LedgerImbalanceDetectedEvent.php
│   ├── Exceptions/
│   │   ├── UnbalancedJournalException.php
│   │   ├── CurrencyMismatchException.php
│   │   ├── InvalidAmountException.php
│   │   ├── AccountClosedException.php
│   │   ├── JournalNotFoundException.php
│   │   └── JournalAlreadyReversedException.php
│   └── Permissions/LedgerPermissions.php
├── Infrastructure/
│   ├── Console/Commands/CheckLedgerBalanceCommand.php
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── LedgerAccountController.php
│   │   │   ├── LedgerEntryController.php
│   │   │   └── LedgerJournalController.php
│   │   ├── Requests/
│   │   │   ├── PostEntryRequest.php
│   │   │   └── RegisterAccountRequest.php
│   │   └── Resources/
│   │       ├── JournalResource.php
│   │       ├── JournalLineResource.php
│   │       └── LedgerAccountResource.php
│   ├── Migrations/
│   │   ├── 2026_09_17_001100_create_ledger_accounts_table.php
│   │   ├── 2026_09_17_001200_create_ledger_journals_table.php
│   │   ├── 2026_09_17_001300_create_ledger_journal_lines_table.php
│   │   ├── 2026_09_17_001400_seed_ledger_permissions.php
│   │   └── 2026_09_17_001500_seed_ledger_system_accounts_stub.php
│   ├── Models/
│   │   ├── LedgerAccountModel.php
│   │   ├── JournalModel.php
│   │   └── JournalLineModel.php
│   ├── NullImplementations/NullLedgerModule.php  (updated — 5 methods)
│   └── Repositories/
│       ├── EloquentLedgerAccountRepository.php
│       └── EloquentJournalRepository.php
└── Providers/LedgerServiceProvider.php
```

Routes file: `routes/modules/ledger.php`

---

## Phase 0 — Research (Complete)

All technical unknowns resolved. See `research.md` for decisions on:
- DB connection strategy (§1, §8)
- Transaction scope (§2)
- UUID v7 (§3)
- Balance query (§4)
- Idempotency dual-layer (§5)
- Module directory structure (§6)
- Event base class and naming (§7)
- Architecture test pattern (§9)
- Per-currency balance check (§10)
- Service provider pattern (§11)
- Controller and response pattern (§12)
- Concurrency test location (§13)
- Metrics pattern (§14)
- `wallet.stub_ledger_balance` removal (§15)

---

## Phase 1 — Design (Complete)

Artifacts produced:
- `data-model.md` — 3 entities (`ledger_accounts`, `journals`, `journal_lines`), relationships, state transitions, migration naming
- `contracts/ledger-module-contract.php` — 5-method PHP interface with full docblocks
- `contracts/post-entry-request.php` — `postEntry()` array shape with validation rules and example
- `contracts/api-contract.md` — all 11 REST endpoints with request/response shapes, error codes, pagination
- `quickstart.md` — 9 runnable validation scenarios mapped to success criteria

---

## Implementation Approach

1. **Foundation**: Migrations → Models → Enums → Exceptions → Domain events
2. **Contract update**: Expand `LedgerModuleContract` with 2 new methods; update `NullLedgerModule`
3. **Application services**: `LedgerAccountService` → `LedgerPostingService` → `LedgerBalanceService` → `LedgerReverseService`
4. **HTTP layer**: `PostEntryRequest` + `RegisterAccountRequest` → controllers → routes → `LedgerServiceProvider`
5. **Artisan command**: `CheckLedgerBalanceCommand`
6. **Tests**: Unit (model immutability, balance formula, exceptions) → Feature (HTTP endpoints, idempotency, reversal) → Integration (full posting flow, outbox, audit) → Architecture (boundary) → Concurrency
7. **E06 integration**: Remove `wallet.stub_ledger_balance` from `phpunit.xml`; verify `WalletBalanceService` tests pass

---

## Data / Financial Controls

- **No mutable balance field** on `ledger_accounts` (constitution Principle 4; FR-E07-024)
- **No partial posting**: journal + lines + outbox event in single `platform` connection transaction
- **Immutability**: `JournalLine` model throws `LogicException` on save/delete after insert
- **Unique constraint**: `journals.idempotency_key` UNIQUE — last-resort race guard
- **Per-currency balance invariant**: `ledger:check-balance` groups by `currency_code`
- **No hard-coded system account references**: resolved via `platform.configuration_values` (TBD-E07-001)

---

## Observability

- Correlation IDs propagated through all services, models, and events
- Metrics: `ledger.entries.posted`, `ledger.balance.query.duration`, `ledger.imbalance.detected`, `ledger.reversals.posted`
- Audit events: every mutation writes to `platform.audit_events`
- Outbox events: every mutation emits to `platform.outbox_events` inside the transaction

---

## Complexity Tracking

Any architecture deviation (e.g., materializing a running balance, changing the DB connection strategy, adding an external metrics sink) requires an ADR and a constitution check update before implementation.
