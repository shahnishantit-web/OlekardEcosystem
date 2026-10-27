---
description: "Task list for P20 — Admin Provider Configuration and Policy Screens"
---

# Tasks: P20 — Admin Provider Configuration and Policy Screens

**Input**: Design documents from `specs/067-admin-provider-config-screens/`

**Prerequisites**: plan.md ✅, spec.md ✅, research.md ✅, data-model.md ✅, contracts/ ✅, quickstart.md ✅

> **Note**: Tasks updated post-`/speckit-analyze` — three findings remediated: I1 (permission name `view`→`read`), I2 (module config uses draft/approve workflow per FR-006/FR-014), C1 (T047 accessibility task added).

**Tech stack**: Next.js 14+ (TypeScript 5.x) + Laravel/PHP 8.4+; Vitest + React Testing Library (frontend); PHPStan level 8 (backend)

**Source code roots**:
- Backend: `wallet-ecosystem/services/api/`
- Frontend: `wallet-ecosystem/apps/admin/`

---

## Phase 1: Setup

**Purpose**: Verify predecessor evidence before writing a single line of code.

- [X] T001 Verify P03/P05 prerequisites: confirm migration `wallet-ecosystem/services/api/database/migrations/2026_10_21_000020_seed_payment_permissions.php` exists; confirm `payment_provider_accounts` table in P05 migration; confirm `PaymentConfigChangeDraftAdminController.php` and `PaymentProviderAdminController.php` are present in `wallet-ecosystem/services/api/app/Modules/Payment/Http/Controllers/Admin/`

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Backend migration, models, services, controllers, routes, and TypeScript client foundations that every user story depends on.

**⚠️ CRITICAL**: No user story work can begin until this phase is complete.

- [X] T002 Create migration `wallet-ecosystem/services/api/database/migrations/2026_10_26_000027_create_payment_module_configs_table.php`: `payment.payment_module_configs` schema with UUID v7 PK, `module_key VARCHAR(100) UNIQUE NOT NULL`, `display_name VARCHAR(128) NOT NULL`, `is_enabled BOOLEAN DEFAULT TRUE NOT NULL`, `display_order INTEGER DEFAULT 0 NOT NULL`, `description TEXT NULL`, `updated_by_actor_id VARCHAR(100) NULL`, `created_at`/`updated_at TIMESTAMPTZ`; index on `module_key`; seed known module keys from `config('payment.known_modules', [])` with `is_enabled=true` and `display_order` matching array index

- [X] T003 Create `wallet-ecosystem/services/api/app/Modules/Payment/Infrastructure/Models/PaymentModuleConfigModel.php` Eloquent model for `payment.payment_module_configs` table: `$connection = 'payment'`, `$table = 'payment_module_configs'`, fillable fields from data-model.md, casts (`is_enabled` → bool, `display_order` → int), UUID v7 generation via `HasUuids` trait

- [X] T004 Create `wallet-ecosystem/services/api/app/Modules/Payment/Application/Services/PaymentModuleConfigService.php` with `list(): Collection` (ordered by `display_order`) and `submitDraft(array $updates, string $actorId): PaymentConfigChangeDraftModel` (creates a `PaymentConfigChangeDraft` with `change_type = 'module_config'` and `change_payload` containing the proposed updates; does NOT write to `payment_module_configs` directly — changes only persist after approval via the existing `PaymentConfigChangeDraftAdminController@approve`); `applyApprovedDraft(PaymentConfigChangeDraftModel $draft): void` (iterates `change_payload`, upserts `payment_module_configs` rows, sets `updated_by_actor_id`)

- [X] T005 Extend `wallet-ecosystem/services/api/app/Modules/Payment/Application/Services/PaymentProviderAdminService.php` with: `listAccounts(string $providerId): Collection` (queries `PaymentProviderAccountModel` by `provider_id`); `getAccount(string $providerId, string $accountId): PaymentProviderAccountModel`; `updateAccount(string $providerId, string $accountId, array $payload, string $actorId, int $configVersion): array` (splits high-risk vs non-high-risk fields per plan.md; non-high-risk → direct save + increment `config_version`; high-risk → create draft via existing draft service + return `requires_approval: true, draft_id`; throws `ConfigVersionConflictException` if `config_version` mismatch); `transitionLifecycle(string $providerId, string $accountId, string $action, string $reason, string $actorId): PaymentProviderAccountModel` (validates transition via state machine in data-model.md; throws `InvalidLifecycleTransitionException` if invalid; persists new status + timestamp; writes audit record via `AuditServiceInterface`)

- [X] T006 Create `wallet-ecosystem/services/api/app/Modules/Payment/Http/Controllers/Admin/ProviderAccountAdminController.php` with four thin methods: `index(Request $request, string $providerId)` → lists accounts via service; `show(Request $request, string $providerId, string $accountId)` → single account with masked `callback_secret_reference`; `update(Request $request, string $providerId, string $accountId)` → delegates to service, returns 200 (non-high-risk) or 202 + draft_id (high-risk) or 409 (version conflict); `lifecycle(Request $request, string $providerId, string $accountId)` → validates `action` and `reason` fields, delegates to service, returns 200 or 409 (invalid transition); no business logic in controller

- [X] T007 Create `wallet-ecosystem/services/api/app/Modules/Payment/Http/Controllers/Admin/ProviderConfigDraftListAdminController.php` with two thin methods: `index(Request $request, string $providerId)` → paginates `PaymentConfigChangeDraftModel` filtered by `provider_id` and optional `status` query param; `show(Request $request, string $providerId, string $draftId)` → single draft with masked diff (redact keys in `HIGH_RISK_FIELDS = ['merchant_account_reference', 'callback_secret_reference', 'settlement_destination']` from `change_payload`); both require `payment.providers.read` permission

- [X] T008 [P] Create `wallet-ecosystem/services/api/app/Modules/Payment/Http/Controllers/Admin/PaymentModuleConfigAdminController.php` with `index()` → delegates to `PaymentModuleConfigService::list()` (returns live approved config); `update(Request $request)` → validates `modules` array, calls `PaymentModuleConfigService::submitDraft()`, returns 202 `{ requires_approval: true, draft_id: "..." }` (same pattern as high-risk account fields); `index` requires `payment.providers.read`, `update` requires `payment.providers.manage`

- [X] T009 Register 8 new admin routes in `wallet-ecosystem/services/api/routes/modules/payment.php` inside the existing `payment.admin` middleware group: `GET providers/{id}/accounts` → `ProviderAccountAdminController@index`; `GET providers/{id}/accounts/{accountId}` → `ProviderAccountAdminController@show`; `PATCH providers/{id}/accounts/{accountId}` → `ProviderAccountAdminController@update`; `POST providers/{id}/accounts/{accountId}/lifecycle` → `ProviderAccountAdminController@lifecycle`; `GET providers/{id}/config-changes` → `ProviderConfigDraftListAdminController@index`; `GET providers/{id}/config-changes/{draftId}` → `ProviderConfigDraftListAdminController@show`; `GET config/modules` → `PaymentModuleConfigAdminController@index`; `PATCH config/modules` → `PaymentModuleConfigAdminController@update`

- [X] T010 Add TypeScript types to `wallet-ecosystem/apps/admin/lib/api/payment.ts`: `AccountLifecycleStatus`, `LifecycleAction`, `ConfigDraftStatus`, `ConfigDraft`, `ModuleConfig`, `UpdateAccountPayload`, `LifecycleTransitionPayload`, `UpdateModuleConfigPayload`, `HighRiskApprovalRequired` — exact definitions from `specs/067-admin-provider-config-screens/data-model.md` TypeScript types section

- [X] T011 Add `PaymentApiClient` methods to `wallet-ecosystem/apps/admin/lib/api/payment.ts` following the existing `useEffect`+`useState` pattern (RD-001): `listProviderAccounts(providerId)`, `getProviderAccount(providerId, accountId)`, `updateProviderAccount(providerId, accountId, payload)` (returns typed union of `{data: PaymentProviderAccount}` | `HighRiskApprovalRequired`), `transitionAccountLifecycle(providerId, accountId, payload)`, `listConfigDrafts(providerId, params?)`, `getConfigDraft(providerId, draftId)`, `getModuleConfigs()`, `updateModuleConfigs(payload)` — all 8 methods per `specs/067-admin-provider-config-screens/research.md` RD-008

- [X] T012 [P] Create `wallet-ecosystem/apps/admin/src/modules/payment/api/providerConfig.ts`: thin re-export wrapper of the 8 new `PaymentApiClient` methods from `lib/api/payment.ts` with typed return helpers for the union response from `updateProviderAccount` (helper: `isHighRiskApprovalRequired(res): res is HighRiskApprovalRequired`)

- [X] T013 [P] Create `wallet-ecosystem/apps/admin/src/modules/payment/components/AccountLifecycleStatusBadge.tsx`: renders `AccountLifecycleStatus` with colour variants — `active` → success/green, `suspended` → warning/orange, `draft` → neutral/grey, `pending_approval` → info/blue, `retired` → neutral/dark, `rejected` → error/red; accepts `status: AccountLifecycleStatus` prop; uses `Badge` from `@/components/ui/Badge`

- [X] T014 [P] Create `wallet-ecosystem/apps/admin/src/modules/payment/components/EnvironmentBadge.tsx`: renders provider environment field with distinct visual treatment — `sandbox` → `variant="warning"` label "SANDBOX", `mock` → `variant="neutral"` label "MOCK", `production` → `variant="error"` label "PRODUCTION — Live traffic" (per RD-006); accepts `environment: 'sandbox' | 'mock' | 'production'` prop; badge is derived from field, not URL

**Checkpoint**: All backend controllers registered, TypeScript client extended, shared display components available — user story work can now proceed in parallel.

---

## Phase 3: User Story 1 — Browse Provider Registry (Priority: P1) 🎯 MVP

**Goal**: Operators can browse all providers and their accounts with status badges, filtering, and access-denied gating.

**Independent Test**: Navigate to `/payments/providers` with a user that has `payment.providers.read`; assert list renders with `ProviderStatusBadge` and `EnvironmentBadge` on each row. Navigate with a user without `payment.providers.read`; assert access-denied state renders.

- [X] T015 [P US1] Create `wallet-ecosystem/apps/admin/src/modules/payment/hooks/useProviders.ts`: `useEffect`+`useState` hook wrapping `PaymentApiClient.listProviders()` with loading/error/data states; optional `statusFilter?: string` and `adapterFilter?: string` params passed as query parameters; returns `{ providers, loading, error, refetch }`

- [X] T016 [US1] Create `wallet-ecosystem/apps/admin/app/(admin)/payments/providers/page.tsx`: `'use client'` directive; reads `payment.providers.read` from `useAuth().hasPermission()`; if missing, renders `ErrorState` access-denied component; otherwise loads `useProviders` and renders a `Table` of providers with columns: name, `EnvironmentBadge`, active account count, `ProviderStatusBadge`; filter controls for status and adapter; loading skeleton and outage `ErrorState` with retry; empty state when no providers

- [X] T017 [P US1] Create `wallet-ecosystem/apps/admin/app/(admin)/payments/providers/[providerId]/page.tsx`: `'use client'` directive; loads provider detail and its accounts via `listProviderAccounts(providerId)`; renders `EnvironmentBadge` for the provider; renders account list with `ProviderStatusBadge` per account; empty state "No accounts configured" when account list is empty; links each account row to `/payments/providers/[providerId]/accounts/[accountId]`

- [X] T018 [US1] Add `payment.providers.read` permission gate to both registry pages (T016, T017): wrap main content in a permission check using `hasPermission('payment.providers.read')`; if missing, render `<ErrorState type="forbidden" message="You don't have access to the Provider Registry." />`; validate the gate renders correctly for a user with `payment.providers.read` only (read-only; edit controls absent)

**Checkpoint**: Provider Registry list and detail screens independently browsable; access control verified.

---

## Phase 4: User Story 2 — Configure Provider Account (Priority: P1)

**Goal**: Operators can edit account config via a draft form; high-risk fields route to approval; secrets are always masked; no `callback_url` input exists.

**Independent Test**: Load account detail; confirm `callback_url` input absent from DOM; confirm `callback_secret_reference` renders masked; edit `max_amount_minor`, save → 200; confirm diff preview shows old vs new; submit `merchant_account_reference` → 202; confirm approval-pending banner.

- [X] T019 [P US2] Create `wallet-ecosystem/apps/admin/src/modules/payment/hooks/useProviderAccount.ts`: `useEffect`+`useState` hook wrapping `getProviderAccount(providerId, accountId)` and exposing `updateAccount(payload)` action that calls `updateProviderAccount`; surfaces `{ account, loading, error, updating, updateResult, refetch }` where `updateResult` distinguishes `{type:'saved', data}` | `{type:'requires_approval', draft_id, high_risk_fields}` | `{type:'conflict'}` | `{type:'error'}`

- [X] T020 [P US2] Create `wallet-ecosystem/apps/admin/src/modules/payment/components/ProviderAccountForm.tsx`: controlled form with all `UpdateAccountPayload` fields; `callback_url` input MUST NOT appear; `callback_secret_reference` renders masked value (`••••••••`) + "Change reference" button using `SecretReferenceField` imported from `wallet-ecosystem/apps/admin/app/(admin)/payments/banks/components/SecretReferenceField.tsx` (RD-002); `adapter_identifier` renders as `<select>` populated from `adapterOptions: string[]` prop (server allowlist; no free-text entry per FR-004); read-only mode when user lacks `payment.providers.manage`; includes `config_version` as hidden field for optimistic-lock

- [X] T021 [P US2] Create `wallet-ecosystem/apps/admin/src/modules/payment/components/ConfigDiffPreview.tsx`: two-column table (Current | Proposed) built from a `diff: Record<string, {current: unknown; proposed: unknown}>` prop; fields listed in `HIGH_RISK_FIELDS = ['merchant_account_reference', 'callback_secret_reference', 'settlement_destination']` display `••••` in both columns (never raw value); JSONB array values rendered as comma-separated lists; integer amount fields displayed as formatted currency units alongside raw minor value

- [X] T022 [US2] Create `wallet-ecosystem/apps/admin/app/(admin)/payments/providers/[providerId]/accounts/[accountId]/page.tsx`: `'use client'` directive; loads account via `useProviderAccount`; renders `ProviderStatusBadge`, `EnvironmentBadge`, `config_version` display, `ProviderAccountForm`; on save: 200 → "Changes saved" toast + refetch; 202 → approval-pending banner "High-risk changes submitted for approval" showing `high_risk_fields` + link to approval queue; 409 version conflict → "Previous approval invalidated — submit for re-approval" warning (FR-008); loading/error/empty states

**Checkpoint**: Account detail form fully functional with draft save, masked secrets, no callback_url, version conflict handling.

---

## Phase 5: User Story 3 — Maker-Checker Approval Flow (Priority: P1)

**Goal**: Submitted drafts appear in an approval queue; a different user can approve/deny; self-approval is blocked client- and server-side.

**Independent Test**: Submit a draft as user A; log in as user B (approver); assert approval queue shows the pending item with diff; approve; assert account `config_version` incremented; attempt self-approval as user A → assert error displayed.

- [X] T023 [P US3] Create `wallet-ecosystem/apps/admin/src/modules/payment/hooks/useConfigDrafts.ts`: `useEffect`+`useState` hook wrapping `listConfigDrafts(providerId, { status })` with optional `status` filter; exposes `{ drafts, loading, error, refetch }`; and a `getDraft(draftId)` action wrapping `getConfigDraft`

- [X] T024 [P US3] Create `wallet-ecosystem/apps/admin/src/modules/payment/components/ApprovalQueueTable.tsx`: renders a `Table` of `ConfigDraft` rows with columns: provider name, drafter name, submission timestamp, change type, inline `ConfigDiffPreview` (collapsed, expandable); per-row Approve and Deny buttons; Approve disabled when `draft.drafted_by_actor_id === currentUserId` (self-approval client-side block per FR-007) with tooltip "Self-approval not permitted"; Deny requires a comment textarea; empty state "No changes awaiting approval" when queue empty

- [X] T025 [US3] Create `wallet-ecosystem/apps/admin/app/(admin)/payments/providers/approvals/page.tsx`: `'use client'` directive; requires `payment.providers.read` to view, `payment.config.approve_high_risk` to approve/deny; loads all pending drafts via `useConfigDrafts` (no `providerId` — cross-provider queue); renders `ApprovalQueueTable` with filter controls for drafter, provider, and submission date range; loading/error/empty states

- [X] T026 [US3] Wire approve/deny actions in `ApprovalQueueTable.tsx`: approve calls existing `/admin/payment/providers/{providerId}/config-changes/{draftId}/approve` endpoint (via `PaymentApiClient`); deny calls the `reject` endpoint with required `notes` field; on 403 server response to self-approval, display inline error "Self-approval blocked by server"; on 409 version-conflict response, display "Config changed since submission — re-review required" and refetch the draft; on success, call `onSuccess` callback to refetch queue in parent

**Checkpoint**: Full maker-checker approval cycle working; self-approval blocked at both layers; conflict handling correct.

---

## Phase 6: User Story 4 — Activate, Suspend, and Restore Provider Accounts (Priority: P1)

**Goal**: Operators can drive lifecycle transitions from the account detail screen; each requires a reason; suspended accounts disappear from option discovery.

**Independent Test**: Suspend an active account via the Lifecycle Controls; assert status badge changes to "suspended"; call payment options API; assert suspended account absent.

- [X] T027 [P US4] Create `wallet-ecosystem/apps/admin/src/modules/payment/components/ProviderLifecycleControls.tsx`: renders only the valid next-transition buttons for the current `AccountLifecycleStatus` (valid transitions per data-model.md state machine); each button opens a `Dialog` with a required `reason` textarea (min 1 char, max 2000); retire action adds a second confirmation step "This action is irreversible. Type RETIRE to confirm."; on submit calls `transitionAccountLifecycle(providerId, accountId, {action, reason})` via prop; shows loading spinner during request; on 409 INVALID_LIFECYCLE_TRANSITION displays inline error; requires `payment.providers.manage` permission (controls hidden/disabled for read-only users)

- [X] T028 [US4] Integrate `ProviderLifecycleControls` into `wallet-ecosystem/apps/admin/app/(admin)/payments/providers/[providerId]/accounts/[accountId]/page.tsx`: pass `onTransitionSuccess` callback that calls `refetch()` on the account; `ProviderStatusBadge` updates immediately to new status on success; display success toast "Account {action}d successfully"; audit trail note (reason, actor, timestamp) displayed in an account events section below the form

**Checkpoint**: Lifecycle transitions fully functional with reason capture, audit display, and status badge refresh.

---

## Phase 7: User Story 5 — Module Availability and Display Order (Priority: P2)

**Goal**: Operators can globally enable/disable payment modules and set display order; changes apply directly (no draft workflow for module config per data-model.md).

**Independent Test**: Disable a module via the Module Availability screen; call `GET /v1/payment/options?purpose_type={module_purpose_type}`; assert empty options list.

- [X] T029 [P US5] Create `wallet-ecosystem/apps/admin/src/modules/payment/hooks/useModuleConfig.ts`: `useEffect`+`useState` hook wrapping `getModuleConfigs()` and exposing `updateModules(payload)` action calling `updateModuleConfigs(payload)`; returns `{ modules, loading, error, updating, refetch }`

- [X] T030 [P US5] Create `wallet-ecosystem/apps/admin/src/modules/payment/components/ModuleAvailabilityForm.tsx`: renders a list of `ModuleConfig` rows each with: `display_name`, `is_enabled` toggle switch, `display_order` number input; read-only when user lacks `payment.providers.manage`; "Submit for approval" button (not "Save directly") collects all changes into `UpdateModuleConfigPayload` and calls `onSubmit`; on 202 approval-pending response shows banner "Changes submitted for approval" with link to approval queue (same pattern as `ProviderAccountForm`); loading/error states; empty state "No modules configured" when list empty

- [X] T031 [US5] Create `wallet-ecosystem/apps/admin/app/(admin)/payments/config/modules/page.tsx`: `'use client'` directive; requires `payment.providers.read` to view; loads `useModuleConfig`; renders `PageHeader` "Payment Module Availability" + `ModuleAvailabilityForm`; on 202 submit response: shows approval-pending banner "Module changes submitted for approval" with link to `/payments/providers/approvals`; on 403: inline error; loading/error states; module list shows live (approved) values from `useModuleConfig`

**Checkpoint**: Module Availability screen functional; global disable reflects in payment options discovery.

---

## Phase 8: User Story 6 — Version History and Audit Trail (Priority: P2)

**Goal**: Operators can view complete config change history (approved + denied) in chronological order with field-level diffs.

**Independent Test**: After three approved changes, navigate to Version History; assert three entries in reverse-chronological order with correct actors and timestamps; assert denied drafts appear with denial reason.

- [X] T032 [P US6] Create `wallet-ecosystem/apps/admin/src/modules/payment/components/VersionHistoryTimeline.tsx`: renders a vertical timeline list of `ConfigDraft[]` in reverse-chronological order; approved entries show drafter, approver, timestamp, and collapsed `ConfigDiffPreview`; denied entries show "Denied by [name]" + denial reason in `review_notes` + collapsed `ConfigDiffPreview`; each entry clearly labelled with its status badge (approved=green, denied=red, superseded=grey); does not show `change_payload` raw JSON; empty state "No configuration changes recorded"

- [X] T033 [US6] Create `wallet-ecosystem/apps/admin/app/(admin)/payments/providers/[providerId]/accounts/[accountId]/history/page.tsx`: `'use client'` directive; requires `payment.providers.read`; loads `listConfigDrafts(providerId)` for all statuses (approved + rejected + superseded); renders `VersionHistoryTimeline`; breadcrumb back to account detail; loading/error/empty states; link from Account detail page to this History page via a "View history" tab or link

**Checkpoint**: Full version history visible with denied entries; drafter/approver/timestamp/diff all present.

---

## Phase 9: Polish & Cross-Cutting Concerns

**Purpose**: Navigation integration, automated tests, OpenAPI docs, static analysis, and quickstart validation.

- [X] T034 Add navigation links to the admin app payments navigation: add "Providers" → `/payments/providers` and "Module Config" → `/payments/config/modules` entries to the payments section of the admin sidebar nav (locate existing nav component in `wallet-ecosystem/apps/admin/`); add "Approval Queue" entry → `/payments/providers/approvals` visible only when user has `payment.config.approve_high_risk`

- [X] T035 [P] Write Jest unit tests for `AccountLifecycleStatusBadge.tsx` and `EnvironmentBadge.tsx` in `wallet-ecosystem/apps/admin/src/modules/payment/components/__tests__/badges.test.tsx`: assert `production` renders with `variant="error"` and text "PRODUCTION — Live traffic"; assert `sandbox` renders with `variant="warning"`; assert `mock` renders with `variant="neutral"`; assert `active` status renders green; assert `suspended` renders orange

- [X] T036 [P] Write Jest unit tests for `ProviderAccountForm.tsx` in `wallet-ecosystem/apps/admin/src/modules/payment/components/__tests__/ProviderAccountForm.test.tsx`: assert no `callback_url` input rendered (query for `[name="callback_url"]` must return null); assert `callback_secret_reference` field shows masked value from prop and "Change reference" button; assert `adapter_identifier` field is a `<select>` element (not free text); assert all form fields disabled when `readOnly=true` prop provided

- [X] T037 [P] Write Jest unit tests for `ConfigDiffPreview.tsx` in `wallet-ecosystem/apps/admin/src/modules/payment/components/__tests__/ConfigDiffPreview.test.tsx`: assert HIGH_RISK_FIELDS keys render `••••` in both Current and Proposed columns; assert non-sensitive fields render values correctly; assert empty diff renders empty state; assert JSONB array rendered as comma-separated list

- [X] T038 [P] Write Jest unit tests for `ApprovalQueueTable.tsx` in `wallet-ecosystem/apps/admin/src/modules/payment/components/__tests__/ApprovalQueueTable.test.tsx`: assert empty state "No changes awaiting approval" when `drafts=[]`; assert Approve button disabled when `draft.drafted_by_actor_id === currentUserId`; assert inline diff visible without navigation; assert Deny button opens comment textarea

- [X] T039 [P] Write Jest unit tests for `ProviderLifecycleControls.tsx` in `wallet-ecosystem/apps/admin/src/modules/payment/components/__tests__/ProviderLifecycleControls.test.tsx`: assert only valid next-transition buttons rendered for each `AccountLifecycleStatus` value; assert reason textarea required (submit disabled when empty); assert retire action renders two-step confirmation dialog

- [ ] T040 [P] Write PHP feature tests for `ProviderAccountAdminController` in `wallet-ecosystem/services/api/tests/Feature/Modules/Payment/ProviderAccountAdminControllerTest.php`: GET list returns 200 with accounts array; GET show returns 200 with masked `callback_secret_reference`; PATCH update with non-high-risk field returns 200 + incremented `config_version`; PATCH update with `merchant_account_reference` returns 202 + `draft_id`; PATCH update with stale `config_version` returns 409 `CONFIGURATION_VERSION_CONFLICT`; POST lifecycle `suspend` returns 200 with `account_lifecycle_status=suspended`; POST lifecycle invalid transition returns 409 `INVALID_LIFECYCLE_TRANSITION`; all endpoints return 403 for missing permission

- [ ] T041 [P] Write PHP feature tests for `ProviderConfigDraftListAdminController` in `wallet-ecosystem/services/api/tests/Feature/Modules/Payment/ProviderConfigDraftListAdminControllerTest.php`: GET index returns paginated list filterable by `status`; GET show returns draft with masked `change_payload`; HIGH_RISK_FIELDS keys in `change_payload` serialized as `****`; 403 for missing `payment.providers.read`; 404 for unknown `draftId`

- [ ] T042 [P] Write PHP feature tests for `PaymentModuleConfigAdminController` in `wallet-ecosystem/services/api/tests/Feature/Modules/Payment/PaymentModuleConfigAdminControllerTest.php`: GET returns module list ordered by `display_order` (live approved values); PATCH `is_enabled=false` returns **202** with `{requires_approval: true, draft_id: "..."}` (does NOT write directly to `payment_module_configs`); draft created with `change_type = 'module_config'` in `payment_config_change_drafts` table; GET returns 403 for missing `payment.providers.read`; PATCH returns 403 for missing `payment.providers.manage`

- [X] T043 Add P20 endpoint schemas and paths to `wallet-ecosystem/services/api/openapi/v1/payment.yaml`: add `ProviderAccount`, `ConfigDraft`, `ModuleConfig`, `UpdateAccountPayload`, `LifecycleTransitionPayload`, `UpdateModuleConfigPayload`, `HighRiskApprovalRequired` to `components/schemas`; add 8 new paths from `specs/067-admin-provider-config-screens/contracts/provider-config-admin-api.yaml` under the existing `paths:` section

- [X] T044 Run `npx tsc --noEmit` from `wallet-ecosystem/apps/admin`; resolve all TypeScript type errors in P20 source files; target: 0 errors (SC-007)

- [ ] T045 Run PHPStan level 8 from `wallet-ecosystem/services/api` targeting new P20 PHP files (`ProviderAccountAdminController`, `ProviderConfigDraftListAdminController`, `PaymentModuleConfigAdminController`, `PaymentModuleConfigService`, `PaymentModuleConfigModel`); resolve all errors; target: 0 errors

- [ ] T046 Run quickstart.md scenarios SC-001 through SC-009 against running Docker stack (`docker compose up -d` from `wallet-ecosystem/`); document pass/fail count per scenario; target: 9/9 pass (Docker-gated)

- [ ] T047 [P] Add accessibility tests to each P20 page component test using `@axe-core/react` (or `vitest-axe` if already installed): import `axe` from `@axe-core/react`; render each of the 6 P20 pages with mock data; call `axe(container)` and assert `violations.length === 0` for critical and serious severity; additionally assert each interactive control in `ProviderAccountForm`, `ApprovalQueueTable`, `ProviderLifecycleControls`, and `ModuleAvailabilityForm` has an accessible label (`aria-label` or `<label for>`); verify keyboard focus reaches all buttons and form fields in DOM order; target: 0 critical/serious Axe violations across all P20 pages (SC-006)

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: No dependencies — start immediately
- **Foundational (Phase 2)**: Depends on Setup — **BLOCKS all user stories**
- **US1 (Phase 3)**: Depends on Foundational (needs `ProviderStatusBadge`, `EnvironmentBadge`, API types + methods)
- **US2 (Phase 4)**: Depends on Foundational; may integrate with US1 pages but independently testable
- **US3 (Phase 5)**: Depends on Foundational; integrates with US2 account detail page (approval banner)
- **US4 (Phase 6)**: Depends on Foundational; integrates with US2 account detail page
- **US5 (Phase 7)**: Depends on Foundational (migration + module controller must exist); independent of US1–US4
- **US6 (Phase 8)**: Depends on Foundational; reads same draft data as US3
- **Polish (Phase 9)**: Depends on all user stories being complete

### User Story Dependencies

- **US1 (P1)**: Can start after Foundational — no dependency on other stories
- **US2 (P1)**: Can start after Foundational — no dependency on US1 (different pages)
- **US3 (P1)**: Can start after Foundational — approval queue is a standalone page; links to US2 account detail
- **US4 (P1)**: Can start after Foundational — lifecycle controls are a standalone component; integrates into US2 page at T028
- **US5 (P2)**: Can start after Foundational — completely independent; separate page + migration
- **US6 (P2)**: Can start after Foundational — reads same config-changes endpoint as US3

### Within Each Phase

- Foundational tasks with [P] markers (T008, T012, T013, T014) can run in parallel with each other
- T010 (types) must complete before T011 (methods that use those types)
- T005 (service extension) must complete before T006 (controller that calls service)
- T004 (PaymentModuleConfigService) must complete before T008 (controller that calls service)
- T009 (route registration) can run in parallel with controller creation but all controllers must exist before routes are exercised

### Parallel Opportunities

```bash
# Foundational parallel group 1 (after T002-T005):
T006, T007, T008  # Controllers — independent files

# Foundational parallel group 2 (after T009):
T010 → T011 → T012  # TypeScript sequential
T013, T014           # Shared components — parallel

# US1 parallel group (after Phase 2):
T015, T017  # Hook and detail page — parallel with T016

# US2 parallel group (after Phase 2):
T019, T020, T021  # Hook and components — parallel, then T022

# US3 parallel group (after Phase 2):
T023, T024  # Hook and table — parallel, then T025, T026

# US4 parallel: T027, then T028 integrates with T022's page

# Polish parallel group (after all stories):
T035, T036, T037, T038, T039  # Frontend tests — all parallel
T040, T041, T042              # Backend tests — all parallel
```

---

## Implementation Strategy

### MVP First (US1 + US2 Only)

1. Complete Phase 1 (Setup)
2. Complete Phase 2 (Foundational — all 13 tasks)
3. Complete Phase 3 (US1 — Browse Provider Registry)
4. **STOP and validate**: Provider registry browses providers and accounts; access-denied gate works
5. Complete Phase 4 (US2 — Configure Provider Account)
6. **STOP and validate**: Draft save works; masked secrets; no `callback_url` input; 200/202/409 states handled
7. Deploy/demo MVP

### Incremental Delivery

1. Foundation + US1 → Read-only registry (auditor use case)
2. + US2 → Draft config changes (operator use case)
3. + US3 → Approval queue (approver use case) — unlocks maker-checker
4. + US4 → Lifecycle controls (incident response use case)
5. + US5 → Module availability (policy operator use case)
6. + US6 → Version history (compliance/audit use case)

### Parallel Team Strategy

With two developers after Phase 2 completes:
- Developer A: US1 → US2 → US4 (account detail flow)
- Developer B: US3 → US5 → US6 (approval/config policy flow)

---

## Notes

- [P] tasks = different files, no blocking dependency on in-progress tasks
- [US#] label maps task to user story from spec.md for traceability
- T040–T042 PHP feature tests are Docker-gated; mark complete when Docker stack available
- T047 accessibility tests require `@axe-core/react` or `vitest-axe`; install if absent (`npm install --save-dev @axe-core/react`)
- T046 quickstart scenarios require Docker stack; mark complete when confirmed
- `callback_url` input MUST NOT appear anywhere (FR-005); verified in T036 ProviderAccountForm test
- Self-approval must be blocked at both T024 (client) and T026 (server response) layers (FR-007)
- `PaymentModuleConfigService::bulkUpdate` applies changes directly (no draft workflow for module config) — per data-model.md constraint note
- Secrets (`callback_secret_reference`) must be masked in ALL serializations: T006 (controller), T007 (draft diff), T036 (form test), T037 (diff test), and T041 (draft controller test)
