# Research — E11 Transaction Management

## Decision: E07 LedgerModuleContract Signature

**Status**: Confirmed

`LedgerModuleContract` (E07) exposes:
- `postEntry(array $request): string` — posts a double-entry journal; returns journal ID
- `entryExists(string $transactionReference): bool`
- `getBalance(string $ledgerAccountReference): int`
- `registerAccount(...): void`
- `getAccountHistory(...): array`

**Critical gap**: No `getJournalLines(string $journalId): array` method exists.

**Implication for reversal execute (T048)**: Cannot retrieve original journal lines from E07 to build
the compensating entry dynamically. Instead, the event handlers that populate
`transaction_projections` will store key ledger account references in the `metadata` JSONB field
under the key `reversal_journal_template`. The reversal service reads this metadata and constructs
compensating lines by:
1. Reading `metadata.reversal_journal_template` from the projection (set when Completed event arrives)
2. Building the inverse (DEBIT→CREDIT, CREDIT→DEBIT, same accounts, same amounts)
3. Posting via `LedgerModuleContract::postEntry()`

Template structure stored in metadata:
```json
{
  "reversal_journal_template": {
    "debit_account": "pocket-ledger-ref-of-wallet",
    "credit_account": "transit-or-source-account",
    "amount": 100000,
    "currency_code": "BWP"
  }
}
```

## Decision: E08–E10 Event Fields

**Status**: Confirmed (by reading actual event classes)

### Funding (E08) events confirmed:
- `FundingTransactionInitiated`: fundingTransactionId, walletId, currencyCode, amount, status, fundingMethod, occurredAt, correlationId
- `FundingTransactionCompleted`: fundingTransactionId, walletId, currencyCode, amount, netAmount, ledgerJournalId, status, occurredAt, correlationId

**Missing**: customer_id, transaction_reference, direction

### Transfer (E09) events confirmed:
- `TransferInitiated`: transferId, senderWalletId, recipientWalletId, currencyCode, grossAmount, feeAmount, netAmount, occurredAt, correlationId

**Missing**: sender_customer_id, recipient_customer_id, transfer_reference, direction

### Withdrawal (E10) events confirmed:
- `WithdrawalCompleted`: withdrawalTransactionId, walletId, currencyCode, grossAmount, feeAmount, netPayoutAmount, channel, ledgerJournalId, occurredAt, correlationId

**Missing**: customer_id, transaction_reference, direction

### Resolution
Event handlers will do a cross-schema read (same PostgreSQL instance) to fetch missing fields:
- Funding: `DB::connection('platform')->table('funding.funding_transactions')`
- Transfer: `DB::connection('platform')->table('transfer.transfer_transactions')`
- Withdrawal: `DB::connection('platform')->table('withdrawal.withdrawal_transactions')`

This is acceptable for read-model population in a modular monolith. Write paths remain strictly
isolated. The read is done once on event receipt, not on every API query.

## Decision: Full-Text Search Approach

**Status**: Confirmed — Option A (PostgreSQL tsvector)

Rationale: No new infrastructure required; existing system already uses tsvector/GIN indexes
(confirmed in E10 migrations). `to_tsvector('english', reference || ' ' || description)` covers
the primary search use case.

## Decision: Idempotency TTL Config Key

**Status**: Confirmed

Platform module provides `IdempotencyServiceInterface`. TTL is configured internally by the Platform
module. E11 does not need to define a separate TTL config key. The idempotency key for reversal
approve is `reversal_approve:{reversalId}`.

## Decision: Migration Timestamp Prefix

**Status**: Confirmed

E10 migrations use `2026_09_21_*`. E11 migrations will use `2026_09_22_*` to ensure they run after
all E10 migrations.

## Decision: Horizon Queue

**Status**: Confirmed — `transaction` queue must be added to supervisor-default.

The horizon.php `supervisor-default.queue` list currently ends with `withdrawal`. E11 adds `transaction`
to this list.

## Decision: Transfer Projection Direction

For transfers, E11 creates TWO projection rows:
1. `direction = 'debit'` — from sender's perspective (`wallet_id = senderWalletId`, `customer_id = sender_customer_id`)
2. `direction = 'credit'` — from recipient's perspective (`wallet_id = recipientWalletId`, `customer_id = recipient_customer_id`)

Both rows share the same `type = 'transfer'` and amount.

## Decision: Reversible Transaction Types

Only `completed` transactions are reversible. Reversible types (subject to config):
- `withdrawal` (type = 'withdrawal', direction = 'debit')
- `funding` (type = 'funding', direction = 'credit')

Transfers are excluded from reversal in V1 (too complex — requires coordinated reversal of both
debit and credit projection rows plus the original transfer ledger entry).
