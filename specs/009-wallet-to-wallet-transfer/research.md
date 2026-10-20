# Research — E09 Wallet-to-Wallet Transfer

## Decision 1: Lookup Token Storage Mechanism

**Decision:** Server-generated opaque UUID stored in Redis as key `transfer_lookup:{uuid}` → JSON payload `{recipientCustomerId, recipientWalletId, recipientPocketLedgerRef, currencyCode, createdAt}`. TTL: 300 seconds. Single-use: key deleted atomically on first successful initiation via Redis `DEL`.

**Rationale:** A UUID v7 token is statistically unguessable (2^122 entropy). Storage in Redis with TTL provides automatic expiry without DB migration. Single-use deletion prevents replay. No HMAC signature needed because the token is server-generated (not constructed by the client) — the security guarantee is the token's existence in Redis, not its content. This mirrors the lookup_token pattern used in payment flows across PaymentKit and Stripe Elements.

**Alternatives considered:**
- HMAC-signed JWT: adds complexity; unnecessary since the client never constructs the token.
- Database-stored token: adds a table migration; Redis is already a project dependency (Horizon).
- Short numeric OTP: enumerable; UUID is safer.

---

## Decision 2: IdentityModuleContract::verifyTransactionPin Signature Extension

**Decision:** Extend `IdentityModuleContract::verifyTransactionPin(string $customerId, string $pin)` to `verifyTransactionPin(string $customerId, string $pin, bool $requireStepUp = false)`. Default `false` preserves backward compatibility with E08 callers. When `true`, the implementation validates that the customer's current session has a valid step-up token (per E04/E05 step-up policy). The step-up gate decision (whether to apply) is caller's responsibility (E09 confirmation service compares `gross_amount >= auth.step_up_threshold`).

**Rationale:** Adding a defaulted boolean parameter is non-breaking. The step-up enforcement belongs in the Identity module (E04), not the Transfer module. The Transfer module passes intent; Identity enforces policy.

**Alternatives considered:**
- Separate `verifyStepUpToken()` call before PIN: two network hops to the same module; unnecessary.
- Hard-code threshold in Transfer module: violates Principle 2 (TBD governance) and Principle 5 (step-up threshold from config).

---

## Decision 3: Route Ordering to Prevent Wildcard Conflicts

**Decision:** In `routes/modules/transfer.php`, define all fixed-path routes before wildcard `{id}` routes. Add `->whereUuid('id')` constraint on all `{id}` routes. Route ordering:
1. `POST /recipient-lookup` (fixed path)
2. `GET /receipts/{receipt_reference}` (fixed prefix `receipts/`)
3. `POST /` (initiate — fixed)
4. `GET /` (list — fixed)
5. `GET /{id}` with `->whereUuid('id')`
6. `POST /{id}/confirm` with `->whereUuid('id')`
7. `POST /{id}/cancel` with `->whereUuid('id')`
8. `GET /{id}/receipt` with `->whereUuid('id')`

**Rationale:** Laravel processes routes in definition order. Without `whereUuid`, `GET /v1/transfers/receipts/...` would match `GET /v1/transfers/{id}` with `id = "receipts"`. The UUID constraint prevents this — `receipts` is not a UUID.

**Alternatives considered:**
- Separate prefix for receipts (`/v1/transfer-receipts/{ref}`): works but splits conceptually related routes.
- Route caching handles ordering: `whereUuid` is the deterministic solution; route cache order is less obvious.

---

## Decision 4: Pocket Selection Logic

**Decision:** At transfer initiation, the sender's pocket is resolved by calling `WalletModuleContract::getPocketLedgerAccountRef(walletId, currencyCode)`. This method (established in E06/E08) returns the ledger account reference for the active pocket matching that wallet+currency. If no active pocket is found, the contract returns null and the Transfer module returns `422 WALLET_POCKET_NOT_FOUND`. Same resolution for recipient's pocket using the `recipientWalletId` + `currencyCode` bound in the `lookup_token` payload.

**Rationale:** The WalletModuleContract already exposes this resolution (used in E08 for FundingTransaction). E09 reuses the same pattern without introducing new wallet module methods.

**Alternatives considered:**
- Accept `pocket_id` directly in the transfer payload: exposes internal pocket IDs to customers; violates the wallet abstraction.
- Pass `wallet_id` only: ambiguous when a wallet has pockets in multiple currencies.

---

## Decision 5: Fee Rounding Direction

**Decision:** Half-away-from-zero rounding (`intval(round($gross * $rateBps / 10000))`), using PHP's default `round()` behaviour. This is consistent with E08's `FundingFeeService`.

**Rationale:** Consistency with E08 prevents discrepancies in reconciliation across funding and transfer fee revenue. Half-away-from-zero is the expected financial rounding direction (ISO 80000-1 mode).

**Alternatives considered:**
- Banker's rounding (PHP `PHP_ROUND_HALF_EVEN`): reduces statistical bias for large volumes but inconsistent with E08.
- Truncation: favours platform (rounds down fee), not neutral.

---

## Decision 6: Daily Limit Scope (Cancelled Transfers)

**Decision:** Cancelled and failed transfers are excluded from the daily sending limit sum. Only `pending` and `completed` transfers count. This is the conservative approach: pending transfers consume headroom as soon as they are created (prevents limit gaming via concurrent initiations), but headroom is released when a transfer is cancelled or fails.

**Rationale:** Including only active/successful states means daily limits reflect money that has moved or is in-flight. Cancelled transfers returned the headroom. Same logic as E08's daily limit design (non-terminal status approach with explicit cancelled exclusion).

**Alternatives considered:**
- Count all transfers including cancelled: overly restrictive; customers who cancel a mistaken transfer lose their daily headroom.
- Count only completed: allows limit gaming (initiate many pending transfers to reserve headroom while only confirming some).

---

## Decision 7: Synchronous vs. Asynchronous Transfer Processing

**Decision:** Transfer confirmation is fully synchronous. On `POST /v1/transfers/{id}/confirm`, the API handler calls `TransferConfirmationService::execute()` which:
1. Verifies PIN
2. Re-validates limits and fee
3. Opens a DB transaction
4. Calls `LedgerModuleContract::postEntry()` within the transaction
5. Updates transfer status and creates receipt
6. Commits DB transaction
7. Dispatches events via outbox
Returns the completed transfer in the same HTTP response.

**Rationale:** Wallet-to-wallet transfers are entirely within OleKard's infrastructure — no external payment provider, no async callback. Synchronous processing is simpler, more observable, and provides immediate feedback to the customer. E08 required async because an external provider needed to confirm the funding.

**Alternatives considered:**
- Queue the debit/credit: adds latency and complexity (need to poll for completion); no benefit for internal transfers.
- Saga/two-phase commit: appropriate for distributed systems; overkill for a monolith with a single PostgreSQL instance.

---

## Decision 8: TransferReference Format

**Decision:** `TXF-{10-CHAR-ALPHANUM}` using the unambiguous character set (A-Z, 0-9, excluding I/O/1/0) — same alphabet as E08's bank reference generator. Total unique references: ~36^10 ≈ 3.6 × 10^15. Generated by a `TransferReferenceGenerator` service modelled after E08's `BankReferenceGenerator`.

**Rationale:** Human-readable for customer support. Unambiguous characters prevent confusion when read aloud (no I vs 1, O vs 0). The same generator pattern is already proven in E08.

**Alternatives considered:**
- UUID v7 as reference: not human-readable.
- Sequential numeric: enumerable (security risk).

---

## Integration Dependencies Resolved

| Dependency | Status | Notes |
|------------|--------|-------|
| `WalletModuleContract::getPocketLedgerAccountRef(walletId, currencyCode)` | ✅ Exists (E08) | Already returns nullable string |
| `WalletModuleContract::getWalletOwnerId(walletId)` | ✅ Exists (E06) | Used in ownership check |
| `LedgerModuleContract::postEntry(lines[])` | ✅ Exists (E07) | Returns journal_id; used in E08 |
| `LedgerModuleContract::getBalance(ref)` | ✅ Exists (E07) | Used in E08 for pre-check |
| `IdentityModuleContract::getCustomerKycTier(customerId)` | ✅ Exists (E05) | Used in E08 for limits |
| `IdentityModuleContract::verifyTransactionPin(customerId, pin)` | ✅ Exists (E08) | Extending with `requireStepUp` param |
| `PlatformConfigurationService::get(key)` | ✅ Exists (E01+) | Used across multiple epics |
| Redis | ✅ Available | Horizon dependency; lookup tokens stored here |
