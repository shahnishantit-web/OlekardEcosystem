# Research — E07 Wallet Ledger

All NEEDS CLARIFICATION items from the plan template have been resolved by reading the E01–E06 codebase. This document records every technical decision with rationale.

---

## 1. Database Connection Strategy

**Decision**: All `LedgerAccount`, `Journal`, and `JournalLine` models use `protected $connection = 'platform'` with explicit schema-qualified table names (`ledger.ledger_accounts`, `ledger.journals`, `ledger.journal_lines`).

**Rationale**: `OutboxEventPublisher::publish()` enforces `DB::connection('platform')->transactionLevel() >= 1` before inserting into `platform.outbox_events`. Because the outbox insert must be atomic with the journal insert, the entire posting transaction must be on the `platform` connection. Using the `ledger` connection for models would require a cross-connection transaction, which PostgreSQL does not support within a single `BEGIN` block through two separate Eloquent connections.

**Alternatives considered**: Separate `ledger` connection with a two-phase commit — rejected; no XA transaction support in the application layer and adds operational complexity. Running `OutboxEventPublisher` on the `ledger` connection — rejected; `OutboxEventPublisher` writes to `platform.outbox_events` and its design is connection-specific.

**Implementation note**: Migrations for ledger tables continue to use `protected $connection = 'ledger'` so that `php artisan migrate --database=ledger` targets the correct connection. All connections point to the same physical PostgreSQL database; they differ only in `search_path`. Explicit `ledger.*` table prefixes work on any connection.

**Source**: `app/Modules/Platform/Application/Services/OutboxEventPublisher.php` (requires `platform` transactionLevel ≥ 1); `config/database.php` (`pgsql_config()` helper — all connections share one host).

---

## 2. Transaction Scope for Posting

**Decision**: `LedgerPostingService::post()` wraps the entire posting operation in `DB::connection('platform')->transaction(function () { ... })`.

**Rationale**: The transaction must encompass:
1. `INSERT INTO ledger.journals`
2. `INSERT INTO ledger.journal_lines` (multiple rows)
3. `INSERT INTO platform.outbox_events` (OutboxEventPublisher)
4. `INSERT INTO platform.idempotency_records` (IdempotencyService::acquireLock)

All of these must commit or roll back atomically. Since steps 3 and 4 use `DB::connection('platform')`, the wrapping transaction must also be on `platform`.

**Race condition guard**: The `idempotency_key` unique constraint on `ledger.journals` (accessed via the `platform` connection with explicit table prefix) serves as the last-resort race guard if two requests with the same key slip past the `IdempotencyService::acquireLock()` fast path simultaneously.

---

## 3. UUID Generation

**Decision**: `(string) Ramsey\Uuid\Uuid::uuid7()` for all primary keys (`id` fields on all three entities).

**Rationale**: UUID v7 is time-ordered, reducing B-tree page splits on sequential inserts. Already in use across E01–E06 (confirmed in E06 Wallet migrations, E05 KYC migrations, E03 Device migrations). The `Ramsey\Uuid` package is already a project dependency.

**Source**: `app/Modules/Wallet/Infrastructure/Migrations/2026_09_18_001200_create_wallet_currency_pockets.php`; `app/Modules/Platform/Domain/Events/BaseIntegrationEvent.php`.

---

## 4. Balance Query Strategy

**Decision**: Raw SQL via `DB::connection('platform')->selectOne()` rather than Eloquent aggregates.

**Rationale**: Balance computation is a single aggregation query with a conditional `SUM`. Using raw SQL avoids loading individual `JournalLine` models into memory and gives explicit control over the query plan and connection. Eloquent sum() helpers would work but obscure the direction-conditional logic.

**Query pattern (normal_balance = credit)**:
```sql
SELECT
    COALESCE(SUM(CASE WHEN direction = 'credit' THEN amount ELSE 0 END), 0)
  - COALESCE(SUM(CASE WHEN direction = 'debit'  THEN amount ELSE 0 END), 0)
    AS balance
FROM ledger.journal_lines jl
JOIN ledger.journals      j  ON j.id = jl.journal_id
JOIN ledger.ledger_accounts la ON la.id = jl.ledger_account_id
WHERE la.ledger_account_reference = ?
  AND j.status = 'posted'
```

**Isolation**: FR-E07-023 requires `READ COMMITTED` minimum. When `getBalance()` is called from within an active `platform` connection transaction (e.g., E06's `SELECT FOR UPDATE` debit serialization), the query runs on the same connection, sees the same transaction snapshot, and returns a consistent balance.

---

## 5. Idempotency Implementation

**Decision**: Dual-layer idempotency guard.

**Layer 1 — Application fast path**: `IdempotencyService::findCompletedResponse()` checks Redis first (read-through from PostgreSQL). If a completed response is found, it is returned immediately without touching the ledger tables.

**Layer 2 — Database race guard**: `journals.idempotency_key` has a `UNIQUE` constraint. If two requests with the same key pass the application fast path simultaneously (race window), only one `INSERT INTO ledger.journals` succeeds. The second throws a unique-constraint violation; this is caught and the original journal is fetched and returned as an idempotent replay (CC-E07-003).

**Source**: `app/Modules/Platform/Application/Services/IdempotencyService.php`; pattern matches `IdempotencyService::acquireLock()` INSERT ON CONFLICT DO NOTHING pattern from E06.

---

## 6. Module Directory Structure

Following the Wallet module pattern exactly:

```
app/Modules/Ledger/
├── Application/Services/
│   ├── LedgerPostingService.php        — postEntry(), idempotency, transaction wrap
│   ├── LedgerBalanceService.php        — getBalance(), entryExists()
│   ├── LedgerReverseService.php        — reverse()
│   └── LedgerAccountService.php        — registerAccount(), auto-register
├── Domain/
│   ├── Contracts/
│   │   └── LedgerModuleContract.php    — 5-method interface (updated from E06 stub)
│   ├── Enums/
│   │   ├── AccountType.php             — asset, liability, income, expense, equity
│   │   ├── NormalBalance.php           — debit, credit
│   │   ├── JournalEntryType.php        — debit, credit, fee, revenue, commission, reversal
│   │   ├── JournalStatus.php           — posted
│   │   ├── JournalLineDirection.php    — debit, credit
│   │   └── LedgerAccountStatus.php     — active, closed
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
│   └── Permissions/
│       └── LedgerPermissions.php       — permission constant strings
├── Infrastructure/
│   ├── Console/Commands/
│   │   └── CheckLedgerBalanceCommand.php
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
│   │   ├── LedgerAccountModel.php      — connection=platform, table=ledger.ledger_accounts
│   │   ├── JournalModel.php            — connection=platform, table=ledger.journals
│   │   └── JournalLineModel.php        — immutability enforcement on save()/delete()
│   ├── NullImplementations/
│   │   └── NullLedgerModule.php        — updated: 5 methods throw ModuleNotImplementedException
│   └── Repositories/
│       ├── EloquentLedgerAccountRepository.php
│       └── EloquentJournalRepository.php
└── Providers/
    └── LedgerServiceProvider.php       — binds LedgerModuleContract → LedgerService adapter
```

**Pattern source**: `app/Modules/Wallet/` structure as inspected in E06 implementation.

---

## 7. Event Naming and Base Class

**Decision**: Extend `BaseIntegrationEvent` from `app/Modules/Platform/Domain/Events/BaseIntegrationEvent.php`. Follow `{Domain}.{Aggregate}.{PastTenseVerb}` dot-notation PascalCase convention.

**Event names**:
- `Ledger.Entry.Posted`
- `Ledger.Entry.Reversed`
- `Ledger.Account.Registered`
- `Ledger.Imbalance.Detected`

**Constructor signature** (from BaseIntegrationEvent): `($aggregateId, ?$actorId, $correlationId, ?$causationId)`

**Source**: `app/Modules/Platform/Domain/Events/BaseIntegrationEvent.php`.

---

## 8. Model Connection vs Migration Connection Split

**Decision**:
- **Models**: `protected $connection = 'platform'` + `protected $table = 'ledger.{table}'`
- **Migrations**: `protected $connection = 'ledger'`

**Rationale**: Models must use `platform` connection for atomic outbox writes (see §1). Migrations must use `ledger` connection so that `php artisan migrate --database=ledger` and `Schema::connection('ledger')` target the correct schema. Both point to the same PostgreSQL server; they differ only in `search_path`.

---

## 9. Architecture Integrity Test

**Decision**: Pest `arch()` assertions in `tests/Architecture/Modules/LedgerBoundaryTest.php`.

**Pattern** (from `tests/Architecture/Modules/WalletBoundaryTest.php`):
```php
arch('Ledger models are not imported outside the Ledger module')
    ->expect('App\Modules\Ledger\Infrastructure\Models')
    ->not->toBeUsedIn([
        'App\Modules\Identity',
        'App\Modules\Kyc',
        'App\Modules\Platform',
        'App\Modules\Wallet',
    ]);
```

---

## 10. Imbalance Detection — Per-Currency Aggregation

**Decision**: `ledger:check-balance` groups by `currency_code` before comparing debit and credit sums.

**Rationale**: Summing amounts across currencies is financially meaningless (FR-E07-036, §9.3). A single `SUM(all debits) == SUM(all credits)` across all currencies could mask imbalances in individual currencies that cancel each other out by coincidence.

**Query pattern**:
```sql
SELECT
    currency_code,
    SUM(CASE WHEN direction = 'debit'  THEN amount ELSE 0 END) AS total_debits,
    SUM(CASE WHEN direction = 'credit' THEN amount ELSE 0 END) AS total_credits
FROM ledger.journal_lines
GROUP BY currency_code
HAVING SUM(CASE WHEN direction = 'debit'  THEN amount ELSE 0 END)
    != SUM(CASE WHEN direction = 'credit' THEN amount ELSE 0 END)
```

---

## 11. Service Provider Pattern

**Decision**: `LedgerServiceProvider` follows the `WalletServiceProvider` pattern exactly.

```php
public function register(): void {
    $this->app->bind(LedgerModuleContract::class, LedgerService::class);
}
public function boot(): void {
    $this->loadMigrationsFrom(__DIR__.'/../Infrastructure/Migrations');
    $this->loadRoutesFrom(base_path('routes/modules/ledger.php'));
    // Register CheckLedgerBalanceCommand via Schedule if cadence is configured
}
```

**Source**: `app/Modules/Wallet/Providers/WalletServiceProvider.php`.

---

## 12. Response Envelope and Controller Pattern

**Decision**: Follow `WalletController` conventions exactly.

- Response: `{ success, data, error, correlation_id }`
- Actor ID: `$request->attributes->get('identity.user_id')`
- Idempotency-Key: `$request->header('Idempotency-Key')`
- Controllers are thin; all business logic in Application Services
- HTTP status: 201 on creation, 200 on idempotent replay or read

**Source**: `app/Modules/Wallet/Infrastructure/Http/Controllers/WalletController.php`.

---

## 13. Concurrency Tests Location

**Decision**: `tests/Concurrency/Modules/Ledger/`

**Pattern source**: `tests/Architecture/Modules/WalletBoundaryTest.php` and existing E06 concurrency tests under `tests/Concurrency/Modules/Wallet/`.

---

## 14. Metrics Emission

**Decision**: Emit metrics via structured log entries tagged with metric name, consistent with E06 pattern (no separate metrics client — structured logs are scraped by the observability pipeline).

**Metric names** (FR-E07-047):
- `ledger.entries.posted` (counter, tagged: `entry_type`)
- `ledger.balance.query.duration` (histogram, ms)
- `ledger.imbalance.detected` (counter)
- `ledger.reversals.posted` (counter)

---

## 15. `wallet.stub_ledger_balance` Removal

**Decision**: The `WALLET_STUB_LEDGER_BALANCE=true` entry in `phpunit.xml` is removed as part of E07 completion (FR-E07-045, SC-E07-011). It remains available as a developer override via `.env` but must not be in CI after E07 is live.

**Impact**: Any E06 test that depended on stubbed ledger balance (e.g., `WalletBalanceServiceTest`) must be updated to seed real ledger state via `LedgerModuleContract` or test doubles once E07 is integrated.
