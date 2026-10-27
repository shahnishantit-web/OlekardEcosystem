# Research: P20 — Admin Provider Configuration and Policy Screens

## RD-001: Existing Admin Component Patterns

**Question**: What component patterns exist in the admin app that P20 should follow?

**Decision**: Reuse the following existing patterns:
- `'use client'` directive for interactive pages; server components for layout shells
- `useAuth()` hook from `@/lib/auth/context` to get `authenticatedClient` and `hasPermission()`
- `PageContainer` + `PageHeader` layout wrappers
- `Table`, `Badge`, `Button`, `Loading`, `ErrorState` from `@/components/ui/`
- API client pattern: `new SomeApiClient(client)` from `@/lib/api/`
- `useEffect` + `useState` for data fetching in client components (existing pattern in `integration-list.tsx`)

**Why**: All existing admin screens use this pattern. Deviating requires ADR per constitution P14. The P12 bank screens (`payments/banks/`) use the same pattern and are the most directly analogous.

**Note on SWR**: The existing `integration-list.tsx` uses raw `useEffect` + `useState`. P20 follows this same pattern for consistency rather than introducing SWR/React Query which would require ADR approval.

---

## RD-002: Reusable P12 Components

**Question**: Which P12 bank integration components can P20 reuse vs. create new?

**Decision**: The following components from `app/(admin)/payments/banks/components/` are usable by P20 but are currently local to the `banks` module. P20 will create parallel components in `src/modules/payment/components/` rather than moving the P12 components (to avoid breaking P12 screens):

| P12 Component | P20 Parallel | Notes |
|--------------|-------------|-------|
| `BankStatusBadge` | `ProviderStatusBadge` | Different status set: `draft / pending_approval / active / suspended / retired` |
| `SecretReferenceField` | Reuse directly (import from P12 path) | Same contract; `callback_secret_reference` VO already masks value |
| `LifecyclePanel` | `ProviderLifecycleControls` | Different lifecycle states and transition actions |
| `ApiOperationEditor` | Not used in P20 | P10-specific |

**Why**: P12 components are not in a shared module; moving them risks breaking P12 screens without test coverage. Import `SecretReferenceField` directly as it has no P12-specific assumptions.

---

## RD-003: Account Lifecycle Status Values

**Question**: What is the complete lifecycle status machine for `PaymentProviderAccount`?

**Decision**: From `PaymentProviderAccountModel.account_lifecycle_status` and the P12 `AccountLifecycleStatus` type, the P20-relevant states are:

```
draft → pending_approval → active ⇄ suspended → retired
                        ↘ rejected (back to draft)
```

| Status | Description | Blocks new attempts? |
|--------|-------------|----------------------|
| `draft` | Being configured; not yet submitted | Yes |
| `pending_approval` | Submitted; waiting for approver | Yes |
| `active` | Live and accepting new attempts | No |
| `suspended` | Manually suspended; in-flight continues | Yes (new only) |
| `retired` | Permanently decommissioned | Yes |
| `rejected` | Rejected by approver; must re-draft | Yes |

**Valid lifecycle transitions (UI-driven)**:
- `draft → pending_approval` (via "Submit for Approval" draft action — existing endpoint)
- `pending_approval → active` (via approve draft — existing endpoint)
- `pending_approval → rejected` (via reject draft — existing endpoint)
- `active → suspended` (via `POST /lifecycle` — new endpoint)
- `suspended → active` (via `POST /lifecycle` — new endpoint)
- `active → retired` (via `POST /lifecycle` — new endpoint, confirmation dialog required)

---

## RD-004: Module Availability Entity

**Question**: Does a `PaymentModuleConfig` DB entity exist, or is module availability stored as platform config?

**Decision**: The `enabled_modules` field on `PaymentProviderAccount` is a per-account setting (which modules this account services). A global "is this payment module available at all" flag does not yet exist as a DB entity. The `MODULE_NOT_ENABLED` eligibility reason is triggered when `enabled_modules` on an account excludes the requested module.

**Resolution for P20**: P20 creates a `payment.payment_module_configs` table with columns: `id`, `module_key`, `is_enabled`, `display_order`, `description`, `updated_by_actor_id`, `draft_payload` (jsonb, nullable), `draft_status`, timestamps. Module availability changes go through the same `payment.payment_config_change_drafts` workflow as account changes, using `change_type = 'module_config'`.

**Why**: This is the minimal approach that integrates with the existing draft/approve workflow without inventing a competing system. Documented as provisional pending formal product spec.

---

## RD-005: Draft Diff Display

**Question**: How should the "diff" between current config and proposed draft be rendered?

**Decision**: The `change_payload` field on `PaymentConfigChangeDraftModel` stores the proposed changes (field → new value). The UI renders a two-column table: "Current" vs. "Proposed". Secret reference fields show masked values in both columns. The diff is built client-side by comparing the live account's fields against `change_payload`.

**Format**:
```
| Field              | Current Value | Proposed Value |
|--------------------|---------------|----------------|
| max_amount_minor   | 1,000,000     | 5,000,000      |
| enabled_currencies | BWP, USD      | BWP, USD, ZAR  |
```

**Why**: Server-side diff computation is possible but adds backend complexity. Client-side diff from existing data is simpler and sufficient for the approval workflow.

---

## RD-006: Environment Badge Derivation

**Question**: How is the environment (`sandbox` / `production` / `mock`) determined for the badge?

**Decision**: `PaymentProvider.environment` field (`sandbox | production | mock`). The badge visual treatment follows design token conventions:
- `sandbox` → yellow/warning badge (`variant="warning"`)
- `mock` → gray/neutral badge (`variant="neutral"`)
- `production` → red/danger badge (`variant="error"`) with text "PRODUCTION — Live traffic"

**Why**: Prevents operators from activating mock accounts in production mistakenly. The `production` badge is styled as a danger variant (not just informational) to make the consequence visible before action.

---

## RD-007: Permission Mapping

**Question**: Which exact permissions gate P20 screens?

**Decision** (from P03 seeded permissions and existing routes):

| Screen / Action | Required Permission |
|----------------|-------------------|
| View Provider Registry | `payment.providers.read` |
| Edit account config (non-high-risk) | `payment.providers.manage` |
| Submit for approval | `payment.config.draft` |
| Approve / Reject draft | `payment.config.approve_high_risk` |
| Suspend / Restore account | `payment.providers.manage` |
| Rotate credential reference | `payment.config.approve_high_risk` |
| View approval queue | `payment.providers.read` |
| Edit module config | `payment.providers.manage` |

**Why**: Matches existing route middleware in `routes/modules/payment.php` (lines 82–123). The `hasPermission()` hook in `useAuth()` is the client-side gate; server-side middleware is authoritative.

---

## RD-008: API Client Patterns for New Endpoints

**Question**: How should P20 extend `lib/api/payment.ts`?

**Decision**: Add new methods to `PaymentApiClient` class following the existing pattern. New methods needed:

```typescript
// Account endpoints
listProviderAccounts(providerId: string): Promise<ApiResponse<{data: PaymentProviderAccount[]}>>
getProviderAccount(providerId: string, accountId: string): Promise<ApiResponse<{data: PaymentProviderAccount}>>
updateProviderAccount(providerId: string, accountId: string, payload: UpdateAccountPayload): Promise<ApiResponse<{data: PaymentProviderAccount} | HighRiskApprovalRequired>>
transitionAccountLifecycle(providerId: string, accountId: string, payload: LifecycleTransitionPayload): Promise<ApiResponse<{data: PaymentProviderAccount}>>

// Draft endpoints
listConfigDrafts(providerId: string): Promise<ApiResponse<{data: ConfigDraft[]}>>
getConfigDraft(providerId: string, draftId: string): Promise<ApiResponse<{data: ConfigDraft}>>

// Module config
getModuleConfigs(): Promise<ApiResponse<{data: ModuleConfig[]}>>
updateModuleConfigs(payload: UpdateModuleConfigPayload): Promise<ApiResponse<{data: ModuleConfig[]} | HighRiskApprovalRequired>>
```

**Why**: Consistent with the established `PaymentApiClient` pattern. All in one file to keep import paths simple.

---

## RD-009: Idempotency for Lifecycle Transitions

**Question**: Do lifecycle transitions (activate/suspend) require Idempotency-Key?

**Decision**: Lifecycle transitions are not financial mutations; they are configuration transitions. Following the existing pattern for `POST /providers/{id}/suspend` (no idempotency required), P20's lifecycle endpoint does not require `Idempotency-Key`. However, it is guarded against duplicate submissions by checking the current status before applying the transition (returns `409 INVALID_TRANSITION` if already in target state).

**Why**: Consistent with existing `suspend` endpoint behaviour. Config changes that require idempotency keys are only the draft-store endpoint (already implemented with `idempotency:require` middleware).
