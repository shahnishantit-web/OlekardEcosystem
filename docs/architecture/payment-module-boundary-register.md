# Payment Module Boundary Register

**Version**: 1.0 (P01 — pending architect sign-off)

**Date**: 2026-10-21

**Purpose**: Authoritative record of which module owns which tables, which interfaces cross module boundaries, and what dependencies are permitted. Derived from: ADR-E48-001, ADR-E48-002, repository code inspection on 2026-10-21.

**Rule**: Any cross-module access not described here is prohibited. Violations require an approved ADR before code change.

---

## Quick Reference: Payment-Related Dependency Chain

```
Business Domain Modules
(Funding | Transfer | Qr | Service | Support | Community P24–P28)
            │ only via
            ▼
    PaymentModuleContract
    [App\Modules\Payment]
            │ provider dispatch
            ▼
    PaymentAdapterInterface ◄── WalletPaymentAdapter
            │                         │ only via
            │                         ▼
            │              WalletModuleContract
            │              [App\Modules\Wallet]
            │                         │ only via
            │                         ▼
            │              LedgerModuleContract
            │              [App\Modules\Ledger]
            │
            └── FeesModuleContract [App\Modules\Fees]
            └── AuditServiceInterface [App\Modules\Platform]
            └── ConfigurationServiceInterface [App\Modules\Platform]
```

---

## Module Entries

### App\Modules\Payment

| Attribute | Detail |
|-----------|--------|
| **Schema** | `payment` (dedicated PostgreSQL schema) |
| **Owned tables** | `payment.payment_providers`, `payment.payment_provider_accounts`, `payment.payment_intents`, `payment.payment_attempts`, `payment.payment_refunds`, `payment.payment_callbacks`, `payment.reconciliation_exceptions` |
| **Exposes** | `PaymentModuleContract` |
| **Permitted callers** | All business modules (Funding, Transfer, Withdrawal, Qr, Service, Support, community) |
| **Inbound dependency** | Any module needing payment uses `PaymentModuleContract` — never `payment.*` tables directly |
| **Outbound dependencies** | `WalletModuleContract` (via WalletPaymentAdapter only), `LedgerModuleContract` (via WalletPaymentAdapter only), `FeesModuleContract`, Platform services |
| **Prohibited** | Direct reads of `payment.*` tables from outside; Payment calling other modules' tables directly |
| **ADR basis** | ADR-E48-001 |

---

### App\Modules\Wallet

| Attribute | Detail |
|-----------|--------|
| **Schema** | `public` |
| **Owned tables** | `wallets`, `wallet_pockets`, `wallet_holds` |
| **Exposes** | `WalletModuleContract` (9 methods) |
| **Permitted callers** | WalletPaymentAdapter (from Payment), Transfer, Funding, Withdrawal, AgentFloat, Admin (adjustments), MultiCurrency |
| **Inbound dependency** | All balance reservations and reads use `WalletModuleContract` |
| **Outbound dependencies** | `LedgerModuleContract` (for journal entries after holds) |
| **Prohibited** | Direct write to `wallets`, `wallet_pockets`, `wallet_holds` from outside; Payment calling WalletModuleContract except via WalletPaymentAdapter |
| **ADR basis** | ADR-E48-002 |

---

### App\Modules\Ledger

| Attribute | Detail |
|-----------|--------|
| **Schema** | TBD — schema-per-module pattern pending verification |
| **Owned tables** | Ledger accounts, journal entries, journal lines (exact table names TBD) |
| **Exposes** | `LedgerModuleContract` (6 methods: postEntry, entryExists, registerAccount, getBalance, getTransactionHistory, getAccountHistory) |
| **Permitted callers** | Wallet (primary), WalletPaymentAdapter (via Wallet delegation), Transfer, Funding, Withdrawal, AgentFloat, Reconciliation |
| **Critical invariant** | `postEntry()` enforces balanced double-entry; every journal MUST balance. Historical entries immutable. |
| **Prohibited** | Direct writes to ledger tables from any module; unbalanced journal submissions (rejected by postEntry) |

---

### App\Modules\Integration (E25)

| Attribute | Detail |
|-----------|--------|
| **Schema** | `public` |
| **Owned tables** | `integrations`, `api_clients`, `webhook_events` |
| **Exposes** | `IntegrationRegistryInterface`, `WebhookEventRepositoryInterface`, `ApiClientRepositoryInterface`, `ProviderHealthInventoryInterface`, `IntegrationRepositoryInterface` |
| **Boundary with Payment (DEC-06)** | **UNRESOLVED** — Payment currently has self-contained bank adapters (`AbstractBankPaymentAdapter`, `PaymentCallbackController`). Whether P12–P14 bank adapters reuse E25 transport/webhook ingress or remain self-contained is unresolved. Architect must decide before P12. |

---

### App\Modules\Fees

| Attribute | Detail |
|-----------|--------|
| **Schema** | `fee` (dedicated PostgreSQL schema) |
| **Owned tables** | `fee.fee_rules`, `fee.fees`, `fee.pricing_versions` |
| **Exposes** | `FeesModuleContract` |
| **Permitted callers** | Payment (orchestration — fee calculation per intent), Transfer, Funding, Withdrawal, Admin |

---

### App\Modules\Audit

| Attribute | Detail |
|-----------|--------|
| **Schema** | `audit` (dedicated PostgreSQL schema) |
| **Owned tables** | `audit.audit_events`, `audit.audit_events_archive`, `audit.compliance_status`, `audit.compliance_history`, `audit.compliance_reports`, `audit.suspicious_activity_reports`, `audit.retention_policies` |
| **Exposes** | `AuditServiceInterface` (via Platform) |
| **Permitted callers** | All modules — write via `AuditServiceInterface` only |
| **Critical invariant** | Audit events are immutable after creation |

---

### App\Modules\Platform

| Attribute | Detail |
|-----------|--------|
| **Schema** | `public` |
| **Owned tables** | `platform_configurations`, `platform_configuration_change_requests`, `platform_configuration_approvals`, `platform_configuration_versions`, `pending_admin_actions` |
| **Exposes** | `ConfigurationServiceInterface`, `AuditServiceInterface`, `ProviderCapabilityEnum` (Platform variant) |
| **Note** | Platform's `ProviderCapabilityEnum` (3 cases) is distinct from Payment's (8 cases). See ADR-E48-003 AD-07. |

---

### All Other Modules (summary)

| Module | Tables (public schema unless noted) | Exposes | Dependency |
|--------|-------------------------------------|---------|------------|
| Admin | `admin_actions`, `admin_wallet_adjustments` | Admin service | Calls WalletModuleContract for adjustments |
| AdminApp | None (UI) | None | Read-only |
| Agent | `agent_applications`, `agents`, `agent_customer_relationships`, `agent_status_history`, `agent_documents` | `AgentModuleContract` | — |
| AgentFloat | `agent_float_wallets`, `agent_float_transactions`, `agent_float_limits` | `AgentFloatContract` | Calls WalletModuleContract |
| Commission | `commission_rules`, `commissions`, `commission_payouts`, `commission_processed_events` | `CommissionModuleContract` | Event-driven |
| Funding | `funding_transactions` | None | Calls PaymentModuleContract |
| Identity | Users, devices, sessions (public) | `ActorStepUpVerifierContract`, auth contracts | — |
| Kyc | `kyc_verifications`, `kyc_documents` | `KycModuleContract` | — |
| MultiCurrency | Wallet pocket extensions | Extends WalletModuleContract | Calls WalletModuleContract |
| Notification | `notifications`, `notification_preferences` | `NotificationModuleContract` | Fire-and-forget only |
| Ops | Operations tables | Ops contracts | — |
| Profile | `profiles`, `profile_photos` | `ProfileModuleContract` | — |
| Qa | Test records | Internal | Not in production flows |
| Qr | `qr_codes`, `merchant_payment_requests` | None | Calls PaymentModuleContract |
| Reconciliation | `reconciliation_runs`, `reconciliation_exceptions` | `ReconciliationModuleContract` | Read-only from Ledger |
| Reporting | Reporting tables | `ReportingModuleContract` | Read-only |
| Risk | `risk_events`, `fraud_rules` | `RiskModuleContract` | Advisory only |
| Security | Security audit tables | Security contracts | — |
| Service | `service_categories`, `service_providers`, `bill_payments` | None | Calls PaymentModuleContract |
| Support | `support_tickets`, `support_messages`, `disputes` | `SupportModuleContract` | Calls PaymentModuleContract for refunds |
| Transaction | `transactions` | `TransactionModuleContract` | Reads from financial modules |
| Transfer | `transfers` | None | Calls WalletModuleContract, LedgerModuleContract |
| Withdrawal | `withdrawals` | None | Calls WalletModuleContract, LedgerModuleContract |

---

## Open Items

| ID | Description | Owner | Blocking |
|----|-------------|-------|---------|
| DEC-06 | E25 ↔ Payment adapter boundary | Architect | P12, P13, P14 |
| AD-07 | Dual ProviderCapabilityEnum resolution | Architect | P12, P13, P14 |
| AD-14 | Payout contract ownership | Architect | P18, P24–P28 |
