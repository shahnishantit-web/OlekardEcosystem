# Implementation Plan — E06 Wallet Management

**Branch**: `006-wallet-management`
**Status**: Planning Complete
**Last updated**: 2026-09-16

---

## Constitution Check

- [x] Approved Laravel/PHP 8.4+ / PostgreSQL / Redis / Next.js / Flutter stack respected — no deviations
- [x] No TBD value invented — all 8 TBDs seeded with `is_tbd = true` per governance; no values assumed
- [x] Authorization remains server-side — all endpoints enforce via `identity.require-permission` middleware (E04)
- [x] Financial integrity preserved — no mutable balance fields; `available_balance = max(0, ledger_balance − held_amount)`; `SELECT FOR UPDATE` on `currency_pockets` for concurrent debit serialization
- [x] Security/audit requirements incorporated — all 15 SRs mapped to implementation; audit events on all privileged operations; IDOR → HTTP 404
- [x] UX/design-system requirements incorporated — Consumer/Agent Flutter screens and Admin Next.js screens specified; design token system used; no new design system invented
- [x] QA strategy incorporated — unit, integration, API, security, concurrency, and architecture tests planned; concurrency tests on real PostgreSQL

**Gate evaluation**: No violations. E06 may proceed to tasks and implementation.

---

## Technical Context

| Item | Decision |
|---|---|
| Backend language | PHP 8.4+ / Laravel (latest in project) |
| Database | PostgreSQL — `wallet` schema; dedicated `wallet` DB connection |
| Cache/Queue | Redis + Laravel Horizon for queued jobs (wallet provisioning job) |
| ORM | Eloquent (bounded within `app/Modules/Wallet/`) |
| API style | REST / JSON; `/v1/wallets` prefix; platform envelope |
| Idempotency | Platform `IdempotencyService` (PostgreSQL-authoritative, Redis read-through) |
| Audit | Platform `AuditService` / `platform.audit_events` table |
| Events | `OutboxEventPublisher` → `platform.outbox_events`; transactional guarantee |
| UUID generation | `Ramsey\Uuid\Uuid::uuid7()` |
| Concurrency | `SELECT FOR UPDATE` on `wallet.currency_pockets` row |
| Balance source | E07 `LedgerModuleContract::getBalance()` — null stub until E07 |
| wallet_reference | `WalletReferenceGenerator`; format TBD-E06-007 via configuration |
| Architecture test | Pest `arch()` in `tests/Architecture/Modules/WalletBoundaryTest.php` |
| Hold service | Internal `WalletHoldService` (E06 Application layer); not cross-module |

---

## E01–E05 Dependencies

| Dependency | Interface | Available |
|---|---|---|
| Bearer token auth | `identity.auth` middleware | ✅ E01 |
| Permission enforcement | `identity.require-permission` middleware | ✅ E04 |
| KYC approval trigger | `Kyc.Case.Approved` outbox event payload | ✅ E05 |
| User UUID | `identity.users.id` cross-schema FK | ✅ E01 |

---

## Phase 0 — Research

**Status**: Complete. See `research.md` for all decisions.

Key resolutions:
1. `wallet` PostgreSQL schema, dedicated DB connection
2. `LedgerModuleContract::getBalance()` — extend existing contract; null stub until E07
3. `WalletReferenceGenerator` — configurable format; TBD-E06-007 governance respected
4. Event subscription via queued `ProcessKycApprovedForWalletJob`
5. `pest-arch` for architecture integrity test
6. `WalletHoldService` — internal Application service; not cross-module in E06
7. `wallet:expire-holds` schedule via configuration key; safe 5-min default
8. Balance stub mode (`wallet.stub_ledger_balance`) — test-environment only

---

## Phase 1 — Design

**Status**: Complete. All design artifacts generated.

| Artifact | Path | Status |
|---|---|---|
| Data model | `specs/006-wallet-management/data-model.md` | ✅ Done |
| API contract | `specs/006-wallet-management/contracts/api-contract.md` | ✅ Done |
| WalletModuleContract | `specs/006-wallet-management/contracts/wallet-module-contract.php` | ✅ Done |
| LedgerServiceContract extension | `specs/006-wallet-management/contracts/ledger-service-contract-extension.php` | ✅ Done |
| WalletHoldService contract | `specs/006-wallet-management/contracts/wallet-hold-service-contract.php` | ✅ Done |
| Quickstart validation guide | `specs/006-wallet-management/quickstart.md` | ✅ Done |

---

## Implementation Approach

Follow the established module structure from E05 KYC. All paths relative to `wallet-ecosystem/services/api/`.

### Module Directory Structure

```
app/Modules/Wallet/
├── Console/Commands/
│   └── ExpireWalletHoldsCommand.php              (wallet:expire-holds)
├── Domain/
│   ├── Contracts/
│   │   └── WalletModuleContract.php              (EXPANDED — FR-E06-046)
│   ├── Enums/
│   │   ├── WalletStatus.php
│   │   ├── PocketStatus.php
│   │   └── WalletLimitType.php
│   ├── Events/
│   │   ├── WalletCreatedEvent.php
│   │   ├── WalletActivatedEvent.php
│   │   ├── WalletPocketAddedEvent.php
│   │   ├── WalletStatusChangedEvent.php
│   │   ├── WalletFrozenEvent.php
│   │   ├── WalletUnfrozenEvent.php
│   │   ├── WalletClosedEvent.php
│   │   ├── WalletLimitsConfiguredEvent.php
│   │   └── WalletHoldExpiredEvent.php
│   ├── Exceptions/
│   │   ├── WalletAlreadyExistsException.php
│   │   ├── WalletNotFoundException.php
│   │   ├── InvalidWalletTransitionException.php
│   │   ├── InsufficientBalanceException.php
│   │   ├── WalletNotTransactableException.php
│   │   ├── WalletHasBalanceException.php
│   │   ├── PocketAlreadyExistsException.php
│   │   └── UnsupportedCurrencyException.php
│   └── Services/
│       └── WalletStateMachine.php
├── Application/
│   ├── Contracts/
│   │   └── WalletHoldServiceContract.php
│   └── Services/
│       ├── WalletProvisioningService.php         (event-driven + manual creation)
│       ├── WalletService.php                     (lifecycle management)
│       ├── WalletPocketService.php               (pocket creation)
│       ├── WalletBalanceService.php              (balance derivation)
│       ├── WalletLimitService.php                (limit enforcement + CRUD)
│       ├── WalletHoldService.php                 (hold placement/release)
│       └── WalletReferenceGenerator.php          (TBD-E06-007 compliant)
├── Infrastructure/
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── WalletController.php              (CRUD + freeze/unfreeze/close)
│   │   │   ├── WalletPocketController.php        (pocket creation + balance)
│   │   │   ├── WalletLimitController.php         (GET/PUT limits)
│   │   │   └── WalletStatusHistoryController.php (paginated history)
│   │   ├── Resources/
│   │   │   ├── WalletResource.php
│   │   │   ├── PocketResource.php
│   │   │   ├── BalanceResource.php
│   │   │   ├── LimitResource.php
│   │   │   └── WalletStatusHistoryResource.php
│   │   └── Requests/
│   │       ├── CreateWalletRequest.php
│   │       ├── UpdateWalletStatusRequest.php
│   │       ├── FreezeWalletRequest.php
│   │       ├── AddPocketRequest.php
│   │       └── UpdateLimitsRequest.php
│   ├── Jobs/
│   │   └── ProcessKycApprovedForWalletJob.php
│   ├── Migrations/
│   │   ├── 2026_09_18_001000_create_wallet_currencies.php
│   │   ├── 2026_09_18_001100_create_wallet_wallets.php
│   │   ├── 2026_09_18_001200_create_wallet_currency_pockets.php
│   │   ├── 2026_09_18_001300_create_wallet_wallet_holds.php
│   │   ├── 2026_09_18_001400_create_wallet_wallet_limits.php
│   │   ├── 2026_09_18_001500_create_wallet_wallet_status_history.php
│   │   ├── 2026_09_18_001600_seed_wallet_currencies.php
│   │   ├── 2026_09_18_001700_seed_wallet_permissions.php
│   │   └── 2026_09_18_001800_seed_wallet_limit_defaults.php
│   ├── Models/
│   │   ├── WalletModel.php
│   │   ├── CurrencyPocketModel.php
│   │   ├── WalletHoldModel.php
│   │   ├── WalletLimitModel.php
│   │   ├── WalletStatusHistoryModel.php
│   │   └── CurrencyModel.php
│   ├── NullImplementations/
│   │   └── NullWalletModule.php                  (EXPANDED — FR-E06-047)
│   └── Repositories/
│       ├── EloquentWalletRepository.php
│       ├── EloquentCurrencyPocketRepository.php
│       ├── WalletStatusHistoryRepository.php     (append-only)
│       └── WalletLimitRepository.php
└── Providers/
    └── WalletServiceProvider.php                 (bindings, routes, migrations, listener)
```

### Also modified outside Wallet module

```
app/Modules/Ledger/Domain/Contracts/LedgerModuleContract.php  (add getBalance())
app/Modules/Ledger/Infrastructure/NullImplementations/NullLedgerModule.php (implement getBalance)
routes/modules/wallet.php                                     (13 route definitions)
app/Console/Kernel.php                                        (schedule wallet:expire-holds)
tests/Architecture/Modules/WalletBoundaryTest.php             (FR-E06-049)
tests/Concurrency/Modules/Wallet/                             (CC-E06-001 to CC-E06-005)
tests/Security/Modules/Wallet/                                (IDOR, auth, permission)
tests/Feature/Api/V1/Wallets/                                 (API scenario tests)
tests/Unit/Modules/Wallet/                                    (unit tests)
tests/Integration/Modules/Wallet/                             (integration tests)
```

---

## Data / Financial Controls

| Control | Implementation |
|---|---|
| No mutable balance fields | No `balance` column on `currency_pockets`; enforced by code review + architecture test |
| Atomic wallet creation | Single `DB::connection('wallet')->transaction()` for wallet + BWP pocket + status_history |
| SELECT FOR UPDATE | `WalletHoldService::place()` and `WalletBalanceService::enforceDebit()` lock `currency_pockets` row |
| Idempotency | `IdempotencyService` on all 13 mutation endpoints |
| Hold lifecycle | `WalletHoldService` enforces create/release/expire; no direct writes from other modules |
| Outbox transactional | `OutboxEventPublisher::publish()` throws if called outside a transaction |
| Append-only history | `WalletStatusHistoryRepository` exposes only `append()`; no update/delete |
| TBD governance | All 8 TBDs seeded with `is_tbd = true`; enforcement checks `is_tbd` before applying |
| Wallet limits seeding | Default limit TBD configurations seeded in `platform.configuration_values` with `is_tbd = true` by `2026_09_18_001800_seed_wallet_limit_defaults.php`; `WalletProvisioningService` creates per-wallet `wallet_limits` rows using those configured defaults at wallet creation time |
| ledger_account_reference | Generated by E06 as UUID v7 at pocket creation; stored on `currency_pockets`; E07 uses the pre-assigned reference when registering entries — E07 need not be available at pocket creation |

---

## Observability

| Signal | Method |
|---|---|
| Structured logs | `Log::info('wallet.*', [...])` with `wallet_id`, `operation`, `correlation_id`, `outcome` |
| Audit trail | `AuditService::record()` on all privileged operations (FR-E06-039) |
| Metrics | `MetricsCollectorInterface::increment()` for `wallet.created`, `wallet.frozen`, etc. |
| Correlation IDs | `X-Correlation-ID` propagated through all service calls and event payloads |

---

## Complexity Tracking

No architecture deviations from the approved stack. Any future deviation requires an ADR and this document must be updated.

TBD-E06-008 (eager/lazy pocket creation): current default is lazy (BWP only at wallet creation). If eager creation is decided later, it affects `WalletProvisioningService` only — no schema change required.
