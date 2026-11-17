# OleKard user guide coverage and verification record

## Reviewed baseline

| Item | Value |
|---|---|
| Review date | 4 November 2026 |
| Branch / HEAD | `main` / `5a9d4058e6e52a4aea9cd70dcb3f2f7faa738a48` |
| Admin application | `wallet-ecosystem/apps/admin`, package version `0.1.0` (Next.js web application) |
| Customer application | `wallet-ecosystem/apps/customer`, Flutter version `1.0.0+1`, Android/iOS project targets |
| Agent application | `wallet-ecosystem/apps/agent`, Flutter version `1.0.0+1`, Android/iOS project targets |
| Guides | Each is document version 1.0, reviewed against the above checkout. |
| Brand source | `docs/design-system/design-system.md`; OleKard navy/gold with Admin teal, Customer green and Agent blue accents. No approved logo artwork was found, so the guides use the text wordmark. |

The manuals are user-facing and describe only present UI flows. Internal source paths and this evidence map are kept here for maintainers. A development/demo feature is not represented as production readiness.

## Walkthrough and screenshot evidence

The isolated P26 browser walkthrough ran through the guarded runner on 4 November 2026. The successful run used a fresh isolated PostgreSQL database named `wallet_ecosystem_p26_e2e_20261104_ba330206`, synthetic identities, event, publication/approval state and operator assignment. It did not use production data or activate a production gate. Persisted summary at completion was `3 registrations | 3 tickets | 1 attendance | 1 attendance audit | 3 free-issuance audits | 5 invitation audits | 0 payment intents | 0 payment attempts | 0 refunds | 0 Ledger journals | 0 payout records | 0 settlement batches`.

The run exercised Admin sign-in/MFA, invitation issue and revocation, Customer login/PIN, free registration, purchaser ticket access and cross-customer denial, Agent login, online manual credential validation, admission, and aggregate attendance. The screenshots are web captures of the running Admin and Flutter-web apps. The native Android Customer and Agent journey has separate evidence in the P26 evidence records, but the screenshots here are not native screenshots. Ticket credential pixels were redacted. No PIN, MFA code, password or admission secret is included in the guides.

| Application | Screen/flow | Walkthrough evidence | Screenshot file | Availability in guide |
|---|---|---|---|---|
| Admin | Sign-in | Isolated browser login | `assets/admin-login.png` | Captured, synthetic account |
| Admin | MFA challenge | Isolated browser MFA path | `assets/admin-mfa.png` | Captured; code not shown |
| Admin | Dashboard | Isolated browser workspace | `assets/admin-dashboard.png` | Captured, synthetic data |
| Admin | Event authoring/review | Isolated P26 event workspace | `assets/admin-event-workspace.png` | Captured; explicit draft/review-only warning shown |
| Admin | Invitation issue | Synthetic invitation flow | `assets/admin-invitation-form.png` | Captured; synthetic identifiers |
| Admin | Invitation revoked | Revocation result | `assets/admin-invitation-revoked.png` | Captured |
| Admin | Aggregate attendance | Journey end-state | `assets/admin-aggregate-attendance.png` | Captured; aggregate-only |
| Customer | Login/PIN | Normal challenge/PIN UI flow | `assets/customer-login.png`, `assets/customer-pin-prompt.png` | Captured; secrets absent |
| Customer | Home | Isolated app home | `assets/customer-home.png` | Captured |
| Customer | Free registration | Successful synthetic free registration | `assets/customer-free-registration.png` | Captured; test-only event/approval |
| Customer | My Event Tickets | Purchaser ticket list | `assets/customer-my-tickets.png` | Captured; credential area visibly redacted |
| Customer | Cross-customer access | Second customer sees no tickets | `assets/customer-no-other-tickets.png` | Captured; access denial verified |
| Agent | Login/PIN | Normal login challenge | `assets/agent-login.png`, `assets/agent-pin-prompt.png` | Captured; synthetic account |
| Agent | Event Gate Admission | Assigned event/gate manual entry | `assets/agent-event-gate.png` | Captured; manual entry only, no credential visible |
| Agent | Admission confirmed | Online successful result | `assets/agent-admission-confirmed.png` | Captured; synthetic assignment |

**Screenshot count:** 17 actual application screenshots. The guides contain 5 labeled visual-verification placeholders for areas not seeded in the isolated walkthrough; placeholders are explicitly not represented as app screenshots.

## Feature-to-guide traceability

Availability labels: **Walked through** = normal UI path executed in the isolated development run; **Implemented, not walked through** = source UI exists, but no relevant synthetic case was exercised for this guide; **Gated** = code/route exists but live capability depends on authority, approved configuration or activation; **Unavailable/API-only** = no user-facing UI entry point was found or the function is not exposed in the reviewed client.

| Application / feature | Guide section | Source/evidence location | Walkthrough / screenshot | Availability | Outstanding clarification or visual gap |
|---|---|---|---|---|---|
| Admin authentication, MFA, account recovery | Admin §§2, 12 | `apps/admin/src/modules/auth/pages/`; `apps/admin/__tests__/auth/` | Browser sign-in/MFA; `admin-login.png`, `admin-mfa.png` | Walked through for login/MFA; recovery not exercised | Recovery provider/configuration and MFA enrollment vary by deployment. |
| Admin dashboard and workspace navigation | Admin §§3, 12 | `apps/admin/src/lib/navigation/nav-items.ts`; `src/modules/dashboard/pages/` | Browser workspace; `admin-dashboard.png` | Walked through | Dashboard metrics depend on data and permissions. |
| User, Agent, devices, sessions, role and permission operations | Admin §4 | Admin nav items; `src/modules/users`, `src/modules/agents`, auth/session pages | Not exercised with identity fixtures; placeholder Fig. 4 | Implemented, not walked through; permission-gated | Owner should visually confirm screen fields and authorised action variants. |
| KYC review | Admin §4 | Admin nav `KYC Reviews`; KYC module pages | Not exercised; placeholder Fig. 4 | Implemented, not walked through; reviewer-gated | Evidence and retention policy must be confirmed by the KYC owner. |
| Wallet, transactions, refunds, payout, reconciliation, settlement, fee/provider configuration | Admin §5 | Admin nav `Payment Finance`/`Operations`; payment module pages | No financial fixture/action; placeholder Fig. 5 | Gated / not verified in walkthrough | No real payment or provider action was run; production terms/providers/authority remain required. |
| Event draft, versioning, review | Admin §6 | `apps/admin/src/modules/community/pages/event-authoring.tsx` | Browser P26 event workspace; `admin-event-workspace.png` | Walked through in development; publication/sales gated | Fixture state is not approval for production publication. |
| Complimentary approver assignment/allocation approval | Admin §7 | Event authoring page; P26 acceptance/evidence | Browser event workspace; invitations exercised | Development/demo only pending Governance authority/assignments | Actual authorised approver records and evidence provider remain required. |
| Invitation issue/revocation | Admin §7 | Event authoring page; P26 E2E evidence | `admin-invitation-form.png`, `admin-invitation-revoked.png` | Walked through with synthetic data | Production approval and private token delivery channel remain external. |
| Gate assignment | Admin §8 | Events assignment/service contract; no dedicated Admin menu screen found | Synthetic assignment used in E2E | Assignment process, not a documented Admin UI flow | Owner must provide authorised assignment procedure if operators need UI instructions. |
| Aggregate attendance | Admin §8 | Event authoring/report section and P26 evidence | `admin-aggregate-attendance.png` | Walked through; aggregate-only | No attendee-level report is exposed in this path. No-show finalisation follows event/reporting rules. |
| Event cancellation request/review | Admin §9 | Event authoring page and cancellation lifecycle contract | Screen code inspected; no live cancellation executed for guide | Implemented, permission-gated; paid recovery gated | Paid refunds/recovery and notification owner configuration remain separate. |
| Notification, audit, delivery log, configuration | Admin §10 | Admin navigation and module page tree | Not exercised against a delivery provider | Implemented, permission-gated | Queue/dispatch status does not prove delivery; provider-backed states vary. |
| OleKard financial terms authoring/acceptance | Admin §11 | Platform terms API and P26 T028 records | No UI screen found | API-only in current scope | No Admin/organiser UI entry point; actual approved terms and publisher authority are outstanding. |
| Customer registration/OTP/PIN login | Customer §§2, 12 | `apps/customer/lib/core/router/app_router.dart`; `modules/auth/presentation/screens/` | Browser challenge/PIN; `customer-login.png`, `customer-pin-prompt.png` | Walked through in Flutter web; native evidence separate | No screenshot from a native device in this deliverable. |
| Customer biometrics/session restore | Customer §2 | `modules/auth/presentation/auth_providers.dart` | Not separately exercised | Implemented/device dependent | Login screen did not show a dedicated biometric button; device-specific behavior needs visual verification. |
| Customer Home, Wallet, Activity, Account, Profile, Security | Customer §§3–4, 9 | Customer bottom-nav/router; `modules/home`, `wallet`, `activity`, `profile` | Home only; `customer-home.png` | Implemented; partial walkthrough | Wallet/profile/security screens not visually captured in this run. |
| Customer funding, send, withdrawal and service purchase | Customer §§4–5 | Customer `payment_routes.dart`; `modules/funding`, `transfer`, `withdrawal`, `services` | No real financial action; placeholder Fig. 4 | Implemented/gated by account/provider; not walked through | Provider config, fees, country and transaction limits vary; visual verification pending. |
| Customer service history, notifications, support and disputes | Customer §§5, 8–9 | Customer router; `modules/services`, `notifications`, `support` | Not exercised | Implemented, not walked through | Support channel, provider state and regional availability need owner confirmation. |
| Customer free event registration | Customer §6 | `modules/events/presentation/screens/free_event_registration_screen.dart` | Browser registration; `customer-free-registration.png` | Development/demo only in tested journey | Production publication/approval gate remains closed. |
| Customer My Event Tickets and purchaser scope | Customer §7 | `modules/events/presentation/screens/customer_event_tickets_screen.dart`; P26 E2E | `customer-my-tickets.png`, `customer-no-other-tickets.png` | Walked through; ticket pixels redacted | Credential replacement/step-up path was inspected but not executed. |
| Customer event discovery/paid checkout | Customer availability | Router and module inventory | No UI route found | Unavailable in reviewed UI; live sales disabled | No event catalogue or paid checkout procedure can be documented. |
| Customer KYC | Customer availability | Customer profile/status code and router | No submission path identified | Status may be visible; no customer submission UI found | KYC submission ownership/route requires product confirmation if intended. |
| Customer event cancellation/refund | Customer availability | Ticket status UI and shared financial contracts | No customer cancellation/refund action exercised or found | Status/integration limited; no customer refund control found | Financial policy and Payments outcome remain external. |
| Agent login/PIN, biometric fallback | Agent §§2, 8 | `apps/agent/lib/modules/auth/presentation/screens/login_screen.dart` | Browser login/PIN; `agent-login.png`, `agent-pin-prompt.png` | Walked through PIN; biometric is device dependent | Browser captures cannot establish native biometric prompts. |
| Agent dashboard, float and commissions | Agent §3 | `modules/dashboard/presentation/screens/dashboard_screen.dart`; float/commission modules | Not exercised; placeholder Fig. 3 | Implemented/configuration-dependent | Synthetic data and permissioned screens need visual verification. |
| Agent customer onboarding/KYC | Agent §4 | `modules/customer_onboarding/presentation/screens/` | Not exercised; placeholder Fig. 4 | Implemented after assignment; not walked through | KYC evidence was not seeded; owner should visually confirm the exact flow. |
| Agent cash-in/cash-out | Agent §5 | `modules/cash_in/presentation/screens/`; `modules/cash_out/presentation/screens/` | Not exercised | Gated by assignment, float and provider; not verified | No financial operation was performed for documentation. |
| Agent Event Gate manual online validation | Agent §6 | `modules/events/presentation/screens/event_gate_scan_screen.dart` | Browser manual flow; `agent-event-gate.png`, `agent-admission-confirmed.png`; native journey evidence separate | Walked through manually; synthetic assignment | Manual credential path only in screenshots. Exact assignment required. |
| Agent camera scanning | Agent §6 | Event gate scanner component (`MobileScanner`) | Not exercised | Implemented path; visual verification pending | Camera capture itself is not verified by browser/manual entry evidence. |
| Agent repeat scan, wrong-gate and offline/unknown behavior | Agent §6, 9 | Event admission service contract and P26 E2E evidence | Browser journey covers wrong scope/repeat; native journey evidence in P26 records | Walked through within documented synthetic scope | Offline intentionally denies; no disconnected scan is permitted. |
| Agent transaction/activity/notifications/account | Agent §§7–8 | Agent nav/router and module page tree | Not exercised in this walkthrough | Implemented/configuration-dependent | Customer/Agent synthetic account data not seeded beyond event login. |

## Walkthrough result and boundaries

The successful runner command was `powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\specs\1002-free-invitation-complimentary-events\run-p26-browser-e2e.ps1`. It completed 2 Playwright tests (focused Customer login and the P26 browser journey), 3 CORS tests with 52 assertions, and 10 Identity MFA tests with 70 assertions; Customer and Agent Flutter web builds completed. The journey used actual UI interactions for login, invitation issue/revocation, free registration, purchaser ticket access, cross-customer denial, assigned-gate manual admission and aggregate reporting; persisted state assertions confirmed three registrations/tickets, one attendance and zero Payment intents/attempts, refunds, Ledger journals, payouts or settlement batches. Test-only publication, approval, identity and operator assignments were fixtures. These are not production approvals.

The existing P26 records also document a native Android Customer/Agent journey within the stated scope, including manual credential entry; that does not verify camera capture. Admin finance, identity/KYC, wallet money movement, customer financial flows, Agent cash flows and most configuration screens were not exercised for this guide. Their procedures are based on the inspected UI/source and are labeled accordingly.

## Visual and content gaps requiring owner follow-up

1. Supply an approved OleKard logo asset if the client requires a graphical logo; the current guides use a text wordmark and the documented color system.
2. Replace `[OleKard owner: insert approved support address and service hours]` with the customer-facing support channel and hours.
3. Provide authorised non-production fixtures and walkthrough access for Admin identity/KYC, finance, notification delivery, customer wallet/services and Agent onboarding/cash operations if the client needs those screens visually verified.
4. Confirm the production organiser verification, complimentary approval authority, financial terms/provider configuration and release gates before publishing any live availability statement.
5. Confirm whether the customer-facing KYC and event refund/cancellation screens are intended to be available; no such submission/control path was found in the reviewed Customer UI.
6. Agent camera scanning requires a supported device/camera walkthrough; this guide documents the app’s camera path but marks visual verification pending.

## Output and rendering QA

Deliverables are stored in `docs/user-guides/`. DOCX table-of-contents and page fields were updated by Microsoft Word before PDF export. Word was launched as a separate hidden automation instance and closed after export. The bundled `render_docx.py` renderer could not run in this Windows environment because LibreOffice (`soffice.exe`) is not installed; its initial invocation also reported missing `pdf2image`. As a fallback, Word exported all three PDFs, which were rasterized page-by-page with PyMuPDF to temporary QA images under `tmp/guide-page-review/`. Contact sheets and individual pages were inspected. Each PDF contains 13 (Admin), 12 (Customer) and 10 (Agent) pages. Page numbering, table of contents, numbered callouts, tables, placeholders and screenshots were checked. Temporary render images are not deliverables.

Source and screenshot paths are relative to the repository root. The screenshot count above reflects the 17 files present under `docs/user-guides/assets/`.
