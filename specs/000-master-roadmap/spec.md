# Master Roadmap — Wallet Ecosystem

## Purpose
This is the **spec-of-specs** roadmap for the 538-story ecosystem. Each epic below is an independently executable Spec-Kit feature. This decomposition is intentional because the complete product is too large for one specify/plan/tasks/implement cycle. Spec Kit explicitly recommends a roadmap of smaller independently specified sub-features for large features. citeturn667688search1

## Source of truth
- Approved 538-story formal implementation specification
- Latest decision baseline
- Project constitution
- Enterprise UX/security/testing requirements

## Architecture
- Laravel/PHP backend APIs
- Next.js/TypeScript Administration + Enterprise web
- Flutter Customer and Agent mobile
- PostgreSQL
- Redis/Horizon
- Modular monolith now; bounded-context service target-state later

## Roadmap

| Epic | Name | Spec Path | Stories | Status |
|---|---|---|---:|---|
| E01 | Central Identity & Authentication | `specs/001-central-identity-authentication/` | 38 | Planned |
| E02 | User & Profile Management | `specs/002-user-profile-management/` | 15 | Planned |
| E03 | Device & Session Management | `specs/003-device-session-management/` | 9 | Planned |
| E04 | Roles, Permissions & Authorization | `specs/004-roles-permissions-authorization/` | 18 | Planned |
| E05 | KYC & Identity Verification | `specs/005-kyc-identity-verification/` | 18 | Planned |
| E06 | Wallet Management | `specs/006-wallet-management/` | 17 | Planned |
| E07 | Wallet Ledger | `specs/007-wallet-ledger/` | 14 | Planned |
| E08 | Wallet Funding | `specs/008-wallet-funding/` | 19 | Planned |
| E09 | Wallet-to-Wallet Transfer | `specs/009-wallet-to-wallet-transfer/` | 19 | Planned |
| E10 | Withdrawal / Cash-Out | `specs/010-withdrawal-cash-out/` | 12 | Planned |
| E11 | Transaction Management | `specs/011-transaction-management/` | 19 | Planned |
| E12 | Agent Management | `specs/012-agent-management/` | 15 | Planned |
| E13 | Agent Float | `specs/013-agent-float/` | 10 | Planned |
| E14 | Agent Commission | `specs/014-agent-commission/` | 9 | Planned |
| E15 | Service & Bill Payments | `specs/015-service-bill-payments/` | 11 | Planned |
| E16 | QR & Merchant Payments | `specs/016-qr-merchant-payments/` | 9 | Planned |
| E17 | Notifications | `specs/017-notifications/` | 10 | Planned |
| E18 | Fraud, Risk & AML | `specs/018-fraud-risk-aml/` | 22 | Planned |
| E19 | Admin Portal | `specs/019-admin-portal/` | 27 | Planned |
| E20 | Reconciliation & Settlement | `specs/020-reconciliation-settlement/` | 16 | Planned |
| E21 | Fees & Pricing | `specs/021-fees-pricing/` | 9 | Planned |
| E22 | Support & Dispute Management | `specs/022-support-dispute-management/` | 13 | Planned |
| E23 | Audit & Compliance | `specs/023-audit-compliance/` | 15 | Planned |
| E24 | Reporting & Analytics | `specs/024-reporting-analytics/` | 13 | Planned |
| E25 | API & Integration Platform | `specs/025-api-integration-platform/` | 20 | Planned |
| E26 | Security | `specs/026-security/` | 16 | Planned |
| E27 | Reliability & Transaction Integrity | `specs/027-reliability-transaction-integrity/` | 11 | Planned |
| E28 | Configuration Management | `specs/028-configuration-management/` | 11 | Planned |
| E29 | Multi-Currency Wallet | `specs/029-multi-currency-wallet/` | 9 | Planned |
| E30 | DevOps & Operations | `specs/030-devops-operations/` | 12 | Planned |
| E31 | Customer Application | `specs/031-customer-application/` | 23 | Planned |
| E32 | Agent Application | `specs/032-agent-application/` | 19 | Planned |
| E33 | Admin Application | `specs/033-admin-application/` | 22 | Planned |
| E34 | QA & Validation | `specs/034-qa-validation/` | 18 | Planned |
| E35 | Unified Design System | `specs/035-unified-design-system/` | 10 | Planned |
| E36 | Unified Application Layout & UX Architecture | `specs/036-unified-app-layout/` | 12 | Planned |
| E37 | Admin UI/UX Layout Convergence | `specs/037-admin-uiux-convergence/` | 9 | Planned |
| E38 | Admin — Fee Rules Management | `specs/038-admin-fee-rules/` | 2 | Planned |
| E39 | Admin — Reports & Analytics | `specs/039-admin-reports/` | 2 | Planned |
| E40 | Admin — Audit Log & Compliance | `specs/040-admin-audit-log/` | 3 | Planned |
| E41 | Admin — System Configuration | `specs/041-admin-system-config/` | 3 | Planned |
| E48 | Shared Payment Integration | `specs/048-shared-payment-integration/` | 170 | Complete/Release Ready |
| E49 | Community Services Platform: Horizontal Foundation | `specs/049-community-services-horizontal/` | 135 | Complete/Release Ready |
| E50 | Funeral Assistance | `specs/050-funeral-assistance/` | TBD | Planned |
| E51 | Events & Ticketing | `specs/051-events-ticketing/` | TBD | Planned |
| E52 | Raffles & Fundraising | `specs/052-raffles-fundraising/` | TBD | Planned |

## Dependency order

### Foundation
E01 Identity/Auth → E02 User → E03 Devices → E04 Authorization → E05 KYC

### Financial Core
E06 Wallet → E07 Ledger → E11 Transactions → E21 Fees

### Money Movement
E08 Funding → E09 Transfers → E10 Withdrawals

### Risk & Operations
E18 Risk → E17 Notifications → E20 Reconciliation → E14 Commission

### Agent
E12 Agent → E13 Float

### Services & Expansion
E15 Services → E16 QR/Merchant → E29 Multi-Currency

### Governance/Platform
E19 Admin → E23 Audit/Compliance → E24 Reporting → E25 Integrations → E26 Security → E27 Reliability → E28 Configuration → E30 Operations

### Experience
E31 Customer App → E32 Agent App → E33 Admin App → E34 QA/Validation

## Global traceability
Every story ID is immutable and must trace to:
SRS requirement → Epic → Feature → Story → plan → task → code → test → QA evidence.
