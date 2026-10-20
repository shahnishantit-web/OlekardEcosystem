# Research — E29 Multi-Currency Wallet

## Decision 1: Module boundary relative to E06 Wallet

**Decision:** Create a new bounded module `MultiCurrency` (`app/Modules/MultiCurrency/`) that _depends on_ but does not modify E06's `Wallet` module. Multi-currency wallets are represented as additional `wallet.wallets` rows (via E06's `WalletService::create()`) tagged with a currency code, plus a new linking table `multi_currency.currency_wallets` that enforces the one-wallet-per-currency rule at the domain layer.

**Rationale:** E06 already owns wallet creation, balance derivation from the E07 ledger, and the activation lifecycle. Duplicating that in a new module would create drift. Instead, MultiCurrency calls E06's public `WalletServiceInterface` to provision the underlying wallet record, then wraps it with FX metadata in its own table. This keeps modules extraction-ready (Principle 7).

**Alternatives considered:**
- Add a `currency_code` column directly to `wallet.wallets` — rejected; invasive change to a shipped module, forces E06 migration.
- Standalone `multi_currency.wallets` with its own balance tracking — rejected; duplicates ledger responsibility from E07, violates Principle 4.

---

## Decision 2: FX rate storage and activation

**Decision:** FX rates are stored in `multi_currency.fx_rates` as versioned, time-bounded rows. Each rate has `effective_from` and `effective_to` (null = currently active). A partial unique index enforces at most one active rate per currency pair. Rate changes are submitted as change requests through E28 Configuration Management (`/v1/admin/multi-currency/fx-rates/change-requests`) and activate via the E28 `ConfigurationActivationJob` pattern — the `effective_from` is set at approval time (effective immediately or scheduled).

**Rationale:** Aligns with E28 maker-checker workflow already in place (Principle 3). FX rates are financial policy — they must not be changed unilaterally without approval. Versioned rows give a complete audit trail (Principle 13).

**Alternatives considered:**
- Store FX rates as E28 platform configuration key-value pairs — rejected; rate pairs are structured data (from/to/rate/spread) not suitable as a single JSON blob per key; querying active rate for a pair would be complex.
- Direct DB update without versioning — rejected; violates audit requirements.

---

## Decision 3: FX quote-then-confirm implementation

**Decision:** A new `multi_currency.fx_quotes` table holds in-flight quotes. Phase 1 (`POST /v1/multi-currency/fx-quotes`) creates a quote row with `status=quoted`, locked rate, computed `to_amount`, and `expires_at = now() + 60 seconds`. Phase 2 (`POST /v1/multi-currency/fx-quotes/{id}/confirm`) validates: quote belongs to requester, status=quoted, not expired; then within a single DB transaction executes the conversion, posts FX ledger entries via E07 `LedgerService`, marks quote `confirmed`, creates `FxTransaction` row with `status=completed`. The confirm call is idempotent via `idempotency_key`.

**Rationale:** Separating quote from confirm gives users price certainty before committing funds (Clarification Q3). Synchronous settlement (Clarification Q5) means no background job is needed and the confirm response carries the final FxTransaction. The 60-second window balances usability against rate risk.

**Alternatives considered:**
- Single-step conversion — rejected; exposes customers to rate drift between request and execution.
- Async settlement with pending state — rejected per Clarification Q5; adds complexity without benefit for intra-platform conversions.

---

## Decision 4: FX ledger journal structure

**Decision:** Each confirmed conversion posts three balanced journal pairs via E07 `LedgerService`:

```
# Entry 1 — Debit source wallet (gross including fee)
Dr  customer.source_currency_wallet   from_amount + fee_amount
Cr  multi_currency.fx_suspense        from_amount + fee_amount

# Entry 2 — Credit destination wallet (net converted amount)
Dr  multi_currency.fx_suspense        to_amount
Cr  customer.target_currency_wallet   to_amount

# Entry 3 — Allocate FX fee to platform revenue
Dr  multi_currency.fx_suspense        fee_amount
Cr  platform.fx_revenue_account       fee_amount
```

The suspense account nets to zero across the three entries. Each entry is a separate `LedgerEntry` in E07; all three are posted atomically in a single DB transaction.

**Rationale:** Maintains double-entry integrity (Principle 4). Suspense account isolates the FX conversion from direct wallet-to-wallet posting, making reconciliation straightforward. Fee revenue capture aligns with E21 fee/pricing module.

**Alternatives considered:**
- Single two-legged entry (Dr source, Cr destination at converted amount, separate fee entry) — rejected; conflates exchange rate application with fee deduction, making reconciliation ambiguous.

---

## Decision 5: Same-currency transfer (US-MCW-004)

**Decision:** `SameCurrencyTransferService` calls E07 `LedgerService` directly with a simple two-legged journal (Dr source wallet, Cr target wallet, same currency, same amount). No FX rate lookup needed. The two wallets must belong to the same customer and be in the same currency; the service validates both constraints before posting.

**Rationale:** Keeps it simple — no quote flow, no FX fee. Clarification Q4 confirmed this is a same-currency-only operation (Clarification Q4).

---

## Decision 6: Currency catalogue management (US-MCW-002)

**Decision:** `multi_currency.currencies` is the canonical list of supported ISO 4217 currencies. Admin operators activate/deactivate currencies via `/v1/admin/multi-currency/currencies`. A currency wallet cannot be created for a currency that is not active. The seeded active currencies match the initial list from the 538-story backlog; additional currencies are added through the admin surface.

**Rationale:** Centralizes currency activation control (Principle 3). Prevents customers from creating wallets in unsupported currencies.

---

## Integration Points

| Upstream | How Used |
|---|---|
| E06 WalletServiceInterface | Create underlying wallet record for each currency wallet |
| E07 LedgerService | Post all financial journal entries (conversion, fee, transfer) |
| E28 ConfigurationManagement | FX rate change-request + approval workflow |
| E21 FeePricingService | Retrieve FX fee rate by currency pair |
| E04 AuthorizationService | Enforce `multi_currency.*` permissions |
| E01 Identity | Resolve customer_id from authenticated token |
