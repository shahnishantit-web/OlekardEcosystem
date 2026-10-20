# Implementation Plan — E13 Agent Float Management
**Date**: 2026-09-21

---

## Constitution Check

- [x] Approved Laravel/Next.js/Flutter stack respected — PHP 8.4+, PostgreSQL, Redis, Laravel Horizon; no deviations
- [x] No TBD value invented — `ledger.agent_float_funding_account.{currency}` is read from `ConfigurationServiceInterface`; throws `FloatFundingAccountNotConfiguredException` if absent (TBD-E13-001). Default limit values treated as `is_tbd = true` until admin explicitly sets them (TBD-E13-002)
- [x] Authorization remains server-side — permissions: `agent_float.wallet.create/view/fund`, `agent_float.limits.manage`, `agent_float.cash_in`, `agent_float.cash_out`, `agent_float.transactions.view_any`; enforced via Laravel Gate/Spatie middleware; seeded via migration
- [x] Financial integrity preserved — double-entry journals via `LedgerModuleContract::postEntry()` for all float balance changes; no direct `balance` column mutation; overdraft prevention via row-level lock (FOR UPDATE on `agent.float_wallets`)
- [x] Security/audit requirements incorporated — all financial mutations (fund, cash-in, cash-out) emit audit records via `AuditServiceInterface`; limit changes audit-logged; idempotency via `IdempotencyServiceInterface`; outbox events via `EventPublisherInterface`
- [x] UX/design-system requirements incorporated — N/A for this epic (backend API + domain layer only; Flutter/Next.js screens are future epics)
- [x] QA strategy incorporated — unit tests (service methods, state machine), feature tests (API contracts), concurrency tests (overdraft prevention, duplicate idempotency keys); see quickstart.md

---

## Technical Context

- **Backend**: `App\Modules\AgentFloat\` (new bounded module), Laravel / PHP 8.4+
- **Database**: PostgreSQL
  - `wallet` connection: `wallet.wallets` (extended with `wallet_type` column)
  - `platform` connection: `agent.float_wallets`, `agent.float_transactions`, `agent.float_limits`
- **Queue/cache**: Redis + Laravel Horizon (outbox processing)
- **API**: REST/OpenAPI — see `contracts/api.yaml`
- **Testing**: PHPUnit/Pest — unit, feature, concurrency

---

## Dependencies

| Dependency | Direction | How consumed |
|-----------|-----------|--------------|
| E07 LedgerService | E13 depends on E07 | `LedgerModuleContract::postEntry()`, `getBalance()`, `registerAccount()` |
| E12 Agent Management | E13 depends on E12 | `agent.agents` table (approved agent lookup); listens to `agent.suspended` / `agent.reactivated` outbox events |
| E06 Wallet Management | E13 depends on E06 | `wallet.wallets` table (float wallet storage); `WalletModuleContract::getPocketLedgerAccountRef()` for cash-in/out customer pocket lookup |
| E04 Permissions | E13 depends on E04 | Float permissions seeded; enforced via middleware |

---

## Module Structure

```
app/Modules/AgentFloat/
├── Application/
│   └── Services/
│       ├── FloatWalletProvisioningService.php   (US-FLOAT-001)
│       ├── FloatFundingService.php               (US-FLOAT-003)
│       ├── FloatCashInService.php                (US-FLOAT-004, US-FLOAT-006)
│       ├── FloatCashOutService.php               (US-FLOAT-005)
│       ├── FloatLimitService.php                 (US-FLOAT-007)
│       ├── FloatAlertService.php                 (US-FLOAT-008)
│       └── FloatTransactionService.php           (US-FLOAT-009)
├── Domain/
│   ├── Enums/
│   │   ├── FloatTransactionType.php             (funding|cash_in|cash_out)
│   │   └── FloatTransactionDirection.php        (debit|credit)
│   ├── Events/
│   │   ├── FloatWalletCreatedEvent.php
│   │   ├── FloatFundedEvent.php
│   │   ├── FloatCashInEvent.php
│   │   ├── FloatCashOutEvent.php
│   │   ├── FloatLimitsConfiguredEvent.php
│   │   ├── FloatWalletStatusChangedEvent.php
│   │   └── FloatLowAlertEvent.php
│   └── Exceptions/
│       ├── FloatWalletAlreadyExistsException.php
│       ├── FloatWalletNotFoundException.php
│       ├── FloatWalletNotActiveException.php
│       ├── InsufficientFloatException.php
│       ├── FloatLimitExceededException.php
│       ├── FloatTransactionLimitExceededException.php
│       ├── FloatDailyLimitExceededException.php
│       ├── FloatFundingAccountNotConfiguredException.php
│       └── AgentNotEligibleForFloatException.php
└── Infrastructure/
    ├── Http/
    │   ├── Controllers/
    │   │   ├── AgentFloatWalletController.php   (create, view)
    │   │   ├── AgentFloatFundingController.php  (fund)
    │   │   ├── AgentFloatLimitController.php    (PATCH, GET limits)
    │   │   ├── AgentFloatCashInController.php   (cash-in)
    │   │   ├── AgentFloatCashOutController.php  (cash-out)
    │   │   └── AgentFloatTransactionController.php (list)
    │   ├── Requests/
    │   │   ├── CreateFloatWalletRequest.php
    │   │   ├── FundFloatWalletRequest.php
    │   │   ├── UpdateFloatLimitsRequest.php
    │   │   ├── CashInRequest.php
    │   │   ├── CashOutRequest.php
    │   │   └── ListFloatTransactionsRequest.php
    │   └── Resources/
    │       ├── FloatWalletResource.php
    │       ├── FloatTransactionResource.php
    │       └── FloatLimitResource.php
    ├── Listeners/
    │   ├── AgentSuspendedFloatListener.php      (mirrors float wallet to suspended)
    │   └── AgentReactivatedFloatListener.php    (restores float wallet to active)
    ├── Migrations/
    │   ├── 2026_09_26_001000_add_wallet_type_to_wallets.php      (wallet connection — add discriminator, fix unique index)
    │   ├── 2026_09_26_001100_create_agent_float_wallets.php      (platform connection — reference table)
    │   ├── 2026_09_26_001200_create_agent_float_transactions.php (platform connection)
    │   ├── 2026_09_26_001300_create_agent_float_limits.php       (platform connection)
    │   └── 2026_09_26_001400_seed_agent_float_permissions.php    (platform connection)
    ├── Models/
    │   ├── AgentFloatWalletModel.php            (agent.float_wallets)
    │   ├── FloatTransactionModel.php            (agent.float_transactions)
    │   └── FloatLimitModel.php                  (agent.float_limits)
    └── Providers/
        └── AgentFloatServiceProvider.php
```

---

## Ledger Account Naming

| Wallet | `ledger_account_reference` pattern | `ownerType` | `accountType` |
|--------|-------------------------------------|-------------|---------------|
| Agent float wallet | `agent_float:{walletId}` | `agent_float` | `asset` |
| Platform funding source (TBD-E13-001) | Configurable, read from `ledger.agent_float_funding_account.BWP` | `system_float` | `asset` |

---

## Journal Posting Patterns

All amounts in minor units. Entry type `'debit'` for funding (platform perspective), `'credit'` for cash-in (platform gives float to customer), `'debit'` for cash-out (platform receives float from customer).

**Float funding** (admin → agent):
```
lines:
  - { ledger_account_reference: agent_float:{walletId}, direction: debit,  amount: X }
  - { ledger_account_reference: {platformFundingRef},    direction: credit, amount: X }
```

**Cash-in** (agent float → customer pocket):
```
lines:
  - { ledger_account_reference: agent_float:{walletId},  direction: credit, amount: X }
  - { ledger_account_reference: customer_pocket:{pocketRef}, direction: debit, amount: X }
```

**Cash-out** (customer pocket → agent float):
```
lines:
  - { ledger_account_reference: customer_pocket:{pocketRef}, direction: credit, amount: X }
  - { ledger_account_reference: agent_float:{walletId},      direction: debit,  amount: X }
```

---

## Overdraft Prevention Pattern

`FloatCashInService.process()`:
1. Resolve agent float wallet via `agent.float_wallets WHERE agent_id = ?`
2. `BEGIN` platform-connection transaction
3. `SELECT * FROM agent.float_wallets WHERE id = ? FOR UPDATE` — serializes concurrent cash-ins
4. `LedgerModuleContract::getBalance(ledgerAccountReference)` — read authoritative balance
5. Validate `balance >= amount` — throw `InsufficientFloatException` if not
6. `LedgerModuleContract::postEntry(...)` — post journal
7. `INSERT INTO agent.float_transactions` — record transaction
8. Check low-float threshold → emit `FloatLowAlertEvent` if triggered and not already alerted
9. `COMMIT`

---

## Idempotency Key Namespacing

| Operation | Namespace |
|-----------|-----------|
| Create float wallet | `float_wallet_create:{agentId}:{idempotencyKey}` |
| Float funding | `float_fund:{agentId}:{idempotencyKey}` |
| Cash-in | `float_cash_in:{agentId}:{customerId}:{idempotencyKey}` |
| Cash-out | `float_cash_out:{agentId}:{customerId}:{idempotencyKey}` |

---

## API Routes

```php
// AgentFloatServiceProvider::boot()
Route::prefix('v1')->middleware(['identity.auth'])->group(function () {
    Route::post  ('agents/{agentId}/float-wallet',              [AgentFloatWalletController::class, 'create']);
    Route::get   ('agents/{agentId}/float-wallet',              [AgentFloatWalletController::class, 'show']);
    Route::post  ('agents/{agentId}/float-wallet/fund',         [AgentFloatFundingController::class, 'fund']);
    Route::patch ('agents/{agentId}/float-wallet/limits',       [AgentFloatLimitController::class, 'update']);
    Route::get   ('agents/{agentId}/float-wallet/limits',       [AgentFloatLimitController::class, 'show']);
    Route::get   ('agents/{agentId}/float-wallet/transactions', [AgentFloatTransactionController::class, 'index']);
    Route::post  ('agents/{agentId}/customers/{customerId}/cash-in',  [AgentFloatCashInController::class, 'cashIn']);
    Route::post  ('agents/{agentId}/customers/{customerId}/cash-out', [AgentFloatCashOutController::class, 'cashOut']);
});
```

---

## Event/Listener Registration

```php
// In AgentFloatServiceProvider
protected $listen = [
    \App\Modules\Agent\Domain\Events\AgentSuspendedEvent::class   => [AgentSuspendedFloatListener::class],
    \App\Modules\Agent\Domain\Events\AgentReactivatedEvent::class => [AgentReactivatedFloatListener::class],
];
```

---

## Permissions to Seed

| Permission | Description |
|-----------|-------------|
| `agent_float.wallet.create` | Admin: create float wallet for an agent |
| `agent_float.wallet.view` | Agent: view own float wallet |
| `agent_float.wallet.view_any` | Admin: view any agent float wallet |
| `agent_float.wallet.fund` | Admin: fund agent float wallet |
| `agent_float.limits.manage` | Admin: set/update float limits |
| `agent_float.limits.view` | Admin/agent: view float limits |
| `agent_float.cash_in` | Agent: perform cash-in for linked customer |
| `agent_float.cash_out` | Agent: perform cash-out for linked customer |
| `agent_float.transactions.view` | Agent: view own float transactions |
| `agent_float.transactions.view_any` | Admin: view any agent float transactions |

---

## Implementation Approach

1. Migrations (wallet_type extension + new agent tables + permissions)
2. Domain layer (enums, events, exceptions)
3. Infrastructure models (AgentFloatWalletModel, FloatTransactionModel, FloatLimitModel)
4. Application services (provisioning → funding → cash-in/out → limits → alert → history)
5. Controllers, requests, API resources
6. Listeners for agent lifecycle events
7. Service provider + route registration
8. Unit tests, feature tests, concurrency tests
9. OpenAPI annotations on controllers

---

## Data / Financial Controls

- Immutable ledger entries (via LedgerModuleContract — no direct journal edits)
- Idempotency on all financial mutations
- Row-level lock (FOR UPDATE) on `agent.float_wallets` for overdraft serialization
- Concurrency tests required: simultaneous cash-ins, duplicate idempotency key replay
- `agent.float_transactions.reference` UNIQUE — prevents duplicate transaction records
- `agent.float_wallets.agent_id` UNIQUE — one float wallet per agent

---

## Observability

- Structured logs on all service operations (wallet creation, funding, cash-in, cash-out, limit changes, alert emission)
- Metrics: `agent_float.cash_in.completed`, `agent_float.cash_out.completed`, `agent_float.fund.completed`, `agent_float.low_alert.emitted`
- Audit records for: float wallet creation, float funding, cash-in, cash-out, limit configuration changes, float wallet status changes
- Correlation IDs propagated from HTTP request header through all service calls

---

## Complexity Tracking

No architecture deviations. Uses established module patterns from E08/E09/E12. Any future change to the approved stack requires an ADR.
