# Implementation Plan: P03 — Tenant Isolation and Payment Permissions

**Branch**: `052-tenant-payment-permissions` | **Date**: 2026-10-21 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/052-tenant-payment-permissions/spec.md`

---

## Summary

P03 closes six authorization gaps in the E48 Payment module:
1. Extends the existing `PaymentPermissionRoleSeeder` to add 5 missing permissions (config.draft, payouts.view, payouts.manage, callbacks.receive, exports.view).
2. Adds `payment.config.draft` route enforcement for high-risk field submissions.
3. Implements a `payment_config_change_drafts` table + maker-checker service (drafter ≠ approver).
4. Adds `approved_by` / `approved_at` / `rejected_by` / `rejected_at` columns on `payment_refunds`; introduces a refund approval route with self-approval guard.
5. Wires `ActorStepUpVerifierContract` into refund approval and credential rotation to enforce the runtime-configurable `auth.step_up_threshold`.
6. Extends `PaymentOrganisationScopeMiddleware` to refund and reconciliation routes; adds callback provider validation.

No changes to wallet balances, ledger entries, or the financial state machine. All schema changes are additive.

---

## Technical Context

**Language/Version**: PHP 8.4+ / Laravel (existing stack)

**Primary Dependencies**:
- E04 RBAC: `identity.require-permission` route middleware → `RequirePermissionMiddleware` → `AuthorizationGuard` → `PermissionResolverService` (Redis-cached)
- E03 Step-Up: `ActorStepUpVerifierContract` (bound to `StepUpService`) — verify step-up token in current session
- E28 Platform Configuration: `ConfigurationServiceInterface::getOrDefault('auth.step_up_threshold', '10000')` — runtime-configurable threshold read at operation time
- E48 Payment module: existing `PaymentOrganisationScopeMiddleware`, `PaymentPermissionRoleSeeder`, `RefundService`, `PaymentProviderAdminService`

**Storage**: PostgreSQL
- `identity.*` schema: add 5 permission rows via updated seeder (upsert — safe on re-run)
- `payment.*` schema: new `payment_config_change_drafts` table; additive columns on `payment_refunds`

**Testing**: PHPUnit (unit + feature tests)

**Target Platform**: Linux server (Docker)

**Project Type**: Web service — Laravel module extension (no new modules)

**Performance Goals**: Standard API response times; no new expensive queries. Permission cache (`auth.permission_cache_ttl`) covers all RBAC lookups.

**Constraints**:
- All schema changes MUST be additive — no column drops, renames, or constraint tightening on existing tables
- Do NOT invent new identity credentials or new org model
- The `$this->authorize()` calls in the existing admin controllers are NOT wired to E04 RBAC (Gate is not connected to `PermissionResolverService`). ONLY the `identity.require-permission` route middleware enforces E04 permissions. The redundant `$this->authorize()` calls MUST be removed from the three admin payment controllers as part of P03 (see research.md).
- `ActorStepUpVerifierContract` is the only correct interface to check step-up status — do not read JWT claims directly
- Config change draft approval MUST be idempotent: re-approving an already-approved draft must return 409, not process twice

---

## Constitution Check

| Principle | Applies | Status |
|-----------|---------|--------|
| P4 — Financial Integrity | Yes — step-up threshold read from config, never hard-coded | ✓ PASS |
| P5 — Identity & Authorization | Yes — step-up via `ActorStepUpVerifierContract`; server-side RBAC via E04 middleware | ✓ PASS |
| P6 — Zero Trust | Yes — provider credentials NEVER in responses or logs; all routes authenticated | ✓ PASS |
| P7 — Domain Boundaries | Yes — `PaymentConfigDraftService` lives in Payment Application layer; does not directly access identity.* tables | ✓ PASS |
| P8 — API Contracts | Yes — new routes documented in OpenAPI with `x-required-permission`; idempotency_key on financial-mutation refund approve | ✓ PASS |
| P9 — Event Reliability | Low risk — no new domain events in P03 scope; existing events unchanged | ✓ PASS |
| P12 — Test-First Quality | Yes — negative tests mandatory (FR-10) | ✓ PASS |
| P13 — Observability | Yes — all auth state changes audited via existing `AuditService` | ✓ PASS |

**Gate result**: PASS. No constitution violations.

---

## Project Structure

### Documentation (this feature)

```text
specs/052-tenant-payment-permissions/
├── plan.md              # This file
├── research.md          # Phase 0 output
├── data-model.md        # Phase 1 output
├── quickstart.md        # Phase 1 output
├── contracts/           # Phase 1 output
└── tasks.md             # /speckit-tasks output (not yet created)
```

### Source Code

```text
wallet-ecosystem/services/api/
├── app/Modules/Payment/
│   ├── Domain/
│   │   ├── Contracts/
│   │   │   └── PaymentConfigChangeDraftRepositoryInterface.php  [NEW]
│   │   ├── Enums/
│   │   │   └── ConfigChangeDraftStatus.php                      [NEW]
│   │   └── Exceptions/
│   │       └── SelfApprovalNotAllowedException.php              [NEW]
│   ├── Application/
│   │   └── Services/
│   │       └── PaymentConfigDraftService.php                    [NEW]
│   ├── Infrastructure/
│   │   ├── Migrations/
│   │   │   ├── 2026_10_21_000004_create_payment_config_change_drafts.php  [NEW]
│   │   │   └── 2026_10_21_000005_add_approval_columns_to_payment_refunds.php  [NEW]
│   │   ├── Models/
│   │   │   └── PaymentConfigChangeDraftModel.php                [NEW]
│   │   └── Repositories/
│   │       └── EloquentPaymentConfigChangeDraftRepository.php   [NEW]
│   └── Http/
│       ├── Controllers/
│       │   └── PaymentRefundController.php                      [MODIFY — remove $this->authorize(); add step-up check]
│       ├── Controllers/Admin/
│       │   ├── PaymentProviderAdminController.php               [MODIFY — remove $this->authorize(); add config.draft route]
│       │   ├── PaymentConfigChangeDraftAdminController.php      [NEW]
│       │   └── RefundApprovalAdminController.php                [NEW]
│       └── Middleware/
│           └── PaymentOrganisationScopeMiddleware.php           [MODIFY — extend to refund + reconciliation routes]
├── database/
│   └── seeders/
│       └── PaymentPermissionRoleSeeder.php                      [MODIFY — add 5 missing permissions + agent role]
└── routes/
    └── modules/
        └── payment.php                                          [MODIFY — add config draft, refund approval routes]

openapi/v1/
└── payment.yaml                                                 [MODIFY — add new routes with x-required-permission]
```

---

## Complexity Tracking

> No constitution violations requiring justification.
