# Research — E10 Withdrawal / Cash-Out

**Feature**: `010-withdrawal-cash-out` | **Created**: 2026-09-18

All technical unknowns resolved. No business TBDs are resolved here (TBD-E10-001 through TBD-E10-005 remain deferred to governance/go-live gates as documented in spec §17).

---

## Decision 1 — WalletHold Mechanism

**Decision**: Extend `WalletModuleContract` with `createHold()` and `releaseHold()` methods that delegate to the existing `WalletHoldService` (E06). Holds are stored in `wallet.wallet_holds` (Wallet module's table). E10's `WithdrawalTransaction` stores a `wallet_hold_id` reference to the hold. E10 does NOT create a separate `withdrawal.wallet_holds` table.

**Rationale**: The Wallet module already owns `wallet.wallet_holds`, `WalletHoldService`, and the `WalletHoldServiceContract` (which was explicitly designed to be opened to cross-module use when needed — see comment in `WalletHoldServiceContract.php`). Reusing this infrastructure ensures `WalletModuleContract::availableBalance()` remains accurate: the Wallet module's balance service sums active holds when computing available balance. If E10 created its own hold table, the Wallet module would be unaware of those holds and `availableBalance()` would return inflated values, allowing double-spend.

**Alternatives considered**:
- E10-owned `withdrawal.wallet_holds` table in `withdrawal` schema — rejected because Wallet module would not know about withdrawal holds when computing `available_balance`, causing incorrect balance reads and potential over-spend.
- Direct access to `WalletHoldServiceContract` from E10 — rejected because it violates the cross-module boundary rule; E10 MUST only depend on `WalletModuleContract`.

**New methods added to `WalletModuleContract`**:
```php
public function createHold(
    string $walletId,
    string $currencyCode,
    int $amount,
    string $actorId,
    string $correlationId,
    string $transactionReference,
    ?\DateTimeImmutable $expiresAt = null,
): string; // Returns hold ID (UUID v7)

public function releaseHold(string $holdId, string $correlationId): void;
```

Both success completion and failure/expiry call `releaseHold()`. The distinction between "funds consumed by payout" and "funds returned on failure" is tracked at the E10 layer via `WithdrawalTransaction.status` — not at the hold level.

**`WalletModuleContractAdapter`** is extended to delegate to `WalletHoldService::place()` and `WalletHoldService::release()`.

**`WalletHoldStatus`** values are `active | released | expired` (no `consumed` status added; E06 does not need to distinguish withdrawal success vs failure at the hold level since the ledger journal serves as the authoritative record).

---

## Decision 2 — PayoutProviderAdapter Interface

**Decision**: Follow the identical interface pattern as E08's `FundingProviderAdapter`:
```php
interface PayoutProviderAdapter {
    public function initiate(array $transaction): array;
    public function verifyCallback(array $payload, string $signature, string $provider): array;
}
```

`initiate()` context array:
```
id, gross_amount, net_payout_amount, currency_code, channel, payout_destination, transaction_reference, correlation_id
```

`initiate()` returns:
```
status: 'initiated' | 'failed'
provider_reference_id: string | null
raw_response: array
```

`verifyCallback()` returns:
```
valid: bool
provider_reference_id: string | null
status: 'success' | 'failure' | 'unknown'
amount: int | null
currency: string | null
```

`MockPayoutProviderAdapter` always returns `status=initiated` with a deterministic `provider_reference_id = 'mock-payout-' . $transaction['id']`.

**Rationale**: Identical pattern to E08's FundingProviderAdapter. The adapter interface is defined in E10; real implementations are owned by E1000 (TBD-E10-001).

---

## Decision 3 — WithdrawalRiskService

**Decision**: Follow the E09 `TransferRiskService` / `RiskResult` pattern exactly. Create:
- `App\Modules\Withdrawal\Application\Contracts\WithdrawalRiskService` (interface)
- `App\Modules\Withdrawal\Application\Contracts\WithdrawalRiskResult` (value object: `approved()`, `rejected(reason)`)
- `App\Modules\Withdrawal\Infrastructure\Stubs\WithdrawalRiskServiceStub` (always returns `approved()`)

Do NOT reuse Transfer module's `RiskResult` or `TransferRiskService` — copy the pattern into the Withdrawal module's namespace to maintain module boundary and extraction readiness.

`WithdrawalRiskService::evaluate()` context array:
```
customer_id, wallet_id, channel, gross_amount, currency_code, payout_destination_type (channel), correlation_id
```

Note: `payout_destination` itself is NOT passed to the risk service — the risk engine only needs the type (mobile_money or bank_transfer) and amount, not PII.

**Rationale**: Identical stub pattern to E09. E16/E18 overrides the `WithdrawalRiskService` IoC binding without changing any E10 calling code.

---

## Decision 4 — payout_destination Encryption

**Decision**: Store `payout_destination` as JSONB in `withdrawal.withdrawal_transactions`. Apply Laravel's `'payout_destination' => 'encrypted:array'` cast in the Eloquent model. The field is decrypted only when accessed via the model, and only passed to `PayoutProviderAdapter::initiate()`.

**Rationale**: Laravel's `Crypt` (AES-256-CBC, app key) is already in use for sessions/cookies. The `encrypted:array` cast handles encode→encrypt on write and decrypt→decode on read transparently. No external dependency required for E10.

**Alternatives considered**: Separate field-level encryption key via secrets manager — deferred to E16/E18 where advanced PII controls are defined; overkill for E10 which only needs to protect the data at rest in the database.

---

## Decision 5 — Journal entry_type for Withdrawal

**Decision**: Use `entry_type = 'debit'` for the completion journal posted via `LedgerModuleContract::postEntry()`.

**Rationale**: The withdrawal completion journal debits the customer's wallet pocket (money leaving the wallet). The `entry_type` field categorizes the overall journal purpose. `debit` is the correct category for money-out operations (consistent with E09 transfer debit journal).

**Journal structure**:
- Fee = 0 (2 lines): debit customer pocket `gross_amount`; credit platform payout transit `gross_amount`.
- Fee > 0 (3 lines): debit customer pocket `gross_amount`; credit platform payout transit `net_payout_amount`; credit fee revenue `fee_amount`.
- `idempotency_key`: `withdrawal_complete:{withdrawal_transaction_id}` (server-generated stable key).
- Payout transit account ref: `platform.configuration_values.ledger.payout_transit.{currency_code}` (TBD-E10-004).
- Fee revenue account ref: `platform.configuration_values.ledger.fee_revenue.{currency_code}` (same config key as E08/E09).

---

## Decision 6 — Module Directory Structure

**Decision**: Follow E09 exact structure:
```
app/Modules/Withdrawal/
├── Application/
│   ├── Contracts/        # WithdrawalRiskService.php, WithdrawalRiskResult.php
│   ├── Jobs/             # WithdrawalPayoutJob.php
│   └── Services/         # WithdrawalInitiationService, WithdrawalConfirmationService,
│                         # WithdrawalCancellationService, WithdrawalCallbackService,
│                         # WithdrawalFeeService, WithdrawalLimitService,
│                         # WithdrawalReceiptService, WithdrawalMetricsService,
│                         # WithdrawalExpiryService
├── Console/
│   └── Commands/         # ExpireStaleWithdrawalsCommand.php
├── Domain/
│   ├── Contracts/        # (none — cross-module contract is in Withdrawal root namespace)
│   ├── Enums/            # WithdrawalStatus.php, WithdrawalChannel.php, WithdrawalFailureReason.php
│   ├── Events/           # WithdrawalInitiated, Processing, Completed, Failed, Cancelled, etc.
│   └── Exceptions/       # WithdrawalNotFoundException, WithdrawalNotModifiableException,
│                         # WithdrawalReceiptImmutableException, WithdrawalRiskRejectedException, etc.
├── Infrastructure/
│   ├── Adapters/         # MockPayoutProviderAdapter.php
│   ├── Http/
│   │   ├── Controllers/  # WithdrawalController, WithdrawalReceiptController, AdminWithdrawalController
│   │   ├── Requests/     # WithdrawalInitiateRequest, WithdrawalConfirmRequest
│   │   └── Resources/    # WithdrawalTransactionResource, WithdrawalReceiptResource
│   ├── Migrations/       # Schema, tables, seeds
│   ├── Models/           # WithdrawalTransactionModel, WithdrawalReceiptModel,
│   │                     # WithdrawalProviderCallbackModel
│   └── Stubs/            # WithdrawalRiskServiceStub.php
└── Providers/
    └── WithdrawalServiceProvider.php
```

---

## Decision 7 — Migration Numbering

**Decision**: Use prefix `2026_09_21_001{N}00_` (batch 21, sequence 001N) for all E10 migrations:
- `2026_09_21_001000_create_withdrawal_schema.php`
- `2026_09_21_001100_create_withdrawal_transactions_table.php`
- `2026_09_21_001200_create_withdrawal_provider_callbacks_table.php`
- `2026_09_21_001300_create_withdrawal_receipts_table.php`
- `2026_09_21_001400_seed_withdrawal_permissions.php`
- `2026_09_21_001500_seed_withdrawal_configuration_stubs.php`
- `2026_09_21_001600_extend_wallet_module_contract_with_hold_methods.php` (migration that records the WalletModuleContract extension)

**Rationale**: E08 used `2026_09_19_*`, E09 used `2026_09_20_*`, so E10 uses `2026_09_21_*`.

---

## Decision 8 — Async Payout Dispatch

**Decision**: Follow E08's `FundingInitiationJob` pattern exactly. Create `WithdrawalPayoutJob` that:
- Runs on the `withdrawal` queue
- `$tries = 3`, `$timeout = 30`
- On success: stores `provider_reference_id`, logs
- On failure after all tries: marks transaction `failed` with `failure_reason = job_dispatch_failed`; releases hold

**Queue name**: `withdrawal` (new queue; add to Horizon config).

---

## Decision 9 — Admin Force-Fail

**Decision**: `POST /v1/admin/withdrawals/{id}/force-fail` follows E09's `AdminTransferController::forceFail()` pattern. Guards: must be `processing`; else `422 WITHDRAWAL_ALREADY_TERMINAL`. On force-fail: release hold, transition to `failed`, emit `Withdrawal.Transaction.Failed`, record audit.

---

## Resolved Technical Unknowns Summary

| Unknown                              | Decision                                     |
|--------------------------------------|----------------------------------------------|
| WalletHold storage                   | wallet.wallet_holds via WalletModuleContract extension |
| PayoutProviderAdapter interface      | Same as FundingProviderAdapter               |
| WithdrawalRiskService                | Same as TransferRiskService + stub           |
| payout_destination encryption        | Laravel encrypted:array cast                 |
| Journal entry_type                   | `debit`                                      |
| Module structure                     | Same as E09                                  |
| Migration numbering                  | 2026_09_21_001{N}00_                         |
| Async dispatch                       | WithdrawalPayoutJob (queue: withdrawal)       |
| Admin force-fail                     | Same as E09 AdminTransferController          |
