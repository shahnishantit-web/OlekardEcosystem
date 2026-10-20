# Implementation Plan: E49 — Community Services Platform: Horizontal Foundation

**Branch**: `049-community-services-horizontal` | **Date**: 2026-10-19 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `specs/049-community-services-horizontal/spec.md`

## Summary

E49 delivers the 14 shared horizontal capabilities (HZ01–HZ14) that underpin all three OleKard Community Services: Funeral Assistance (E50), Events & Ticketing (E51), and Permitted Raffles & Fundraising (E52). The implementation extends the existing modular-monolith OleKard platform, adding a new `CommunityServices` bounded module to the Laravel API, new admin panel modules in Next.js and a community navigation shell in the Flutter customer app.

Core design decisions:
- A new `community_services` PostgreSQL schema isolates all HZ tables from the wallet ecosystem's existing schemas.
- Organisation and tenant isolation is enforced at the query layer via a `ScopedToOrganisation` interface applied to every CommunityServices repository.
- The Order/Payment orchestration layer wraps (does not replace) the existing `Wallet` and `Payment` modules via the established `PaymentGateway` contract.
- The Approval (maker-checker) service is a new generalised domain service, making maker-checker available to E50–E52 without duplication.
- Partner adapters conform to the `PartnerAdapterContract` interface; no adapter-specific logic reaches the core domain.

## Technical Context

**Language/Version**: PHP 8.4+ (Laravel 11) · TypeScript 5.x (Next.js 15) · Dart 3.x (Flutter 3.x)

**Primary Dependencies**:
- Laravel: existing foundation modules (Identity E01, RBAC E04, Wallet E06, Ledger E07, Notifications E17, Audit E23, Support E22, Reporting E24, Integration E25)
- Next.js: existing admin component library and module pattern (`apps/admin/src/modules/`)
- Flutter: existing Riverpod state management, GoRouter navigation, shared design tokens

**Storage**: PostgreSQL — new `community_services` schema; Redis — existing Horizon queue for outbox jobs

**Testing**: PHPUnit (feature + unit) at PHPStan level 8; Jest + React Testing Library for admin; Flutter test + integration_test for mobile

**Target Platform**: Linux server (API + worker), Desktop browser (admin), iOS 14+ / Android 8+ (consumer mobile)

**Project Type**: Multi-tier (Laravel API + Next.js admin SPA + Flutter mobile) — modular-monolith backend

**Performance Goals**: p95 API reads ≤ 1 s; p95 writes ≤ 2 s; 100 payment initiations/s; 100 scan requests/s (provider calls simulated in load tests)

**Constraints**: All monetary amounts as `bigint` minor units; no floating-point finance; idempotency key required for all financial mutations; PHPStan level 8 zero errors; WCAG 2.2 AA for all new screens

**Scale/Scope**: 100,000 consumers, 1,000 organisations, 500 concurrent users — baseline load test target

## Constitution Check

| Principle | Gate | Status |
|---|---|---|
| P1 Source-of-Truth | No silent change to business intent — all 14 HZ features traced to source doc | ✅ PASS |
| P2 TBD Governance | No TBD values invented — retention periods deferred to business owner per assumption | ✅ PASS |
| P3 Configuration Over Hard-Coding | Step-up threshold, maker-checker thresholds, notification frequency caps, refund policy limits all configurable | ✅ PASS |
| P4 Financial Integrity | Order totals in integer minor units; idempotency on all financial mutations; double-entry compensating entries for refunds; no direct balance mutation | ✅ PASS |
| P5 Identity and Authorization | Reuses E01/E04 foundation; no separate credential store; step-up for financial approvals; gate-operator role enforced at API layer | ✅ PASS |
| P6 Zero Trust | Webhook signature verification before processing; secrets in managed vault; no credentials in API responses or logs | ✅ PASS |
| P7 Domain Boundaries | New `CommunityServices` module has explicit interface; thin controllers; services own business logic; no wallet direct mutation | ✅ PASS |
| P8 API Contracts | `/v1/community/...` versioned routes; UUID v7 IDs; idempotency keys; OpenAPI docs; error envelopes | ✅ PASS |
| P9 Event Reliability | Transactional outbox for all domain events; at-least-once with deduplication; compensating entries for reversals | ✅ PASS |
| P10 UX / Design System | Consumer: bottom nav (Home, Community, Events, My Tickets, Activity, More); Admin: desktop-first; design tokens reused | ✅ PASS |
| P11 Accessibility | WCAG 2.2 AA automated (axe-core) + manual screen-reader review on all new screens | ✅ PASS |
| P12 Test-First Quality | Each story has acceptance criteria and QA scenarios; concurrency tests required for financial flows | ✅ PASS |
| P13 Observability | Structured logging, correlation IDs, metrics for order/payment success, notification delivery, audit events | ✅ PASS |
| P14 No Silent Architecture Changes | No new frameworks; extends existing Laravel/Next.js/Flutter stack; no new ORM | ✅ PASS |
| P15 Release Governance | All 14 HZ features must pass acceptance criteria, security gates and QA before E50–E52 can proceed | ✅ PASS |

**Constitution Check Result**: ALL GATES PASS — proceed to Phase 1.

## Project Structure

### Documentation (this feature)

```text
specs/049-community-services-horizontal/
├── plan.md              # This file
├── research.md          # Phase 0 — resolved decisions
├── data-model.md        # Phase 1 — entity schemas
├── quickstart.md        # Phase 1 — validation guide
├── contracts/           # Phase 1 — OpenAPI fragments
│   ├── community-organisations.yaml
│   ├── community-membership.yaml
│   ├── community-consent.yaml
│   ├── community-catalogue.yaml
│   ├── community-orders.yaml
│   ├── community-receipts.yaml
│   ├── community-refunds.yaml
│   ├── community-partners.yaml
│   └── community-approvals.yaml
└── tasks.md             # Phase 2 — /speckit-tasks output
```

### Source Code (repository root)

```text
# Backend — Laravel API (wallet-ecosystem/services/api/)
app/Modules/CommunityServices/
├── Organisation/
│   ├── Application/          # OrganisationService, VerificationService
│   ├── Domain/               # Organisation model, OrganisationRepository interface, events
│   └── Infrastructure/       # Eloquent repositories, migrations
├── Membership/
│   ├── Application/          # MembershipService, InvitationService
│   ├── Domain/               # Membership, StaffInvitation models, domain events
│   └── Infrastructure/       # Eloquent repositories, migrations
├── Consent/
│   ├── Application/          # ConsentService, DataRightsService
│   ├── Domain/               # ConsentRecord model, ConsentNotice model
│   └── Infrastructure/       # repositories, migrations
├── Catalogue/
│   ├── Application/          # CatalogueService, ServiceEntitlementPolicy
│   ├── Domain/               # ServiceCatalogue model, CategoryVersion model
│   └── Infrastructure/       # repositories, migrations
├── Order/
│   ├── Application/          # OrderService, PaymentOrchestrator, IdempotencyGuard
│   ├── Domain/               # Order, PaymentAttempt models, state machines, domain events
│   └── Infrastructure/       # repositories, migrations
├── Receipt/
│   ├── Application/          # ReceiptService
│   ├── Domain/               # Receipt model
│   └── Infrastructure/       # repositories, migrations
├── Refund/
│   ├── Application/          # RefundService, DisputeService
│   ├── Domain/               # RefundRequest, DisputeHold models, events
│   └── Infrastructure/       # repositories, migrations
├── Partner/
│   ├── Application/          # PartnerConnectorService, WebhookIngressService
│   ├── Domain/               # PartnerConnector model, PartnerAdapterContract interface
│   └── Infrastructure/       # repositories, migrations, stub adapters
├── Approval/
│   ├── Application/          # ApprovalService, PolicyVersionService
│   ├── Domain/               # ApprovalRequest, PolicyVersion models, events
│   └── Infrastructure/       # repositories, migrations
└── Shared/
    ├── ScopedToOrganisation.php    # query scope interface
    ├── CommunityServicesProvider.php
    └── Events/                     # shared domain events (Payment.Confirmed, etc.)

# Backend routes
routes/community-services.php       # all /v1/community/* routes

# Backend OpenAPI
openapi/v1/community-services.yaml  # master file referencing contract fragments

# Admin panel — Next.js (wallet-ecosystem/apps/admin/src/modules/)
community-services/
├── organisations/        # HZ02 org list/detail/review/verify
├── memberships/          # HZ03 staff management
├── consent/              # HZ04 notice management, DSARs
├── catalogue/            # HZ05 service enablement
├── orders/               # HZ06 order search/detail/escalation
├── receipts/             # HZ07 receipt search/export
├── refunds/              # HZ08 refund policy config, request review
├── partners/             # HZ12 connector registry, health
└── approvals/            # HZ13 approval inbox, policy versioning

# Customer mobile — Flutter (wallet-ecosystem/apps/customer/lib/modules/)
community_home/           # HZ14 bottom nav shell + Home tile
community_membership/     # HZ03 community discovery, join, status
community_consent/        # HZ04 privacy notice, preferences, DSAR
community_catalogue/      # HZ05 service browsing
community_order/          # HZ06 checkout flow, payment, pending state
community_receipt/        # HZ07 receipt view, download
community_support/        # HZ11 raise case, track status

# Tests
wallet-ecosystem/services/api/tests/
├── Feature/CommunityServices/     # feature tests per module
└── Unit/CommunityServices/        # unit tests per domain service
```

**Structure Decision**: Uses Option 3 variant (multi-tier API + Admin SPA + Mobile). All new backend code under `app/Modules/CommunityServices/` following the existing Application/Domain/Infrastructure sub-structure used by Wallet, Notification and other mature modules. Admin uses the existing flat-module pattern under `src/modules/community-services/`. Flutter follows the existing per-feature module pattern under `lib/modules/`.

## Complexity Tracking

No constitution violations. No complexity justification required.

## Implementation Phases

### Phase A — Schema and Core Contracts (HZ02, HZ03, HZ06 backend)
Foundation tables: organisations, memberships, invitations, orders, payment_attempts. Core tenant-isolation scope. `PaymentOrchestrator` wrapping existing Payment module. Idempotency guard.

### Phase B — Financial Safety Layer (HZ07, HZ08, HZ13 backend)
Receipts, refunds, dispute holds, maker-checker approval service, policy versioning. Double-entry compensating ledger entries via existing Ledger module.

### Phase C — Platform Controls (HZ04, HZ05, HZ12 backend)
Consent records, versioned notices, data-rights workflows. Service catalogue with jurisdiction gates. Partner connector registry, adapter contract interface, webhook ingress with signature verification.

### Phase D — Observability Hooks (HZ09, HZ10, HZ11 backend)
Notification job enqueue via transactional outbox (extends existing E17 outbox). Dashboard/report query layer (extends existing E24 reporting). Audit record emission (extends existing E23 audit).

### Phase E — Admin Panel (HZ02, HZ03, HZ05, HZ06, HZ08, HZ12, HZ13 admin)
Next.js admin modules: organisation onboarding, staff management, service catalogue, order review, refund review, partner connectors, approval inbox.

### Phase F — Consumer Mobile Shell (HZ14, HZ03, HZ06, HZ07, HZ11 mobile)
Flutter bottom navigation update (add Community, Events tabs). Community discovery/join flow. Checkout flow reuse. Receipt view. Support case creation.

### Phase G — Concurrency, Security and Acceptance Tests
Tenant-isolation negative tests (AT01). Payment replay and idempotency tests (AT02). Refund race tests (AT03). Maker-checker race tests (AT07). Webhook signature and replay tests. WCAG automated checks.
