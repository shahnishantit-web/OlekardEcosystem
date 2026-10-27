# UI Screen Contracts: P21 — Admin Finance Dashboard & Operations Screens

## Screen Inventory

| Route | Component | Required Permission | Story |
|-------|-----------|-------------------|-------|
| `/payments/finance` | `FinanceDashboardPage` | `payment.history.read.platform` | US1 |
| `/payments/finance/intents` | `IntentListPage` | `payment.history.read.platform` | US1/US2 |
| `/payments/finance/intents/[id]` | `IntentTimelinePage` | `payment.intents.admin` | US2 |
| `/payments/finance/refunds` | `RefundManagementPage` | `payment.refunds.view` | US3 |
| `/payments/finance/refunds/approvals` | `RefundApprovalQueuePage` | `payment.refunds.approve` | US3 |
| `/payments/finance/disputes` | `DisputeCasesPage` | `payment.disputes.view` | US4 |
| `/payments/finance/disputes/[id]` | `DisputeCaseDetailPage` | `payment.disputes.view` | US4 |
| `/payments/finance/reconciliation` | `ReconciliationExceptionsPage` | `payment.reconciliation.view` | US4 |
| `/payments/finance/payouts` | `PayoutRequestsPage` | `payment.payout.view` | US3 |
| `/payments/finance/payouts/[id]` | `PayoutRequestDetailPage` | `payment.payout.view` | US3 |
| `/payments/finance/reports` | `PaymentReportsPage` | `payment.export.read.organisation` | US5 |

---

## Navigation Integration

Add to `src/lib/navigation/nav-items.ts` under the Payments group:

```
{ href: '/payments/finance', label: 'Finance Dashboard', icon: BarChart3, requiredPermission: 'payment.history.read.platform' }
{ href: '/payments/finance/refunds/approvals', label: 'Refund Approvals', icon: CheckSquare, requiredPermission: 'payment.refunds.approve' }
{ href: '/payments/finance/disputes', label: 'Disputes', icon: AlertTriangle, requiredPermission: 'payment.disputes.view' }
{ href: '/payments/finance/reconciliation', label: 'Reconciliation', icon: RefreshCw, requiredPermission: 'payment.reconciliation.view' }
{ href: '/payments/finance/payouts', label: 'Payouts', icon: ArrowUpRight, requiredPermission: 'payment.payout.view' }
{ href: '/payments/finance/reports', label: 'Reports', icon: FileText, requiredPermission: 'payment.export.read.organisation' }
```

---

## Component Contracts

### FinanceDashboardPage
**Behaviour:**
- Fetches `GET /api/v1/admin/payment/dashboard-summary` on mount
- Shows 3 KPI tile groups: Volume (by currency), Attempts (by status), Backlog count
- Shows freshness banner if `(Date.now() - fetchedAt) > STALE_THRESHOLD_SECONDS * 1000`
- Shows paginated intent list from `GET /api/v1/admin/payment/history?per_page=20` filtered to `has_pending_fulfilment=true` for the backlog section
- Each backlog row has a "Query Status" button → `POST /api/v1/admin/payment/intents/{id}/query-status` → shows 202 toast "Status query dispatched"

**States:**
- `loading` → skeleton tiles
- `error` → error banner + retry button
- `stale` → amber banner "Data may be outdated — last refreshed at {time}" + refresh button
- `empty` → "No payment activity today" message

---

### IntentTimelinePage (`/payments/finance/intents/[id]`)
**Behaviour:**
- Fetches `GET /api/v1/admin/payment/intents/{id}/timeline`
- Renders chronological `TimelineItem` list: intent created → each attempt → ledger posted → fulfilment
- Each attempt shows: attempt number, provider code, status badge, timestamps, failure reason (if any)
- Each `LedgerJournalRef` renders: description, posted_at, debit/credit lines, audit link
- "Query Provider Status" button visible only when `intent.status === 'captured'` AND user has `payment.intents.admin`
- Provider reference field renders as `****` + last 4 chars if present

**States:**
- `loading` → skeleton timeline
- `not_found` → "Payment intent not found" with back button
- `no_attempts` → shows intent row then "No attempts — intent created but not yet submitted"
- `no_journals` → shows attempts but "No ledger journals yet"

---

### RefundApprovalQueuePage (`/payments/finance/refunds/approvals`)
**Behaviour:**
- Fetches `GET /api/v1/admin/payment/refunds` (pending approval queue)
- Each row: amount, currency, intent link, requestor, reason, requested_at
- "Approve" button → POST `/refunds/{id}/approve` with `Idempotency-Key` header
- "Reject" button → opens modal requiring rejection reason → POST `/refunds/{id}/reject`
- Self-approval (SELF_APPROVAL_NOT_ALLOWED 403) → shows inline error "You cannot approve your own refund request"
- Conflict (409) → shows inline error "This refund has already been processed"
- Approve/Reject buttons ONLY render when user has `payment.refunds.approve` (checked client-side as supplement to server enforcement)

**States:**
- `loading` → skeleton rows
- `empty` → "No refunds pending approval"
- `error` → error banner + retry
- `self_approval_blocked` → inline per-row error
- `conflict` → inline per-row error

---

### ReconciliationExceptionsPage (`/payments/finance/reconciliation`)
**Behaviour:**
- Fetches `GET /api/v1/admin/payment/reconciliation/exceptions` with filters
- Filters: status (open/investigating/escalated/resolved), type, provider, date range
- Default filter: `status=open` (resolved excluded from default view)
- "Update Status" action → PATCH `/reconciliation/exceptions/{id}` with `{status, notes}`
- Resolved rows visually muted (opacity-50 or `text-muted-foreground`)

---

### PayoutRequestsPage (`/payments/finance/payouts`)
**Behaviour:**
- Fetches `GET /api/v1/admin/payment/payout/requests` with status filter
- Each row: fund_reference_id, purpose_type, organisation, currency, computed_payable_minor, status
- Link to detail page
- "Approve Reservation" button (status=`reservation_pending`, permission=`payment.payout.approve`) → POST `/payout/requests/{id}/approve-reservation` with `Idempotency-Key`
- Execute and Confirm-Settlement actions show "Pending — not yet available" badge (status only; no button)

---

### PaymentReportsPage (`/payments/finance/reports`)
**Behaviour:**
- Filters: date_from, date_to, currency, provider, scope_type
- "Generate Report" renders inline summary table from `GET /api/v1/admin/payment/history` with aggregate display
- "Export to CSV" → POST `/api/v1/admin/payment/exports` → polls `GET /exports/{id}` every 5s
- Export button disabled while export in progress (same params); shows spinner
- On `downloadable` status → shows download link (opens `/api/v1/payment/exports/download/{token}`)
- Currency rows never aggregated: separate rows for BWP and USD totals
- Freshness cutoff timestamp shown as footnote

---

## Shared Finance Components (new)

| Component | Props | Purpose |
|-----------|-------|---------|
| `IntentStatusBadge` | `status: IntentStatus` | Coloured badge for intent state |
| `RefundStatusBadge` | `status: RefundStatus` | Coloured badge for refund state |
| `PayoutStatusBadge` | `status: PayoutRequestStatus` | Coloured badge for payout state |
| `DisputeStatusBadge` | `status: DisputeStatus` | Coloured badge for dispute state |
| `CurrencyAmount` | `amountMinor: number, currency: string` | Formatted amount e.g. "BWP 150.00" |
| `TimelineEvent` | `type, title, timestamp, detail?` | Single item in a chronological timeline |
| `LedgerJournalCard` | `journal: LedgerJournalRef` | Debit/credit table + audit link |
| `StaleDataBanner` | `fetchedAt: Date, threshold: number` | Amber freshness warning with refresh |
| `ExportProgressBar` | `status, rowCount?` | Async export status indicator |
| `FinanceKpiTile` | `label, value, currency?, trend?` | Dashboard KPI card |

---

## API Client Extensions (new methods on `PaymentApiClient`)

```typescript
// Dashboard
getDashboardSummary(): Promise<ApiResponse<DashboardSummary>>

// Intent timeline
getIntentTimeline(id: string): Promise<ApiResponse<PaymentIntentTimeline>>
queryIntentStatus(id: string): Promise<ApiResponse<{ message: string }>>  // 202

// Refund management (existing partially — extend)
listRefundManagement(params: RefundListParams): Promise<ApiResponse<PaginatedResponse<RefundListItem>>>
getRefundDetail(id: string): Promise<ApiResponse<RefundListItem>>
queryRefundStatus(id: string): Promise<ApiResponse<{ message: string }>>  // 202

// Dispute cases (new)
listDisputeCases(params: DisputeFilterParams): Promise<ApiResponse<PaginatedResponse<DisputeCaseSummary>>>
getDisputeCase(id: string): Promise<ApiResponse<DisputeCaseSummary>>
authoriseDisputeHold(id: string, payload: HoldPayload, idempotencyKey: string): Promise<ApiResponse<{ message: string }>>

// Payout requests (new)
listPayoutRequests(params: PayoutFilterParams): Promise<ApiResponse<PaginatedResponse<PayoutRequestSummary>>>
getPayoutRequest(id: string): Promise<ApiResponse<PayoutRequestSummary>>
approvePayoutReservation(id: string, idempotencyKey: string): Promise<ApiResponse<{ message: string }>>
rejectPayoutReservation(id: string, payload: { reason: string }): Promise<ApiResponse<{ message: string }>>

// History / reports (extend existing)
getPaymentHistory(params: HistoryParams): Promise<ApiResponse<PaginatedResponse<PaymentIntentSummary>>>

// Export
requestExport(payload: ExportRequest): Promise<ApiResponse<{ export_id: string; status: string }>>
getExportStatus(exportId: string): Promise<ApiResponse<ExportStatus>>
```
