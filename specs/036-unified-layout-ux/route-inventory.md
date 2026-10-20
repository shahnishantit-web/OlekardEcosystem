# Route Inventory: OleKard Applications

**Feature**: E36 | **Date**: 2026-10-10 | Satisfies: FR-027

---

## Admin Portal (`apps/admin`)

> Auth: All `(admin)` routes require authenticated admin session. Public routes are login only.

| Route Path | Screen/Page | Auth Required | Permission | Layout Parent | Nav Entry | Status |
|------------|-------------|---------------|------------|---------------|-----------|--------|
| `/login` | Login | No | — | Root | No | ✅ Implemented |
| `/` | Root redirect | No | — | Root | No | ✅ Implemented |
| `/dashboard` | Admin Dashboard | Yes | — | AdminShell | Overview > Dashboard | ✅ Implemented |
| `/admin-portal/dashboard` | Portal Dashboard | Yes | — | AdminShell | — | ✅ Implemented |
| `/admin-portal/users` | User Management | Yes | `users.read` | AdminShell | Users & Identity > Users | ✅ Implemented |
| `/admin-portal/users/:id` | User Detail | Yes | `users.read` | AdminShell | — | ✅ Implemented |
| `/admin-portal/wallets` | Wallet Management | Yes | `wallets.read` | AdminShell | Finance > Wallets | ✅ Implemented |
| `/admin-portal/transactions` | Transaction Management | Yes | `transactions.read` | AdminShell | Finance > Transactions | ✅ Implemented |
| `/sessions` | Session Management | Yes | `sessions.read` | AdminShell | Users & Identity > Sessions | ✅ Implemented |
| `/kyc` | KYC Review List | Yes | `kyc.read` | AdminShell | Customer Ops > KYC | ✅ Implemented |
| `/kyc/:id` | KYC Detail | Yes | `kyc.read` | AdminShell | — | ✅ Implemented |
| `/agents` | Agent Management | Yes | `agents.read` | AdminShell | Ecosystem Ops > Agents | ✅ Implemented |
| `/agents/:id` | Agent Detail | Yes | `agents.read` | AdminShell | — | ✅ Implemented |
| `/float` | Float Management | Yes | `float.read` | AdminShell | Ecosystem Ops > Float | ✅ Implemented |
| `/reconciliation/batches` | Reconciliation Batches | Yes | `reconciliation.read` | AdminShell | Finance > Reconciliation | ✅ Implemented |
| `/reconciliation/batches/:id` | Batch Detail | Yes | `reconciliation.read` | AdminShell | — | ✅ Implemented |
| `/reconciliation/settlements` | Settlement List | Yes | `reconciliation.read` | AdminShell | Finance > Settlements | ✅ Implemented |
| `/fees/rules` | Fee Rules | Yes | `fees.read` | AdminShell | Finance > Fee Rules | ✅ Implemented |
| `/fees/rules/create` | Create Fee Rule | Yes | `fees.write` | AdminShell | — | ✅ Implemented |
| `/audit` | Audit Events | Yes | `audit.read` | AdminShell | Compliance & Audit > Audit | ✅ Implemented |
| `/compliance` | Compliance Status | Yes | `audit.read` | AdminShell | Compliance & Audit > Compliance | ✅ Implemented |
| `/compliance/retention` | Retention Policies | Yes | `audit.read` | AdminShell | — | ✅ Implemented |
| `/reports` | Reports | Yes | `reports.read` | AdminShell | Compliance & Audit > Reports | ✅ Implemented |
| `/reports/:id` | Report Job Detail | Yes | `reports.read` | AdminShell | — | ✅ Implemented |
| `/reports/schedules` | Report Schedules | Yes | `reports.read` | AdminShell | — | ✅ Implemented |
| `/reports/reconciliation` | Reconciliation Report | Yes | `reports.read` | AdminShell | — | ✅ Implemented |
| `/reports/settlement` | Settlement Report | Yes | `reports.read` | AdminShell | — | ✅ Implemented |
| `/integrations` | API Clients | Yes | `integrations.read` | AdminShell | Platform > Integrations | ✅ Implemented |
| `/integrations/:id` | API Client Detail | Yes | `integrations.read` | AdminShell | — | ✅ Implemented |
| `/integrations/providers` | Integration Providers | Yes | `integrations.read` | AdminShell | — | ✅ Implemented |
| `/integrations/webhooks` | Webhook Monitor | Yes | `integrations.read` | AdminShell | — | ✅ Implemented |
| `/security/dashboard` | Security Dashboard | Yes | `security.findings.read` | AdminShell | Security > Security Dashboard | ✅ Implemented |
| `/security/findings` | Vulnerability Findings | Yes | `security.findings.read` | AdminShell | Security > Findings | ✅ Implemented |
| `/operations/dead-letter` | Dead Letter Queue | Yes | `operations.dead_letter.manage` | AdminShell | Operations > Dead Letter | ✅ Implemented |
| `/configuration` | Configuration List | Yes | `config.read` | AdminShell | Platform > Configuration | ✅ Implemented |
| `/configuration/approvals` | Config Approvals | Yes | `config.approve` | AdminShell | — | ✅ Implemented |
| `/configuration/approvals/:id` | Approval Detail | Yes | `config.approve` | AdminShell | — | ✅ Implemented |
| `/configuration/:domain/:key` | Config Key Detail | Yes | `config.write` | AdminShell | — | ✅ Implemented |
| `/multi-currency/currencies` | Currencies | Yes | `multi_currency.read` | AdminShell | Finance > Currencies | ✅ Implemented |
| `/multi-currency/fx-rates` | FX Rates | Yes | `multi_currency.read` | AdminShell | Finance > FX Rates | ✅ Implemented |
| `/platform/security-config` | Security Config | Yes | `config.read` | AdminShell | Platform > Security Config | ✅ Implemented |
| `/financial-dashboard` | Financial Dashboard | Yes | `finance.read` | AdminShell | Overview > Financial | ✅ Implemented |
| `/risk-dashboard` | Risk Dashboard | Yes | `risk.read` | AdminShell | Overview > Risk | ✅ Implemented |
| `/ops` | Ops Overview | Yes | `ops.read` | AdminShell | Operations > Overview | ✅ Implemented |
| `/ops/logs` | System Logs | Yes | `ops.read` | AdminShell | Operations > Logs | ✅ Implemented |
| `/ops/metrics` | Metrics | Yes | `ops.read` | AdminShell | Operations > Metrics | ✅ Implemented |
| `/ops/db-monitoring` | DB Monitoring | Yes | `ops.read` | AdminShell | Operations > DB | ✅ Implemented |
| `/ops/queue-monitoring` | Queue Monitoring | Yes | `ops.read` | AdminShell | Operations > Queue | ✅ Implemented |
| `/ops/providers` | Providers | Yes | `ops.read` | AdminShell | Operations > Providers | ✅ Implemented |
| `/ops/alert-rules` | Alert Rules | Yes | `ops.write` | AdminShell | Operations > Alert Rules | ✅ Implemented |
| `/ops/alerts` | Alerts | Yes | `ops.read` | AdminShell | Operations > Alerts | ✅ Implemented |
| `/ops/backup-jobs` | Backup Jobs | Yes | `ops.read` | AdminShell | Operations > Backups | ✅ Implemented |
| `/ops/deployments` | Deployments | Yes | `ops.read` | AdminShell | Operations > Deployments | ✅ Implemented |
| `/qa` | QA Dashboard | Yes | `qa.execute` | AdminShell | Quality > QA Dashboard | ✅ Implemented |
| `/qa/runs/:id` | QA Run Detail | Yes | `qa.execute` | AdminShell | — | ✅ Implemented |

**Total admin routes**: 55 (53 authenticated + 2 public: `/login` + `/` root redirect)

---

## Customer App (`apps/customer`)

> Auth: All shell routes require authenticated customer session.

| Route Path | Screen | Auth Required | Shell Tab | Layout Parent | Status |
|------------|--------|---------------|-----------|---------------|--------|
| `/` | Splash | No | — | None | ✅ Implemented |
| `/login` | Login | No | — | Auth flow | ✅ Implemented |
| `/register` | Register | No | — | Auth flow | ✅ Implemented |
| `/register/otp` | OTP Verification | No | — | Auth flow | ✅ Implemented |
| `/register/profile` | Profile Setup | No | — | Auth flow | ✅ Implemented |
| `/register/pin` | PIN Creation | No | — | Auth flow | ✅ Implemented |
| `/forgot-pin` | Forgot PIN | No | — | Auth flow | ✅ Implemented |
| `/forgot-pin/otp` | OTP (Reset) | No | — | Auth flow | ✅ Implemented |
| `/forgot-pin/new-pin` | New PIN | No | — | Auth flow | ✅ Implemented |
| `/step-up-pin` | Step-Up PIN | Yes | — | Full-screen | ✅ Implemented |
| `/add-money` | Add Money | Yes | — | Full-screen | ✅ Implemented |
| `/send` | Send Money | Yes | — | Full-screen | ✅ Implemented |
| `/withdraw` | Withdraw | Yes | — | Full-screen | ✅ Implemented |
| `/home` | Home | Yes | Home (0) | MainShell | ✅ Implemented |
| `/home/notifications` | Notifications | Yes | Home (0) | MainShell | ✅ Implemented |
| `/wallet` | Wallet Detail | Yes | Wallet (1) | MainShell | ✅ Implemented |
| `/receive` | Receive Money | Yes | Wallet (1) | MainShell | ✅ Implemented |
| `/services` | Services Home | Yes | Services (2) | MainShell | ✅ Implemented |
| `/services/:categoryId` | Service Providers | Yes | Services (2) | MainShell | ✅ Implemented |
| `/services/purchase/:providerId` | Service Purchase | Yes | Services (2) | MainShell | ✅ Implemented |
| `/services/history` | Service History | Yes | Services (2) | MainShell | ✅ Implemented |
| `/activity` | Transaction List | Yes | Activity (3) | MainShell | ⚠️ New route (E36) |
| `/activity/:id` | Transaction Detail | Yes | Activity (3) | MainShell | ⚠️ New route (E36) |
| `/profile` | Profile | Yes | Account (4) | MainShell | ✅ Implemented |
| `/profile/edit` | Edit Profile | Yes | Account (4) | MainShell | ✅ Implemented |
| `/security` | Security Settings | Yes | Account (4) | MainShell | ✅ Implemented |
| `/security/change-pin` | Change PIN | Yes | Account (4) | MainShell | ✅ Implemented |
| `/security/devices` | Device Management | Yes | Account (4) | MainShell | ✅ Implemented |
| `/support` | Support Home | Yes | Account (4) | MainShell | ✅ Implemented |
| `/support/new` | New Inquiry | Yes | Account (4) | MainShell | ✅ Implemented |
| `/support/:id` | Ticket Detail | Yes | Account (4) | MainShell | ✅ Implemented |
| `/disputes` | Dispute List | Yes | Account (4) | MainShell | ✅ Implemented |
| `/disputes/new/:txId` | Raise Dispute | Yes | Account (4) | MainShell | ✅ Implemented |
| `/disputes/:id` | Dispute Detail | Yes | Account (4) | MainShell | ✅ Implemented |

**Note**: `/activity` and `/activity/:id` are new routes added by E36. They map to the existing `TransactionListScreen` and `TransactionDetailScreen`.

---

## Agent App (`apps/agent`)

> Auth: All shell routes require authenticated agent session.

| Route Path | Screen | Auth Required | Shell Tab | Layout Parent | Status |
|------------|--------|---------------|-----------|---------------|--------|
| `/` | Splash | No | — | None | ✅ Implemented |
| `/login` | Login | No | — | Auth flow | ✅ Implemented |
| `/forgot-pin` | Forgot PIN | No | — | Auth flow | ✅ Implemented |
| `/forgot-pin/otp` | OTP (Reset) | No | — | Auth flow | ✅ Implemented |
| `/forgot-pin/new-pin` | New PIN | No | — | Auth flow | ✅ Implemented |
| `/step-up-pin` | Step-Up PIN | Yes | — | Full-screen | ✅ Implemented |
| `/cash-in/search` | Customer Search (CI) | Yes | — | Full-screen | ✅ Implemented |
| `/cash-in/form` | Cash In Form | Yes | — | Full-screen | ✅ Implemented |
| `/cash-in/confirm` | Cash In Confirm | Yes | — | Full-screen | ✅ Implemented |
| `/cash-in/result` | Cash In Result | Yes | — | Full-screen | ✅ Implemented |
| `/cash-out/scan` | Pickup Code Scan | Yes | — | Full-screen | ✅ Implemented |
| `/cash-out/search` | Customer Search (CO) | Yes | — | Full-screen | ✅ Implemented |
| `/cash-out/form` | Cash Out Form | Yes | — | Full-screen | ✅ Implemented |
| `/cash-out/confirm` | Cash Out Confirm | Yes | — | Full-screen | ✅ Implemented |
| `/cash-out/result` | Cash Out Result | Yes | — | Full-screen | ✅ Implemented |
| `/onboarding/register` | Customer Registration | Yes | — | Full-screen | ✅ Implemented |
| `/onboarding/result` | Registration Result | Yes | — | Full-screen | ✅ Implemented |
| `/customers/:id/status` | Customer Status | Yes | — | Full-screen | ✅ Implemented |
| `/customers/:id/kyc` | KYC Capture | Yes | — | Full-screen | ✅ Implemented |
| `/float/top-up` | Float Top-Up | Yes | — | Full-screen | ✅ Implemented |
| `/security` | Security Settings | Yes | — | Full-screen | ✅ Implemented |
| `/change-pin` | Change PIN | Yes | — | Full-screen | ✅ Implemented |
| `/dashboard` | Agent Dashboard | Yes | Dashboard (0) | AgentShell | ✅ Implemented |
| `/customers` | Customer Lookup | Yes | Customers (1) | AgentShell | ⚠️ Moved to shell (E36) |
| `/transactions` | Transaction List | Yes | Transactions (2) | AgentShell | ✅ Implemented |
| `/transactions/:id` | Transaction Detail | Yes | Transactions (2) | AgentShell | ✅ Implemented |
| `/notifications` | Notifications | Yes | Activity (3) | AgentShell | ✅ Implemented |
| `/float` | Float Overview | Yes | — (was Transactions tab) | AgentShell | ✅ Implemented |
| `/commissions` | Commissions | Yes | Dashboard quick action | AgentShell | ✅ Implemented |
| `/profile` | Profile | Yes | Account (4) | AgentShell | ✅ Implemented |

**Note**: `/customers` is moved from full-screen (parentNavigatorKey: root) to the shell (Customers tab) by E36. `/float` and `/commissions` are retained as shell routes accessible from the Dashboard, not dedicated tabs.
