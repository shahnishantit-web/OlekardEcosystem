# Research — E08 Wallet Funding

All NEEDS CLARIFICATION items from the plan template have been resolved by reading the E01–E07 codebase and spec artifacts. This document records every technical decision with rationale.

---

## 1. Database Connection Strategy

**Decision**: All `FundingTransaction`, `FundingProviderCallback`, `FundingReceipt`, `BankReconciliation`, and `UnmatchedDeposit` models use `protected $connection = 'platform'` with explicit schema-qualified table names (`funding.funding_transactions`, etc.).

**Rationale**: Identical to E07. `OutboxEventPublisher::publish()` enforces `DB::connection('platform')->transactionLevel() >= 1` before inserting into `platform.outbox_events`. All funding state changes (credit posting, status transitions, outbox events, idempotency records) must be atomic in a single `BEGIN/COMMIT` block, which requires a single connection.

**Alternatives considered**: Separate `funding` connection — rejected for the same reason as E07; cross-connection atomicity is not achievable within a single Laravel DB transaction.

**Implementation note**: Migrations use `Schema::connection('ledger')` to create `funding.*` schema tables, consistent with the E07 pattern of using the `ledger` connection for DDL while the `platform` connection handles DML.

**Source**: `app/Modules/Platform/Application/Services/OutboxEventPublisher.php`; `research.md §1` (E07).

---

## 2. Transaction Scope for Financial Operations

**Decision**: Every state-changing operation wraps all DB mutations in `DB::connection('platform')->transaction(function () { ... })`. This encompasses: funding transaction status update + receipt creation + outbox event + idempotency record + audit event.

**Rationale**: The financial integrity guarantee (constitution Principle 4) requires that no partial state is possible. A failed `postEntry()` must roll back the `FundingTransaction` status update and the outbox event together. A failed outbox write must roll back the credit posting.

**Critical invariant**: `FundingTransaction.status = completed` and `FundingTransaction.ledger_journal_id = $journalId` are set inside the same transaction that posts to the ledger. If either fails, neither persists.

---

## 3. Provider Callback Processing: Inline vs Queue

**Decision**: Provider callbacks are processed **inline** within the HTTP request cycle, not dispatched to a queue.

**Rationale**: Queued callback processing creates a window where the provider's HTTP request has returned `200` but the wallet has not yet been credited. If the queue job fails permanently, the wallet is never credited but the provider believes the transaction succeeded. Inline processing ensures the `200` response to the provider is only sent after the wallet is successfully credited (or after a confirmed duplicate is detected).

**Implementation**: The callback controller:
1. Validates provider HMAC/signature (fast fail on invalid signature).
2. Checks `IdempotencyService` using `provider_reference_id` as key (fast `200` for duplicates).
3. Opens a DB transaction: stores `FundingProviderCallback`, validates amount, posts ledger entry, transitions status, creates receipt, writes outbox event.
4. Returns `200` on commit; returns `500` on transaction rollback (provider will retry).

**Retry handling**: The provider retries on `500`; idempotency guard on the `provider_reference_id` ensures at-most-once crediting even with concurrent retries.

**Alternatives considered**: Queue-based processing — rejected because it breaks the synchronous acknowledgement contract with providers and creates orphaned-success scenarios on queue failure.

---

## 4. FundingProviderAdapter Interface

**Decision**: E08 defines `FundingProviderAdapter` as a PHP interface (`app/Modules/Funding/Domain/Contracts/FundingProviderAdapter.php`) with two required methods: `initiate()` and `verifyCallback()`. A `MockFundingProviderAdapter` is the only concrete implementation in E08; real adapters are TBD-E08-001 / E1000.

**Rationale**: The adapter pattern keeps E08 provider-agnostic. The IoC container resolves the adapter via a service provider binding keyed on the `funding_method` string (`funding.adapter.mobile_money`, `funding.adapter.bank_transfer`, etc.). E08 resolves the adapter via `App::make("funding.adapter.{$method}")`.

**MockFundingProviderAdapter behaviour**: Configurable via `funding.mock.success_rate` (default 1.0 = always succeed). In tests, override to 0.0 to simulate failures. Emits a synthetic `provider_reference_id` = `MOCK-{UUID}`.

---

## 5. Agent PIN Verification

**Decision**: E08 calls `AuthModuleContract::verifyTransactionPin(string $actorId, string $pin): bool` to verify agent transaction PINs. This contract method is expected to exist in the Auth module (E01/E03); if it does not, E08 adds it as a contract extension.

**Implementation**: The plaintext PIN is extracted from the request, passed to `AuthModuleContract::verifyTransactionPin()`, and immediately discarded. The Auth service performs `Hash::check($pin, $agent->transaction_pin_hash)`. The raw PIN is never stored, logged, or included in any event payload (SR-E08-005).

**Step-up check**: For agent cash-in above `auth.step_up_threshold` BWP, the caller must present a valid step-up token (per E03 Device & Session). The middleware checks `request->hasValidStepUp()` before the controller executes.

---

## 6. Customer Pocket Ledger Account Reference Resolution

**Decision**: E08 resolves the customer's wallet pocket ledger account reference by calling `WalletModuleContract::getPocketLedgerAccountRef(string $walletId, string $currencyCode): string`. This method returns the pocket's UUID v7 (which is also the ledger account reference — a convention established in E06).

**Dependency gap**: `WalletModuleContract` does not currently expose `getPocketLedgerAccountRef()`. E08's FRs require this method to be added to `WalletModuleContract` as part of E08 implementation (this is a contract extension to E06, not a new module).

**Before posting**: `FundingCreditService` calls `LedgerModuleContract::registerAccount()` for the pocket reference (idempotent) to ensure the account is registered in the ledger even if E06 used `NullLedgerModule` when the pocket was created.

---

## 7. Platform Float Account Reference Resolution

**Decision**: E08 resolves the platform float account reference from `PlatformConfigurationService::get("ledger.float_account.{$currencyCode}")`. If the key is absent or null, `FundingCreditService` throws `FloatAccountNotConfiguredException` — a non-recoverable config error caught at startup in the service provider's boot-time validation.

**TBD-E08-004 / TBD-E07-001**: The actual values for `ledger.float_account.{currency_code}` are not yet seeded. E08 creates a migration that seeds a placeholder key structure (`ledger.float_account.BWP = NULL`). Until the real reference is seeded, E08 credit posting fails at boot-time validation rather than silently posting to a null account.

---

## 8. Agent Float Account Reference Convention

**Decision**: Agent float ledger account references follow a deterministic convention: `agent_float_{agent_id}_{currency_code}` (e.g., `agent_float_01930f7b-...-bwp`). This is constructed in `AgentCashInService` from the agent's UUID and the currency code (lowercased ISO 4217).

**Registration**: On the first cash-in for a given agent+currency, `AgentCashInService` calls `LedgerModuleContract::registerAccount()` with:
- `ledgerAccountReference`: `agent_float_{agentId}_{currencyCode}`
- `accountType`: `asset`
- `currencyCode`: the cash-in currency
- `ownerReference`: `$agentId`
- `ownerType`: `agent_float`

Since `registerAccount()` is idempotent, subsequent cash-ins for the same agent+currency are no-ops on registration.

**Balance check**: Agent float balance is checked via `LedgerModuleContract::getBalance("agent_float_{agentId}_{currencyCode}")` before each cash-in (CC-E08-002: inside the DB transaction with SELECT FOR UPDATE on the ledger account row).

---

## 9. postEntry() Request Shape for Funding Credits

**Decision**: Two distinct `entry_type` values are used:
- **Digital funding credit** (`credit`): debit platform float, credit customer pocket.
- **Agent cash-in** (`debit`): debit agent float, credit customer pocket. Agent cash-in uses `entry_type = 'debit'` because the initiating movement is a debit of the agent's asset account.

**Digital funding credit payload**:
```php
$this->ledgerContract->postEntry([
    'idempotency_key'       => 'funding_credit_' . $tx->id,
    'entry_type'            => 'credit',
    'currency_code'         => $tx->pocket_currency_code,
    'transaction_reference' => $tx->transaction_reference,
    'correlation_id'        => $correlationId,
    'description'           => 'Wallet funding via ' . $tx->funding_method->value,
    'actor_id'              => 'system',
    'lines' => [
        ['ledger_account_reference' => $floatRef,   'direction' => 'debit',  'amount' => $tx->net_amount],
        ['ledger_account_reference' => $pocketRef,  'direction' => 'credit', 'amount' => $tx->net_amount],
    ],
]);
```

**Agent cash-in payload**:
```php
$this->ledgerContract->postEntry([
    'idempotency_key'       => 'agent_cashin_' . $tx->id,
    'entry_type'            => 'debit',
    'currency_code'         => $tx->pocket_currency_code,
    'transaction_reference' => $tx->transaction_reference,
    'correlation_id'        => $correlationId,
    'description'           => 'Agent cash-in',
    'actor_id'              => $tx->agent_id,
    'lines' => [
        ['ledger_account_reference' => $agentFloatRef, 'direction' => 'debit',  'amount' => $tx->amount],
        ['ledger_account_reference' => $pocketRef,     'direction' => 'credit', 'amount' => $tx->amount],
    ],
]);
```

**Idempotency key prefix**: `funding_credit_` / `agent_cashin_` + `tx->id` ensures the key is unique per transaction and survives callback retries.

---

## 10. Bank Reference Generation Algorithm

**Decision**: Bank reference format: `OLK-{YYYYMMDD}-{8CHAR}` where `{8CHAR}` is `strtoupper(Str::random(8))` (Laravel alphanumeric, charset `[a-zA-Z0-9]`). Generation retries up to 5 times on DB UNIQUE constraint violation before throwing `BankReferenceGenerationException`.

**Collision probability**: With 36^8 ≈ 2.8 trillion possible suffixes and a realistic volume of < 100,000 open references at any time, collision probability per attempt is negligible (< 0.000004%).

**Date component**: Uses the current UTC date (`Carbon::now('UTC')->format('Ymd')`), giving a natural grouping for daily reconciliation queries.

---

## 11. Idempotency Key Namespacing

**Decision**: Three separate idempotency namespaces to prevent cross-domain collisions:
- Customer mutations: `funding:{client_idempotency_key}` (supplied via `Idempotency-Key` header)
- Provider callbacks: `funding_callback:{provider_reference_id}` (keyed on provider's transaction ID)
- Bank notifications: `funding_bank:{bank_notification_id}` (keyed on bank's transaction ID)

**TTL**: All use `funding.idempotency.ttl_seconds` (default 86 400 s; see FR-E08-041).

---

## 12. Expiry Command Registration and Scheduling

**Decision**: `ExpireStaleTransactionsCommand` is registered in `FundingServiceProvider::register()` (added to the `commands` array) and scheduled in `FundingServiceProvider::boot()` via `$this->callAfterResolving(Schedule::class, fn(Schedule $s) => $s->command('funding:expire-stale')->everyFiveMinutes())`.

**Idempotency of the command**: The command uses a chunked query to avoid loading all stale records into memory. Each record is processed in its own sub-transaction to prevent a single failure from blocking all other expirations.

---

## 13. Module Structure

```
app/Modules/Funding/
├── Application/
│   ├── FundingService.php                      (FundingModuleContract adapter)
│   ├── Services/
│   │   ├── FundingInitiationService.php
│   │   ├── FundingConfirmationService.php
│   │   ├── FundingCancellationService.php
│   │   ├── FundingLimitService.php
│   │   ├── FundingFeeService.php
│   │   ├── FundingCreditService.php            (posts ledger entry on success)
│   │   ├── ProviderCallbackService.php         (validates + processes callbacks inline)
│   │   ├── BankNotificationService.php
│   │   ├── BankMatchingService.php
│   │   ├── BankReconciliationService.php
│   │   └── AgentCashInService.php
│   └── Commands/
│       └── ExpireStaleTransactionsCommand.php
├── Domain/
│   ├── Contracts/
│   │   ├── FundingModuleContract.php
│   │   └── FundingProviderAdapter.php
│   ├── Enums/
│   │   ├── FundingMethod.php                   (mobile_money|bank_transfer|card|agent)
│   │   ├── FundingStatus.php                   (pending|processing|awaiting_match|completed|failed|cancelled)
│   │   └── FundingFailureReason.php            (provider_declined|amount_mismatch|processing_timeout|bank_reference_expired|wallet_suspended)
│   ├── Events/
│   │   ├── FundingTransactionInitiated.php
│   │   ├── FundingTransactionConfirmed.php
│   │   ├── FundingTransactionCompleted.php
│   │   ├── FundingTransactionFailed.php
│   │   ├── FundingTransactionCancelled.php
│   │   ├── FundingTransactionExpired.php
│   │   ├── FundingAgentCashInCompleted.php
│   │   ├── FundingBankDepositReconciled.php
│   │   └── FundingUnmatchedDepositReceived.php
│   └── Exceptions/
│       ├── FundingTransactionNotFoundException.php
│       ├── FundingLimitExceededException.php
│       ├── InsufficientFloatException.php
│       ├── TransactionNotModifiableException.php
│       ├── DuplicateCallbackException.php
│       ├── FloatAccountNotConfiguredException.php
│       ├── FeeChangedException.php
│       └── BankReferenceGenerationException.php
├── Infrastructure/
│   ├── Adapters/
│   │   └── MockFundingProviderAdapter.php
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── FundingTransactionController.php
│   │   │   ├── ProviderCallbackController.php
│   │   │   ├── BankNotificationController.php
│   │   │   ├── AgentTransactionController.php
│   │   │   ├── FundingReceiptController.php
│   │   │   └── AdminUnmatchedDepositController.php
│   │   ├── Requests/
│   │   │   ├── InitiateFundingRequest.php
│   │   │   ├── ConfirmFundingRequest.php
│   │   │   ├── CancelFundingRequest.php
│   │   │   ├── ProviderCallbackRequest.php
│   │   │   ├── BankNotificationRequest.php
│   │   │   └── AgentCashInRequest.php
│   │   └── Resources/
│   │       ├── FundingTransactionResource.php
│   │       ├── FundingReceiptResource.php
│   │       └── UnmatchedDepositResource.php
│   └── Models/
│       ├── FundingTransactionModel.php
│       ├── FundingProviderCallbackModel.php
│       ├── FundingReceiptModel.php
│       ├── BankReconciliationModel.php
│       └── UnmatchedDepositModel.php
└── Providers/
    └── FundingServiceProvider.php
```

Routes file: `routes/modules/funding.php`

---

## 14. Architecture Test

**Decision**: `tests/Architecture/Modules/FundingBoundaryTest.php` — Pest `arch()` assertions verifying:
- Nothing outside `App\Modules\Funding\` imports `FundingTransactionModel`, `FundingReceiptModel`, etc. directly.
- `FundingTransactionController` depends only on service classes, not model classes.
- `FundingService` implements `FundingModuleContract`.
- No controller directly calls `LedgerModuleContract` (must go via service layer).

---

## 15. Testing Structure

- **Unit tests**: `tests/Unit/Modules/Funding/` — services (fee calculation, limit enforcement, bank reference generation, expiry command), model factories
- **Feature tests**: `tests/Feature/Modules/Funding/` — full HTTP request cycle (initiation, confirmation, callbacks, agent cash-in, bank notifications, admin endpoints)
- **Concurrency tests**: `tests/Concurrency/Modules/Funding/` — CC-E08-001 (duplicate callbacks), CC-E08-002 (agent float race), CC-E08-003 (daily limit race), CC-E08-004 (bank payment double-match)
- **Base test class**: `Tests\Support\FundingTestCase` (extends `TestCase`, provides `makeAgentWithPermission()`, `makeCustomerWithWallet()`, `postFundingTransaction()` helpers)

---

## 16. Metrics Pattern

**Decision**: Inject `MetricsCollectorInterface` into `FundingCreditService`, `ProviderCallbackService`, `AgentCashInService`, `BankMatchingService`, and `ExpireStaleTransactionsCommand`. Follow E07's established pattern. Metric failure does not abort the business operation (`@try/catch` around `$this->metrics->increment()`).

**9 metrics** (FR-E08-051): see spec §7.15. Labels use string enum values directly.
