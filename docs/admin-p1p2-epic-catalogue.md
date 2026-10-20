# OleKard Admin — P1/P2 Epic Catalogue

**Generated:** 2026-10-12  
**Source of Truth:** `Wallet_Ecosystem_Updated_538_Implementation_Specification.md` (538 stories, 34 epics)  
**Admin App Base:** `wallet-ecosystem/apps/admin` (Next.js 14, App Router)  
**Repository Audit Date:** 2026-10-12  
**Phase:** A — Backlog Definition (no implementation yet; approval required before proceeding)

---

## Audit Status Summary

| Area | EXISTS | PARTIAL | MISSING |
|------|--------|---------|---------|
| Auth routes | login | — | forgot-pwd, reset-pwd, mfa-enrollment, mfa-recovery, verify-email, account-locked, account-disabled, pwd-expired |
| Admin routes | 57/58 | devices (no page.tsx) | support/disputes, notifications management |
| System/error pages | 7 | — | 401, 429, 503, 504, maintenance, offline, unsupported-browser |
| UI components | 26/26 | — | — |
| Auth API flows | login, MFA verify, step-up, sessions | — | forgot-pwd, reset-pwd, mfa-enrollment, mfa-recovery |
| Profile/settings | personal info, account status | change-password, security settings | notifications prefs, MFA self-service |
| Operational modules | 17 core modules | — | notification management, support/disputes, system health monitor |
| Navigation | 31 items | — | — |
| Test coverage | 16 test files | — | integration, E2E, full page coverage |

---

## Epic Catalogue

### ADM-FOUND-001 — Admin App Foundation & Shell

| Field | Value |
|-------|-------|
| **Priority** | Foundation (prerequisite for all epics) |
| **Status** | EXISTS — E37 spec covers remaining convergence work |
| **Stories** | 6 |
| **Depends on** | E01 (backend auth), E04 (RBAC), design-system tokens |
| **Risk** | Low — shell is operational; E37 tasks cover remaining gaps |

**Scope:** Authenticated shell (AdminShell + AdminSidebar + AdminHeader + PageContainer), root layout, middleware route protection, permission-based nav filtering, error boundaries, design token cascade.

#### US-FOUND-001 — Admin accesses the authenticated shell

**As** an authenticated admin user,  
**I want** a persistent sidebar + header layout with permission-filtered navigation,  
**So that** I can navigate all authorized sections without re-authentication.

**Given** a valid `admin_token` cookie is present  
**When** I visit any `/dashboard` or admin route  
**Then** I see AdminShell with collapsed/expanded sidebar, header with user avatar, and nav items filtered to my permissions

**Given** `admin_token` is missing or expired  
**When** I visit any protected route  
**Then** middleware redirects me to `/login?callbackUrl={pathname}` and no protected content is served

#### US-FOUND-002 — Admin sees permission-gated navigation

**Given** I am logged in with role `ops_manager` (no `security.findings.read`)  
**When** I inspect the sidebar  
**Then** "Vulnerabilities" nav item is hidden  
**And** direct GET to `/security/findings` redirects to `/403`

#### US-FOUND-003 — Admin sees error boundaries without losing shell context

**Given** a page-level React error occurs inside `(admin)` group  
**When** the error boundary triggers  
**Then** the AdminShell (sidebar + header) remains visible with the in-shell error page  
**And** a "Try again" reset action is available

**Backend APIs:** None (client-side shell only)  
**Remaining work:** E37 tasks T001–T067 (layout convergence, nav expansion, token fixes)

---

### ADM-P1-001 — Password Recovery Flow

| Field | Value |
|-------|-------|
| **Priority** | P1 |
| **Status** | MISSING — no route, no module, no API client |
| **Stories** | 5 |
| **Depends on** | E01 backend (forgot-password, reset-password, verify-email APIs) |
| **Risk** | HIGH — admin accounts with no recovery path are locked out permanently on credential loss |

**Scope:** Forgot-password page → email delivery → reset-password page (token-gated) → success. Email verification page for new admin accounts. Account-locked and account-disabled informational pages.

#### US-P1-001-001 — Admin initiates forgot-password

**Given** I am on `/login` and have forgotten my password  
**When** I click "Forgot password?" and submit my email  
**Then** the system sends a password-reset email (or confirms silently for unknown emails)  
**And** I am shown a "Check your email" confirmation screen  
**And** the backend fires `ADMIN_PASSWORD_RESET_REQUESTED` audit event

**Given** I submit an invalid email format  
**When** I click Send  
**Then** inline validation blocks submission  
**And** no API call is made

#### US-P1-001-002 — Admin completes password reset

**Given** I received a reset-password email with a valid token  
**When** I click the link and land on `/reset-password?token={token}`  
**Then** I see a form to enter and confirm a new password  
**And** password strength is enforced (min 10 chars, 1 uppercase, 1 number, 1 special)

**Given** I submit a valid new password  
**When** I click Reset  
**Then** the backend invalidates the token, updates credentials, fires `ADMIN_PASSWORD_RESET_SUCCEEDED`  
**And** I am redirected to `/login` with a success banner

**Given** I land on `/reset-password?token={expired_token}`  
**When** the page loads  
**Then** I see an "This link has expired" error with a link back to `/forgot-password`

#### US-P1-001-003 — Admin sees account-locked page

**Given** my admin account is locked (≥5 consecutive failed logins)  
**When** I attempt to log in  
**Then** the login API returns `account_locked`  
**And** I am redirected to `/account-locked` with a message: "Your account has been locked. Contact your administrator."

#### US-P1-001-004 — Admin sees account-disabled page

**Given** my admin account has been disabled by a super-admin  
**When** I attempt to log in  
**Then** the login API returns `account_disabled`  
**And** I am redirected to `/account-disabled` with contact instructions

#### US-P1-001-005 — Admin verifies email on first login

**Given** I am a newly-created admin whose email is unverified  
**When** I log in for the first time  
**Then** I am redirected to `/verify-email` with instructions  
**And** I can re-send the verification email  
**And** once verified I am redirected to the dashboard

**Backend APIs:** `POST /v1/admin/auth/forgot-password`, `POST /v1/admin/auth/reset-password`, `POST /v1/admin/auth/verify-email`, `POST /v1/admin/auth/resend-verification`  
**New files:** `app/forgot-password/page.tsx`, `app/reset-password/page.tsx`, `app/verify-email/page.tsx`, `app/account-locked/page.tsx`, `app/account-disabled/page.tsx`, `src/modules/auth/pages/forgot-password.tsx`, `src/modules/auth/pages/reset-password.tsx`, `src/modules/auth/api/password-recovery.ts`

---

### ADM-P1-002 — MFA Enrollment & Management

| Field | Value |
|-------|-------|
| **Priority** | P1 |
| **Status** | MISSING — enrollment and recovery routes absent; TOTP/FIDO2 verify exists in login |
| **Stories** | 4 |
| **Depends on** | E01 backend MFA enrollment APIs, ADM-P1-001 (auth baseline) |
| **Risk** | HIGH — no way for admin to enroll or recover MFA factors without an out-of-band process |

**Scope:** TOTP enrollment wizard, FIDO2/passkey registration, recovery code generation/download, MFA required enforcement gate.

#### US-P1-002-001 — Admin enrolls TOTP authenticator

**Given** I am logged in and MFA is not yet configured  
**When** I navigate to `/mfa-enrollment`  
**Then** I see a TOTP setup wizard: QR code + manual entry key  
**And** I confirm setup by entering a TOTP code  
**And** on success the backend returns recovery codes and fires `MFA_TOTP_ENROLLED`

**Given** I enter an incorrect TOTP code on enrollment  
**When** I click Verify  
**Then** I see "Incorrect code, try again" without terminating the session

#### US-P1-002-002 — Admin enrolls FIDO2/passkey

**Given** I am on the MFA enrollment page  
**When** I choose "Security Key" and my browser supports WebAuthn  
**Then** the browser credential creation flow triggers  
**And** on success the passkey is registered and `MFA_FIDO2_ENROLLED` is fired

**Given** my browser does not support WebAuthn  
**When** I choose "Security Key"  
**Then** I see an informational message: "Your browser does not support hardware security keys. Use the authenticator app instead."

#### US-P1-002-003 — Admin downloads recovery codes

**Given** I have just enrolled MFA  
**When** the success screen shows recovery codes  
**Then** I can download them as a `.txt` file  
**And** I must check "I have saved my recovery codes" before continuing  
**And** codes can be regenerated once (invalidating previous codes)

#### US-P1-002-004 — Admin is forced to MFA enrollment gate

**Given** the platform policy requires MFA for admin accounts  
**And** I log in without MFA enrolled  
**When** login succeeds  
**Then** I am redirected to `/mfa-enrollment` and cannot access the dashboard until enrollment is complete

**Backend APIs:** `POST /v1/admin/auth/mfa/enroll-totp`, `POST /v1/admin/auth/mfa/verify-totp`, `POST /v1/admin/auth/mfa/enroll-fido2`, `POST /v1/admin/auth/mfa/recovery-codes`  
**New files:** `app/mfa-enrollment/page.tsx`, `app/mfa-recovery/page.tsx`, `src/modules/auth/pages/mfa-enrollment.tsx`, `src/modules/auth/api/mfa-enrollment.ts`

---

### ADM-P1-003 — Admin Profile & Account Settings

| Field | Value |
|-------|-------|
| **Priority** | P1 |
| **Status** | EXISTS/PARTIAL — personal info + account status page exists; change-password, MFA management, notification prefs absent |
| **Stories** | 5 |
| **Depends on** | ADM-P1-001, ADM-P1-002 |
| **Risk** | MEDIUM — functional gaps prevent self-service security management |

**Scope:** Personal info editor, change-password form, MFA devices list + add/remove, notification preferences, active sessions list from profile.

#### US-P1-003-001 — Admin updates personal information

**Given** I am on my profile page  
**When** I edit display name and submit  
**Then** the backend updates my profile and fires `ADMIN_PROFILE_UPDATED`  
**And** the updated name appears in the header avatar tooltip immediately

#### US-P1-003-002 — Admin changes password

**Given** I am on the security settings tab  
**When** I enter current password + new password + confirm  
**Then** the backend validates current password, updates credentials, fires `ADMIN_PASSWORD_CHANGED`  
**And** all existing sessions except current are revoked  
**And** I see a success toast

**Given** I enter an incorrect current password  
**When** I submit  
**Then** I see "Current password is incorrect" without logging me out

#### US-P1-003-003 — Admin manages enrolled MFA devices

**Given** I am on the security settings tab  
**When** the MFA devices list loads  
**Then** I see all enrolled TOTP apps and security keys with their names and enrollment dates  
**And** I can remove any device (with step-up confirmation)  
**And** I can add a new TOTP or FIDO2 device

#### US-P1-003-004 — Admin manages notification preferences

**Given** I am on the notifications preferences tab  
**When** the preferences load  
**Then** I can toggle email notifications for: login alerts, password changes, suspicious activity  
**And** preferences are saved immediately on toggle  
**And** the backend fires `ADMIN_NOTIFICATION_PREFS_UPDATED`

#### US-P1-003-005 — Admin views and revokes own active sessions

**Given** I am on the active sessions tab  
**When** the sessions list loads  
**Then** I see all active admin sessions with device/IP/last-seen  
**And** I can revoke any session except the current one  
**And** revocation fires `ADMIN_SESSION_REVOKED`

**Backend APIs:** `PATCH /v1/admin/profile`, `POST /v1/admin/auth/change-password`, `GET/DELETE /v1/admin/auth/mfa/devices`, `PATCH /v1/admin/notification-preferences`  
**Existing files to extend:** `src/modules/auth/pages/sessions.tsx`, `src/modules/profile/pages/user-profile.tsx`  
**New files:** `src/modules/profile/pages/security-settings.tsx`, `src/modules/profile/pages/notification-prefs.tsx`, routes at `/profile/security`, `/profile/notifications`

---

### ADM-P1-004 — Device & Session Management

| Field | Value |
|-------|-------|
| **Priority** | P1 |
| **Status** | EXISTS/PARTIAL — device module/API + components exist; missing `app/(admin)/devices/page.tsx` |
| **Stories** | 3 |
| **Depends on** | E03 backend, ADM-FOUND-001 |
| **Risk** | LOW — API exists; only route wiring missing |

**Scope:** Device list (registered admin devices), device trust/revoke, device detail. Session list already complete; device page is the gap.

#### US-P1-004-001 — Admin views registered devices

**Given** I navigate to `/devices`  
**When** the device list loads  
**Then** I see all registered admin devices with: name, platform, last-seen, trust status  
**And** the current device is highlighted  
**And** results paginate and are searchable

#### US-P1-004-002 — Admin revokes a compromised device

**Given** I see a suspicious device in the device list  
**When** I click "Revoke" and confirm  
**Then** the backend revokes all sessions tied to that device  
**And** fires `ADMIN_DEVICE_REVOKED`  
**And** the device status shows "Revoked"

#### US-P1-004-003 — Admin cannot see other admins' devices

**Given** I am an ops_manager  
**When** the device list loads  
**Then** I only see MY own devices  
**And** the API returns only my device records (enforced server-side)

**Backend APIs:** `GET /v1/admin/devices`, `DELETE /v1/admin/devices/{id}`  
**New files:** `app/(admin)/devices/page.tsx` (the missing route)

---

### ADM-P1-005 — Operational Dashboards

| Field | Value |
|-------|-------|
| **Priority** | P1 |
| **Status** | EXISTS/PARTIAL — 3 dashboard routes render with placeholder/stub data |
| **Stories** | 4 |
| **Depends on** | E33 backend dashboard APIs, ADM-FOUND-001 |
| **Risk** | MEDIUM — dashboards show stubs; live data integration needed |

**Scope:** Main ops dashboard (KPI cards, transactions chart, activity feed, quick actions), financial dashboard (revenue KPIs, chart), risk dashboard (alert counts, suspicious activity).

#### US-P1-005-001 — Admin views the main operational dashboard

**Given** I navigate to `/dashboard`  
**When** the page loads  
**Then** I see 4 KPI cards (Total Wallets, Active Users, Today's Transactions, Pending KYC)  
**And** a transactions volume chart with period selector (7/30/90 days)  
**And** a recent activity table (last 10 transactions) with status badges  
**And** a quick actions panel (New User, Review KYC, Float Top-up, View Reports)

**Given** I change the period selector to "Last 30 days"  
**When** the selection changes  
**Then** KPI cards and chart refresh without full page reload  
**And** URL updates with `?period=30d` for shareability

#### US-P1-005-002 — Admin views the financial dashboard

**Given** I have `adminapp.dashboard.financial` permission  
**When** I navigate to `/financial-dashboard`  
**Then** I see revenue KPIs, fee income, commission payouts, transaction value breakdown  
**And** a daily revenue chart for the selected period

**Given** I lack `adminapp.dashboard.financial`  
**When** I navigate to `/financial-dashboard`  
**Then** I am redirected to `/403`

#### US-P1-005-003 — Admin views the risk dashboard

**Given** I have `adminapp.dashboard.risk` permission  
**When** I navigate to `/risk-dashboard`  
**Then** I see: open risk alerts count, suspicious transactions count, AML flags, blocked accounts  
**And** a risk events timeline chart  
**And** the 5 most recent unreviewed alerts in a table

#### US-P1-005-004 — Dashboard shows loading, empty, and error states

**Given** the dashboard API is slow  
**When** the page renders before data resolves  
**Then** KPI cards show skeleton loaders, chart shows a shimmer  
**And** quick actions are not blocked by loading state

**Given** the dashboard API returns an error  
**When** the error boundary catches it  
**Then** I see an inline error message with a retry button  
**And** AdminShell remains intact

**Backend APIs:** `GET /v1/admin-app/dashboard`, `GET /v1/admin-app/financial-dashboard`, `GET /v1/admin-app/risk-dashboard`

---

### ADM-P1-006 — User Management

| Field | Value |
|-------|-------|
| **Priority** | P1 |
| **Status** | EXISTS/PARTIAL — user list + detail routes exist; action APIs (suspend, close, export) need completion |
| **Stories** | 5 |
| **Depends on** | E02, E04 backend, ADM-P1-005 |
| **Risk** | MEDIUM — list/detail functional; mutating actions need idempotency enforcement |

**Scope:** User search (by name/email/phone/ID), detail view (profile, wallets, transactions, KYC, devices), suspend, reactivate, close account, force-logout, export.

#### US-P1-006-001 — Admin searches and filters users

**Given** I navigate to `/admin-portal/users`  
**When** the user list loads  
**Then** I see a paginated table with columns: Name, Email, Phone, KYC Status, Account Status, Created  
**And** I can search by name, email, or phone  
**And** I can filter by: Status (Active/Suspended/Closed), KYC (Verified/Pending/Failed), Created date range

#### US-P1-006-002 — Admin views user detail

**Given** I click on a user in the list  
**When** the detail page loads  
**Then** I see: profile info, account status badge, wallet balances, recent transactions (last 10), KYC status, enrolled devices, active sessions  
**And** each section has a "View all" link to the relevant management page

#### US-P1-006-003 — Admin suspends a user

**Given** I am on a user detail page for an Active user  
**When** I click "Suspend" and enter a reason  
**Then** the backend updates status, fires `USER_SUSPENDED`, records the admin actor + reason in audit  
**And** the user's active sessions are revoked  
**And** the status badge updates to "Suspended"

**Given** I try to suspend an already-suspended user  
**Then** the Suspend button is disabled with tooltip "User is already suspended"

#### US-P1-006-004 — Admin reactivates a suspended user

**Given** a user is in Suspended status  
**When** I click "Reactivate" and confirm  
**Then** the backend updates status, fires `USER_REACTIVATED`, records audit  
**And** the status badge updates to "Active"

#### US-P1-006-005 — Admin exports user list

**Given** I have applied search/filter criteria  
**When** I click "Export CSV"  
**Then** the system queues a report job and notifies me when ready (or triggers immediate download for small sets)  
**And** exported data is limited to my permitted fields (no PII beyond my role scope)

**Backend APIs:** `GET /v1/admin/users`, `GET /v1/admin/users/{id}`, `POST /v1/admin/users/{id}/suspend`, `POST /v1/admin/users/{id}/reactivate`, `POST /v1/admin/users/export`

---

### ADM-P1-007 — KYC Review & Decisions

| Field | Value |
|-------|-------|
| **Priority** | P1 |
| **Status** | EXISTS/PARTIAL — KYC queue and detail routes exist; approve/reject/reverify actions need completion |
| **Stories** | 4 |
| **Depends on** | E05 backend, ADM-P1-006 |
| **Risk** | HIGH — incorrect KYC decisions have financial and regulatory consequences; maker-checker required |

**Scope:** KYC review queue (filterable by status/date/risk), document viewer, approve/reject with reason, request-additional-documents, reverification trigger.

#### US-P1-007-001 — KYC reviewer views the review queue

**Given** I have `adminapp.kyc.review` permission  
**When** I navigate to `/kyc`  
**Then** I see a queue of submissions with: User name, Submission date, KYC type, Risk score, Current status  
**And** I can filter by Status (Pending/Under Review/Escalated), date range, risk score range  
**And** I can sort by submission date or risk score

#### US-P1-007-002 — KYC reviewer inspects a submission

**Given** I click a KYC item in the queue  
**When** the detail page loads  
**Then** I see: identity documents (zoomable, rotateable), selfie/liveness result, data fields, AI pre-screen result, risk indicators  
**And** the document viewer has keyboard navigation and screen-reader alt text

#### US-P1-007-003 — KYC reviewer approves a submission

**Given** I have reviewed a submission and it is valid  
**When** I click "Approve" and confirm  
**Then** the backend marks KYC approved, fires `KYC_APPROVED`, unlocks relevant wallet limits  
**And** the user receives a notification  
**And** the item is removed from the pending queue

**Given** another reviewer has already approved this submission  
**When** I try to approve  
**Then** I see "This submission has already been decided" and no duplicate action is taken (idempotent)

#### US-P1-007-004 — KYC reviewer rejects a submission

**Given** I identify a problem in the submission  
**When** I click "Reject", select a reason code, and confirm  
**Then** the backend rejects with reason, fires `KYC_REJECTED`, triggers a customer notification  
**And** an audit entry records the reviewer, reason code, and timestamp

**Backend APIs:** `GET /v1/admin/kyc/queue`, `GET /v1/admin/kyc/{id}`, `POST /v1/admin/kyc/{id}/approve`, `POST /v1/admin/kyc/{id}/reject`

---

### ADM-P1-008 — Wallet Operations

| Field | Value |
|-------|-------|
| **Priority** | P1 |
| **Status** | EXISTS/PARTIAL — wallet list route exists; freeze/unfreeze/adjustment actions need completion |
| **Stories** | 4 |
| **Depends on** | E06, E07 backend (ledger), ADM-P1-006 |
| **Risk** | CRITICAL — balance adjustments require double-entry ledger posting; no direct mutation |

**Scope:** Wallet list (search by user/wallet ID/status), wallet detail (balances, ledger entries, limits), freeze/unfreeze, administrative adjustment (with ledger posting), close wallet.

#### US-P1-008-001 — Admin searches wallets

**Given** I navigate to `/admin-portal/wallets`  
**When** the list loads  
**Then** I see paginated wallets with: Wallet ID, Owner (linked), Currency, Balance, Status, Created  
**And** I can search by wallet ID or owner email/phone  
**And** I can filter by Status (Active/Frozen/Closed) and currency

#### US-P1-008-002 — Admin views wallet detail and ledger

**Given** I click a wallet  
**When** the detail loads  
**Then** I see: current balance, currency, status, owner link, spending limit, available balance  
**And** a ledger entries table (most recent 20, paginated) with debit/credit/type/reference  
**And** running balance column in the ledger

#### US-P1-008-003 — Admin freezes/unfreezes a wallet

**Given** I am on a wallet detail page for an Active wallet  
**When** I click "Freeze" and enter a reason  
**Then** the backend freezes the wallet, fires `WALLET_FROZEN`, records admin actor + reason in audit  
**And** the status badge updates to "Frozen"  
**And** no further debit/credit is possible until unfrozen

**Given** the wallet is Frozen  
**When** I click "Unfreeze" and confirm  
**Then** the backend unfreezes it, fires `WALLET_UNFROZEN`, records audit

#### US-P1-008-004 — Admin performs a controlled balance adjustment

**Given** I need to credit/debit a wallet for operational reasons  
**When** I click "Adjustment", fill amount + currency + reason + reference  
**Then** the action requires a second admin to approve (maker-checker)  
**And** on approval the backend posts a double-entry journal (no direct balance mutation)  
**And** fires `WALLET_ADJUSTMENT_POSTED`

**Backend APIs:** `GET /v1/admin/wallets`, `GET /v1/admin/wallets/{id}`, `POST /v1/admin/wallets/{id}/freeze`, `POST /v1/admin/wallets/{id}/unfreeze`, `POST /v1/admin/wallets/{id}/adjustment`

---

### ADM-P1-009 — Transaction Management

| Field | Value |
|-------|-------|
| **Priority** | P1 |
| **Status** | EXISTS/PARTIAL — transaction list route exists; reverse/refund/export actions need completion |
| **Stories** | 4 |
| **Depends on** | E11 backend, E07 (ledger), ADM-P1-008 |
| **Risk** | CRITICAL — reversals post compensating ledger entries; idempotency required |

**Scope:** Transaction search (by ID/user/reference/date/type/status), detail view (ledger entries, provider response, timeline), manual reversal initiation, export.

#### US-P1-009-001 — Admin searches transactions

**Given** I navigate to `/admin-portal/transactions`  
**When** the list loads  
**Then** I see a table with: Tx ID, Type (funding/transfer/withdrawal/fee/commission), Amount, Currency, Status, User, Date  
**And** I can search by Tx ID, user, or reference number  
**And** I can filter by: Type, Status, Currency, Date range, Amount range

#### US-P1-009-002 — Admin views transaction detail

**Given** I click a transaction  
**When** the detail loads  
**Then** I see: full metadata, linked wallet IDs, ledger entry table (debits + credits), provider response, timeline, correlation ID  
**And** for failed transactions: failure reason and retry count are shown

#### US-P1-009-003 — Admin reverses a transaction

**Given** I am on a Completed transaction detail  
**When** I click "Reverse" and enter a reason  
**Then** the system requires step-up authentication before proceeding  
**And** on confirmation the backend posts compensating ledger entries, fires `TRANSACTION_REVERSED`  
**And** the original transaction status updates to "Reversed"  
**And** a new linked reversal transaction is created

**Given** the transaction is already reversed  
**Then** the Reverse button is disabled

#### US-P1-009-004 — Admin exports transactions

**Given** I have filtered the transaction list  
**When** I click "Export CSV"  
**Then** for sets ≤5000 rows: immediate download  
**For** larger sets: a report job is queued and I receive an email with the download link

**Backend APIs:** `GET /v1/admin/transactions`, `GET /v1/admin/transactions/{id}`, `POST /v1/admin/transactions/{id}/reverse`, `POST /v1/admin/transactions/export`

---

### ADM-P1-010 — Agent Management

| Field | Value |
|-------|-------|
| **Priority** | P1 |
| **Status** | EXISTS/PARTIAL — agent list + detail routes exist; approval workflow needs completion |
| **Stories** | 4 |
| **Depends on** | E12 backend, ADM-P1-006 |
| **Risk** | MEDIUM — agent suspension impacts float wallets and customer operations |

**Scope:** Agent list (search, filter by status/region), agent detail (profile, KYC, float balance, performance metrics, transaction history), approve/reject onboarding, suspend/reactivate.

#### US-P1-010-001 — Admin views and searches agents

**Given** I have `adminapp.agents.manage` permission  
**When** I navigate to `/agents`  
**Then** I see a table with: Agent Name, Code, Status, Float Balance, Region, Joined  
**And** I can search by name or agent code  
**And** I can filter by Status (Pending/Active/Suspended/Rejected) and region

#### US-P1-010-002 — Admin approves/rejects an agent application

**Given** an agent application is in Pending status  
**When** I click the agent and click "Approve" or "Reject" with reason  
**Then** the backend updates status, fires `AGENT_APPROVED` or `AGENT_REJECTED`, records audit  
**And** the agent receives a notification of the decision

#### US-P1-010-003 — Admin suspends/reactivates an agent

**Given** an agent is Active  
**When** I click "Suspend" and provide a reason  
**Then** the backend suspends the agent, their float wallet is frozen, fires `AGENT_SUSPENDED`  
**And** all pending agent transactions are flagged for review

#### US-P1-010-004 — Admin views agent performance metrics

**Given** I am on an agent detail page  
**When** the metrics section loads  
**Then** I see: transaction count (last 30 days), float utilization %, commission earned, cash-in/out volume  
**And** a 30-day activity chart

**Backend APIs:** `GET /v1/admin/agents`, `GET /v1/admin/agents/{id}`, `POST /v1/admin/agents/{id}/approve`, `POST /v1/admin/agents/{id}/reject`, `POST /v1/admin/agents/{id}/suspend`, `POST /v1/admin/agents/{id}/reactivate`

---

### ADM-P1-011 — Agent Float Management

| Field | Value |
|-------|-------|
| **Priority** | P1 |
| **Status** | EXISTS/PARTIAL — float management route exists; top-up and deduction actions need completion |
| **Stories** | 3 |
| **Depends on** | E13 backend, E07 (ledger), ADM-P1-010 |
| **Risk** | HIGH — float adjustments post to ledger; idempotency and maker-checker required |

**Scope:** Float wallet list (per agent), float top-up, float deduction, float limit configuration, float history.

#### US-P1-011-001 — Admin views agent float balances

**Given** I have `adminapp.float.manage` permission  
**When** I navigate to `/float`  
**Then** I see a table: Agent, Agent Code, Float Currency, Current Float Balance, Float Limit, Last Movement  
**And** I can filter by low-float (balance < 20% of limit)

#### US-P1-011-002 — Admin tops up agent float

**Given** I click "Top Up" on an agent's float  
**When** I enter amount and reason  
**Then** the action requires maker-checker approval  
**And** on approval the backend posts a ledger credit to the float wallet, fires `AGENT_FLOAT_TOPPED_UP`  
**And** the float balance updates immediately

#### US-P1-011-003 — Admin adjusts float limit

**Given** I want to change an agent's float limit  
**When** I edit the limit field and submit  
**Then** the new limit is saved with effective date and fires `AGENT_FLOAT_LIMIT_UPDATED`  
**And** audit records the old value, new value, and admin actor

**Backend APIs:** `GET /v1/admin/agents/float`, `POST /v1/admin/agents/{id}/float/top-up`, `PATCH /v1/admin/agents/{id}/float/limit`

---

### ADM-P1-012 — Role & Permission Management

| Field | Value |
|-------|-------|
| **Priority** | P1 |
| **Status** | EXISTS/PARTIAL — admin portal has user detail with role assignment; dedicated role management page absent |
| **Stories** | 3 |
| **Depends on** | E04 backend, ADM-P1-006 |
| **Risk** | MEDIUM — incorrect role assignment could grant or deny critical capabilities |

**Scope:** Role list (system + custom), role detail (permissions matrix), assign roles to admin users, create/edit custom roles, permission audit.

#### US-P1-012-001 — Super-admin views role catalogue

**Given** I have `adminapp.roles.manage` permission  
**When** I navigate to `/admin-portal/roles` (new route)  
**Then** I see all system roles and custom roles with: Name, Type, User Count, Permissions count  
**And** I can view each role's permission matrix

#### US-P1-012-002 — Super-admin assigns a role to an admin user

**Given** I am on a user detail page  
**When** I click "Edit Roles" and select a new role  
**Then** the backend updates the assignment, fires `ADMIN_ROLE_ASSIGNED`, records audit  
**And** the user's nav and permissions update on their next request

#### US-P1-012-003 — Super-admin creates a custom role

**Given** I click "Create Role"  
**When** I name the role and select permissions from the permission matrix  
**Then** the backend creates the custom role, fires `ADMIN_ROLE_CREATED`  
**And** the role is available for assignment immediately

**Backend APIs:** `GET /v1/admin/roles`, `POST /v1/admin/roles`, `GET /v1/admin/roles/{id}`, `POST /v1/admin/users/{id}/roles`

---

### ADM-P1-013 — Reconciliation & Settlement

| Field | Value |
|-------|-------|
| **Priority** | P1 |
| **Status** | EXISTS/PARTIAL — batch list + detail + settlement list routes exist; approval actions need completion |
| **Stories** | 4 |
| **Depends on** | E20 backend, E07 (ledger), ADM-P1-009 |
| **Risk** | HIGH — settlement approval is a financial action requiring maker-checker |

**Scope:** Reconciliation batch review (matched/unmatched/exceptions), exception resolution, settlement calculation approval, settlement execution, settlement history.

#### US-P1-013-001 — Admin views reconciliation batches

**Given** I navigate to `/reconciliation/batches`  
**When** the list loads  
**Then** I see batches with: Date, Provider, Total Transactions, Matched Count, Unmatched Count, Status  
**And** batches with exceptions are highlighted  
**And** I can filter by Status (Pending/In Review/Approved/Settled) and date

#### US-P1-013-002 — Admin reviews a reconciliation batch

**Given** I click a batch  
**When** the detail loads  
**Then** I see: matched transactions (green), unmatched transactions (red), amounts, discrepancies  
**And** I can mark each exception as: Resolved / Escalated / Write-off  
**And** the batch status updates as all exceptions are resolved

#### US-P1-013-003 — Admin approves settlement

**Given** a reconciliation batch is fully resolved  
**When** I click "Approve Settlement"  
**Then** the action requires a second approver (maker-checker)  
**And** on second approval the backend executes settlement, posts ledger entries, fires `SETTLEMENT_APPROVED`  
**And** settlement run ID and amount are logged in audit

#### US-P1-013-004 — Admin views settlement history

**Given** I navigate to `/reconciliation/settlements`  
**When** the list loads  
**Then** I see all completed settlements with: Date, Provider, Amount, Currency, Reference, Status  
**And** I can download a settlement report for any completed run

**Backend APIs:** `GET /v1/admin/reconciliation/batches`, `GET /v1/admin/reconciliation/batches/{id}`, `POST /v1/admin/reconciliation/batches/{id}/approve`, `GET /v1/admin/settlements`

---

### ADM-P1-014 — Fee Rules Management

| Field | Value |
|-------|-------|
| **Priority** | P1 |
| **Status** | EXISTS/PARTIAL — fee rules list + create routes exist; edit/deactivate/simulation actions needed |
| **Stories** | 3 |
| **Depends on** | E21 backend, ADM-FOUND-001 |
| **Risk** | HIGH — fee rule changes are financial and require versioning + approval |

**Scope:** Fee rule list (filter by type/currency/status), create fee rule, edit fee rule, activate/deactivate, version history, fee simulation.

#### US-P1-014-001 — Admin creates a fee rule

**Given** I navigate to `/fees/rules/create`  
**When** I fill: name, type (flat/percentage/tiered), currency, amount, applicable transaction types, effective date  
**Then** the backend creates the rule in Draft status, fires `FEE_RULE_CREATED`  
**And** the rule does not apply until approved and activated

#### US-P1-014-002 — Admin activates/deactivates a fee rule

**Given** I am on the fee rules list  
**When** I toggle a rule's active state  
**Then** the backend updates the effective state with version bump  
**And** fires `FEE_RULE_ACTIVATED` or `FEE_RULE_DEACTIVATED`  
**And** audit records the admin actor and timestamp

#### US-P1-014-003 — Admin simulates fee impact

**Given** I am on a fee rule detail page  
**When** I click "Simulate" and enter a transaction amount  
**Then** the system calculates the fee that would apply (without posting)  
**And** shows the net amount after fee deduction

**Backend APIs:** `GET /v1/admin/fees/rules`, `POST /v1/admin/fees/rules`, `PATCH /v1/admin/fees/rules/{id}`, `POST /v1/admin/fees/rules/{id}/activate`, `POST /v1/admin/fees/simulate`

---

### ADM-P1-015 — Reports & Analytics

| Field | Value |
|-------|-------|
| **Priority** | P1 |
| **Status** | EXISTS/PARTIAL — report list + schedule + reconciliation/settlement report routes exist; on-demand generation and download need completion |
| **Stories** | 4 |
| **Depends on** | E24 backend, ADM-P1-009, ADM-P1-013 |
| **Risk** | LOW — reporting is read-only; no financial mutations |

**Scope:** Report catalogue (standard reports), on-demand report generation, scheduled report management, report download, reconciliation and settlement reports.

#### US-P1-015-001 — Admin generates an on-demand report

**Given** I navigate to `/reports`  
**When** I click "Generate Report", select type (Transaction/Settlement/User/Fee), date range, filters  
**Then** the backend queues the job and returns a job ID  
**And** I see the report in "In Progress" status  
**And** when complete I am notified and the download link appears

#### US-P1-015-002 — Admin manages scheduled reports

**Given** I navigate to `/reports/schedules`  
**When** the list loads  
**Then** I see all configured report schedules with: name, frequency, next run, last run, recipients  
**And** I can create, edit, pause, or delete schedules

#### US-P1-015-003 — Admin downloads a completed report

**Given** a report has completed  
**When** I click "Download"  
**Then** the file downloads in the configured format (CSV or PDF)  
**And** the download is logged in audit (who downloaded, when)

#### US-P1-015-004 — Admin views the reconciliation report

**Given** I navigate to `/reports/reconciliation`  
**When** I select a date range  
**Then** I see a summary: total transactions, matched %, unmatched count, exception amount  
**And** I can drill into the underlying batch detail

**Backend APIs:** `POST /v1/admin/reports`, `GET /v1/admin/reports/{id}`, `GET /v1/admin/reports/{id}/download`, `GET/POST/PATCH/DELETE /v1/admin/reports/schedules`

---

### ADM-P1-016 — Audit Log & Compliance

| Field | Value |
|-------|-------|
| **Priority** | P1 |
| **Status** | EXISTS/PARTIAL — audit events page + compliance dashboard + retention routes exist; immutable enforcement and full filter set needed |
| **Stories** | 3 |
| **Depends on** | E23 backend, ADM-FOUND-001 |
| **Risk** | MEDIUM — audit log must be immutable; no admin editing of entries |

**Scope:** Audit event viewer (immutable, filterable), compliance status dashboard, data retention policy management, compliance reports.

#### US-P1-016-001 — Admin views the immutable audit log

**Given** I navigate to `/audit`  
**When** the audit log loads  
**Then** I see events in reverse-chronological order: Timestamp, Event Type, Actor, Affected Entity, IP, Correlation ID  
**And** I can filter by: Event Type, Actor, Date range, Entity type  
**And** no edit or delete controls are present on any entry

#### US-P1-016-002 — Admin views compliance dashboard

**Given** I navigate to `/compliance`  
**When** the page loads  
**Then** I see: regulatory checklist status, open compliance items, upcoming retention deadlines  
**And** items are color-coded by urgency

#### US-P1-016-003 — Admin configures data retention policies

**Given** I navigate to `/compliance/retention`  
**When** I update a retention period  
**Then** the change is logged in audit and goes through approval workflow  
**And** the new policy takes effect from the next scheduled cleanup run

**Backend APIs:** `GET /v1/admin/audit/events`, `GET /v1/admin/compliance/status`, `GET/PATCH /v1/admin/compliance/retention-policies`

---

### ADM-P1-017 — System Configuration Management

| Field | Value |
|-------|-------|
| **Priority** | P1 |
| **Status** | EXISTS/PARTIAL — config list + approvals + key detail routes exist; versioning and approval workflow need hardening |
| **Stories** | 3 |
| **Depends on** | E28 backend, ADM-FOUND-001 |
| **Risk** | HIGH — configuration changes affect transaction limits, KYC rules, fraud thresholds, fees — all financial |

**Scope:** Configuration catalogue (domain-grouped), config key edit (with change-preview), approval workflow for critical configs, version history, config import/export.

#### US-P1-017-001 — Admin edits a configuration value

**Given** I navigate to `/configuration/{domain}/{key}`  
**When** I edit the value and submit  
**Then** for critical configs: a change request is created in "Pending Approval" status  
**For** non-critical configs: saved immediately with audit entry  
**And** I see a diff preview before confirming

#### US-P1-017-002 — Admin approves a pending config change

**Given** a config change is pending  
**When** a second admin (not the submitter) navigates to `/configuration/approvals` and approves  
**Then** the backend applies the new value, fires `CONFIG_UPDATED`, records both actors in audit  
**And** the effective date is recorded

#### US-P1-017-003 — Admin views configuration version history

**Given** I am on a config key detail page  
**When** I expand the version history section  
**Then** I see all previous values with: changed-by, changed-at, approved-by, effective-from  
**And** I can view the value at any past point (read-only)

**Backend APIs:** `GET /v1/admin/config`, `GET/PATCH /v1/admin/config/{domain}/{key}`, `GET/POST /v1/admin/config/approvals`

---

### ADM-P1-018 — Fraud, Risk & AML Dashboard

| Field | Value |
|-------|-------|
| **Priority** | P1 |
| **Status** | COMPLETE for US-P1-018-001 through 004 — verified 2026-10-15; see [implementation evidence](../specs/042-fraud-risk-aml-dashboard/implementation-status.md) |
| **Stories** | 4 |
| **Depends on** | E18 backend, ADM-P1-009 |
| **Risk** | HIGH — incorrect risk decisions can block legitimate transactions or allow fraud |

**Scope:** Risk alert queue (filterable), alert investigation workflow (assign/resolve/escalate), AML flag management, risk rule configuration, suspicious account controls.

#### US-P1-018-001 — Admin views the risk alert queue

**Given** I have `security.alerts.read` permission  
**When** I navigate to `/security/dashboard`  
**Then** I see a table of open risk alerts: Severity, Type, Entity (user/wallet), Created, Assigned To  
**And** I can filter by Severity (Critical/High/Medium/Low) and Status (Open/In Review/Resolved)

#### US-P1-018-002 — Admin investigates a risk alert

**Given** I click an alert  
**When** the investigation view opens  
**Then** I see: alert trigger details, affected transactions, entity history, risk score timeline  
**And** I can: assign to myself, add investigation notes, link related alerts  
**And** I can resolve (false positive/confirmed fraud) or escalate

#### US-P1-018-003 — Admin freezes an account for investigation

**Given** I confirm a transaction is suspicious  
**When** I click "Freeze Account" from the alert investigation page  
**Then** the backend freezes the account, fires `ACCOUNT_FROZEN_AML`, records audit  
**And** the user receives a notification (if configured)

#### US-P1-018-004 — Admin reviews security findings

**Given** I have `security.findings.read` permission  
**When** I navigate to `/security/findings`  
**Then** I see vulnerability findings with: CVE ID (if applicable), Severity, Component, Status, Discovered  
**And** I can mark findings as Acknowledged, In Remediation, or Resolved

**Backend APIs:** `GET /v1/admin/security/alerts`, `GET /v1/admin/security/alerts/{id}`, `POST /v1/admin/security/alerts/{id}/resolve`, `GET /v1/admin/security/findings`

---

### ADM-P1-019 — Notification Management

| Field | Value |
|-------|-------|
| **Priority** | P1 |
| **Status** | COMPLETE — three mandatory stories verified; see specs/043-notification-management/implementation-status.md |
| **Stories** | 3 |
| **Depends on** | E17 backend, ADM-FOUND-001 |
| **Risk** | MEDIUM — notifications are configurable per spec; admin must be able to manage templates and rules |

**Scope:** Notification template list/edit, notification delivery log, notification rules/triggers management, test notification send.

#### US-P1-019-001 — Admin views and edits notification templates

**Given** I navigate to `/notifications/templates` (new route)  
**When** the template list loads  
**Then** I see templates grouped by: Event type, Channel (email/SMS/push), Language  
**And** I can click any template to edit the subject and body  
**And** preview renders variable substitution with sample data

#### US-P1-019-002 — Admin views notification delivery log

**Given** I navigate to `/notifications/log` (new route)  
**When** the log loads  
**Then** I see recent notifications: Recipient, Channel, Event, Status (Sent/Failed/Bounced), Timestamp  
**And** I can filter by channel, status, and date range  
**And** I can retry failed notifications

#### US-P1-019-003 — Admin configures notification rules

**Given** I am on the notification rules page  
**When** I toggle a notification rule (e.g., "Email on suspicious login")  
**Then** the backend saves the rule state and fires `NOTIFICATION_RULE_UPDATED`  
**And** the rule takes effect immediately for new events

**Backend APIs:** `GET/PATCH /v1/admin/notifications/templates`, `GET /v1/admin/notifications/log`, `POST /v1/admin/notifications/retry/{id}`, `GET/PATCH /v1/admin/notifications/rules`  
**New files:** `app/(admin)/notifications/page.tsx`, `app/(admin)/notifications/templates/page.tsx`, `app/(admin)/notifications/log/page.tsx`, `src/modules/notifications/`

---

### ADM-P1-020 — Integration & API Platform Management

| Field | Value |
|-------|-------|
| **Priority** | P1 |
| **Status** | COMPLETE — all three stories verified; see specs/044-integration-api-platform-management/implementation-status.md |
| **Stories** | 3 |
| **Depends on** | E25 backend, ADM-FOUND-001 |
| **Risk** | MEDIUM — API key management is security-sensitive; proper revocation required |

**Scope:** Integration partner list, API client management (create/revoke keys), webhook delivery log, webhook retry, provider health status.

#### US-P1-020-001 — Admin manages API client credentials

**Given** I navigate to `/integrations/providers`  
**When** I click "New API Client" for a partner  
**Then** I enter name, permissions, IP whitelist  
**And** on creation the client secret is shown once (never stored in plaintext again)  
**And** I can rotate or revoke credentials at any time

#### US-P1-020-002 — Admin monitors webhook deliveries

**Given** I navigate to `/integrations/webhooks`  
**When** the webhook log loads  
**Then** I see: Event, Endpoint, Status (Delivered/Failed/Retrying), Attempts, Last Attempt  
**And** I can filter by status and event type  
**And** I can manually trigger a retry for failed webhooks

#### US-P1-020-003 — Admin views provider health status

**Given** I navigate to `/integrations`  
**When** the integration list loads  
**Then** each integration shows a health indicator: Healthy/Degraded/Down with last-checked timestamp  
**And** clicking "View Health" shows a 24-hour uptime chart and recent error log

**Backend APIs:** `GET/POST /v1/admin/integrations/api-clients`, `POST /v1/admin/integrations/api-clients/{id}/rotate`, `DELETE /v1/admin/integrations/api-clients/{id}`, `GET /v1/admin/webhooks/deliveries`, `POST /v1/admin/webhooks/deliveries/{id}/retry`

---

### ADM-P1-021 — Support & Dispute Management

| Field | Value |
|-------|-------|
| **Priority** | P1 |
| **Status** | COMPLETE — [implementation evidence](../specs/045-support-dispute-management/implementation-status.md) |
| **Stories** | 4 |
| **Depends on** | E22 backend, ADM-P1-009 |
| **Risk** | HIGH — unresolved disputes may require financial refunds/reversals |

**Scope:** Support case queue (filterable by status/priority), case detail (customer info, related transactions, timeline), case assignment, resolution workflow, dispute escalation, refund initiation.

#### US-P1-021-001 — Admin views the support case queue

**Given** I navigate to `/support/cases` (new route)  
**When** the case queue loads  
**Then** I see: Case ID, Customer, Type (Support/Dispute), Priority, Status, Created, Assigned  
**And** I can filter by Type, Status (Open/In Progress/Escalated/Resolved), Priority, date  
**And** overdue cases are highlighted

#### US-P1-021-002 — Admin investigates a support case

**Given** I click a case  
**When** the case detail loads  
**Then** I see: customer profile link, case description, related transaction links, evidence files, conversation timeline  
**And** I can add internal notes, attach files, and link to related cases

#### US-P1-021-003 — Admin resolves a dispute with refund

**Given** I confirm a valid dispute  
**When** I click "Initiate Refund", enter amount and reason  
**Then** the system requires step-up authentication  
**And** on confirmation the backend creates a reversal transaction with ledger posting  
**And** fires `DISPUTE_RESOLVED_WITH_REFUND`  
**And** the customer is notified

#### US-P1-021-004 — Admin escalates a case

**Given** a case requires senior review  
**When** I click "Escalate" and select the escalation reason  
**Then** the case status updates to "Escalated"  
**And** the assigned senior agent is notified  
**And** a 24-hour SLA timer starts

**Backend APIs:** `GET /v1/admin/support/cases`, `GET /v1/admin/support/cases/{id}`, `PATCH /v1/admin/support/cases/{id}`, `POST /v1/admin/support/cases/{id}/resolve`, `POST /v1/admin/support/cases/{id}/refund`  
**New files:** `app/(admin)/support/cases/page.tsx`, `app/(admin)/support/cases/[id]/page.tsx`, `src/modules/support/`

---

### ADM-P1-022 — Multi-Currency & FX Management

| Field | Value |
|-------|-------|
| **Priority** | P1 |
| **Status** | PARTIAL — currency approvals, manual FX and history implemented; live provider and broad E29 regression pending. [Evidence](../specs/046-multi-currency-fx-management/implementation-status.md) |
| **Stories** | 3 |
| **Depends on** | E29 backend, ADM-P1-014 (fee rules for FX fees) |
| **Risk** | MEDIUM — FX rate errors could cause financial mispricing |

**Scope:** Currency configuration (enable/disable currencies), exchange rate management (manual + provider rates), FX spread configuration, rate history.

#### US-P1-022-001 — Admin manages supported currencies

**Given** I navigate to `/multi-currency/currencies`  
**When** the list loads  
**Then** I see all supported currencies with: Code, Name, Status (Active/Inactive), Precision, Base  
**And** I can enable/disable a currency (with approval workflow for financial impact)

#### US-P1-022-002 — Admin manages exchange rates

**Given** I navigate to `/multi-currency/fx-rates`  
**When** the rate table loads  
**Then** I see current rates for all active currency pairs with: Rate, Spread %, Provider, Last Updated  
**And** I can manually override a rate (which locks it to manual mode)  
**And** I can revert to provider-sourced rates

#### US-P1-022-003 — Admin views rate history

**Given** I click a currency pair  
**When** the history view opens  
**Then** I see a rate chart (last 30 days) with manual overrides annotated  
**And** a table of all historical rates with source (manual/provider) and timestamp

**Backend APIs:** `GET/PATCH /v1/admin/currencies`, `GET/POST /v1/admin/fx/rates`, `GET /v1/admin/fx/rates/{pair}/history`

---

### ADM-P1-023 — Security & Vulnerability Management

| Field | Value |
|-------|-------|
| **Priority** | P1 |
| **Status** | EXISTS/PARTIAL — security dashboard + findings routes exist; remediation workflow and hardening config needed |
| **Stories** | 3 |
| **Depends on** | E26 backend, ADM-P1-018 |
| **Risk** | HIGH — security misconfiguration has direct exposure to external threats |

**Scope:** Security findings with remediation workflow, security config (password policy, session policy, IP allowlists), security events log.

#### US-P1-023-001 — Admin configures platform security policies

**Given** I navigate to `/platform/security-config`  
**When** the page loads  
**Then** I see: Password policy (min length, complexity, expiry), Session policy (idle timeout, max sessions), MFA policy (required/optional by role), IP allowlist  
**And** changes go through approval workflow  
**And** each setting shows its current value and last-modified-by

#### US-P1-023-002 — Admin tracks security findings through remediation

**Given** I have `security.findings.read` permission and click a finding  
**When** the finding detail opens  
**Then** I see: CVSS score, affected component, remediation steps, timeline  
**And** I can update status: Open → In Remediation → Resolved → Verified  
**And** each status change is logged in audit

#### US-P1-023-003 — Admin views the security events log

**Given** I navigate to `/security/events` (new route, or tab on security/dashboard)  
**When** the event log loads  
**Then** I see security-classified audit events: failed logins, privilege escalations, config changes, API key rotations  
**And** I can filter by severity and event type  
**And** I can export the event log for SIEM ingestion

**Backend APIs:** `GET/PATCH /v1/admin/platform/security-config`, `GET/PATCH /v1/admin/security/findings/{id}`, `GET /v1/admin/security/events`

---

### ADM-P2-001 — HTTP Error & System Status Pages

| Field | Value |
|-------|-------|
| **Priority** | P2 |
| **Status** | MISSING — 7 standard error/status pages absent |
| **Stories** | 3 |
| **Depends on** | ADM-FOUND-001 (ErrorPage component) |
| **Risk** | LOW — functional impact is UX only; no financial risk |

**Scope:** 401 Unauthorized, 429 Too Many Requests, 503 Service Unavailable, 504 Gateway Timeout, Maintenance mode page, Offline page, Unsupported browser page.

#### US-P2-001-001 — Admin sees friendly HTTP error pages

**Given** the server returns a 429, 503, or 504 response  
**When** the page renders  
**Then** I see an informative error page with: error code, human-readable message, suggested action  
**And** the AdminShell shell is preserved for 429/503 within the authenticated context  
**And** the root layout is used for 503/504 when the shell is unavailable

#### US-P2-001-002 — Admin sees maintenance mode page

**Given** a `MAINTENANCE_MODE=true` environment flag is set  
**When** I visit any page  
**Then** middleware intercepts and serves the `/maintenance` page with estimated return time  
**And** a superadmin bypass token allows admins to skip maintenance mode

#### US-P2-001-003 — Admin sees unsupported browser page

**Given** I open the admin app in an unsupported browser (IE11, old Edge)  
**When** the root layout detects an incompatible user agent  
**Then** I see an unsupported-browser page with: supported browser list and download links  
**And** no JavaScript errors or blank screens are shown

**New files:** `app/401/page.tsx`, `app/429/page.tsx`, `app/503/page.tsx`, `app/504/page.tsx`, `app/maintenance/page.tsx`, `app/offline/page.tsx`, `app/unsupported-browser/page.tsx`

---

### ADM-P2-002 — Operations Center & Background Jobs

| Field | Value |
|-------|-------|
| **Priority** | P2 |
| **Status** | EXISTS/PARTIAL — dead-letter route exists; queue monitoring + job management need completion |
| **Stories** | 3 |
| **Depends on** | E27 backend, E30 (DevOps), ADM-FOUND-001 |
| **Risk** | MEDIUM — dead letter queue failures can indicate financial processing failures |

**Scope:** Dead letter queue (view, retry, discard), queue health monitoring, background job status dashboard.

#### US-P2-002-001 — Admin manages the dead letter queue

**Given** I navigate to `/operations/dead-letter`  
**When** the queue loads  
**Then** I see failed messages with: Queue name, Message ID, Error, Failure count, Last attempt  
**And** I can retry individual messages or bulk-retry by queue  
**And** I can view the message payload for debugging  
**And** discarding a message requires confirmation

#### US-P2-002-002 — Admin monitors queue health

**Given** I navigate to `/ops/queue-monitoring`  
**When** the page loads  
**Then** I see: Queue name, Depth, Processing rate (msg/sec), Average latency, Failed count  
**And** queues above a warning threshold are highlighted  
**And** data refreshes every 30 seconds

#### US-P2-002-003 — Admin views background job status

**Given** I navigate to `/ops` (ops center)  
**When** the jobs section loads  
**Then** I see active, pending, and recently completed jobs with: Job type, Status, Progress, Started, Duration  
**And** I can cancel a running job (with confirmation)

**Backend APIs:** `GET /v1/admin/operations/dead-letter`, `POST /v1/admin/operations/dead-letter/{id}/retry`, `GET /v1/admin/operations/queues`, `GET /v1/admin/operations/jobs`

---

### ADM-P2-003 — DevOps & Observability UI

| Field | Value |
|-------|-------|
| **Priority** | P2 |
| **Status** | EXISTS/PARTIAL — 10 ops sub-routes exist as stubs under /ops/*; live data integration needed |
| **Stories** | 3 |
| **Depends on** | E30 backend, ADM-P2-002 |
| **Risk** | LOW — observability is informational; no financial mutations |

**Scope:** Application metrics (requests/sec, error rate, latency), structured log viewer (search, filter, tail), deployment history, alert rules management.

#### US-P2-003-001 — Admin views application metrics

**Given** I navigate to `/ops/metrics`  
**When** the metrics page loads  
**Then** I see: request rate, error rate, P50/P95/P99 latency, active users — for the last 1h/24h/7d  
**And** each metric has a chart and a current-value card

#### US-P2-003-002 — Admin searches application logs

**Given** I navigate to `/ops/logs`  
**When** I enter a search term and time range  
**Then** I see matching log lines with: timestamp, level, service, message  
**And** I can filter by log level (ERROR/WARN/INFO/DEBUG)  
**And** I can click any log entry to see full context

#### US-P2-003-003 — Admin manages alert rules

**Given** I navigate to `/ops/alert-rules`  
**When** the alert rules list loads  
**Then** I see rules with: name, condition, threshold, notification channel, enabled state  
**And** I can create, edit, enable/disable alert rules

**Backend APIs:** `GET /v1/admin/ops/metrics`, `GET /v1/admin/ops/logs`, `GET/POST/PATCH/DELETE /v1/admin/ops/alert-rules`

---

### ADM-P2-004 — QA & Test Management Dashboard

| Field | Value |
|-------|-------|
| **Priority** | P2 |
| **Status** | EXISTS/PARTIAL — QA dashboard + run detail routes exist; test execution trigger needs completion |
| **Stories** | 2 |
| **Depends on** | E34 backend QA, ADM-FOUND-001 |
| **Risk** | LOW — read-only for most users; test execution is superadmin-only |

**Scope:** Test suite list, test run history, live run progress, run detail (pass/fail per scenario), manual test trigger.

#### US-P2-004-001 — Admin views QA test run history

**Given** I navigate to `/qa`  
**When** the dashboard loads  
**Then** I see: recent test runs with pass rate, total/passed/failed/skipped counts, triggered-by, duration  
**And** I can filter by suite, status, and date  
**And** pass-rate trends chart shows the last 30 runs

#### US-P2-004-002 — Admin triggers a manual test run

**Given** I have `qa.execute` permission  
**When** I click "Run Tests" and select a suite  
**Then** the backend queues the test run  
**And** I can watch progress in real time (WebSocket or polling)  
**And** on completion I receive a notification with the pass/fail summary

**Backend APIs:** `GET /v1/admin/qa/runs`, `POST /v1/admin/qa/runs`, `GET /v1/admin/qa/runs/{id}`

---

### ADM-P2-005 — Admin App Test Coverage

| Field | Value |
|-------|-------|
| **Priority** | P2 |
| **Status** | PARTIAL — 16 test files exist; no integration tests, no E2E tests |
| **Stories** | 3 |
| **Depends on** | All P1 epics (tests written alongside implementation) |
| **Risk** | MEDIUM — insufficient coverage increases regression risk for financial flows |

**Scope:** Unit/component tests for all P1 epic UI flows, integration tests (API mock layer), E2E smoke tests (login → dashboard → key action).

#### US-P2-005-001 — All P1 page components have unit test coverage

**Given** all P1 epic features are implemented  
**When** `npx jest --coverage` runs  
**Then** code coverage is ≥80% for all source files under `src/modules/`  
**And** every user action (click, form submit, error state) has at least one test

#### US-P2-005-002 — Critical financial flows have integration tests

**Given** the mock API layer (MSW) is configured  
**When** integration tests for wallet adjustment, transaction reversal, settlement approval run  
**Then** all tests pass  
**And** maker-checker flows are tested end-to-end with two simulated admin sessions

#### US-P2-005-003 — Smoke E2E tests cover golden path

**Given** Playwright or Cypress is configured  
**When** the E2E smoke suite runs  
**Then** login → dashboard, login → view user list, login → view KYC queue, login → view wallet all pass  
**And** session expiry redirect works correctly

---

### ADM-P2-006 — Accessibility, Performance & Cross-Theme Polish

| Field | Value |
|-------|-------|
| **Priority** | P2 |
| **Status** | PARTIAL — E37 addresses token/theme issues; WCAG audit and performance optimization remain |
| **Stories** | 3 |
| **Depends on** | ADM-FOUND-001 (E37), all P1 epics |
| **Risk** | LOW — polish-level work; no functional regressions expected |

**Scope:** WCAG 2.1 AA compliance across all admin pages, keyboard navigation, screen reader testing, initial page load optimization, Core Web Vitals.

#### US-P2-006-001 — All admin pages pass WCAG 2.1 AA axe-core checks

**Given** jest-axe runs on all page components  
**When** the accessibility test suite runs  
**Then** zero axe-core violations at WCAG 2.1 AA level are reported  
**And** all interactive elements are keyboard reachable  
**And** focus order is logical on all forms

#### US-P2-006-002 — Admin app meets Core Web Vitals targets

**Given** the production build is deployed  
**When** Lighthouse runs on `/dashboard` and `/admin-portal/users`  
**Then** LCP ≤ 2.5s, FID ≤ 100ms, CLS ≤ 0.1  
**And** Next.js bundle analysis shows no single chunk > 250KB

#### US-P2-006-003 — Admin app renders correctly in supported browsers

**Given** the app is accessed in Chrome 110+, Firefox 110+, Edge 110+, Safari 16+  
**When** core flows (login, dashboard, user list) are opened  
**Then** no visual regressions are observed  
**And** the light indigo admin theme renders consistently across all browsers

---

## Backend Dependency Register

For each epic, the following backend APIs must exist before frontend implementation can connect to real data.

| Epic ID | Required Backend APIs | Source Epic | Status |
|---------|----------------------|-------------|--------|
| ADM-P1-001 | forgot-password, reset-password, verify-email | E01 | CHECK |
| ADM-P1-002 | MFA enroll TOTP/FIDO2, recovery codes | E01, E03 | CHECK |
| ADM-P1-003 | change-password, MFA devices CRUD, notification prefs | E01, E02, E17 | CHECK |
| ADM-P1-004 | admin devices GET/DELETE | E03 | CHECK |
| ADM-P1-005 | dashboard GET endpoints (3) | E33 | CHECK |
| ADM-P1-006 | users GET/list, suspend, reactivate, export | E02, E19 | CHECK |
| ADM-P1-007 | KYC queue, approve, reject | E05, E19 | CHECK |
| ADM-P1-008 | wallets GET/list, freeze, unfreeze, adjustment | E06, E07, E19 | CHECK |
| ADM-P1-009 | transactions GET/list, reverse, export | E11, E07, E19 | CHECK |
| ADM-P1-010 | agents GET/list, approve, reject, suspend | E12, E19 | CHECK |
| ADM-P1-011 | float GET/list, top-up, limit PATCH | E13, E19 | CHECK |
| ADM-P1-012 | roles GET/POST, role assignment POST | E04, E19 | CHECK |
| ADM-P1-013 | reconciliation batches, settlement approve | E20, E19 | CHECK |
| ADM-P1-014 | fee rules CRUD, simulate | E21, E19 | CHECK |
| ADM-P1-015 | reports POST/GET, schedules CRUD | E24, E19 | CHECK |
| ADM-P1-016 | audit events GET, compliance status, retention PATCH | E23, E19 | CHECK |
| ADM-P1-017 | config GET/PATCH, approvals | E28, E19 | CHECK |
| ADM-P1-018 | security alerts GET, resolve, findings GET | E18, E19 | CHECK |
| ADM-P1-019 | notification templates, log, rules | E17, E19 | MISSING |
| ADM-P1-020 | API clients CRUD, webhook deliveries | E25, E19 | CHECK |
| ADM-P1-021 | support cases CRUD, resolve, refund | E22, E19 | CHECK |
| ADM-P1-022 | currencies GET/PATCH, FX rates GET/POST | E29, E19 | CHECK |
| ADM-P1-023 | security config GET/PATCH, findings PATCH, events GET | E26, E19 | CHECK |
| ADM-P2-001 | none (UI only) | — | N/A |
| ADM-P2-002 | dead-letter, queues, jobs | E27, E30 | CHECK |
| ADM-P2-003 | metrics, logs, alert-rules | E30, E19 | CHECK |
| ADM-P2-004 | QA runs GET/POST | E34, E19 | CHECK |

> **CHECK** = backend E-series epic is COMPLETE/RELEASE READY per memory.  
> **MISSING** = E17 notification admin endpoints need verification before ADM-P1-019 can connect.

---

## Dependency Matrix

```
ADM-FOUND-001
    └── ADM-P1-001 (auth recovery)
        └── ADM-P1-002 (MFA enrollment)
            └── ADM-P1-003 (profile/settings)
                └── ADM-P1-004 (device management)

ADM-FOUND-001
    └── ADM-P1-005 (dashboards)
        └── ADM-P1-006 (user management)
            ├── ADM-P1-007 (KYC)
            ├── ADM-P1-008 (wallets)
            │   └── ADM-P1-009 (transactions)
            ├── ADM-P1-010 (agents)
            │   └── ADM-P1-011 (float)
            └── ADM-P1-012 (roles)

ADM-P1-009 (transactions)
    ├── ADM-P1-013 (reconciliation)
    ├── ADM-P1-015 (reports)
    └── ADM-P1-021 (support/disputes)

ADM-FOUND-001
    ├── ADM-P1-014 (fee rules)
    ├── ADM-P1-016 (audit)
    ├── ADM-P1-017 (configuration)
    ├── ADM-P1-018 (fraud/risk/AML)
    ├── ADM-P1-019 (notifications)
    ├── ADM-P1-020 (integrations)
    ├── ADM-P1-022 (multi-currency)
    └── ADM-P1-023 (security hardening)

ALL P1 Epics
    ├── ADM-P2-001 (error pages)
    ├── ADM-P2-002 (ops center)
    ├── ADM-P2-003 (devops UI)
    ├── ADM-P2-004 (QA dashboard)
    ├── ADM-P2-005 (test coverage)
    └── ADM-P2-006 (a11y & perf)
```

---

## Execution Roadmap

### Sprint 0 — Foundation Stabilisation (ADM-FOUND-001 + E37)
**Duration:** 2 weeks (in parallel with backlog planning)  
**Scope:** Complete E37 tasks T001–T067 (layout convergence, token fixes, nav expansion, dashboard layout)  
**Gate:** All 139 existing tests pass; `next build` clean; `/dashboard` matches wireframe

### Sprint 1 — Auth Completeness (Highest Risk Gap)
**Epics:** ADM-P1-001 (password recovery), ADM-P1-002 (MFA enrollment)  
**Duration:** 1 week  
**Why first:** Admin lockout risk is P1 blocker; no other backlog depends on these routes

### Sprint 2 — Profile & Identity Management
**Epics:** ADM-P1-003 (profile/settings), ADM-P1-004 (devices)  
**Duration:** 1 week  
**Deliverable:** Complete self-service security settings + device management

### Sprint 3 — Core Operations: Users + KYC + Wallets
**Epics:** ADM-P1-006, ADM-P1-007, ADM-P1-008  
**Duration:** 2 weeks  
**Dependency:** E02/E05/E06 backend APIs  
**Deliverable:** Fully functional user/KYC/wallet management with real data

### Sprint 4 — Transactions + Agents + Float
**Epics:** ADM-P1-009, ADM-P1-010, ADM-P1-011  
**Duration:** 2 weeks  
**Deliverable:** Full transaction management with reversal; agent lifecycle; float top-up

### Sprint 5 — Financial Operations
**Epics:** ADM-P1-013 (reconciliation), ADM-P1-014 (fees), ADM-P1-022 (multi-currency)  
**Duration:** 2 weeks  
**Note:** All require maker-checker for financial actions

### Sprint 6 — Dashboards + Live Data
**Epics:** ADM-P1-005 (dashboards with live data)  
**Duration:** 1 week  
**Deliverable:** All 3 dashboards connected to real API data with period selectors

### Sprint 7 — Governance & Configuration
**Epics:** ADM-P1-012 (roles), ADM-P1-016 (audit), ADM-P1-017 (config), ADM-P1-015 (reports)  
**Duration:** 2 weeks  
**Deliverable:** Complete governance tier

### Sprint 8 — Security, Risk & Integrations
**Epics:** ADM-P1-018 (fraud/risk), ADM-P1-020 (integrations), ADM-P1-023 (security)  
**Duration:** 2 weeks

### Sprint 9 — Missing Modules
**Epics:** ADM-P1-019 (notifications), ADM-P1-021 (support/disputes)  
**Duration:** 2 weeks  
**Note:** ADM-P1-019 blocks on E17 admin endpoint verification

### Sprint 10 — P2 Infrastructure
**Epics:** ADM-P2-001 (error pages), ADM-P2-002 (ops center), ADM-P2-003 (devops UI), ADM-P2-004 (QA)  
**Duration:** 2 weeks

### Sprint 11 — Quality Gate
**Epics:** ADM-P2-005 (test coverage), ADM-P2-006 (a11y + perf)  
**Duration:** 2 weeks  
**Gate:** ≥80% coverage; zero axe-core violations; Core Web Vitals pass

---

## Summary

| Category | Count | Status |
|----------|-------|--------|
| Foundation epics | 1 | EXISTS (E37 in progress) |
| P1 epics | 23 | 2 MISSING, 1 PARTIAL (device page), 20 EXISTS/PARTIAL (stub→real data) |
| P2 epics | 6 | 1 MISSING (error pages), 3 PARTIAL, 2 stub |
| **Total epics** | **30** | |
| Total user stories | **95** | |
| Missing new routes | 12 | forgot-pwd, reset-pwd, verify-email, account-locked, account-disabled, mfa-enrollment, mfa-recovery, notifications templates/log, support cases/detail, security/events |
| Backend APIs all available | 28/29 | E17 notification admin endpoints need verification |

---

**STOP — Phase A complete. Approval required before any implementation begins.**

To proceed: review this catalogue, approve the epic order / sprint plan, then run `/speckit-specify` for the first implementation epic (recommended: ADM-P1-001 — Password Recovery Flow).
