# Tasks: ADM-P1-014 — Fee Rules Management

**Input**: Design documents from `specs/038-fee-rules-management/`

**Prerequisites**: plan.md ✅, spec.md ✅, research.md ✅, data-model.md ✅, quickstart.md ✅

**Organization**: Tasks grouped by user story for independent implementation and testing.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies)
- **[Story]**: Maps to user story from spec.md

---

## Phase 1: Setup — API Module & Route Wiring

**Purpose**: Create the shared API wrapper and route that all user stories depend on. No user story implementation can begin until T001–T003 are complete.

- [X] T001 Create `wallet-ecosystem/apps/admin/src/modules/fees/api/fees.ts` — export TypeScript interfaces (`FeeRule`, `FeeRuleStatus`, `TransactionType`, `FeeCalculationResult`, `CalculateFeeRequest`) and three async functions: `getFeeRule(client, id)` calling `GET /v1/fees/rules/{id}`, `deactivateFeeRule(client, id)` calling `DELETE /v1/fees/rules/{id}`, and `calculateFee(client, req)` calling `POST /v1/fees/calculate`; all functions accept `AuthenticatedApiClient` as first arg and return typed responses

- [X] T002 Create `wallet-ecosystem/apps/admin/app/(admin)/fees/rules/[id]/page.tsx` — Next.js App Router dynamic route; import and render `<FeeRuleDetailPage ruleId={params.id} />` from `@/src/modules/fees/pages/fee-rule-detail`; set `metadata.title = 'Fee Rule Detail — OleKard Admin'`

- [X] T003 Modify `wallet-ecosystem/apps/admin/src/modules/fees/pages/fee-rules-list.tsx` — import `useRouter` from `next/navigation`; add `const router = useRouter()` at top of component; in the `rows` mapping add `onClick: () => router.push(`/fees/rules/${r.id}`)` and `className: 'cursor-pointer'` to each row object so clicking any row navigates to the detail page; ensure existing filter and fetch logic is untouched

**Checkpoint**: Fee rules list rows are clickable; detail route exists and is wired up. Foundation ready.

---

## Phase 2: User Story 1 — Admin views fee rule detail (US-P1-014-001, P1) 🎯 MVP

**Goal**: Admin can click a rule in the list and see all its attributes on a dedicated detail page, with proper loading/error states, and the create form redirects to the new rule's detail page with 409 conflict feedback.

**Independent Test**: Run `npx jest p1014 --testNamePattern="detail"` — load/display tests pass. Manually: click any list row → detail page shows rule attributes.

- [X] T004 [US1] Create `wallet-ecosystem/apps/admin/src/modules/fees/pages/fee-rule-detail.tsx` with the following structure:
  - Props: `{ ruleId: string }`
  - State: `rule: FeeRule | null`, `loading: boolean`, `error: string | null`, `toast: { message: string; kind: 'success' | 'error' } | null`
  - On mount: call `getFeeRule(authenticatedClient, ruleId)` → set `rule`; handle 404 by setting `error = 'Fee rule not found'`
  - Sub-components (inline): `FeeRuleStatusBadge({ status })` returning `<Badge>` with variant mapping (`active→success`, `deactivated→error`, `expired→warning`, `superseded→neutral`); `DetailField({ label, value, mono? })` rendering `<dt>` + `<dd>` pair with `text-xs font-medium text-text-secondary uppercase tracking-wide` label style
  - Layout: `<PageContainer>` → `<PageHeader title={rule.transaction_type ?? 'Fee Rule'} />` → two-column grid of `<DetailField>` elements showing: ID (mono), Transaction Type, Fee Type, Fee Amount (percentage shows `${rate}%`, fixed shows `BWP ${(amount_minor/100).toFixed(2)}`), Min Fee, Max Fee, Currency, Subject Type, Subject ID (mono, nullable), Valid From, Valid To, Status (badge), Created At, Created By (mono)
  - Show `<Loading label="Loading fee rule…" />` while loading; `<ErrorState message={error} onRetry={fetch} />` on error
  - Import from: `@/components/layout/PageContainer`, `@/components/layout/PageHeader`, `@/components/ui/Card`, `@/components/ui/Badge`, `@/components/ui/Loading`, `@/components/ui/ErrorState`, `@/lib/auth/context`, `@/src/modules/fees/api/fees`

- [X] T005 [US1] Modify `wallet-ecosystem/apps/admin/src/modules/fees/pages/fee-rule-create.tsx` — after successful `authenticatedClient.post('/v1/fees/rules', ...)` the response includes `data.data.id`; change `router.push('/fees/rules')` to `router.push(`/fees/rules/${createdRule.id}`)` so admin lands on the new rule's detail page; add 409 error handling: in the `catch` block, check `(err as {response?:{status?:number}})?.response?.status === 409` and set a top-level `conflictError` state string = `"A conflicting active fee rule already exists for this transaction type, currency, and subject scope."`, then render this as a non-dismissible error banner above the form (red border, error icon, red text); keep existing 422 field error handling unchanged

- [X] T006 [US1] Write test scaffolding in `wallet-ecosystem/apps/admin/__tests__/features/fees/p1014-fee-rules-management.test.tsx` — add file header comment for ADM-P1-014 coverage; set up jest mocks for `next/navigation` (`useRouter` returning `{ push: jest.fn(), replace: jest.fn() }`), `next/link`, `@/lib/auth/context` (`useAuth` mock); set up `mockGetFeeRule`, `mockDeactivateFeeRule`, `mockCalculateFee` as `jest.fn()` and mock `@/src/modules/fees/api/fees` module; define fixture constants `ACTIVE_RULE`, `DEACTIVATED_RULE`, `EXPIRED_RULE` with all required FeeRule fields; write describe block "US-P1-014-001 Fee Rule Detail" with tests: (a) renders loading state on mount, (b) renders rule attributes after fetch resolves, (c) shows error state when API returns 404, (d) navigates to /fees/rules/{id} when list row clicked; import and test both `FeeRulesListPage` and `FeeRuleDetailPage`

**Checkpoint**: Detail page shows all rule attributes; create form redirects to new rule; 409 handled gracefully. US1 independently testable.

---

## Phase 3: User Story 2 — Admin deactivates a fee rule (US-P1-014-002, P1)

**Goal**: Active fee rules show a "Deactivate" button guarded by `fees.rules.delete` permission. Clicking opens a confirmation dialog. On confirm, `DELETE /v1/fees/rules/{id}` is called, status badge updates to Deactivated, button disappears, success toast fires.

**Independent Test**: Run `npx jest p1014 --testNamePattern="deactivat"` — all deactivation tests pass. Manually: open active rule → click Deactivate → confirm → badge shows DEACTIVATED.

- [X] T007 [US2] Extend `wallet-ecosystem/apps/admin/src/modules/fees/pages/fee-rule-detail.tsx` — add state: `deactivateDialogOpen: boolean` (false), `deactivating: boolean` (false); add `permissions` from `useAuth()`; add `handleDeactivate` async function that sets `deactivating=true`, calls `deactivateFeeRule(authenticatedClient, ruleId)`, on success updates `rule.status` to `'deactivated'` (optimistic local update) and sets `toast = { message: 'Fee rule deactivated successfully.', kind: 'success' }`, on error sets `toast = { message: 'Failed to deactivate rule. Please try again.', kind: 'error' }`, finally sets `deactivating=false` and `deactivateDialogOpen=false`; render `<DeactivateDialog>` component (inline, wraps existing `Dialog` component from `@/components/ui/Dialog`) showing title "Deactivate Fee Rule", body warning "This rule will no longer apply to transactions. This action cannot be undone. To restore fees for this transaction type, create a new rule.", two buttons: "Cancel" (secondary) and "Confirm Deactivation" (danger variant, loading when `deactivating`); show the Deactivate button in the page actions area only when `rule.status === 'active' && permissions.has('fees.rules.delete')`; for non-active rules render a `<p>` note: `"This rule has been ${rule.status} and is no longer applied to transactions."` instead

- [X] T008 [US2] Extend `wallet-ecosystem/apps/admin/src/modules/fees/pages/fee-rule-detail.tsx` — add `Toast` rendering: below the `<PageHeader>`, if `toast !== null` render `<Toast message={toast.message} onDismiss={() => setToast(null)} />` from `@/components/ui/Toast`; style toast container with `role="status"` and colour-coded background: `toast.kind === 'success'` uses green background token, `'error'` uses red; verify `Toast` auto-dismisses after 5 s via its existing `autoCloseMs` prop

- [X] T009 [US2] Add deactivation test cases to `wallet-ecosystem/apps/admin/__tests__/features/fees/p1014-fee-rules-management.test.tsx` in a new describe block "US-P1-014-002 Deactivate Fee Rule": (a) renders Deactivate button for active rule when user has fees.rules.delete permission, (b) does NOT render Deactivate button when rule.status !== 'active', (c) does NOT render Deactivate button when user lacks fees.rules.delete permission, (d) opens confirmation dialog on Deactivate click, (e) calls deactivateFeeRule and updates badge to DEACTIVATED on confirm, (f) shows success toast after deactivation, (g) shows error toast when API call fails (mock rejects), (h) closes dialog without calling API on Cancel; mock `deactivateFeeRule` to resolve for success cases and reject for failure case

**Checkpoint**: Deactivation workflow complete with confirmation, optimistic update, and toast feedback. US2 independently testable.

---

## Phase 4: User Story 3 — Admin simulates fee impact (US-P1-014-003, P2)

**Goal**: A collapsible "Simulate Fee Impact" section on the detail page lets admin enter a transaction amount and see the calculated fee via `POST /v1/fees/calculate`. Pre-fills `transaction_type` and `currency` from the current rule. No transaction is created.

**Independent Test**: Run `npx jest p1014 --testNamePattern="simulat"` — all simulation tests pass. Manually: open any rule → click Simulate → enter amount → see fee breakdown.

- [X] T010 [US3] Extend `wallet-ecosystem/apps/admin/src/modules/fees/pages/fee-rule-detail.tsx` — add state: `simOpen: boolean` (false), `simAmountDisplay: string` (''), `simLoading: boolean` (false), `simResult: FeeCalculationResult | null` (null), `simError: string | null` (null); add `SimulationPanel` inline component below the rule attributes Card, toggled by a "Simulate Fee Impact" button (secondary variant with a calculator icon or `▶` prefix); panel contains: (1) Amount input labelled "Transaction Amount (BWP)" that accepts decimal input (stores display string, converts to minor units on submit by multiplying by 100 and rounding); (2) "Calculate Fee" button (primary) that calls `calculateFee(authenticatedClient, { transaction_type: rule.transaction_type, amount_minor: Math.round(parseFloat(simAmountDisplay) * 100), currency: rule.currency, subject_type: 'global' })`, sets `simLoading=true` during call; (3) result display section rendered when `simResult !== null`: if `!simResult.has_applicable_rule` show "No fee rule found — this transaction type currently incurs no fee"; else show table with rows: Fee Amount (`BWP ${(simResult.fee_amount_minor / 100).toFixed(2)}`), Fee Type (`simResult.fee_type`), Net Amount (`BWP ${((parseFloat(simAmountDisplay) * 100 - simResult.fee_amount_minor) / 100).toFixed(2)}`), and conditional notices for `min_fee_applied` = "(minimum fee applied)" and `max_fee_applied` = "(maximum fee applied)"; (4) error display for `simError` as inline error text below the Calculate button; client-side validation: if `parseFloat(simAmountDisplay) <= 0` or NaN, set `simError = 'Enter a valid amount greater than 0'` and skip API call; if `Math.round(parseFloat(simAmountDisplay) * 100) > 999_999_999`, set `simError = 'Amount too large. Maximum is BWP 9,999,999.99'` and skip API call

- [X] T011 [US3] Add simulation test cases to `wallet-ecosystem/apps/admin/__tests__/features/fees/p1014-fee-rules-management.test.tsx` in a new describe block "US-P1-014-003 Simulate Fee Impact": (a) simulation panel is collapsed by default, (b) panel opens on "Simulate Fee Impact" button click, (c) calls calculateFee with correct args when Calculate clicked with valid amount, (d) displays fee amount and net amount from result, (e) shows "minimum fee applied" notice when simResult.min_fee_applied = true, (f) shows "No fee rule found" message when has_applicable_rule = false, (g) shows inline error for invalid (non-numeric) amount input without calling API, (h) shows simError when calculateFee rejects; mock `calculateFee` with appropriate responses for each case

**Checkpoint**: Simulation panel fully functional. All 3 user stories independently testable.

---

## Phase 5: Polish & Cross-Cutting Concerns

**Purpose**: TypeScript validation, test run, and final accessibility check.

- [X] T012 Run `npx tsc --noEmit --skipLibCheck` from `wallet-ecosystem/apps/admin` — fix any TypeScript errors introduced by new files (common issues: missing return types, non-null assertions needed for `rule` fields, `AuthenticatedApiClient` import path)

- [X] T013 Run `npx jest __tests__/features/fees/p1014-fee-rules-management.test.tsx --verbose` from `wallet-ecosystem/apps/admin` — verify all tests pass; fix any failing assertions; record pass count in implementation summary

- [X] T014 [P] Verify ARIA accessibility on `fee-rule-detail.tsx` — confirm: `<Dialog>` has `role="dialog" aria-modal="true" aria-labelledby`; Deactivate button has a meaningful `aria-label`; simulation panel button has descriptive label; Toast has `role="status" aria-live="polite"`; all form controls have `<label>` associations

- [X] T015 [P] Run full test suite `npx jest` from `wallet-ecosystem/apps/admin` — confirm existing 139+ tests continue to pass (regression check); record total pass count

---

## Dependencies & Execution Order

### Phase Dependencies

- **Phase 1 (T001–T003)**: No dependencies — start immediately; T001, T002, T003 can run in parallel
- **Phase 2 US1 (T004–T006)**: Depends on T001, T002, T003
- **Phase 3 US2 (T007–T009)**: Depends on T004 (detail page must exist)
- **Phase 4 US3 (T010–T011)**: Depends on T004; can run in parallel with Phase 3
- **Phase 5 Polish (T012–T015)**: Depends on all phases complete

### User Story Dependencies

- **US1 (P1)**: Depends on Phase 1 (T001–T003)
- **US2 (P1)**: Depends on US1's T004 (detail page scaffold) — extends the same file
- **US3 (P2)**: Depends on US1's T004 — extends the same file; can proceed in parallel with US2

### Parallel Opportunities

- T001, T002, T003 (Phase 1): All different files — fully parallel
- T009 and T011 (test tasks for US2/US3): Can be written in parallel with their implementation tasks
- T014 and T015 (Phase 5): Different concerns — parallel

---

## Parallel Example: Phase 1

```
T001: src/modules/fees/api/fees.ts              ← independent
T002: app/(admin)/fees/rules/[id]/page.tsx      ← independent
T003: src/modules/fees/pages/fee-rules-list.tsx ← independent (different file)
```

All three can run concurrently.

---

## Implementation Strategy

### MVP First (US1 + US2 only)

1. Complete Phase 1: T001, T002, T003 (parallel)
2. Complete US1: T004, T005, T006
3. Complete US2: T007, T008, T009
4. **STOP and VALIDATE**: Open detail page, deactivate a rule — confirm working
5. Ship US1+US2

### Full Delivery

6. Add US3: T010, T011
7. Polish: T012–T015

---

## Notes

- `fee-rule-detail.tsx` is extended across three phases (T004 → T007–T008 → T010) — each task adds self-contained state + UI to the same file
- The `Dialog` component (`components/ui/Dialog.tsx`) is minimal and handles Escape key + focus management — use as-is; do not add a backdrop overlay unless the existing Dialog.tsx already has one
- `Toast` component auto-dismisses at 5 s — no manual timer needed in the detail component
- `fees.ts` API wrapper returns raw response data; error handling (try/catch) is done in the component, not the wrapper
- The `Table` component renders rows as plain `<tr>` elements — row click is added via `onClick` in the row object passed to `rows` prop (see existing usage in `fee-rules-list.tsx`)
