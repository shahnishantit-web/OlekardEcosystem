# Module Boundary Register

**Version**: 1.0 (P01 draft — pending architect sign-off)

**Date**: 2026-10-21

**Scope**: OleKard modular monolith — 30 application modules. This register is the authoritative boundary document. Any cross-module dependency not listed here is prohibited without an approved ADR.

**Legend**:
- `→ Interface` means another module must call the listed interface; direct table access is prohibited.
- UNRESOLVED entries require the architecture document to be added to the repository (DEC-07).

---

## Module Map

### 1. Payment

| Field | Value |
|-------|-------|
| **Namespace** | `App\Modules\Payment` |
| **Schema** | `payment.*` (dedicated PostgreSQL schema) |
| **Owned tables** | `payment.payment_providers`, `payment.payment_provider_accounts`, `payment.payment_intents`, `payment.payment_attempts`, `payment.payment_refunds`, `payment.payment_callbacks`, `payment.reconciliation_exceptions` |
| **Exposes** | `PaymentModuleContract` (createPaymentIntent, getAvailableOptions, getPaymentStatus) |
| **Permitted callers** | Any business module (Funding, Transfer, Withdrawal, Service, Qr, community features) |
| **Prohibited direct access** | All `payment.*` tables — callers use `PaymentModuleContract` only |
| **Depends on (interfaces only)** | `WalletModuleContract` (via WalletPaymentAdapter only), `LedgerModuleContract` (via WalletPaymentAdapter only), `FeesModuleContract` (for fee calculation), Platform `ConfigurationServiceInterface`, Platform `AuditServiceInterface`, Identity `ActorStepUpVerifierContract` |
| **Must NOT call** | No other module's tables; no direct balance mutations |

---

### 2. Wallet

| Field | Value |
|-------|-------|
| **Namespace** | `App\Modules\Wallet` |
| **Schema** | `public.wallets`, `public.wallet_pockets`, `public.wallet_holds` |
| **Owned tables** | `wallets`, `wallet_pockets`, `wallet_holds` (public schema) |
| **Exposes** | `WalletModuleContract` (9 methods including createHold, releaseHold, availableBalance) |
| **Permitted callers** | Payment (WalletPaymentAdapter only), Transfer, Funding, Withdrawal, AgentFloat, Admin (adjustments via service), MultiCurrency |
| **Prohibited direct access** | `wallets`, `wallet_pockets`, `wallet_holds` — callers use `WalletModuleContract` |
| **Depends on** | `LedgerModuleContract` (for posting journal entries after balance operations) |
| **Must NOT be modified by E48** | ADR-E48-002 constraint — any new method requires its own ADR |

---

### 3. Ledger

| Field | Value |
|-------|-------|
| **Namespace** | `App\Modules\Ledger` |
| **Schema** | TBD — schema-per-module pending verification |
| **Owned tables** | Ledger journal tables (accounts, journal entries, lines) |
| **Exposes** | `LedgerModuleContract` (postEntry, entryExists, getTransactionHistory, getBalance, registerAccount, getAccountHistory) |
| **Permitted callers** | Wallet (primary), Payment (via WalletPaymentAdapter only), Transfer, Funding, Withdrawal, AgentFloat, Reconciliation, Reporting |
| **Prohibited direct access** | Ledger journal tables — callers use `LedgerModuleContract::postEntry()` only |
| **Financial invariant** | `postEntry()` enforces double-entry: every journal MUST balance (debits == credits). Partial entries are rejected. Historical entries are immutable — reversals use compensating entries. |

---

### 4. Integration (E25)

| Field | Value |
|-------|-------|
| **Namespace** | `App\Modules\Integration` |
| **Schema** | `public.integrations`, `public.api_clients`, `public.webhook_events` |
| **Owned tables** | `integrations`, `api_clients`, `webhook_events` |
| **Exposes** | `IntegrationRegistryInterface`, `WebhookEventRepositoryInterface`, `ApiClientRepositoryInterface`, `ProviderHealthInventoryInterface`, `IntegrationRepositoryInterface` |
| **Permitted callers** | Payment (for provider health and connector lifecycle — UNRESOLVED, see DEC-06), Ops, Admin, Security |
| **Boundary with Payment (DEC-06)** | **UNRESOLVED** — Payment currently owns `AbstractBankPaymentAdapter` and `PaymentCallbackController` (its own HTTP adapter layer). It is unresolved whether bank adapters (P12–P14) should use E25 transport/connector registry or remain self-contained in Payment. Owner: Architect. Must resolve before P12. |

---

### 5. Fees

| Field | Value |
|-------|-------|
| **Namespace** | `App\Modules\Fees` |
| **Schema** | `fee.*` (dedicated PostgreSQL schema) |
| **Owned tables** | `fee.fee_rules`, `fee.fees`, `fee.pricing_versions` |
| **Exposes** | `FeesModuleContract` (fee calculation) |
| **Permitted callers** | Payment (orchestration layer, for fee calculation on each intent), Transfer, Funding, Withdrawal, Admin (fee rule management) |
| **Prohibited direct access** | `fee.*` tables — callers use `FeesModuleContract` |

---

### 6. Audit

| Field | Value |
|-------|-------|
| **Namespace** | `App\Modules\Audit` |
| **Schema** | `audit.*` (dedicated PostgreSQL schema) |
| **Owned tables** | `audit.audit_events`, `audit.audit_events_archive`, `audit.compliance_status`, `audit.compliance_history`, `audit.compliance_reports`, `audit.suspicious_activity_reports`, `audit.retention_policies` |
| **Exposes** | `AuditServiceInterface` (via Platform module) |
| **Permitted callers** | All modules that need to emit audit events (Payment, Wallet, Identity, Admin, etc.) via `AuditServiceInterface` |
| **Prohibited direct access** | `audit.*` tables — write via `AuditServiceInterface` only; read via Audit query service |

---

### 7. Identity

| Field | Value |
|-------|-------|
| **Namespace** | `App\Modules\Identity` |
| **Schema** | `public` (users, devices, sessions tables) |
| **Exposes** | `ActorStepUpVerifierContract`, authentication contracts |
| **Permitted callers** | Payment, all modules requiring actor authentication |

---

### 8. Platform

| Field | Value |
|-------|-------|
| **Namespace** | `App\Modules\Platform` |
| **Schema** | `public.platform_configurations`, `public.platform_configuration_change_requests`, `public.platform_configuration_approvals`, `public.platform_configuration_versions`, `public.pending_admin_actions` |
| **Exposes** | `ConfigurationServiceInterface`, `AuditServiceInterface`, `ProviderCapabilityEnum` (Platform variant) |
| **Note** | Platform's `ProviderCapabilityEnum` (InitiatePayment, QueryTransactionStatus, ReversePriorPayment) is used by `WalletPaymentAdapter` and is distinct from Payment's `ProviderCapabilityEnum` (8 cases including Payout). This dual-enum pattern must be resolved — see DEC-P01-ENUM-01 in ADR-E48-003. |

---

### 9–30. Other Modules (summary entries)

| Module | Primary Tables (public schema unless noted) | Exposes | Key Constraint |
|--------|---------------------------------------------|---------|----------------|
| Admin | `admin_actions`, `admin_wallet_adjustments` | Admin service contracts | Wallet adjustments via WalletModuleContract only |
| AdminApp | (UI-only, no owned tables) | N/A | Read-only aggregation |
| Agent | `agent_applications`, `agents`, `agent_customer_relationships`, `agent_status_history`, `agent_documents` | `AgentModuleContract` | Agent status changes are audited |
| AgentFloat | `agent_float_wallets`, `agent_float_transactions`, `agent_float_limits` | `AgentFloatContract` | Float wallets use WalletModuleContract |
| Commission | `commission_rules`, `commissions`, `commission_payouts`, `commission_processed_events` | `CommissionModuleContract` | Commission is event-driven; no direct payment writes |
| Funding | `funding_transactions` | (none — calls PaymentModuleContract) | Funding is a consumer of PaymentModuleContract |
| Kyc | `kyc_verifications`, `kyc_documents` | `KycModuleContract` | KYC decisions are immutable (audit trail) |
| MultiCurrency | Wallet pocket extensions | (extends WalletModuleContract) | Never mutates base wallet balance directly |
| Notification | `notifications`, `notification_preferences` | `NotificationModuleContract` | Notifications are fire-and-forget; never block financial flows |
| Ops | Operations/runbook tables | Ops service contracts | Internal tooling only |
| Profile | `profiles`, `profile_photos` | `ProfileModuleContract` | Profile data is non-financial |
| Qa | Test record tables | (internal) | Not consumed by production flows |
| Qr | `qr_codes`, `merchant_payment_requests` | (calls PaymentModuleContract) | QR is a consumer of PaymentModuleContract |
| Reconciliation | `reconciliation_runs`, `reconciliation_exceptions` | `ReconciliationModuleContract` | Must not modify ledger entries — read-only + exception flagging |
| Reporting | Reporting tables | `ReportingModuleContract` | Read-only aggregation from ledger/transaction data |
| Risk | `risk_events`, `fraud_rules` | `RiskModuleContract` | Risk decisions are advisory; Payment enforces blocking |
| Security | Security audit tables | Security service contracts | Security events must be audited |
| Service | `service_categories`, `service_providers`, `bill_payments` | (calls PaymentModuleContract) | Bill payment is a consumer of PaymentModuleContract |
| Support | `support_tickets`, `support_messages`, `disputes` | `SupportModuleContract` | Dispute resolution triggers refund via PaymentModuleContract |
| Transaction | `transactions` | `TransactionModuleContract` | Unified transaction log — reads from all financial modules |
| Transfer | `transfers` | (calls WalletModuleContract, LedgerModuleContract) | Wallet-to-wallet: uses WalletModuleContract holds |
| Withdrawal | `withdrawals` | (calls WalletModuleContract, LedgerModuleContract) | Cash-out: uses WalletModuleContract holds |

---

## Dependency Direction Summary

```
Business Modules (Funding, Transfer, Qr, Service, Support, community)
    ↓ call
PaymentModuleContract [owned by Payment]
    ↓ Payment orchestrates via
PaymentAdapterInterface [WalletPaymentAdapter | BankAdapters]
    ↓ WalletPaymentAdapter calls only
WalletModuleContract [owned by Wallet]
    ↓ Wallet calls only  
LedgerModuleContract [owned by Ledger]

Payment ← BusinessPurposeResolverInterface → Business Modules (domain registers handler)
Payment → FeesModuleContract [owned by Fees] for fee calculation
Payment → AuditServiceInterface [Platform] for audit events
Payment → ConfigurationServiceInterface [Platform] for step-up thresholds
```

**Rule**: Dependencies only flow DOWN this chain or sideways via Platform services. No upward dependencies permitted.
