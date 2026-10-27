# Research: P03 — Tenant Isolation and Payment Permissions

**Date**: 2026-10-21 | **Status**: Complete

---

## Decision 1: Gate vs. Route Middleware for Permission Enforcement

**Decision**: Use ONLY `identity.require-permission` route middleware for permission enforcement. Remove the redundant `$this->authorize()` calls from the three admin payment controllers.

**Rationale**: `RequirePermissionMiddleware` → `AuthorizationGuard` → `PermissionResolverService` is the correct E04 enforcement path. Laravel's `Gate` is NOT wired to `PermissionResolverService` (no `Gate::before` or `Gate::define` callback exists in any ServiceProvider). The `$this->authorize()` calls in `PaymentProviderAdminController`, `ReconciliationExceptionAdminController`, and `RefundService` call the Laravel Gate, which has no registered ability for any `payment.*` permission — meaning these calls would always throw `AuthorizationException` if reached without being pre-blocked by route middleware. Since route middleware already enforces the same permission before the controller is reached, removing the duplicate `$this->authorize()` calls eliminates a dead code path with no security reduction.

**Alternatives considered**:
- Register a `Gate::before` callback that proxies to `PermissionResolverService`: Rejected — creates a second enforcement path that must stay in sync with the route middleware configuration; the middleware is simpler and already correct.
- Keep `$this->authorize()` and also register the Gate: Rejected — two separate enforcement mechanisms for the same permission create drift risk; belt-and-suspenders here is a liability not an asset.

---

## Decision 2: Extend `PaymentPermissionRoleSeeder` vs. Create a New Seeder

**Decision**: Extend the existing `PaymentPermissionRoleSeeder` (T149 from E48) to add the 5 new permissions. Do NOT create a separate P03 seeder.

**Rationale**: `PaymentPermissionRoleSeeder` already uses idempotent upsert on `slug`, making additions safe on re-run. Splitting into two seeders (E48 + P03) creates an ordering dependency and risks divergence. All 14 payment.* permissions belong in one canonical seeder for the payment module.

**Additions required**:
- `payment.config.draft` — Submit a high-risk provider configuration change for approval
- `payment.payouts.view` — View payout records (P18 readiness)
- `payment.payouts.manage` — Initiate and manage payouts (P18 readiness)
- `payment.callbacks.receive` — System-level permission for the callback processing path (not assignable to user roles)
- `payment.exports.view` — Export payment data (audit/compliance)

**Role assignments to add**:
- `agent` role: `payment.intents.read`, `payment.intents.manage`, `payment.refunds.request` (FR-B01 assumption — override when FD01 available)
- `admin` role: add `payment.config.draft`, `payment.payouts.view`, `payment.payouts.manage`, `payment.exports.view` (callbacks.receive is system-only, NOT in any user role)
- `admin-officer` role: add `payment.exports.view` (read-only access)

---

## Decision 3: Storage for Config Change Drafts

**Decision**: New `payment.payment_config_change_drafts` table in the `payment.*` PostgreSQL schema. Do NOT reuse the Platform Configuration `configuration_change_requests` table (E28).

**Rationale**: E28's `configuration_change_requests` table stores key-value platform configuration changes (fee thresholds, limits, etc.). Payment provider config changes are structured objects (provider credentials, routing) with different validation semantics. Mixing them conflates two distinct bounded contexts. The `payment.*` schema owns payment provider configuration per module boundary principles (P7).

**Alternatives considered**:
- Reuse E28 `ConfigurationChangeRequestRepositoryInterface`: Rejected — the E28 interface is designed for flat key-value pairs; payment provider config changes are structured (provider_id FK, change_payload JSON). Forcing them into a key-value model requires awkward serialization and loses type safety.

---

## Decision 4: Step-Up Check Mechanism

**Decision**: Inject `ActorStepUpVerifierContract` (bound to `StepUpService`) into `PaymentConfigDraftService` and `RefundService`. Call `verifyStepUp(string $actorId, string $correlationId): bool` before approving a refund above threshold or rotating credentials.

**Rationale**: `ActorStepUpVerifierContract` is the declared cross-module interface for step-up verification (E03/E01). `StepUpService` reads the session-level step-up token. Reading JWT claims directly would bypass the session revocation check (E03 concern).

**Threshold resolution**: `ConfigurationServiceInterface::getOrDefault('auth.step_up_threshold', '10000')` returns the threshold in BWP minor units. At runtime, the value is compared against `amount_minor` for refund approvals. For credential rotation, step-up is always required regardless of amount (operation type, not amount-gated).

---

## Decision 5: Self-Approval Prevention Implementation

**Decision**: Store `drafted_by_actor_id` in `payment_config_change_drafts` and `requested_by_actor_id` in `payment_refunds`. At approval time, compare the approver's `actor_id` (from the authenticated session, NOT from the request body) against the stored drafter/requester. If equal, throw `SelfApprovalNotAllowedException` → 403 with `code: SELF_APPROVAL_NOT_ALLOWED`.

**Rationale**: The drafter/requester actor_id is the primary key for self-approval prevention. It must be stored server-side at draft/request creation time and compared server-side at approval time. Never trust the request body for actor identity.

---

## Decision 6: Org-Scope Extension

**Decision**: Apply `PaymentOrganisationScopeMiddleware` to the refund routes by routing them through a common middleware group that already includes this middleware. For reconciliation admin routes, add an explicit org-scope check in `ReconciliationExceptionAdminController::index()` using the actor's `organisation_id` attribute.

**Rationale**: `PaymentOrganisationScopeMiddleware` works by resolving an intent from the `{intentId}` route parameter. Since refund routes include `{intentId}`, the same middleware covers them automatically. Reconciliation exceptions have their own route parameter (`{id}`), requiring a different org-scope check in the controller.

---

## Decision 7: Callback Provider Validation

**Decision**: Add provider validation in `PaymentCallbackController::store()` before dispatching `PaymentCallbackProcessingJob`. Query `payment_providers` WHERE `code = {providerCode}` AND `is_enabled = true`. If no active provider found, return 404 and log `payment.callback.unknown_provider` with the providerCode and source IP (hashed for privacy).

**Rationale**: Currently `PaymentCallbackController` accepts any `{providerCode}` without validation — an attacker can probe the endpoint with arbitrary provider codes, wasting processing resources. The validation is a guard clause only; no financial state changes on unknown providerCode.

**Alternatives considered**:
- Move validation into `PaymentCallbackProcessingJob` (async): Rejected — the job runs asynchronously; by the time it detects an invalid provider, the callback has already been stored in `payment_callbacks`. Fail-fast at the HTTP layer is cleaner.
