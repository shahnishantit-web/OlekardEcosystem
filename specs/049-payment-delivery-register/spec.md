# Feature Specification: P00 — Shared Payment Architecture Delivery Register

**Feature Branch**: `049-payment-delivery-register`

**Created**: 2026-10-21

**Status**: PARTIAL — source documents are available and verified; repository implementation, predecessor evidence and owner approvals remain incomplete across work packages

**2026-10-22 P10 update**: the historical source-document gap above is superseded
for P10. Architecture v1.1 was found and hash/version verified at
`C:/Users/BAPS/Downloads/OleKard_Shared_Payment_Architecture.docx`; complete text is
retained in `docs/sources/`. This closes availability only, not predecessor
readiness or approvals. See [P10 evidence](../059-e25-payment-callback-ingress/evidence.md).

**Work Package**: P00 (document-local ID per prompt pack)

**Authority**: Constitution Principles 1–15; ADR-E48-001; ADR-E48-002; OKI-ARCH-2026-003

**Source Documents**:
| Document | Location | Status |
|----------|----------|--------|
| `OleKard_Claude_SpecKit_Managed_Prompts.docx` v1.0 (2026-10-20) | `C:\Projects\OleKardEcosystem\OleKard_Claude_SpecKit_Managed_Prompts.docx` | ✅ FOUND — read 2026-10-21 |
| `OleKard_Shared_Payment_Architecture.docx` v1.1 (2026-10-19) | `docs/sources/OleKard_Shared_Payment_Architecture_v1.1.docx` | ✅ FOUND — SHA-256 `B5D07562EE2497C726AABEFE18782ABFFC094A39CED8D0CF3E16AA0F2CFF7837`; version/title verified |

> **Note on source documents**: The prompt pack defines P00–P33 scope, predecessors and role owners. The architecture document v1.1 is now present and version/hash verified; historical AWDOC entries below must be reclassified per the affected package. Source availability does not supply missing owner approvals, provider contracts or implementation evidence.

**Boundary**: Assess only. No implementation, schema migration, or provider execution in this work package.

---

## 1. Purpose

This document is the authoritative **P00 Repository Assessment and Delivery Register** for the OleKard Shared Payment Architecture. It provides:

1. **Current-state inspection**: evidence of what is built, tested, and released.
2. **Gap register**: classification of every P-ID scope requirement as `IMPL`, `PART`, `MISS`, `EXTB`, `AWDEC`, or `AWDOC`, with file and test evidence.
3. **Managed backlog**: 34 work packages (P00–P33) with titles, predecessors, and role owners exactly as defined in the prompt pack.
4. **Execution register**: status, dependencies, source references, acceptance evidence, risks, blockers, next actions, and restart context per package.

This register separates three independent completion axes: **code completion**, **provider certification**, and **production readiness**.

---

## 2. Scope

### In scope for P00 (this assessment)

- Inspect constitution, Spec Kit workflow, completed specifications, backend, admin, Flutter, Wallet, Ledger, and E25.
- Inventory all direct wallet/payment callers and existing tests.
- Produce the gap register and dependency map (Sections 4–5).
- Produce the managed backlog P00–P33 (Section 6) using prompt pack definitions exactly.
- Produce the execution register (Section 7).

### Not in scope for P00

- Writing new code, migrations, or API endpoints.
- Provider certification or bank onboarding.
- Production deployment authorisation.

---

## 3. Architecture Constraints (Non-Negotiable)

| ID | Constraint | Source |
|----|-----------|--------|
| C01 | One shared `App\Modules\Payment` module. No business module owns a PaymentIntent. | ADR-E48-001 |
| C02 | Provider adapters isolated in `Infrastructure/Adapters/`. Orchestration never calls provider HTTP directly. | ADR-E48-001 |
| C03 | `WalletPaymentAdapter` delegates exclusively to `WalletModuleContract` and `LedgerModuleContract`. Never mutates wallet balance directly. | ADR-E48-002 |
| C04 | Integer minor units only. No float arithmetic. | Constitution P4 |
| C05 | All state-changing payment operations require `Idempotency-Key`. | Constitution P4, P8 |
| C06 | Live bank adapters are BLOCKED until real bank documentation, API credentials, and security review are provided. MockBankPaymentAdapter must be production-blocked. | Prompt pack P12–P14 boundary |
| C07 | No second ledger. All financial postings via existing `LedgerModuleContract`. | Constitution P4 |
| C08 | E25 Integration Platform owns common external ingress/connector infrastructure. Payment module is a consumer, not owner, of E25 infrastructure. | E25 spec, prompt pack P10 |
| C09 | Money and tenant context must be derived server-side. | Constitution P1, P6 |
| C10 | No duplicate charges, ledger postings, or domain entitlements under any retry or concurrency scenario. | Constitution P4 |
| C11 | Incoming payment capability does not imply payout support. Do not fabricate bank payout APIs. | Prompt pack P18 boundary |
| C12 | No mock-backed UI testing counts as provider certification. | Prompt pack dependency register note |

---

## 4. Repository Inspection Findings

### 4.1 Governance Artifacts

| Artifact | Found | Path | Notes |
|----------|-------|------|-------|
| Constitution | ✅ | `.specify/memory/constitution.md` | v1.0.0, 15 principles |
| AGENTS.md | ❌ | — | Not found at project root or repo root |
| Spec Kit workflow | ✅ | `.specify/` | v1.1.3.dev0, sequential numbering |
| Prompt pack v1.0 | ✅ | `C:\Projects\OleKardEcosystem\OleKard_Claude_SpecKit_Managed_Prompts.docx` | Defines P00–P33 scopes |
| Architecture doc v1.1 | ❌ | — | Governing requirement baseline; not in repository |
| ADR-E48-001 | ✅ | `docs/architecture/decisions/ADR-E48-001-payment-module-ownership.md` | Status: Accepted |
| ADR-E48-002 | ✅ | `docs/architecture/decisions/ADR-E48-002-wallet-payment-adapter-delegation.md` | Status: Accepted |

### 4.2 Backend — Payment Module (`App\Modules\Payment`)

**Module path**: `wallet-ecosystem/services/api/app/Modules/Payment/`
**File count**: 88 PHP files | **E48 status**: COMPLETE/RELEASE READY (2026-10-18)

| Component | Files | Status |
|-----------|-------|--------|
| Domain contracts | `Domain/Contracts/*.php` (6) | ✅ |
| Domain enums | `Domain/Enums/*.php` (6) | ✅ |
| Domain exceptions | `Domain/Exceptions/*.php` (10) | ✅ |
| State machines | `Domain/StateMachines/*.php` (2) | ✅ |
| Value objects | `Domain/ValueObjects/*.php` (10) | ✅ |
| Domain events | `Domain/Events/*.php` (5) | ✅ |
| Application services | `Application/Services/*.php` (7) | ✅ |
| Background jobs | `Application/Jobs/*.php` (3) | ✅ |
| Adapters | `Infrastructure/Adapters/*.php` (5) | ✅ |
| DB migrations | `Infrastructure/Migrations/*.php` (8) | ✅ |
| Eloquent models | `Infrastructure/Models/*.php` (8) | ✅ |
| HTTP controllers | `Http/Controllers/*.php` (7) | ✅ |
| OpenAPI | `openapi/v1/payment.yaml` | ✅ |
| Feature tests | `tests/Feature/Modules/Payment/*.php` (5) | ✅ (Docker-gated) |
| Unit tests | `tests/Unit/Modules/Payment/**/*.php` (4) | ✅ (Docker-gated) |

**Assessment of E48 implementation against prompt pack P01–P11 scope**:

| P-ID | Prompt pack title | Assessment | Key gap |
|------|-----------------|-----------|---------|
| P01 | Architecture contracts and accounting decisions | PART | Accounting decision register (custody, fee bearers, merchant account ownership) not separately documented |
| P02 | Payment schema and API contracts | PART | Quote/fee snapshot schema; rollback strategy not documented |
| P03 | Tenant isolation and payment permissions | PART | Maker-checker for sensitive permission changes not verified |
| P04 | Purpose validation quotes and fee snapshots | PART | Immutable quote versioning; stale-quote revalidation before execution |
| P05 | Provider registry and option eligibility | PART | Eligibility filter chain (org/module/purpose/country/currency/amount) |
| P06 | Intent attempt and payment state engine | PART | UNKNOWN state on timeout; unsafe provider-switch block |
| P07 | Durable idempotency concurrency and recovery | PART | Crash-window tests; Redis loss recovery (Docker-gated) |
| P08 | Ledger acknowledgement and fulfilment handoff | PART | Capture-survives-ledger-outage test; accounting separation documented |
| P09 | OleKard Wallet payment adapter | PART | Reservation/capture semantics documented; crash-after-debit recovery |
| P10 | E25 adapter framework and callback ingress | PART | E25 connector reuse boundary not documented; replay/quarantine validation |
| P11 | Outbox inbox notifications and domain delivery | PART | Transactional outbox atomicity; inbox consumer deduplication |

### 4.3 Backend — Refund / Reversal / Payout Gap

| P-ID | Title | Status | Gap |
|------|-------|--------|-----|
| P15 | Shared refund engine and original method return | PART | `RefundService.php` exists; full FSM and approval/submit path need verification |
| P16 | Reversals disputes and chargebacks | MISS | No `Reversal`, `Dispute`, or `Chargeback` model or service in repository |
| P17 | Reconciliation clearing and provider settlement | PART | `ReconciliationExceptionService` + E20 module exist; statement ingress + settlement matching not verified |
| P18 | Beneficiary verification and payout lifecycle | MISS | No payout module; `BankProviderDocumentationGapModel` acknowledges gap |

### 4.4 Admin UI

| P-ID | Status | Evidence | Gap |
|------|--------|---------|-----|
| P20 | PART | 5 provider admin pages in `app/(admin)/payment/` | Draft/version-history/approval workflow not verified |
| P21 | MISS | — | No payment finance dashboard in admin |

### 4.5 Flutter

| P-ID | Status | Evidence | Gap |
|------|--------|---------|-----|
| P22 | PART | `payment_checkout_sheet.dart`, `payment_notifier.dart` | Recovery after app restart; return-route allowlist |
| P23 | PART/MISS | `payment_status_screen.dart` | History list, refund request, support-from-transaction screens missing |

### 4.6 Community Domain (P24–P28) — ALL BLOCKED

All five community domain packages are BLOCKED. Community module removed 2026-10-20. P18 (payout) also MISSING — a prerequisite for all five.

### 4.7 E25 Integration Platform

E25 module (`App\Modules\Integration`) ✅ — `IntegrationRegistryInterface`, `ProviderHealthInventory`, 15 PHP files confirmed. E25↔Payment webhook/callback boundary documentation: MISS.

### 4.8 Direct Wallet/Payment Callers

All pre-E48 modules (E08–E16, E13–E14) call `WalletModuleContract` directly — compliant, as they pre-date the shared payment module and own their domain. `App\Modules\Payment` delegates to `WalletModuleContract` via `WalletPaymentAdapter` — ✅ compliant per ADR-E48-002.

---

## 5. Gap Register

Classification: `IMPL` = Implemented | `PART` = Partial | `MISS` = Missing | `EXTB` = Externally Blocked | `AWDEC` = Awaiting Decision | `AWDOC` = Awaiting Document

### 5.1 Platform / Orchestration

| Req | Requirement | Class | Evidence / Gap |
|-----|-------------|-------|----------------|
| R01 | One shared `App\Modules\Payment` module | IMPL | 88 PHP files; ADR-E48-001 |
| R02 | `payment.*` schema (8 tables) with exact-money types | IMPL | 8 migrations |
| R03 | API contracts with idempotency, pagination, typed next actions | PART | `payment.yaml` written; quote/snapshot response schema unverified |
| R04 | Intent/attempt FSMs with UNKNOWN state on timeout | PART | FSMs exist; UNKNOWN→timeout path needs Docker validation |
| R05 | Idempotency: Redis acceleration + PostgreSQL authoritative (Hybrid Option C) | PART | Tests written; crash-window scenarios Docker-gated |
| R06 | Capture survives ledger outage; repair without recharge | PART | Architecture asserted; not Docker-validated |
| R07 | Block unsafe provider switch while prior attempt may have charged | PART | Partial in FSM; needs explicit test |
| R08 | Outbox atomicity: commit event with domain state | PART | Events emitted; transactional atomicity not verified |
| R09 | Inbox consumer deduplication with downstream domain effect | PART | `PaymentCallbackProcessingJob` exists; deduplication completeness unverified |

### 5.2 Provider Adapters

| Req | Requirement | Class | Evidence / Gap |
|-----|-------------|-------|----------------|
| R10 | `AbstractBankPaymentAdapter` framework | IMPL | `AbstractBankPaymentAdapter.php` |
| R11 | `MockBankPaymentAdapter` dev-only + production-blocked | IMPL | `ProductionBlockedException` guard |
| R12 | Bank 1 live adapter | EXTB | No bank docs or credentials |
| R13 | Bank 2 live adapter | EXTB | Same |
| R14 | Bank 3 live adapter | EXTB | Same |
| R15 | Raw-body callback verification + trusted account mapping | PART | `BankCallbackVerifier.php` exists; completeness needs Docker |
| R16 | E25 connector lifecycle reuse documented | MISS | Boundary between E25 and Payment callback ingress not written |

### 5.3 Refund / Reversal / Dispute / Payout

| Req | Requirement | Class | Evidence / Gap |
|-----|-------------|-------|----------------|
| R17 | Refund FSM: request→approval→submit→pending/unknown→confirmed | PART | `RefundService.php`, `RefundLimitExceededException`; FSM completeness unverified |
| R18 | Concurrent partial refunds cannot over-refund | PART | Guard exists; concurrent test needed |
| R19 | Reversal / dispute / chargeback case management | MISS | No entity or service |
| R20 | Correcting journals for dispute outcomes | MISS | Requires P16 |
| R21 | Provider statement ingress with duplicate-source controls | MISS | Only manual `ReconciliationExceptionService`; no automated ingress |
| R22 | Settlement bank matching (capture clearing ≠ bank settlement) | MISS | E20 handles general recon; payment-specific not implemented |
| R23 | Payout lifecycle with beneficiary verification | MISS | No payout module |

### 5.4 Admin UI

| Req | Requirement | Class | Evidence / Gap |
|-----|-------------|-------|----------------|
| R24 | Provider CRUD with capabilities, accounts, credentials | PART | CRUD pages present; draft/approve/version-history workflow unverified |
| R25 | Maker-checker for sensitive provider changes | PART | Architecture asserts this; not verified |
| R26 | Admin finance dashboard: payment timeline, refund approval, reconciliation | MISS | No dashboard page |
| R27 | Admin filterable payment reports with currency separation | MISS | Depends on P21 |

### 5.5 Flutter

| Req | Requirement | Class | Evidence / Gap |
|-----|-------------|-------|----------------|
| R28 | Server-driven checkout with bank + Wallet options, fees, currency | PART | `payment_checkout_sheet.dart` done; recovery after restart/network loss |
| R29 | Allowlisted return destinations; block unsafe provider replacement | PART | Partial; not separately tested |
| R30 | Payment history list screen | MISS | Not in customer app |
| R31 | Refund request screen | MISS | Not in customer app |
| R32 | Support-from-transaction flow | MISS | Not in customer app |
| R33 | Account switching clears private payment cache | PART | Architecture asserted; not tested |

### 5.6 Community Domain

| Req | Requirement | Class | Blocker |
|-----|-------------|-------|---------|
| R34 | Funeral Assistance payment journey (LC06) | BLOCKED | Community removed; P18 MISSING |
| R35 | Paid Events ticketing + admission (LC07) | PARTIAL | Draft Events foundation implemented; payment/admission/payout flows remain blocked; P18 execution/settlement MISSING |
| R36 | Free invitation + complimentary events (LC08) | PARTIAL | Development zero-price allocation, registration, online admission and aggregate reporting implemented under P25 reuse. Production recipient verification/complimentary authority, free-only publication, cancellation notices, offline policy and independent review remain blocked; see specs/1002-free-invitation-complimentary-events/. |
| R37 | Raffle entry draw and prize (LC09) | BLOCKED | Community removed; jurisdiction approval open |
| R38 | Donation fundraising + beneficiary (LC10) | BLOCKED | Community removed; P18 MISSING |

### 5.7 Source Document Requirements

| Req | Requirement | Class | Blocker |
|-----|-------------|-------|---------|
| R39 | FD01–FD11 Functional Design requirements | PART | Source available; verify each package mapping and remaining owner decisions |
| R40 | AD01–AD12 Admin screen requirements | PART | Source available; route/access implementation and approval evidence remain package-specific |
| R41 | MB01–MB08 Mobile requirements | PART | Source available; package implementation and runtime verification remain open |
| R42 | LC01–LC16 Lifecycle flow requirements | PART | Source available; owner-approved policy and service evidence remain package-specific |
| R43 | Architecture doc Sections 1, 2, 9, 10, 11 | PART | Source available; architecture/finance sign-offs and implementation traceability remain open |
| R44 | AGENTS.md repository-wide instructions | AWDOC | Not created yet |

---

## 6. Managed Backlog — Work Packages P00–P33

> Titles, predecessors, and role owners reproduced verbatim from `OleKard_Claude_SpecKit_Managed_Prompts.docx` v1.0 (2026-10-20).

| P-ID | Title | Predecessors | Owner Role | Status |
|------|-------|-------------|-----------|--------|
| P00 | Repository assessment and delivery register | None | Technical lead | IN_PROGRESS |
| P01 | Architecture contracts and accounting decisions | P00 | Architect and finance reviewer | PARTIAL |
| P02 | Payment schema and API contracts | P01 | Backend engineer | PARTIAL |
| P03 | Tenant isolation and payment permissions | P02 | Security and backend engineers | PARTIAL |
| P04 | Purpose validation quotes and fee snapshots | P02 P03 | Backend and domain engineers | PARTIAL |
| P05 | Provider registry and option eligibility | P02 P03 P04 | Backend integration engineer | PARTIAL |
| P06 | Intent attempt and payment state engine | P02 P03 P04 P05 | Backend engineer | PARTIAL |
| P07 | Durable idempotency concurrency and recovery | P06 | Backend reliability engineer | PARTIAL |
| P08 | Ledger acknowledgement and fulfilment handoff | P01 P06 P07 | Ledger and backend engineers | PARTIAL |
| P09 | OleKard Wallet payment adapter | P06 P07 P08 | Wallet and backend engineers | PARTIAL |
| P10 | E25 adapter framework and callback ingress | P05 P06 P07 | Integration engineer | PARTIAL |
| P11 | Outbox inbox notifications and domain delivery | P08 P10 | Backend messaging engineer | PARTIAL |
| P12 | Bank 1 adapter and certification | P10 P11 | Provider integration engineer | EXTERNALLY_BLOCKED |
| P13 | Bank 2 adapter and certification | P10 P11 | Provider integration engineer | EXTERNALLY_BLOCKED |
| P14 | Bank 3 adapter and certification | P10 P11 | Provider integration engineer | EXTERNALLY_BLOCKED |
| P15 | Shared refund engine and original method return | P08 P09 P10 P11 | Finance and backend engineers | PARTIAL |
| P16 | Reversals disputes and chargebacks | P15 | Finance and risk engineers | MISSING |
| P17 | Reconciliation clearing and provider settlement | P08 P10 P15 P16 | Finance systems engineer | PARTIAL |
| P18 | Beneficiary verification and payout lifecycle | P03 P08 P15 P16 P17 | Finance and payout engineers | PARTIAL/BLOCKED |
| P19 | Receipts communication and payment history APIs | P08 P11 P15 | Backend and communications engineers | PARTIAL |
| P20 | Admin provider configuration and policy screens | P03 P05 P10 | Next.js admin engineer | PARTIAL |
| P21 | Admin finance dashboard and operations screens | P17 P18 P19 P20 | Next.js admin and finance engineers | MISSING |
| P22 | Shared Flutter checkout and recovery | P06 P09 P10 P19 | Flutter engineer | PARTIAL |
| P23 | Flutter receipts history refunds and support | P19 P22 | Flutter engineer | PARTIAL/MISSING |
| P24 | Funeral Assistance complete shared payment journey | P18 P19 P22 P23 | Community domain engineers | PARTIAL/BLOCKED |

| P25 | Paid Events ticketing and admission journey | P18 P19 P22 P23 | Events domain engineers | PARTIAL (5 checklist gates open) |

P25 clarification update (2026-10-27): user-selected post-publication edit handling requires explicit authorised sales resumption after an approved version is published; existing orders retain purchase-time terms; capacity reductions below sold tickets plus active holds are rejected; and every proposed post-publication edit pauses sales. These selections do not constitute owner approval. P25 remains PARTIAL/BLOCKED; its financial/admission flows and checklist gates remain unresolved. See `specs/072-paid-events-ticketing/{spec.md,research.md,tasks.md,evidence.md,restart.md}`.

P25 clarification update (2026-10-27): user selected reopening refunded ticket capacity only after refund confirmation plus credential revocation, while sales remain open and before event start; approved organiser/sponsor public display; hiding proposed drafts during review; and retaining visible event pages after sellout/sales close with checkout disabled. Privacy, refund, permission and publication approval evidence remains pending; P25 is still PARTIAL/BLOCKED. See `specs/072-paid-events-ticketing/`.

P25 clarification update (2026-10-27): user selected an explicit event time zone for start/sales deadlines and customer display; rejection of invalid/ambiguous daylight-saving times; continued ticket validity after approved rescheduling; and sales closing no later than event start. Time, display, schedule-update and notification contracts and owner approvals remain pending; P25 remains PARTIAL/BLOCKED.

P25 clarification update (2026-10-27): user selected an approved event-level maximum order quantity across ticket categories; denial of refunds after confirmed admission except with separate authorised Finance review; left general refund eligibility for unused tickets pending; and selected automatic checkout restoration after expired holds free inventory only when the event is approved/on sale and no review/operator pause applies. Limit, refund and state-transition contracts/approvals remain pending; P25 remains PARTIAL/BLOCKED.

P25 clarification update (2026-10-27): category and aggregate event capacities both apply; allocations are firm, cannot undercut sold tickets plus active holds, and unallocated capacity remains unavailable until authorised assignment. One currency applies per event; ticket price and confirmed Payments fees are displayed separately with total before confirmation. Capacity/allocation approvals, currency and fee quote contracts remain pending; P25 remains PARTIAL/BLOCKED.

P25 clarification update (2026-10-27): authorised Finance/platform administrator assigns one supported event currency, locked after first order/active hold; paid-ticket publication/sales are blocked without assignment and no default is used. Fee payer and tax treatment remain pending Finance/Legal decisions. Currency, permission, fee/tax and Payments quote evidence remain open; P25 stays PARTIAL/BLOCKED.

P25 clarification update (2026-10-27): organiser proposes positive ticket prices; an independent event reviewer approves publication and a separately authorised Finance reviewer approves prices. P25 excludes complimentary tickets. Sales are disabled without an enabled shared Payments method for the assigned event currency. Permission, availability and quote contracts/approvals remain pending; fee responsibility and taxes remain undecided.

P25 clarification update (2026-10-27): customer must reconfirm if payable total changes; quote/payment start expires with inventory hold; authenticated active customer account is required before hold/checkout. Cumulative per-customer limit and mandatory customer acknowledgement of event policies remain pending Legal/Product approval; P25 remains PARTIAL/BLOCKED.

P25 clarification update (2026-10-27): issue/reschedule/cancellation notices use shared Notifications/preferences; scannable credentials remain in authenticated accounts and are excluded from external notices. Cancelled event pages remain visible with sales disabled; published cancellation requires separate authorised review before status/notices. Notification/security/cancellation authority contracts remain open; refunds remain unresolved.

P25 clarification update (2026-10-27): scanner exposes only validation status/category, fails closed when online validation is unavailable, requires online exit records for re-entry, and counts only returns against the re-entry limit. Cancelled-event reactivation remains pending approved policy. Privacy, availability, scan and lifecycle evidence remain outstanding.

| P26 | Free invitation and complimentary event journey | P25 | Events domain engineers | PARTIAL - development registration/admission/reporting verified; operational authority, cancellation/offline policy and independent review pending |
| P27 | Permitted raffle entry draw and prize journey | P18 P19 P22 P23 | Raffle domain engineers | BLOCKED |
| P28 | Donation fundraising and beneficiary journey | P18 P19 P22 P23 | Fundraising domain engineers | BLOCKED |
| P29 | Scoped reporting support and closure controls | P21 P23 P24 P25 P26 P27 P28 | Reporting and support engineers | BLOCKED |
| P30 | Operations observability incident and recovery | P10 P11 P17 P18 P21 | Operations and reliability engineers | PARTIAL |
| P31 | Security privacy and financial resilience verification | P29 P30 | Security and QA engineers | NOT_STARTED |
| P32 | Wallet caller migration and controlled rollout | P24 P25 P26 P27 P28 P29 P30 P31 | Technical lead and release engineer | NOT_STARTED |
| P33 | Final convergence lifecycle evidence and release report | P00–P32 (with conditional deferrals) | Delivery manager and QA lead | NOT_STARTED |

P25 continuation (2026-10-27): `specs/072-paid-events-ticketing` records verified architecture v1.1 source, classified tasks and predecessor evidence. A draft-only Events foundation is implemented and locally migrated behind a deny-all organiser authorizer; no P25 route, publication, sale or financial action exists. Draft tests and scoped static/lint checks pass. Hold/issuance/late capture, gate and credential policy, cancellation and payout eligibility require approved evidence; P18 execution/settlement remain stubs under AD-14/AD-10/LC14. The checklist confirmation was explicitly waived for independent work; policy approval and full plan review remain open. No live processing enabled.

P25 specification continuation: draft acceptance and ownership assumptions clarified; forged draft ownership/version regression added. Latest P25/P24 scoped test run passed 10 tests/35 assertions; five policy/review checklist markers remain open. Status stays PARTIAL/BLOCKED.

P25 authority clarification: organiser, independent reviewer, edit invalidation, event-specific re-entry and gate-assignment directions are recorded in `specs/072-paid-events-ticketing/spec.md`. Permission contracts, policy details and owner approval evidence remain open; no P25 publication or admission is enabled.

P25 re-entry clarification: one admission, separate permitted re-entry records, recorded exits, a per-event limit and denial on the next online scan following operator revocation are selected directions. Owner approval, concrete limits and scan/authority contracts remain open; no P25 admission flow is enabled.

P25 race/expiry clarification: scan/revocation follows commit order; expired holds release while payment remains unresolved; released holds require reacquisition or approved refund; duration changes preserve existing expiries and an earlier approved event start shortens affected holds. Owner approval, transaction contracts and real concurrency evidence remain open; P25 stays PARTIAL/BLOCKED.

P25 verification clarification: holder verification remains pending; a separately authorised support or finance reviewer may restore eligibility only after the selected unsuccessful-refund checks; the initial release is online-only; authorised organisers configure re-entry limits subject to review. Permission contracts, numeric limits and owner approvals remain open.

P25 payout/closure clarification: organiser request follows completion and reconciliation with liabilities resolved or covered by an approved reserve; Finance reviews/approves and sets the reserve under approved policy. Completion timing, reserve calculation, permissions, P18 contracts and owner approvals remain open; no payout or settlement is enabled.

P25 settlement clarification: external bank proceeds require verified reconciled settlement and all relevant bank proceeds must settle; later liabilities remain open for Finance review; payout approval is separated from reserve setting. Wallet-proceeds eligibility, Finance/Legal/architect approvals and P18 contracts remain open.

P25 entitlement/edit clarification: assignment remains pending; released orders require full reacquisition or approved refund, extra verified payments remain unresolved for Finance, and post-publication edits pause sales until review and authorised resumption after rejection. Owner approvals, permission contracts and financial implementation remain open.

P25 implementation resume (2026-10-27): independently authorized T020 adds draft-only order-limit, currency-boundary and timezone/sales-window validation; EventDraftFoundationTest is 8/8 passing (29 assertions), scoped Pint passes, and the local guarded schema migration applied. T017–T020 are implemented; checklist remains 2 checked / 5 unchecked. The scan privacy/offline/re-entry decisions are specified; remaining blockers are missing owner authority/contract artifacts, numeric hold/re-entry and refund/cancellation/payout policies, P18 AD-14/AD-10/LC14, real concurrency and independent Security/Finance review. P25 remains PARTIAL; no payment, admission, payout, deployment or P24 activation occurred. Detailed status: `specs/072-paid-events-ticketing/`.

P24 evidence: `specs/071-funeral-assistance/{research,evidence,restart,traceability}.md`. The repaired P18 beneficiary migration and four P24 migrations are applied locally; scoped P24 tests pass (11 tests, 55 assertions). This is not a release: every P24 HTTP route returns 503 and the Payment purpose resolver remains closed. The new actor-to-organisation verifier denies access until an authoritative foundation source exists. Local/Platform audit and private in-app notice outbox are implemented; admin/mobile funeral actions are disabled. P18 execution/settlement stubs, late-capture policy, two-process concurrency, P22/P23 runtime gates, AD-14, AD-10 and LC14 remain unresolved. Keep live processing and P24 HTTP disabled.

2026-10-27 continuation: Identity now has a locally migrated reviewed organisation/membership registry, distinct request/review/revoke permissions (declared, not assigned), state-only event history and P24 fail-closed binding. Final combined registry/P24 PHPUnit reports 15 tests/73 assertions passed; scoped PHPStan has no errors. This is a code-level contract, not approved organisation provenance or finance acceptance. Registry evidence criteria/role assignment, two-process races, P18 payout execution, late-capture and AD-14/AD-10/LC14 remain open. P24 HTTP and live payment processing remain disabled.

Further continuation: real Identity-backed P24 tenant HTTP and authenticated-actor idempotency replay checks pass; P24/Identity/Platform suite reports 21 passes and 88 assertions. Existing financial idempotency namespaces are preserved and their actor-header migration remains open. P19 receipt/history final-class test setup was repaired; 13 unit checks pass and 15 database/listener cases remain skipped. No release or finance acceptance is claimed. See P24 evidence and restart notes.

Targeted Flutter P22/P23 checkout, history notifier and receipt tests report 28 passes; mock bank scenarios are not provider certification. Runtime, concurrency and finance decision gates remain open.

Phase 10 convergence continuation: Implemented complete unblocked scope for P24 (`specs/071-funeral-assistance`). Two-process concurrency tests for activation and confirmation replay, representative revocation, mobile obligation flow with shared payment checkout launch, Next.js admin review/close/report pages, typed domain models/enums/value objects/repositories, typed FormRequests/JsonResources, configurable defaults, and payout 503 error contract are implemented and verified. All 20 CommunityFuneral tests pass (157 assertions); PHPStan level 5 passes (43 files, 0 errors); route:list displays 18 active routes; Dart analyze passes with 0 issues. P18 family payout execution and settlement remain intentionally BLOCKED per AD-14/AD-10/LC14. HTTP readiness gate remains false.


**Prompt pack notes**:
- P12/P13/P14 can remain blocked on bank documentation while Wallet and independent platform work progresses.
- Implement P22 against certified contracts and development test fixtures where a live bank is unavailable, but do not count mock-backed UI testing as provider certification.
- P32/P33 may deliver a qualified release for explicitly approved enabled scope while conditional capabilities remain disabled and tracked.

---

## 7. Execution Register (Selected Packages)

### P00
| Field | Value |
|-------|-------|
| Status | IN_PROGRESS |
| Next action | Add architecture doc v1.1 to repository; re-run gap analysis for FD/LC/AD/MB |

### P01
| Field | Value |
|-------|-------|
| Status | PARTIAL |
| Gap | Accounting decision register: custody, merchant account ownership, chart-of-accounts mappings, fee bearers, late-payment policy |
| Next action | Architect + finance reviewer: produce `ADR-E48-003-accounting-decisions.md` |

### P02
| Field | Value |
|-------|-------|
| Status | PARTIAL |
| Gap | Quote/fee snapshot schema verification; additive migration rollback strategy |
| Next action | Backend engineer: verify quote/snapshot against P02 scope item 2; document rollback |

### P03
| Field | Value |
|-------|-------|
| Status | PARTIAL |
| Gap | Maker-checker for sensitive config changes; sponsor/gate-operator finance-access denial confirmed |
| Next action | Security engineer: negative-test cross-tenant access and self-approval |

### P04
| Field | Value |
|-------|-------|
| Status | PARTIAL |
| Gap | Immutable quote versioning; stale-quote revalidation; renewed consumer confirmation on changed total |
| Next action | Backend engineer: implement/verify quote version; add stale-quote detection before orchestration call |

### P05
| Field | Value |
|-------|-------|
| Status | PARTIAL |
| Gap | Eligibility filter chain (org/module/purpose/country/currency/amount layers) |
| Next action | Backend integration engineer: verify eligibility filter against P05 scope item 2 |

### P06
| Field | Value |
|-------|-------|
| Status | PARTIAL |
| Gap | UNKNOWN state stays UNKNOWN on timeout (not auto-resolved); unsafe provider-switch block explicit test |
| Next action | Backend engineer: verify UNKNOWN→timeout path; add provider-switch test |

### P07
| Field | Value |
|-------|-------|
| Status | PARTIAL (Docker-gated) |
| Gap | Crash-window tests (submission/debit/capture sequences); Redis loss recovery |
| Next action | Run Docker validation for `WalletPaymentConcurrencyTest` and `PaymentIdempotencyTest` |

### P08
| Field | Value |
|-------|-------|
| Status | PARTIAL |
| Gap | Capture-survives-ledger-outage test; accounting separation (capture ≠ accounting ≠ fulfilment) |
| Blocker | P01 accounting decisions must be confirmed first |
| Next action | Finance reviewer + backend: confirm accounting mappings; Docker ledger-outage test |

### P09
| Field | Value |
|-------|-------|
| Status | PARTIAL |
| Gap | Reservation/capture vs. atomic debit semantics documented; crash-after-debit-before-acknowledgement recovery |
| Next action | Wallet + backend engineer: document semantic choice; verify crash-after-debit recovery |

### P10
| Field | Value |
|-------|-------|
| Status | PARTIAL — independent development framework implemented and verified on 2026-10-22; live activation BLOCKED |
| Specification | `specs/059-e25-payment-callback-ingress/` — source v1.1 verified; stories, plan, tasks, contracts, traceability and runbook present |
| Implementation evidence | 442 final backend tests pass, 0 fail/error/skip; 45-file PHPStan including orchestration passes. Real two-process submission/hard crash, actual-ledger replay and Payment/outbox rollback verified. See specs/059-e25-payment-callback-ingress/prerequisite-closure.md |
| Certification / release | BLOCKED — no real Bank 1/2/3 contracts, credentials, certification/operating evidence or production authorization |
| Prerequisite gap | Local technical gates CLOSED: all recorded unit/static findings fixed; concurrency/crash/ledger evidence passes; schema checked and 23 package migration identities explicitly adopted at batch 0. Scoped migrate reports Nothing to migrate. This is local schema adoption, not fabricated historical execution or release approval |
| Scope closed | E25 receipt ownership (DEC-06 technical boundary documented), account/raw signature verification, encrypted evidence, durable delivery intent, account/environment dedup, quarantine/retry APIs/UI, mock activation/execution guards |
| Pending decisions | Real provider contracts/certification, approved evidence retention/rotation/break-glass and release sign-off. P06 FR-07 is corrected by P10-R1 to block fresh operations while UNKNOWN. Live processing stays disabled per user instruction |
| Next action | Review completed prerequisite changes and obtain real provider/policy evidence. No live activation, P11 or production deployment automatically |
| Restart | `specs/059-e25-payment-callback-ingress/restart.md`; main at a72ca74252e4ecb42e08b7a9db398491d04f4778, P10 edits uncommitted alongside preserved predecessor work |

### P11
| Field | Value |
|-------|-------|
| Status | PARTIAL |
| Gap | Transactional outbox atomicity; inbox deduplication with downstream domain effect; E17 notification templates per event |
| Next action | Backend messaging engineer: verify outbox atomicity; check E17 templates for PaymentConfirmedEvent, PaymentFailedEvent |

### P12 / P13 / P14
| Field | Value |
|-------|-------|
| Status | EXTERNALLY_BLOCKED |
| Gap | No bank identity confirmed; no API documentation; no sandbox credentials |
| Next action | Business/Compliance lead: identify banks; initiate documentation and sandbox access |

### P15
| Field | Value |
|-------|-------|
| Status | PARTIAL |
| Gap | Full refund FSM (request→approval→submit→pending/unknown→confirmed→definitive failure); concurrent partial refund lock |
| Next action | Finance engineer: verify FSM against P15 scope; add concurrent-partial-refund test |

### P16
| Field | Value |
|-------|-------|
| Status | MISSING |
| Gap | No reversal/dispute/chargeback domain |
| Dependency | P15 must be complete |
| Next action | Finance and risk engineers: create new spec using prompt pack P16 text |

### P17
| Field | Value |
|-------|-------|
| Status | PARTIAL |
| Gap | Provider statement ingress; settlement bank matching separate from E20 general reconciliation |
| Dependency | P16 must be complete |
| Next action | Finance systems engineer: assess E20 coverage of P17 scope; identify and spec gaps |

### P18
| Field | Value |
|-------|-------|
| Status | MISSING |
| Gap | No payout module |
| Dependency | P03, P08, P15, P16, P17 must be complete |
| Risk | P24–P28 (all community features) cannot proceed until P18 is complete |
| Blocker | DEC-04 — payout module ownership decision required before spec can be written |
| Next action | Architect: decide payout module ownership; create P18 spec |

### P19
| Field | Value |
|-------|-------|
| Status | PARTIAL |
| Gap | Paginated payment history API; receipt separate from domain invoice; transactional notification deduplication |
| Next action | Backend engineer: verify history API endpoint in `payment.yaml`; add if missing |

### P20
| Field | Value |
|-------|-------|
| Status | PARTIAL |
| Gap | Draft/validate/approve/activate/suspend/version-history workflow; maker-checker; conflict/approval-pending states |
| Next action | Frontend engineer: audit existing pages against P20 scope items 2–4 |

### P21
| Field | Value |
|-------|-------|
| Status | MISSING |
| Dependency | P17 PARTIAL, P18 MISSING — cannot start until both complete |
| Next action | Plan against P21 prompt text once P18 is ready |

### P22
| Field | Value |
|-------|-------|
| Status | PARTIAL |
| Gap | Recovery after app restart / network loss; allowlisted return destinations; block unsafe provider replacement |
| Next action | Flutter engineer: implement/verify recovery; add restart-recovery widget test |

### P23
| Field | Value |
|-------|-------|
| Status | PARTIAL/MISSING |
| Gap | History list screen, refund request screen, support-from-transaction flow missing |
| Next action | Flutter engineer: implement `PaymentHistoryScreen`, `RefundRequestScreen`, `SupportFromTransactionFlow` |

### P24–P28
| Field | Value |
|-------|-------|
| Status | BLOCKED |
| Primary blockers | Community module removed 2026-10-20; P18 MISSING; DEC-08 (re-specify community) open |
| Note | P27 additionally requires jurisdiction/operating approval (DEC-09) |

### P29–P33
| Field | Value |
|-------|-------|
| Status | BLOCKED / NOT_STARTED |
| Note | P29 blocked on P21 + P24–P28. P31–P33 cannot start until their full predecessor chains are complete. P32/P33 may deliver qualified release for approved enabled scope per prompt pack note. |

---

## 8. Dependency Map

```
P01 (arch+accounting) ─────────────────────────────────┐
P02 (schema+API) → P03 → P04 → P05 → P06 → P07 → P08 │
                                       │          │    │ (P01 required)
                                       └──→ P10 ─→ P11 │
                                                        ↓
P08 + P09 + P10 + P11 ──→ P15 (refund)
                      ──→ P17 (reconciliation) [after P15+P16]
                      ──→ P19 (receipts/history)

P15 ──→ P16 (disputes) ──→ P17 ──→ P18 (payout)

P03 + P08 + P15 + P16 + P17 ──→ P18 ──┐
P19 + P22 + P23 ────────────────────────┤
                                         ↓
                            P24 Funeral  ← BLOCKED (community removed)
                            P25 Events   ← BLOCKED
                            P26 Free     ← BLOCKED (← P25)
                            P27 Raffle   ← BLOCKED + DEC-09
                            P28 Donation ← BLOCKED

P12 / P13 / P14 (bank adapters) ── EXTERNALLY_BLOCKED — independent of community

P17 + P18 + P19 + P20 ──→ P21 (admin finance dashboard)
P21 + P23 + P24–P28 ──→ P29 (reporting/support)
P10 + P11 + P17 + P18 + P21 ──→ P30 (operations)
P29 + P30 ──→ P31 (security verification)
P24–P31 ──→ P32 (migration/rollout)
P00–P32 ──→ P33 (final convergence)
```

**Critical path — live payment (Wallet only)**: P01→P02→P03→P04→P05→P06→P07→P08→P09 ← current state: all PARTIAL

**Critical path — live bank payment**: Above + P10→(P12 or P13 or P14) ← EXTERNALLY_BLOCKED

**Critical path — community features**: P15→P16→P17→P18→P19→P22→P23→P24/P25/P27/P28 ← P16+P18 MISSING

---

## 9. Open Decisions

| DEC-ID | Decision | Owner | Risk if deferred |
|--------|---------|-------|-----------------|
| DEC-01 | Which bank is "Bank 1"? Obtain API documentation and onboarding timeline. | Business Lead | P12 stays EXTB; no live bank payment |
| DEC-02 | Which bank is "Bank 2"? | Business Lead | P13 stays EXTB |
| DEC-03 | Which bank is "Bank 3"? | Business Lead | P14 stays EXTB |
| DEC-04 | Is payout / disbursement in the `Payment` module or a separate module? Payout adapter separate from incoming payment adapter. | Architect | P18 cannot be specified; P24–P28 all blocked |
| DEC-05 | Accounting decision register: custody, merchant account ownership, chart-of-accounts mappings, fee bearers, late-payment policy. | Architect + Finance reviewer | P01 stays PARTIAL; P08 ledger mapping blocked |
| DEC-06 | RESOLVED technical boundary for P10: E25 owns receipt/ingress/retry/quarantine; Payments owns semantic validation and state | Architect + Integration engineer | ADR-E25-P10-payment-callback-boundary.md implemented; Security/Finance live activation review remains pending |
| DEC-07 | Source availability RESOLVED 2026-10-22: v1.1 verified and original retained at docs/sources/OleKard_Shared_Payment_Architecture_v1.1.docx | Technical Lead | P10 FD06/LC01/LC03/LC05 reviewed; historical AWDOC claims in other packages need their own source review, not automatic readiness |
| DEC-08 | Re-specify community services module (replacement for removed E49) as a prerequisite for P24–P28. | Product Lead | P24–P28 remain BLOCKED even after P18 is complete |
| DEC-09 | What jurisdiction/operating approval is required for Raffle (P27)? Which regulatory authority in Botswana? | Compliance Lead | P27 cannot activate even after community re-specification |
| DEC-10 | Donation all-or-nothing vs. unconditional terms (P28); what is the target-failure policy? | Product + Finance | P28 closure policy cannot be specified |
| DEC-11 | AGENTS.md — create repository-wide agent instruction file or confirm intentionally absent? | Technical Lead | R44 stays AWDOC |

---

## 10. Risk Register

| Risk | Severity | Likelihood | Mitigation |
|------|---------|-----------|-----------|
| No live bank adapter → no real-money payments | CRITICAL | High (current state) | DEC-01–03: identify banks; start P12 as soon as docs received |
| Architecture document v1.1 not in repository → FD/LC/AD/MB unverifiable | HIGH | Medium | DEC-07 — add to repository urgently |
| P18 (payout) missing → P24–P28 all blocked | HIGH | High (current state) | DEC-04 + create P18 spec |
| P16 (disputes) missing → P17 reconciliation incomplete | HIGH | Medium | Finance/risk engineers create P16 spec |
| Community module removed → P24–P28 have no consumer domain | HIGH | High (current state) | DEC-08 — re-specify community module |
| P01–P11 all PARTIAL → hidden financial integrity gaps | HIGH | Medium | Docker validation for P06/P07/P08 crash scenarios |
| E25 ↔ Payment boundary undocumented | MEDIUM | Medium | DEC-06 → ADR |
| Raffle jurisdiction approval not obtained | MEDIUM | Medium | DEC-09 before P27 can activate |
| PHPStan / Docker not re-run post-community-services removal | MEDIUM | Low | Run as immediate next step |

---

## 11. Definition of Done (P00 Assessment)

| Criterion | Status |
|-----------|--------|
| All 34 P-IDs registered with titles matching prompt pack verbatim | ✅ PASS |
| Every gap classification has file/test evidence or "not found" | ✅ PASS |
| No source requirement silently dropped | ✅ PASS (R39–R44 recorded as AWDOC) |
| Bank/documentation/custody/accounting decisions identified | ✅ PASS (DEC-01–DEC-11) |
| Domain-policy decisions identified | ✅ PASS |
| Three completion axes separated (code / provider cert / production readiness) | ✅ PASS |
| Prompt pack P-IDs verified and mapped verbatim | ✅ PASS |
| Architecture document verified | ❌ FAIL — `OleKard_Shared_Payment_Architecture.docx v1.1` not in repository |

**Overall DoD**: PARTIAL — all structural criteria pass; architecture document source verification outstanding.

---

## 12. Next Permitted Actions (Priority Order)

> Do not start any implementation package automatically. Do not deploy to production without authorisation.

1. **DEC-07 source now available**: Original v1.1 is hash-verified under docs/sources. P10 references reviewed; re-run the remaining package-specific FD/AD/MB/LC gap analysis when those packages are authorized, without inferring implementation readiness.
2. **Immediate**: Run Docker validation — PHPStan level 8 + quickstart tests for `App\Modules\Payment`.
3. **DEC-05 (short-term)**: Complete P01 accounting decision register (ADR-E48-003); unblocks P08.
4. **DEC-06 technical boundary documented**: ADR-E25-P10-payment-callback-boundary.md and P10 evidence close the ownership ambiguity; prerequisite failures, provider contracts/certification and live activation remain blocked.
5. **DEC-01 (short-term)**: Identify Bank 1; start P12 specification.
6. **DEC-04 (short-term)**: Decide payout module ownership; create P18 spec. This is the critical-path prerequisite for all community features (P24–P28).
7. **DEC-08 (medium-term)**: Re-specify community module; P24–P28 cannot proceed until P18 is DONE and a new community spec is approved.
8. **Medium-term**: Close P03–P11 gap items before running P31 security verification.

---

## User Scenarios & Testing

### User Story 1 — Technical Lead Reviews Payment Programme Status (P1)

A technical lead opens the delivery register and can answer: which P-IDs are DONE/PARTIAL/MISSING/BLOCKED, what the critical path to live payment acceptance is, and what decisions are needed before any BLOCKED item can advance.

**Acceptance Scenarios**:
1. Given this document, when a stakeholder asks "what is blocking live bank payment acceptance?", the answer (DEC-01 → P12 → bank docs) is derivable within 2 minutes from Section 8 + Section 9.
2. Given this document, when a stakeholder asks "what is blocking community features?", the answer (P16+P18 MISSING → DEC-04+DEC-08) is derivable from Section 7 (P18 entry) + Section 9 (DEC-04, DEC-08).

### User Story 2 — Architect Selects Next Implementation Package (P1)

An architect consults the register to identify which P-ID to select next (predecessors satisfied, scope defined, not externally blocked).

**Acceptance Scenarios**:
1. Given Sections 6 and 7, when filtering by `Status = PARTIAL` and all predecessors done or in-progress, P15 (refund engine gap closure) and P20 (admin provider config gaps) are identified as highest-priority actionable packages.
2. Given Section 9, when filtering for open decisions, exactly 11 decisions are listed with named owner roles and risk descriptions.

---

## Requirements

### Functional Requirements

- **FR-01**: Every work package P00–P33 MUST have a unique ID, title (verbatim from prompt pack), predecessors, owner role, status, and next action.
- **FR-02**: Every gap classification MUST cite a file path, test name, or "not found" as evidence.
- **FR-03**: No source requirement may be silently dropped; FD/LC/AD/MB codes from the missing architecture document are recorded as AWDOC.
- **FR-04**: All bank/documentation/custody/accounting/domain-policy decisions must be recorded in Section 9 with an owner role.
- **FR-05**: Three completion axes (code, provider certification, production readiness) must be separated.
- **FR-06**: No implementation, schema migration, or provider execution in P00.

## Success Criteria

- **SC-01**: All 34 P-IDs (P00–P33) registered — verifiable by counting rows in Section 6.
- **SC-02**: Every gap classification in Section 5 has at minimum one file path, test name, or "not found" note.
- **SC-03**: Every open decision in Section 9 has an identified owner role and a risk description.
- **SC-04**: A technical lead can answer the two critical-path questions (bank payment + community features) within 2 minutes from Section 8.
- **SC-05**: No requirement from the prompt pack or repository evidence is absent from the gap register.

## Assumptions

- E48 (Shared Payment Integration) is COMPLETE/RELEASE READY per memory record, but all P01–P15 packages are PARTIAL pending Docker validation and full prompt pack scope closure.
- Prompt pack P-ID titles, predecessors, and role owners are reproduced verbatim and are the authoritative P-ID registry for this programme.
- `OleKard_Shared_Payment_Architecture.docx v1.1` is a separate document; its FD/LC/AD/MB codes cannot be verified until added to the repository.
- Community module removal (2026-10-20) is permanent for the current iteration; P24–P28 remain BLOCKED until a replacement community spec is approved.
- P12, P13, and P14 can remain externally blocked while P01–P11 and P15–P23 proceed independently.
- P32/P33 may deliver a qualified release for explicitly approved enabled scope (e.g. Wallet-only payments) while bank/community capabilities remain disabled and tracked.

2026-10-28 P25 blocker-resolution checkpoint: internal versioned event review/publication workflow implemented in Events behind deny-by-default authorizer/readiness contracts; local migration applied; focused P25 tests 11/11 pass (41 assertions), scoped Pint and application PHPStan pass. T021 ready/implemented; T008 partially implemented but inaccessible pending B-01/B-03; other dependent financial/admission tasks unchanged. Five checklist gates remain unchecked; P25 PARTIAL. No P24/live-payment activation/deployment. Exact blocker table and single next action are in `specs/072-paid-events-ticketing/{research.md,restart.md,evidence.md}`.

P25 verification correction 2026-10-28: final P25 Events feature suite is 12 pass, 0 fail, 0 skip (44 assertions); scoped Pint passes and scoped application PHPStan has 0 errors. P25 migration 100003 is Ran locally in batch 11. This supersedes the preceding 11-test checkpoint; no full migrations, P24 activation, live processing or deployment.

P25 B-01 review artifact (2026-10-28): drafted `specs/072-paid-events-ticketing/authorization-matrix.md` against approved Identity global RBAC and membership contracts. Proposed P25 permissions/scopes remain unapproved and unregistered; B-01 remains blocked. A-01�A-04 are the focused decisions, with Identity/Security, Events and Finance owners identified. No production authorization changed; B-03 and B-11 remain independent blockers. T022 complete as documentation; no other READY implementation task identified.

P25 update 2026-10-28: A-01/A-02/A-04 recorded as product decisions; A-03 in principle. Implemented T024 cumulative event-version contributors and separate event/Finance approvers. Focused tests 14/14 pass, 53 assertions; scoped Pint and application PHPStan pass; local migration 100004 applied. B-01 still blocked on exact A-03 mapping/owner review. No Identity grant or separate E04 package started. D-02 remains numeric re-entry limit only; return-count semantics and configuration authority are established. See P25 matrix and conditional authorization dependency proposal.

2026-10-28 P25 scoped authorization checkpoint: accepted development permission IDs are registered but have no automatic role grants. Events owns exact resource assignments; scoped checks require both coarse Identity permission and applicable scope assignment. `ScopedEventAuthorization` and migration 100005 were implemented/applied locally; see P25 `research.md` and `traceability.md`. T025/T026 ready/implemented. Assignment write endpoints remain blocked by `assignment-authority-proposal.md`; T005/T011/T015/T016 remain partial/dependent as recorded. Focused verification: 19 passed, 97 assertions; Pint pass; PHPStan Events app 0 errors. No live/P24 or production change.

Verification count correction: final focused Events authorization suite is 19 passed / 100 assertions; Pint passed; Events application PHPStan 0 errors; migration 100005 locally ran in batch 13. Assignment authority writer, ticket-owner/refund flow, aggregate reporting and production authorization remain unavailable.

## P25 update — 2026-10-28

P25 implemented development-only Events-owned org/event assignment writes and an event gate registry with scoped gate-validation delegation. Identity permission catalogue has no automatic role assignments. Two-approver bootstrap is available only through an explicit command and was not executed. P25 remains PARTIAL; independent reviews, operational bootstrap evidence, admission/finance dependencies and P24/live-processing gates remain open. See `specs/072-paid-events-ticketing/evidence.md`, `tasks.md` and `restart.md`.

Correction to prior progress notes: T027 assignment administration and T029 gate registry are implemented for development. Final P25 scoped verification is 27 passed / 0 failed / 0 skipped / 136 assertions; Pint/PHPStan passed. This does not clear B-03, B-11, independent reviews or admission/fulfilment blockers.
### P25 2026-10-28 continuation

Prepared `identity-security-review-index.md` for independent review; no named P25 reviewer or appointment procedure is present in governance records. Added a production fail-closed bootstrap-evidence verifier; reference strings alone cannot create authority, and bootstrap was not executed. Added authenticated scoped draft/review routes and admin authoring workspace at `/community/events`; no publication, sales, checkout, admission, attendance or payout route/UI was enabled. Verification for this continuation is recorded in P25 `evidence.md`; prior suite-harness construction failure remains separate from focused test results. P25 remains PARTIAL. D-02, B-03, B-11 and independent Security/Finance/Privacy reviews remain open.

Verification update: P25 current scoped Events suite 28 pass / 0 fail / 0 skip / 139 assertions; Events PHPStan and Pint pass; route list shows 16 P25 event routes with no publish or sales-resume route; scoped admin TypeScript passes. Full admin tsc reports only stale `.next/types` role-page imports; ESLint was interrupted without a result. Details are in P25 `evidence.md`.

2026-10-28 P25 checkpoint: development authoring/review and T009 inventory-hold foundation implemented; focused Events suite 31/152 passes, Pint and scoped PHPStan pass. T009 concurrency NOT VERIFIED. P25 PARTIAL. Admin full lint/build fail on unrelated pre-existing ESLint errors; full tsc passes after Next regenerated stale .next types. B-03 proposal and independent review handoff prepared but not approved/appointed. P18 blocks only payout task T014, not P25 inventory, checkout/issuance or online admission dependencies. Live bootstrap/publication/sales, P24 and deployment remain disabled. See specs/072-paid-events-ticketing/{tasks,evidence,restart}.md.

Admin verification detail: full lint has 30 errors in 7 pre-existing non-P25 files (payments forms/editor, funeral collections, campaign create/detail and reconciliation exceptions); P25 scoped lint is successful with warnings only. Full admin build compiles then fails at the same lint/type gate. Full 	sc --noEmit passes after normal build refreshed stale .next route types. See P25 evidence.md.

P25 B-03 update 2026-10-28: implemented development-scoped Finance currency assignment, registered `events.currency.assign` without role grants, precision/provenance snapshot, first inventory hold currency lock, drift review issue, draft-edit preservation, and actor-free Payments static per-category eligibility service. Targeted migrations 100009/100010 applied locally. Verification: Events 40/210 pass, Payment eligibility 20/34 pass, PostgreSQL separate-session concurrency four scenarios pass including first-hold currency lock, scoped PHPStan 0 errors, Pint --test pass. Full legacy migration command fails before P25 on duplicate populated identity.users; no destructive recovery attempted. P25 remains PARTIAL: canonical Payments module/purpose/country contract, MultiCurrency cross-connection race coordination, B-06 fee/tax contract, T004 approval/recovery, T005 credential/refund/re-entry policies, independent reviews and P18-only payout remain unresolved. Publication readiness stays deny-by-default; no bootstrap, operational grant, paid sales, P24, deployment or live processing. Details: P25 research/evidence/restart and B-03 proposal.

B-03 follow-up, 2026-10-28: Event precision drift is now recorded as an operator issue and pauses existing sales availability after rollback, while preserving existing monetary records. Final P25 Events feature suite 40/211 and Payment readiness unit suite 21/35 pass; PostgreSQL concurrency four scenarios pass including event currency first-lock serialization; scoped PHPStan 0 errors and Pint pass. No canonical production context was invented; readiness/publication remain denied. Full rollout boundaries remain as previously recorded.

Final test count correction 2026-10-28: Events 40 passed / 0 failed / 0 skipped / 213 assertions; Payment eligibility 21/35, zero failed/skipped. The Finance currency assignment test now also verifies reassignment is denied after locking.

### P25 verification continuation — 2026-10-28

Events currency drift regression: 41 tests passed, 0 failed, 0 skipped, 220 assertions. Pint and scoped Events PHPStan passed. Current code tests and records inactive currency/precision drift by rejecting the attempted hold, persisting an operator-review issue plus domain audit, and pausing active sales outside the rolled-back hold transaction. Real separate-session PostgreSQL hold concurrency evidence remains the four previously recorded scenarios. Cross-module currency-change races are not verified and current locks do not serialize them. Details, canonical context proposal, country semantics, order-lock gap and migration repair proposal are in `research.md` and `evidence.md`.

2026-10-28 P25 CTX/MC/FIN bounded scope: canonical events/paid_event_ticket identifiers are declared with collision safety; no Events resolver bound because there is no authoritative order model; provider account allowlists unchanged. Paid country derives from approved event record for physical single-country events only. Events and MultiCurrency share PostgreSQL transaction-scoped currency coordination and trigger; targeted local migrations applied. Three real currency races and four inventory races pass; Events 47/253, registry 3/6, eligibility 22/38 pass; targeted Pint/PHPStan pass. T010 durable-order lock remains mandatory/not implemented. Financial drift disposition, B-06/T004/T005/D-02/B-11 and independent reviews remain open. P25 PARTIAL; production bootstrap, publication, checkout/sales, deployment and P24 disabled. See P25 evidence/research/restart.

Caller regression limit: existing AdminFxManagementTest is not verifiable in the current local schema (21/22 tests fail because platform.configuration_change_requests is missing; no repair authorized). Existing FuneralAssistanceFlowTest passes 6/48 and the prior one-argument currency lock callers remain source-compatible. Full details in P25 evidence.md.

P25 final policy packet prepared 2026-10-28: business-policy-decision-packet.md consolidates unapproved B-06, T004, T005, D-02 and FIN-01 questions, current state/contract evidence, recommendations, owners, external evidence and verification debt. AdminFxManagementTest's 21/22 failure is directly relevant but cannot exercise writer compatibility because platform.configuration_change_requests migration 2026_10_02_000002 is Pending; API-path verification remains open. No migration, policy implementation, bootstrap, publication, checkout, sales, deployment or P24 activation.

### 2026-10-28 sustained P08 dependency-chain result

- P08 status: PARTIAL. Wallet-owned committed capture, posted Ledger evidence verification, Payment capture persistence/outbox and repair are implemented for development; focused PostgreSQL integration and fault injection pass. Bootstrap and clean 313-migration chain/repeat run pass in a disposable DB. P08 quickstart SC-01–SC-07 reconciliation and independent review remain open.
- Wallet dependency: DEVELOPMENT VERIFIED / OPERATIONALLY DISABLED. AD-08 destination/account mapping and Finance evidence remain absent. Test fixtures are not production grants or mapping approval.
- P11 status: PARTIAL. Replay route/re-dispatch and notification deduplication pass. Replay remains platform-wide under existing operations authorization. PaymentConfirmedListener no longer writes an invalid fabricated financial allocation; financial split/allocation remains a shared Finance/Payments decision and is not created by notifications. No tenant isolation claim.
- P25 status: PARTIAL. T008 authoring/review and T009 hold/currency coordination are verified in development. T010 order/checkout/issuance awaits B-06 and T004 order-bound evidence; T011 durable entitlement awaits T010 and Identity/Security/Privacy requirements; T013 refunds/cancellation awaits policy and shared refund contract; T014 alone depends on P18/B-11. No production publication or sales.
- Final scoped suite: 75 passed / 0 failed / 0 skipped / 411 assertions. Changed Wallet/Payment/Ledger PHPStan level 8: 0 findings. Pint: 12 scoped files pass. PostgreSQL separate-session Wallet, inventory and currency coordination scripts passed. Existing AdminFxManagementTest local schema failure remains 21 failures of 22 due absent platform.configuration_change_requests and is not counted as a pass.

No migration-history repair, production bootstrap, operational grant, provider certification, deployment, P24 activation or live-processing change was made. See specs/057-ledger-ack-fulfilment/verification-traceability.md and specs/072-paid-events-ticketing/evidence.md.

## Final verification result - 2026-10-28

After the repair-exhaustion fix and the new SC-06/SC-07 tests, the combined P08/P11/P25 regression command completed **80 passed, 0 failed, 0 skipped, 441 assertions**. Pint check: 14 changed-slice files pass. Targeted PHPStan on changed Wallet/Payments/Ledger source plus the new P08 feature tests: 0 errors with 1G memory. Real PostgreSQL Wallet capture/release/expiry races and P25 inventory/currency coordination races passed. `git diff --check` found no whitespace errors; it printed only repository CRLF normalization warnings. These results supersede the earlier 75-test scoped count in this record.

Final combined suite correction - 2026-10-28: after adding persisted Payment confirmation outbox dispatch retry coverage, the P08/P11/P25 combined regression is **81 passed, 0 failed, 0 skipped, 446 assertions**. `WalletCapturePaymentIntegrationTest::test_durable_confirmation_outbox_delivers_after_payment_commit_when_dispatch_is_retried` asserts the outbox row remains pending and no notification exists after Payment commit, then dispatches that row in a later worker invocation and asserts one delivered event/notification. This is restart/re-dispatch evidence, not a forced OS process termination. The remaining consumer commit-before-ack flow needs a P25 durable order/entitlement effect.

2026-10-28 closeout: P08 PARTIAL. SC-01-SC-07 developer walkthrough passed on isolated PostgreSQL (81/0/0, 446 assertions; SC-04 scan 5/0/0, 8 assertions); AdminFxManagementTest passed schema-correct isolated DB 22/22 (118 assertions). Full Payment/Ledger PHPStan remains 485 findings (same latest baseline); targeted changed slice 0. Do not equate these results with independent operator/Finance/Security approval.

P25 remains PARTIAL. Approved issuance invariant is settled; remaining development dependency is B-06 concrete fee/seller/tax/calculation input followed by Events authoritative order binding to P08 `purposeReference`, currency lock at first order/hold, durable per-ticket issuance and acknowledgement. P25 B-06 decision form is in `specs/072-paid-events-ticketing/business-policy-decision-packet.md`. Late-capture/refund/cancellation decisions and independent reviews remain pending. T014 alone awaits P18/B-11. Do not enable bootstrap, publication, checkout/sales, production access, deployment or P24.


P26 implementation checkpoint (2026-10-30): specs/1002-free-invitation-complimentary-events/ records the verified v1.1 LC08 source, task classification, additive free entitlement schema, invitation/redemption API, atomic PostgreSQL registration, reuse of purchaser tickets/online gates, aggregate reporting, and admin/customer UI. Clean isolated PostgreSQL migration chain completed; focused Events suite 69/69 and separate-session concurrency 4 scenarios passed. Customer event widget tests and scoped analysis pass; Admin authoring lint passes. Full Flutter analyze reports 33 findings outside the scoped event files. P26 remains PARTIAL: Identity active-customer recipient verification, complimentary approval authority, free-only publication decision, P25 cancellation/notification and offline contracts, and independent Security/Privacy review remain open. No live/production gate changed.

P26 verification checkpoint (2026-10-30): T011 now has seven passing separate-session PostgreSQL scenarios including revoke-first, redeem-first, synchronized revoke/redemption, post-revocation idempotent replay and rollback/retry; typed Identity active-CUSTOMER verification is implemented. Events feature tests 72/519 pass. Admin P26 component 1/1 and community accessibility 4/4 pass; customer event widget 2/2 and scoped Flutter analyze pass. P26 remains PARTIAL; T006/T007/T014/T015/T016/T020 remain gated, with full Flutter analyzer 33 findings and three pre-existing Identity PHPStan findings recorded in package evidence.

P26 remediation checkpoint (2026-10-30): prior 3 Identity PHPStan findings, 1 Events Pint finding and all 33 customer Flutter analyzer findings are resolved. Current scoped PHPStan/Pint and full Flutter analyze pass; isolated Identity tests 17/45 and Events tests 89/565 pass; focused Flutter auth/event/transaction tests 50 pass. T011's prior seven PostgreSQL race/order scenarios remain verified (no registration-lock changes in this remediation). P26 remains PARTIAL: T006/T007/T014/T015/T016/T020 gates remain, and SC-007/T023 browser/device integration is not verified because safe isolated browser auth/assignment fixtures and P26 Playwright setup are absent. See specs/1002-free-invitation-complimentary-events/remediation-register.md and browser-e2e-handoff.md.


P26 isolated-browser checkpoint (2026-10-30): T023 harness setup was implemented with a guarded per-run PostgreSQL database, runtime-target assertion, test-only identities/assignments, dedicated Playwright configuration, and Admin/Flutter-web journey spec. Seven runner invocations included six Playwright runs that verified isolated database/migrations but had zero journey passes. Exact-origin preflight and invalid-MFA CORS checks passed; successful MFA returned HTTP 200 without Access-Control-Allow-Origin, blocking Chromium before invitation UI. Admin P26-spec ESLint and TypeScript checks pass. T023 remains NOT VERIFIED; no browser/device, customer/gate/report or persisted journey evidence is claimed. See specs/1002-free-invitation-complimentary-events/browser-e2e-handoff.md.
P26 continuation checkpoint (2026-11-03): Project-owner answers accepted the scoped complimentary approval model for development, a narrow free-only development publication rule, online-only/offline deferral for this release, and continued containment for unknown cancellation-finance outcomes. Governance was identified as the independent-review appointment authority; actual Security/Privacy appointees and signed reviews remain absent. P26 now has a typed complimentary approval evidence port with scope/requester-approver/quota/validity/revocation checks and persisted snapshots; production approval and publication bindings remain deny-by-default. Current isolated regression passes CORS 3/52, Identity MFA 10/70 and Events 77/556; scoped PHPStan under PHP 8.4.15 reports 0 Errors; Pint --test passes 11 touched PHP files. T023's strict same-run native Wallet-capture assertion remains unverified because the existing emulator is offline. T015/T020 still require P25/Payments/Ledger/Notification contracts; T014 requires formal scope-change and connectivity-loss evidence; T016/T024 require actual Governance appointments and independent outcomes. P26 remains PARTIAL; no production bootstrap/publication/sales, deployment, P18 or P24 activation.

P26 current-source browser checkpoint (2026-11-03): the isolated Chromium journey passed 2/2 Playwright tests and exact persisted counts after T006 contract changes. The harness now avoids the retained 8100 container by choosing a unique port. Strict native same-run Wallet-capture absence remains pending because `emulator-5554` is offline; production and review gates remain as above. No retained test DB/container was deleted.


P26 native checkpoint (2026-11-03): after the project-owner-approved non-wiping emulator restart, the guarded Customer+Agent native journey passed on emulator-5554. Same-run persisted counts 2|2|1|1|1|0|0|0|0|0|0|0 include zero Wallet captures and zero Payment/refund/Ledger/payout/settlement rows. Customer real challenge/PIN, free registration and ticket access passed; Agent wrong-gate denial, assigned-gate admission and repeat-scan denial passed. T023's documented native criterion is verified. P26 remains PARTIAL for T006/T007/T014/T015/T020 decisions/contracts and T016/T024 independent reviews; no activation gate changed. Details: specs/1002-free-invitation-complimentary-events/evidence.md.
