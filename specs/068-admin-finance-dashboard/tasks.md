# Tasks: P21 â€” Admin Finance Dashboard & Operations Screens

**Input**: Design documents from `specs/068-admin-finance-dashboard/`

**Prerequisites**: plan.md âœ“, spec.md âœ“, research.md âœ“, data-model.md âœ“, contracts/ui-screens.md âœ“, quickstart.md âœ“

**Organization**: Tasks are grouped by user story to enable independent implementation and testing of each story.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies)
- **[Story]**: Which user story this task belongs to (US1â€“US5)

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Extend shared foundation â€” API client types, nav entries, and both new backend controllers that unblock every user story.

- [X] T001 Extend `apps/admin/lib/api/payment.ts` â€” add all P21 TypeScript types: `DashboardSummary`, `CurrencyVolume`, `PaymentIntentSummary`, `PaymentIntentTimeline`, `PaymentAttemptRow`, `LedgerJournalRef`, `LedgerLine`, `RefundListItem`, `DisputeCaseSummary`, `PayoutRequestSummary`, `ExportStatus`, `ExportRequest`, and supporting enums (`IntentStatus`, `RefundStatus`, `PayoutRequestStatus`, `DisputeStatus`, `ReconciliationExceptionStatus`)
- [X] T002 Extend `apps/admin/lib/api/payment.ts` â€” add all P21 `PaymentApiClient` methods: `getDashboardSummary`, `getIntentTimeline`, `queryIntentStatus`, `listRefundManagement`, `getRefundDetail`, `queryRefundStatus`, `listDisputeCases`, `getDisputeCase`, `authoriseDisputeHold`, `listPayoutRequests`, `getPayoutRequest`, `approvePayoutReservation`, `rejectPayoutReservation`, `getPaymentHistory`, `requestExport`, `getExportStatus` (see contracts/ui-screens.md for full signatures; use `res.data as unknown as T` pattern â€” NOT `res.data.data`)
- [X] T003 [P] Create Laravel controller `services/api/app/Modules/Payment/Http/Controllers/Admin/DashboardSummaryAdminController.php` â€” GET action queries `payment_intents` (today UTC): count by status, sum `amount_minor` grouped by `currency_code` for captured/fulfilled, count captured with no `payment_fulfilments` record; gate: `permission.history.read.platform`; response: `{data: {volume_by_currency, attempt_counts_by_status, fulfilment_backlog_count, fetched_at}}`
- [X] T004 [P] Create Laravel controller `services/api/app/Modules/Payment/Http/Controllers/Admin/PaymentIntentTimelineAdminController.php` â€” GET `{id}` action: (1) loads intent + attempts via existing `PaymentIntentRepository` and `PaymentAttemptRepository`; (2) retrieves pending-accounting flag via `PendingAccountingRepository`; (3) retrieves ledger journal refs via `LedgerJournalQueryService::forReference(intentId)` (Ledger module service â€” do NOT query `ledger_journals`/`ledger_accounts` tables directly from a Payment controller); (4) strips `callback_secret_reference` and raw credential fields from attempt data; gate: `payment.intents.admin`; response: `{data: {intent, attempts, fulfilment, ledger_journals, pending_accounting}}`. If `LedgerJournalQueryService` does not yet exist, create a thin interface + implementation stub in `services/api/app/Modules/Ledger/Services/LedgerJournalQueryService.php` returning `LedgerJournalRef[]` for a given `reference_id`.
- [X] T005a [P] Create `services/api/app/Modules/Payment/Jobs/QueryPaymentIntentStatusJob.php` â€” job class that accepts `$intentId` (string), calls the provider via the existing `PaymentProviderAdapterFactory` in read-only query mode, and dispatches a `PaymentIntentStatusQueried` domain event with the result; modelled after `services/api/app/Modules/Payment/Jobs/QueryRefundStatusJob.php`; MUST NOT mutate payment intent state
- [X] T005 Register both new controllers in `services/api/routes/modules/payment.php` â€” add within existing admin auth/permission middleware group: `GET /api/v1/admin/payment/dashboard-summary` â†’ `DashboardSummaryAdminController@index` and `GET /api/v1/admin/payment/intents/{id}/timeline` â†’ `PaymentIntentTimelineAdminController@show`; also add `POST /api/v1/admin/payment/intents/{id}/query-status` dispatching `QueryPaymentIntentStatusJob` (same pattern as existing `query-status` routes), gate: `payment.intents.admin`
- [X] T006 Add 6 nav items to `apps/admin/src/lib/navigation/nav-items.ts` under Payments group: Finance Dashboard (`/payments/finance`, icon: `BarChart3`, permission: `payment.history.read.platform`), Refund Approvals (`/payments/finance/refunds/approvals`, icon: `CheckSquare`, permission: `payment.refunds.approve`), Disputes (`/payments/finance/disputes`, icon: `AlertTriangle`, permission: `payment.disputes.view`), Reconciliation (`/payments/finance/reconciliation`, icon: `RefreshCw`, permission: `payment.reconciliation.view`), Payouts (`/payments/finance/payouts`, icon: `ArrowUpRight`, permission: `payment.payout.view`), Reports (`/payments/finance/reports`, icon: `FileText`, permission: `payment.export.read.organisation`)
- [X] T007 [P] PHPStan level 8 check on new backend controllers: `services/api/vendor/bin/phpstan analyse app/Modules/Payment/Http/Controllers/Admin/DashboardSummaryAdminController.php app/Modules/Payment/Http/Controllers/Admin/PaymentIntentTimelineAdminController.php --level=8` â€” 0 errors required before proceeding

**Checkpoint**: Foundation ready â€” all API client types exist, nav links exist, backend endpoints live. User story phases can begin.

---

## Phase 2: US1 â€” Finance Operations Dashboard (Priority: P1) â­ MVP

**Goal**: Finance operators see KPI tiles (volume by currency, attempt counts by status, fulfilment backlog), a freshness timestamp/stale-data banner, and can trigger a safe "query provider status" action on backlog items.

**Independent Test**: Navigate to `/payments/finance` with seeded payment data â†’ verify KPI tiles render with correct currency-separated amounts, backlog table lists captured-not-fulfilled intents, freshness timestamp is shown, stale banner appears after threshold.

- [X] T008 [P] [US1] Create `apps/admin/src/modules/payment/components/FinanceKpiTile.tsx` â€” card component with `label: string`, `value: string | number`, `currency?: string`, `trend?: 'up' | 'down' | 'neutral'` props; uses existing design tokens; renders label, value, and optional currency unit
- [X] T009 [P] [US1] Create `apps/admin/src/modules/payment/components/StaleDataBanner.tsx` â€” amber banner with `fetchedAt: Date`, `thresholdSeconds: number` props; shows "Data may be outdated â€” last refreshed at {time}" + refresh button when `(Date.now() - fetchedAt.getTime()) > thresholdSeconds * 1000`; reads threshold from `NEXT_PUBLIC_FINANCE_DASHBOARD_STALE_THRESHOLD_SECONDS` env var (default 300)
- [X] T010 [P] [US1] Create `apps/admin/src/modules/payment/components/IntentStatusBadge.tsx` â€” coloured badge component for `IntentStatus`; colours: `created`=gray, `captured`=blue, `fulfilled`=green, `failed`=red, `cancelled`=gray-muted; uses shadcn/ui Badge
- [X] T011 [US1] Create `apps/admin/src/modules/payment/hooks/useDashboardSummary.ts` â€” fetches `getDashboardSummary()` on mount; returns `{summary, isLoading, error, refetch}`; stores `fetchedAt` client-side timestamp on successful fetch; no polling (manual refresh only)
- [X] T012 [US1] Create `apps/admin/app/(admin)/payments/finance/page.tsx` â€” `FinanceDashboardPage`; on load calls `useDashboardSummary`; renders: `FinanceKpiTile` per currency (volume), `FinanceKpiTile` per status (attempts), backlog count tile; `StaleDataBanner` when stale; paginated backlog table from `GET /api/v1/admin/payment/history?has_pending_fulfilment=true&per_page=20`; each backlog row has "Query Status" button that calls `queryIntentStatus(id)` â†’ shows 202 toast "Status query dispatched"; implements loading-skeleton, empty, error+retry, and permission-denied states; permission: `payment.history.read.platform`
- [X] T013 [US1] Write Jest tests in `apps/admin/__tests__/features/payment/e68-finance-dashboard.test.tsx` â€” test: KPI tiles render with mock summary data (currency-separated values), stale banner appears when fetchedAt is older than threshold, stale banner absent when fresh, empty state when `volume_by_currency=[]`, error+retry state when API fails, "Query Status" button calls `queryIntentStatus`, loading skeleton on initial load

**Checkpoint**: US1 done â€” `/payments/finance` fully functional with KPI tiles, stale banner, backlog, and status query.

---

## Phase 3: US2 â€” Payment Investigation (Priority: P1)

**Goal**: Finance operators drill down from the backlog into a full intent timeline showing attempts, ledger journal lines (account names, not raw IDs), fulfilment status, and masked provider references. No write actions.

**Independent Test**: Navigate to `/payments/finance/intents/{id}` with a seeded full-lifecycle intent â†’ verify timeline renders: intent created event, all attempts (provider code, status badge, timestamps), ledger journal card (debit/credit rows), fulfilment status, masked provider reference.

- [X] T014 [P] [US2] Create `apps/admin/src/modules/payment/components/TimelineEvent.tsx` â€” single timeline item with `type: 'intent' | 'attempt' | 'ledger' | 'fulfilment'`, `title: string`, `timestamp: string`, `detail?: React.ReactNode` props; vertically stacked with connector line
- [X] T015 [P] [US2] Create `apps/admin/src/modules/payment/components/LedgerJournalCard.tsx` â€” card showing `LedgerJournalRef`: description, posted_at, debit/credit lines table (account_name, type, amount_minor formatted via CurrencyAmount), audit link button; MUST NOT show raw wallet UUIDs in any label; provider reference renders as `****{last4}` if present
- [X] T016 [P] [US2] Create `apps/admin/src/modules/payment/components/CurrencyAmount.tsx` â€” checks if component already exists in `apps/admin/src/components/` or design system; if not, creates: `amountMinor: number, currency: string` props; formats as "BWP 150.00" using `Intl.NumberFormat`; never mixes currencies in one render
- [X] T017 [US2] Create `apps/admin/src/modules/payment/hooks/useIntentTimeline.ts` â€” fetches `getIntentTimeline(id)` for a given intent ID; returns `{timeline, isLoading, error, refetch}`; caches per ID
- [X] T018 [US2] Create `apps/admin/app/(admin)/payments/finance/intents/page.tsx` â€” `IntentListPage`; paginated table from `getPaymentHistory(params)`; columns: intent ID (linked to detail), status badge, amount+currency, provider code, created_at, has_pending_fulfilment indicator; filter bar: status, currency, provider, date range; server-side sorting/paging; permission: `payment.history.read.platform`
- [X] T019 [US2] Create `apps/admin/app/(admin)/payments/finance/intents/[id]/page.tsx` â€” `IntentTimelinePage`; uses `useIntentTimeline(id)`; renders chronological `TimelineEvent` list: (1) intent created, (2) each `PaymentAttemptRow` (attempt number, provider code, `IntentStatusBadge`, timestamps, failure reason if any), (3) ledger journal section with `LedgerJournalCard` per journal, (4) fulfilment status; "Query Provider Status" button visible ONLY when `intent.status === 'captured'` AND user has `payment.intents.admin`; provider_reference shown as `****{last4}` if present; no-attempts state: "No attempts â€” intent created but not yet submitted"; no-journals state: "No ledger journals yet"; permission-denied hides journal + status-query; permission: `payment.history.read.platform`
- [X] T020 [US2] Write Jest tests in `apps/admin/__tests__/features/payment/e68-intent-timeline.test.tsx` â€” test: full timeline renders in chronological order, masked provider reference (`provider_reference` shows `****1234` not raw value), no-attempts state renders correctly, no-journals state renders correctly, "Query Provider Status" button absent when `status !== 'captured'`, status query button absent for user without `payment.intents.admin`, `LedgerJournalCard` shows account names not raw UUIDs

**Checkpoint**: US1 + US2 done â€” end-to-end investigation from dashboard to intent detail (the primary DoD).

---

## Phase 4: US3 â€” Refund Approval & Verified Payout Operations (Priority: P2)

**Goal**: Finance approvers can review, approve, and reject pending refunds with mandatory reasons; approve payout reservations; idempotency keys prevent duplicate postings; support users see items but not approve/reject controls.

**Independent Test**: With a pending refund seeded (`DevPendingRefundSeeder`), user with `payment.refunds.approve` can approve â†’ ledger entry created, item moves to approved state; user without that permission sees item but no Approve/Reject buttons.

- [X] T021 [P] [US3] Create `apps/admin/src/modules/payment/components/RefundStatusBadge.tsx` â€” badge for `RefundStatus`; colours: `pending_approval`=yellow, `approved`=blue, `processing`=blue-pulse, `completed`=green, `rejected`=red, `failed`=red
- [X] T022 [P] [US3] Create `apps/admin/src/modules/payment/components/PayoutStatusBadge.tsx` â€” badge for `PayoutRequestStatus`; colours: `awaiting_approval`=yellow, `reservation_pending`=yellow, `approved`=blue, `executing`=blue, `settled`=green, `rejected`=red, `failed`=red; executing/settled states do NOT render an action button
- [X] T023 [US3] Create `apps/admin/src/modules/payment/hooks/useRefundApprovalQueue.ts` â€” fetches `listRefundManagement({status: 'pending_approval'})`; provides `approve(id, idempotencyKey)` calling `POST /refunds/{id}/approve`; provides `reject(id, reason, idempotencyKey)` calling `POST /refunds/{id}/reject`; generates UUID v7 idempotency key per action invocation; handles 403 (SELF_APPROVAL_NOT_ALLOWED) â†’ inline error "You cannot approve your own refund request"; handles 409 â†’ inline error "This refund has already been processed"; on success: removes item from queue optimistically
- [X] T024 [US3] Create `apps/admin/app/(admin)/payments/finance/refunds/approvals/page.tsx` â€” `RefundApprovalQueuePage`; fetches from `useRefundApprovalQueue`; each row: amount+currency (`CurrencyAmount`), intent link, requestor, reason, requested_at, `RefundStatusBadge`; Approve button: renders ONLY when `hasPermission('payment.refunds.approve')` â†’ opens confirm dialog â†’ submits; Reject button: renders ONLY when `hasPermission('payment.refunds.approve')` â†’ opens modal with mandatory reason textarea â†’ submits; per-row inline error states for 403/409; empty state: "No refunds pending approval"; permission: `payment.refunds.approve`
- [X] T025 [US3] Create `apps/admin/src/modules/payment/hooks/useRefundManagement.ts` â€” fetches `listRefundManagement(params)` with filters: status, currency, date_from, date_to; returns `{refunds, isLoading, error, refetch, page, setPage}`
- [X] T026 [US3] Create `apps/admin/app/(admin)/payments/finance/refunds/page.tsx` â€” `RefundManagementPage`; full refund list with `RefundStatusBadge`, filters (status, currency, date range), intent link per row; read-only view â€” no approve/reject buttons; permission: `payment.refunds.view`
- [X] T027 [US3] Create `apps/admin/src/modules/payment/hooks/usePayoutRequests.ts` â€” fetches `listPayoutRequests(params)` with status filter; provides `approveReservation(id, idempotencyKey)` calling `approvePayoutReservation`; provides `rejectReservation(id, reason)` calling `rejectPayoutReservation`; generates UUID v7 idempotency key per invocation
- [X] T028 [US3] Create `apps/admin/app/(admin)/payments/finance/payouts/page.tsx` â€” `PayoutRequestsPage`; columns: fund_reference_id, purpose_type, organisation, currency, computed_payable_minor (`CurrencyAmount`), `PayoutStatusBadge`; "Approve Reservation" button visible ONLY for `status=reservation_pending` AND `hasPermission('payment.payout.approve')`; execute/confirm-settlement lifecycle states show "Pending â€” not yet available" badge (no button â€” routes are 501 stubs); link to detail page; permission: `payment.payout.view`
- [X] T029 [US3] Create `apps/admin/app/(admin)/payments/finance/payouts/[id]/page.tsx` â€” `PayoutRequestDetailPage`; full payout detail: beneficiary_id, fund_reference_id, purpose_type, currency+amount, status history; Approve Reservation action (same gating as list); back link; permission: `payment.payout.view`
- [X] T030 [US3] Write Jest tests in `apps/admin/src/modules/payment/__tests__/RefundApproval.test.tsx` â€” test: approve button absent when user lacks `payment.refunds.approve`, approve flow shows confirm dialog and calls API with idempotency key, 403 self-approval shows inline error message, 409 conflict shows "already processed" inline error, reject requires non-empty reason (submit disabled when empty), successful approval removes item from queue
- [X] T031 [US3] Write Jest tests in `apps/admin/src/modules/payment/__tests__/PayoutRequests.test.tsx` â€” test: `PayoutStatusBadge` renders correct colour per status, approve-reservation button visible for `reservation_pending` + permission, button absent when status is `executing`/`settled`, "Pending â€” not yet available" badge shown for execute/settle states

**Checkpoint**: US1 + US2 + US3 done â€” approval workflows gated correctly, support users cannot approve.

---

## Phase 5: US4 â€” Disputes & Reconciliation Exceptions (Priority: P2)

**Goal**: Finance operators can view and filter disputes and reconciliation exceptions, update exception statuses with investigation notes, and see linked support case references (read-only).

**Independent Test**: With seeded reconciliation exceptions, the exceptions list renders, filters by `status=open` by default, allows updating status to `investigating` with notes, and resolved items are visually muted and excluded from default view.

- [X] T032 [P] [US4] Create `apps/admin/src/modules/payment/components/DisputeStatusBadge.tsx` â€” badge for `DisputeStatus`; colours: `open`=red, `on_hold`=yellow, `under_review`=blue, `won`=green, `lost`=red-muted, `closed_reversed`=gray
- [X] T033 [US4] Create `apps/admin/src/modules/payment/hooks/useDisputeCases.ts` â€” fetches `listDisputeCases(params)` with filters: status, case_type, requires_finance_review, deadline_before, date_from, date_to; returns paginated `DisputeCaseSummary[]`; provides `authoriseHold(id, payload, idempotencyKey)` calling `authoriseDisputeHold`
- [X] T034 [US4] Create `apps/admin/app/(admin)/payments/finance/disputes/page.tsx` â€” `DisputeCasesPage`; columns: case ID, `DisputeStatusBadge`, case_type, linked intent ID, requires_finance_review flag, deadline_at, created_at; filters: status, case_type, requires_finance_review (checkbox), deadline_before (date); link to detail page; permission: `payment.disputes.view`
- [X] T035 [US4] Create `apps/admin/app/(admin)/payments/finance/disputes/[id]/page.tsx` â€” `DisputeCaseDetailPage`; shows full dispute: chargeback state, linked support case reference (case ID as link with "case not found" fallback if 404), associated intent ID (link to intent timeline), timeline of status changes; action: "Authorise Hold" button (ONLY when user has `payment.disputes.manage`); permission: `payment.disputes.view`
- [X] T036 [US4] Extend `apps/admin/src/modules/payment/hooks/useReconciliationExceptions.ts` (already exists for P20 screen) â€” add filter params: type, status, provider, date_from, date_to; add `updateException(id, {status, notes})` action calling `PATCH /api/v1/admin/payment/reconciliation/exceptions/{id}`; default filter params: `{status: 'open'}`
- [X] T037 [US4] Create `apps/admin/app/(admin)/payments/finance/reconciliation/page.tsx` â€” `ReconciliationExceptionsPage`; uses extended `useReconciliationExceptions` hook; columns: type badge, provider_code, amount+currency (`CurrencyAmount`), `ReconciliationExceptionStatus` badge, notes excerpt, created_at; default filter: `status=open`; "Update Status" inline action â†’ opens popover with status select + notes textarea â†’ submits PATCH; resolved rows visually muted (opacity-50, text-muted-foreground); filter params reflected in URL query string; permission: `payment.reconciliation.view`
- [X] T038 [US4] Write Jest tests in `apps/admin/src/modules/payment/__tests__/ReconciliationExceptions.test.tsx` â€” test: default view filters to `status=open`, resolved items have muted styling, "Update Status" action calls PATCH with correct body, filter params update URL, `DisputeStatusBadge` renders correct colour per status, dispute detail shows "case not found" label when linked case returns 404

**Checkpoint**: US1 + US2 + US3 + US4 done â€” full investigation and exception management suite.

---

## Phase 6: US5 â€” Financial Reports & Audit Evidence (Priority: P3)

**Goal**: Finance users generate filtered reports with currency-separated totals, export to CSV asynchronously with progress indicator, and access audit evidence links per item.

**Independent Test**: With seeded payment data, the reports screen renders a summary table with separate BWP/USD rows (no cross-currency sum row), the export button triggers an async job, polling shows progress, download link appears on completion, and duplicate export submissions are prevented.

- [X] T039 [P] [US5] Create `apps/admin/src/modules/payment/components/ExportProgressBar.tsx` â€” status indicator with `status: 'idle' | 'pending' | 'processing' | 'downloadable' | 'error'`, `rowCount?: number` props; shows spinner + "Exportingâ€¦" when pending/processing; shows download link when downloadable; shows retry when error; button disabled when not idle
- [X] T040 [US5] Create `apps/admin/src/modules/payment/hooks/usePaymentExport.ts` â€” manages export lifecycle: calls `requestExport(payload)` â†’ polls `getExportStatus(exportId)` every 5 seconds; stops polling when status is `downloadable` or `error`; max 60 polls (5 minutes); on timeout: shows "export taking longer than expected â€” check back later" with export ID; tracks in-flight export ID in state to prevent duplicate submissions with same params; returns `{exportStatus, trigger, downloadUrl, isPolling}`
- [X] T041 [US5] Create `apps/admin/app/(admin)/payments/finance/reports/page.tsx` â€” `PaymentReportsPage`; filter form: date_from, date_to, currency (multi-select), provider, scope_type; "Generate Report" button â†’ calls `getPaymentHistory(params)` with aggregation display; results table: one row per currency (BWP row, USD row â€” NEVER a combined row); columns: currency, total count, total amount (`CurrencyAmount`), success count, failed count, success rate; freshness cutoff footnote: "Data authoritative as of {cutoff_at} â€” data after this time may not yet be reconciled"; "Export to CSV" button â†’ triggers `usePaymentExport` â†’ renders `ExportProgressBar`; audit icon per row â†’ links to `/audit?entity_id={id}` (existing audit log viewer from E40); permission: `payment.export.read.organisation`
- [X] T042 [US5] Write Jest tests in `apps/admin/src/modules/payment/__tests__/PaymentReports.test.tsx` â€” test: report renders separate rows for BWP and USD (no combined row), export button disabled while polling in progress, `ExportProgressBar` shows spinner during pending/processing state, download link appears when status is `downloadable`, second export submit blocked while first still in progress, freshness cutoff footnote rendered when `cutoff_at` is present

**Checkpoint**: All 5 user stories done.

---

## Phase 7: Polish & Cross-Cutting Concerns

**Purpose**: TypeScript check, backend PHPStan, permission audit, Docker-gated quickstart, accessibility.

- [X] T043 TypeScript strict-mode check: run `npx tsc --noEmit` from `apps/admin/` â€” 0 errors required on all P21 files (types in `lib/api/payment.ts`, hooks, components, and pages)
- [X] T044 Verify all 11 finance screen routes return access-denied state (not a 404 or crash) for a user with no payment permissions â€” check: `FinanceDashboardPage`, `IntentListPage`, `IntentTimelinePage`, `RefundApprovalQueuePage`, `RefundManagementPage`, `DisputeCasesPage`, `DisputeCaseDetailPage`, `ReconciliationExceptionsPage`, `PayoutRequestsPage`, `PayoutRequestDetailPage`, `PaymentReportsPage` each implement `FR-021` access-denied render path
- [X] T045 [P] Run Jest across all P21 test files: `npx jest src/modules/payment/__tests__/ --coverage` from `apps/admin/` â€” all tests pass; report coverage
- [ ] T046 [P] PHPStan level 8 check (Docker-gated): `docker compose exec api bash -c "REDIS_CLIENT=predis vendor/bin/phpstan analyse app/Modules/Payment/Http/Controllers/Admin/ --level=8"` â€” 0 errors
- [ ] T047 Quickstart validation SC-01â€“SC-10 (Docker-gated): follow `specs/068-admin-finance-dashboard/quickstart.md` â€” all 10 scenarios pass; create `DevPendingRefundSeeder.php` if not already present (needed for SC-04)
- [X] T048 Accessibility check on `FinanceDashboardPage` and `RefundApprovalQueuePage` â€” verify keyboard navigation works for KPI tiles and approve/reject modal, focus trapping in approval modal, aria-labels on status badges, no colour-only information conveyance

---

## Dependencies & Execution Order

### Phase Dependencies

- **Phase 1 (Setup)**: No dependencies â€” start immediately
- **Phase 2 (US1)**: Depends on T001â€“T007 (Phase 1) â€” needs API types + nav + backend endpoints
- **Phase 3 (US2)**: Depends on Phase 1 â€” needs `PaymentApiClient.getIntentTimeline`, `IntentStatusBadge` from US1 can be reused
- **Phase 4 (US3)**: Depends on Phase 1 â€” needs `PaymentApiClient` approve/payout methods; uses `CurrencyAmount` from US2
- **Phase 5 (US4)**: Depends on Phase 1 â€” needs `PaymentApiClient` dispute/exception methods; uses `CurrencyAmount` from US2
- **Phase 6 (US5)**: Depends on Phase 1 â€” needs export API methods; uses `CurrencyAmount` from US2
- **Phase 7 (Polish)**: Depends on all story phases complete

### User Story Dependencies

- **US1 (P1)**: Unblocked after Phase 1 â€” no story dependencies
- **US2 (P1)**: Unblocked after Phase 1 â€” reuses `IntentStatusBadge` from US1 (T010); can start concurrently
- **US3 (P2)**: Unblocked after Phase 1 â€” reuses `CurrencyAmount` (T016); best started after US2 is done
- **US4 (P2)**: Unblocked after Phase 1 â€” reuses `CurrencyAmount` (T016); parallel with US3
- **US5 (P3)**: Unblocked after Phase 1 â€” reuses `CurrencyAmount` (T016); after US3/US4

### Within Each Story

- Components [P] before hooks â†’ hooks before pages â†’ pages before tests
- `CurrencyAmount` (T016) is shared: created in US2, used by US3, US4, US5

### Parallel Opportunities

- T003 and T004 (backend controllers): [P] â€” different files
- T008, T009, T010 (US1 components): [P] â€” different files
- T014, T015, T016 (US2 components): [P] â€” different files
- T021, T022 (US3 badge components): [P] â€” different files
- T032 (US4 badge component): [P] alongside US3 components
- T045, T046 (Jest + PHPStan): [P] â€” independent tools

---

## Parallel Example: Phase 1

```bash
# Backend controllers (run together â€” different files):
Task T003: DashboardSummaryAdminController.php
Task T004: PaymentIntentTimelineAdminController.php

# Then sequentially:
Task T005: Register routes (depends on T003, T004)
Task T006: Nav items (independent, can run alongside T005)
Task T007: PHPStan gate check
```

## Parallel Example: US1 Components

```bash
# All three can run together (different files):
Task T008: FinanceKpiTile.tsx
Task T009: StaleDataBanner.tsx
Task T010: IntentStatusBadge.tsx

# Then sequentially:
Task T011: useDashboardSummary.ts (uses types from T001/T002)
Task T012: FinanceDashboardPage (uses T008, T009, T010, T011)
Task T013: Jest tests
```

---

## Implementation Strategy

### MVP First (US1 + US2 â€” primary DoD)

1. Complete Phase 1: Foundation (T001â€“T007)
2. Complete Phase 2: US1 â€” Finance Dashboard
3. Complete Phase 3: US2 â€” Intent Timeline
4. **STOP and VALIDATE**: SC-001 "navigate from dashboard backlog to intent drill-down in under 3 clicks" passes â†’ primary DoD met
5. Demo to stakeholders

### Incremental Delivery

1. Phase 1 + US1 + US2 â†’ **MVP** (investigation DoD met)
2. + US3 â†’ Approval workflows (governance DoD met)
3. + US4 â†’ Disputes + reconciliation (reconciliation DoD met)
4. + US5 â†’ Reports + export (compliance/reporting complete)
5. Phase 7: Polish

### Total Task Count

| Phase | Tasks | Notes |
|-------|-------|-------|
| Phase 1: Setup | T001â€“T007 + T005a (8 tasks) | Unblocks everything |
| Phase 2: US1 | T008â€“T013 (6 tasks) | MVP P1 |
| Phase 3: US2 | T014â€“T020 (7 tasks) | MVP P1 |
| Phase 4: US3 | T021â€“T031 (11 tasks) | P2 |
| Phase 5: US4 | T032â€“T038 (7 tasks) | P2 |
| Phase 6: US5 | T039â€“T042 (4 tasks) | P3 |
| Phase 7: Polish | T043â€“T048 (6 tasks) | Docker-gated |
| **Total** | **49 tasks** | |

---

## Notes

- `[P]` tasks = different files, no dependencies within the phase
- `[Story]` label maps task to user story for traceability (US1â€“US5)
- `CurrencyAmount` (T016) is created in US2 â€” US3/US4/US5 tasks depend on it existing; verify before starting those phases
- **ApiResponse shape**: All hooks MUST use `res.data as unknown as T` not `res.data.data` (see research.md Decision 1 and the useProviders.ts fix applied in E67)
- T047 requires `DevPendingRefundSeeder.php` for SC-04 â€” create alongside T047 if not already present
- T046â€“T048 are Docker-gated; mark BLOCKED if Docker stack is unavailable
- Commit after each completed checkpoint (end of each phase)
