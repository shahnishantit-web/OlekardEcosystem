# Research: P01 — Architecture Contracts and Accounting Decisions

**Date**: 2026-10-21

**Purpose**: Resolve all NEEDS CLARIFICATION items from Technical Context before Phase 1 design.

---

## 1. Module Inventory (SC-01)

**Decision**: 30 modules confirmed from `wallet-ecosystem/services/api/app/Modules/`.

**Evidence**: `ls app/Modules/` output on 2026-10-21.

**All 30 modules**:

| Module | Schema / Table prefix | Has own migration schema? |
|--------|----------------------|--------------------------|
| Admin | `public.admin_actions`, `public.admin_wallet_adjustments` | No (public) |
| AdminApp | (read-only permissions/views, no owned tables confirmed) | No |
| Agent | `public.agent_applications`, `public.agent_documents`, `public.agents`, `public.agent_customer_relationships`, `public.agent_status_history` | No |
| AgentFloat | `public.agent_float_wallets`, `public.agent_float_transactions`, `public.agent_float_limits` | No |
| Audit | `audit.*` | Yes — `audit` schema |
| Commission | `public.commission_rules`, `public.commissions`, `public.commission_payouts`, `public.commission_processed_events` | No |
| Fees | `fee.*` | Yes — `fee` schema |
| Funding | `public.funding_transactions` | No |
| Identity | Users, devices, sessions, MFA (public schema) | No |
| Integration | `public.integrations`, `public.api_clients`, `public.webhook_events` | No |
| Kyc | `public.kyc_verifications`, `public.kyc_documents` | No |
| Ledger | Ledger journal tables (schema TBD — Ledger module owns these) | TBD |
| MultiCurrency | Multi-currency pocket tables (wallet schema extension) | TBD |
| Notification | `public.notifications`, `public.notification_preferences` | No |
| Ops | Operations/runbook tables | TBD |
| Payment | `payment.*` — 7 tables (see §3) | Yes — `payment` schema |
| Platform | `public.platform_configurations`, `public.platform_configuration_change_requests`, `public.platform_configuration_approvals`, `public.platform_configuration_versions`, `public.pending_admin_actions` | No |
| Profile | `public.profiles`, `public.profile_photos` | No |
| Qa | QA test records (internal) | No |
| Qr | `public.qr_codes`, `public.merchant_payment_requests` | No |
| Reconciliation | `public.reconciliation_runs`, `public.reconciliation_exceptions` | No |
| Reporting | Reporting tables | TBD |
| Risk | `public.risk_events`, `public.fraud_rules` | No |
| Security | Security audit tables | No |
| Service | `public.service_categories`, `public.service_providers`, `public.bill_payments` | No |
| Support | `public.support_tickets`, `public.support_messages`, `public.disputes` | No |
| Transaction | `public.transactions` | No |
| Transfer | `public.transfers` | No |
| Wallet | `public.wallets`, `public.wallet_pockets`, `public.wallet_holds` | No |
| Withdrawal | `public.withdrawals` | No |

**Note**: Modules with TBD in "Has own migration schema?" require verification in Phase 1 tasks. The pattern `002-schema-per-module.md` ADR confirms the schema-per-module strategy was adopted; not all modules have migrated yet.

---

## 2. ADR Location and Status

**Decision**: ADRs live in `docs/architecture/decisions/` (repository root `docs/`, not inside `wallet-ecosystem/`).

**Evidence**: Found `ADR-E48-001-payment-module-ownership.md` and `ADR-E48-002-wallet-payment-adapter-delegation.md` at that path.

**Note**: The `wallet-ecosystem/docs/adrs/` path contains only E02 ADRs. E48 ADRs are at the project-root `docs/` path. ADR-E48-003 MUST be created at `docs/architecture/decisions/ADR-E48-003-accounting-decisions.md`.

**Existing ADRs relevant to P01**:
- ADR-E48-001 (Accepted): Payment module ownership and boundaries
- ADR-E48-002 (Accepted): Wallet adapter delegation pattern
- `002-schema-per-module.md` (wallet-ecosystem/docs/adrs/): Schema-per-module pattern
- `003-idempotency-strategy.md`: Idempotency storage strategy
- `004-outbox-pattern.md`: Outbox for integration events

**ADR-E48-003 does not exist yet** — this is a P01 output.

---

## 3. Payment Module Tables (SC-01)

**Decision**: Payment module owns exactly 7 tables in the `payment` PostgreSQL schema.

**Evidence**: Migration files `2026_10_18_000002` through `2026_10_18_000008`.

**Tables**:
- `payment.payment_providers` — registered payment providers (Wallet, Bank1, Bank2, Bank3, Mock)
- `payment.payment_provider_accounts` — organisation-specific provider credentials
- `payment.payment_intents` — primary payment lifecycle record
- `payment.payment_attempts` — individual attempt at processing (tied to one provider)
- `payment.payment_refunds` — refund lifecycle records
- `payment.payment_callbacks` — raw inbound webhook/callback payloads
- `payment.reconciliation_exceptions` — flagged discrepancies for manual review

---

## 4. Cross-Module Interfaces Used by Payment (SC-02)

**Decision**: 5 cross-module interfaces are confirmed with payment relevance.

**Evidence**: PHP interface files read directly.

1. **`PaymentModuleContract`** — owned by Payment; consumed by business modules (Funeral, Events, etc.)
   - 3 methods: `createPaymentIntent()`, `getAvailableOptions()`, `getPaymentStatus()`
   
2. **`PaymentAdapterInterface`** — owned by Payment domain; implemented by each provider adapter
   - 4 methods: `initiatePayment()`, `queryPaymentStatus()`, `initiateRefund()`, `cancelPayment()`
   
3. **`BusinessPurposeResolverInterface`** — owned by Payment domain; implemented by each business module
   - 2 methods: `supportedPurposeType()`, `resolve()` → `PayableObligation`
   
4. **`WalletModuleContract`** — owned by Wallet; called only by `WalletPaymentAdapter`
   - 9+ methods; payment-relevant: `createHold()`, `releaseHold()`, `availableBalance()`, `ownerActorId()`, `getPocketLedgerAccountRef()`
   
5. **`LedgerModuleContract`** — owned by Ledger; called by `WalletPaymentAdapter` (via delegation)
   - 5 methods: `postEntry()`, `entryExists()`, `getTransactionHistory()`, `getBalance()`, `registerAccount()`, `getAccountHistory()`

---

## 5. WalletPaymentAdapter Capabilities (SC-02)

**Decision**: WalletPaymentAdapter uses Platform's `ProviderCapabilityEnum`, not Payment's.

**Evidence**: `WalletPaymentAdapter.php` line 22: `use App\Modules\Platform\Domain\Enums\ProviderCapabilityEnum;`

**Platform capabilities declared**: `InitiatePayment`, `QueryTransactionStatus`, `ReversePriorPayment`

**Payment `ProviderCapabilityEnum`** has 8 cases: `InitiatePayment`, `QueryPayment`, `VerifyCallback`, `FullRefund`, `PartialRefund`, `CancelPayment`, `AuthoriseCapture`, `Payout`

**Finding**: Two separate capability enums exist — Platform's (used by WalletPaymentAdapter) and Payment's (used by the rest of the system). This is a naming/design inconsistency to record. Neither includes a `Payout` capability in WalletPaymentAdapter's declarations. The Payment enum has `Payout` but no corresponding method in `PaymentAdapterInterface`. This gap blocks P18.

---

## 6. E25 ↔ Payment Adapter Boundary (DEC-06)

**Decision**: UNRESOLVED. Evidence is ambiguous.

**Evidence**:
- `App\Modules\Integration` owns `IntegrationRegistryInterface`, `WebhookEventRepositoryInterface`, `ApiClientRepositoryInterface`, `ProviderHealthInventoryInterface` — these are connector lifecycle and webhook ingress.
- `App\Modules\Payment` owns `AbstractBankPaymentAdapter`, `BankCallbackVerifier`, `MockBankPaymentAdapter` — these appear to be self-contained HTTP adapters.
- `PaymentCallbackController` handles bank webhooks inside the Payment module, not Integration module.

**Interpretation**: Payment module currently owns its own adapter HTTP layer (does not reuse E25 connector infrastructure). But this may violate E25's charter of owning "common external ingress/connector infrastructure".

**Recorded as**: DEC-06 — UNRESOLVED — owner: Architect — risk: duplicate webhook ingress if P12–P14 bank adapters implement their own HTTP clients without using E25 transport.

---

## 7. Bank Purchase ≠ Wallet Top-Up (FR-06)

**Decision**: CONFIRMED by design. A bank payment does not interact with `WalletModuleContract`.

**Evidence**: `WalletPaymentAdapter` is the ONLY adapter that calls `WalletModuleContract`. `AbstractBankPaymentAdapter` and `MockBankPaymentAdapter` do not import `WalletModuleContract`.

**Rationale**: The OleKard Wallet is a stored-value account. When a consumer pays via bank, funds go to OleKard's bank settlement account (external), not to the consumer's OleKard Wallet. The consumer's wallet balance is unchanged. The credit side of the accounting entry for a bank payment goes to an OleKard revenue/settlement receivable account, not to the consumer pocket.

---

## 8. Accounting Decision Status Summary (FR-07)

| Decision | Status | Source | Owner |
|----------|--------|--------|-------|
| (a) Custody account type | UNRESOLVED | FD10 — arch doc missing | Finance Lead |
| (b) Merchant/org settlement bank account | UNRESOLVED | FD06 — arch doc missing | Finance Lead |
| (c-i) Wallet payment: debit consumer pocket → credit ? | PARTIALLY CONFIRMED | ADR-E48-002 + code | P08 Architect |
| (c-ii) Bank payment: debit bank settlement → credit ? | UNRESOLVED | FD05 — arch doc missing | Finance Lead |
| (c-iii) Refund: debit ? → credit consumer/payer | PARTIALLY CONFIRMED | WalletPaymentAdapter refund logic | P08 Architect |
| (d) Fee bearer per payment method | PARTIALLY CONFIRMED (Wallet: fee=0, feeMinor: 0) | WalletPaymentAdapter code | Finance Lead (bank methods) |
| (e) Late-payment suspense account | UNRESOLVED | FD05 — arch doc missing | Finance Lead |
| FX risk bearer (multi-currency) | UNRESOLVED | E29 scope — deferred | Finance Lead |

**Note on (c-i)**: The wallet payment debit side is confirmed (consumer pocket is debited via `LedgerModuleContract`). The credit side (which account receives the credit — org receivable, org suspense, or org wallet pocket) is unresolved pending FD10.

---

## 9. Fee Calculation Boundary

**Decision**: Fee calculation is owned by the Fees module (E21), not the Payment module.

**Evidence**: `fee.fee_rules` and `fee.fees` tables are in the Fees module schema. Payment module records `fee_minor` on `payment_intents` and `payment_attempts` but does not calculate it internally.

**Confirmed**: For WalletPaymentAdapter, `feeMinor = 0` is hardcoded. Bank adapter fee calculation goes to the Fees module contract (consumed by Payment orchestration layer).

---

## 10. NEEDS CLARIFICATION Resolutions

All NEEDS CLARIFICATION items from Technical Context resolved:

| Item | Resolution |
|------|-----------|
| ADR location | `docs/architecture/decisions/` (project root) |
| 30 modules confirmed | Yes — verified from filesystem |
| Payment schema name | `payment` (PostgreSQL schema) |
| ADR-E48-003 exists? | No — must be created as P01 output |
| WalletPaymentAdapter capability enum | Platform's (not Payment's) — two enums coexist |
| E25 ↔ Payment boundary | UNRESOLVED — Payment appears self-contained |
| Bank purchase ≠ wallet top-up | CONFIRMED from code |
| Fee bearer for Wallet payments | 0 (confirmed from code) |
