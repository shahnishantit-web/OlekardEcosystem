# Implementation Plan — E19 Admin Portal

## Constitution Check

- [x] Approved Laravel/Next.js/Flutter stack respected — backend Laravel/PHP 8.4+; frontend Next.js + TypeScript; no stack change
- [x] No TBD value invented — all dashboard metrics pull from domain module contracts; "provider selection" note removed from spec
- [x] Authorization remains server-side — all `/v1/admin/` routes require `auth:sanctum` + `admin.*` permission; maker-checker enforced in AdminWalletAdjustmentService
- [x] Financial integrity preserved — wallet adjustments post via E07 LedgerService (double-entry); transaction reversals post compensating entries; no direct balance mutation
- [x] Security/audit requirements incorporated — every state-changing admin action writes an immutable `AdminAction` record; all audit events emitted via AuditServiceInterface
- [x] UX/design-system requirements incorporated — Next.js Admin Portal is desktop-first; shared design tokens applied; confirmation screens for destructive actions
- [x] QA strategy incorporated — 10 quickstart scenarios; unit tests for services; integration tests for all endpoints; concurrency tests for maker-checker

## Technical Context

- **Backend:** Laravel / PHP 8.4+
- **Module path:** `App\Modules\Admin\`
- **Database:** PostgreSQL — `admin` schema; 2 new tables (`admin_actions`, `admin_wallet_adjustments`)
- **Queue/cache:** Redis + Laravel Horizon — export jobs on `admin` queue; dashboard stats cached 5 min
- **API:** REST/OpenAPI — 4 contract files in `contracts/`
- **Frontend:** Next.js 14 + TypeScript — `wallet-ecosystem/apps/admin/` (existing app)
- **Testing:** PHPUnit/Pest for backend; Playwright/Jest for Next.js admin UI
- **Observability:** structured logs with `correlation_id`; `admin.*` metrics; AuditServiceInterface
- **Dependencies (consumed):**

  | Module | Contract / Service | Used For |
  |--------|-------------------|----------|
  | E01 Central Identity | `UserService` (internal) | Suspend, reactivate, reset-auth |
  | E02 User Profile | `UserProfileModuleContract` | View user profile in admin detail |
  | E03 Device & Session | `DeviceSessionService` (internal) | View sessions, terminate session |
  | E04 Permissions | Permission seeder infrastructure | `admin.*` permission set |
  | E05 KYC | `KycModuleContract::getKycTierForUser()` | KYC status display in user profile |
  | E06 Wallet | `WalletModuleContract::getWalletSummary()` | Wallet balance, status for admin view |
  | E07 Ledger | `LedgerModuleContract::postEntry()` | Post adjustment and reversal journal entries |
  | E11 Transaction | `TransactionModuleContract::listTransactions()` | Search/view/export transactions |
  | E12 Agent | `AgentModuleContract::getAgentProfile()` | Agent statistics in dashboard |
  | E18 Risk | `RiskEvaluatorInterface` / `AccountHoldService` | Freeze/unfreeze wallet (delegates to E18 hold); view risk alerts |

- **Integration contract (published by this module):** None — Admin module is a consumer; all admin actions are surfaced as `admin.*` audit events for E23 (Audit & Compliance).

## Module Tree

```
App\Modules\Admin\
├── Domain\
│   ├── Contracts\
│   │   └── AdminMetricsInterface.php         ← dashboard metric aggregation contract
│   ├── Enums\
│   │   ├── AdminActionType.php               ← suspend_user | reactivate_user | reset_auth | freeze_wallet | unfreeze_wallet | wallet_adjustment | transaction_reversal | transaction_refund | terminate_session
│   │   └── AdjustmentStatus.php             ← pending_approval | approved | rejected | posted
│   └── Models\
│       ├── AdminAction.php                   ← immutable audit log (append-only)
│       └── AdminWalletAdjustment.php         ← maker-checker wallet adjustment workflow
├── Application\
│   └── Services\
│       ├── DashboardService.php             ← aggregates KPIs from E01/E05/E06/E11/E12/E18
│       ├── UserAdminService.php             ← suspend, reactivate, reset-auth, list sessions (delegates to E01/E03)
│       ├── WalletAdminService.php           ← search/view, freeze/unfreeze (delegates to E06/E18), adjustment workflow
│       ├── TransactionAdminService.php      ← search/view, reversal, refund, export (delegates to E07/E11)
│       └── AdminMetricsService.php          ← implements AdminMetricsInterface; emits StatsD counters
├── Infrastructure\
│   ├── Http\
│   │   ├── Controllers\
│   │   │   ├── AdminDashboardController.php ← US-ADMIN-001–008
│   │   │   ├── AdminUserController.php      ← US-ADMIN-009–014
│   │   │   ├── AdminWalletController.php    ← US-ADMIN-015–021
│   │   │   └── AdminTransactionController.php ← US-ADMIN-022–027
│   │   ├── Requests\
│   │   │   └── [one FormRequest per state-changing endpoint]
│   │   └── Resources\
│   │       └── [one ApiResource per response schema]
│   ├── Migrations\
│   │   ├── 2026_09_26_000001_create_admin_actions_table.php
│   │   └── 2026_09_26_000002_create_admin_wallet_adjustments_table.php
│   └── Providers\
│       └── AdminServiceProvider.php
└── routes\
    └── admin.php

Next.js Admin Portal (wallet-ecosystem/apps/admin/):
├── app/
│   ├── (dashboard)/
│   │   └── page.tsx                         ← US-ADMIN-001–008 dashboard KPIs
│   ├── users/
│   │   ├── page.tsx                         ← US-ADMIN-009 search users
│   │   └── [id]/
│   │       ├── page.tsx                     ← US-ADMIN-010 user profile + actions
│   │       └── sessions/page.tsx            ← US-ADMIN-014 user sessions
│   ├── wallets/
│   │   ├── page.tsx                         ← US-ADMIN-015 search wallets
│   │   └── [id]/
│   │       ├── page.tsx                     ← US-ADMIN-016–018 wallet detail + freeze/unfreeze
│   │       ├── ledger/page.tsx              ← US-ADMIN-019 wallet ledger
│   │       └── adjustment/page.tsx          ← US-ADMIN-020–021 adjustment + approval
│   └── transactions/
│       ├── page.tsx                         ← US-ADMIN-022 search transactions
│       └── [id]/
│           └── page.tsx                     ← US-ADMIN-023–026 detail + reverse/refund
└── components/admin/
    ├── DashboardKpiCard.tsx
    ├── UserActionModal.tsx
    ├── WalletFreezeModal.tsx
    ├── AdjustmentMakerForm.tsx
    ├── AdjustmentCheckerApproval.tsx
    └── TransactionExportButton.tsx
```

## Phase 0 — Research

Complete. See `research.md`. All unknowns resolved:
1. **Dashboard aggregation strategy:** 5-minute Redis cache with cache-aside pattern; real-time fallback for <500ms queries
2. **Module boundary:** Thin `/v1/admin/` API layer delegating to domain services — admin module owns only `AdminAction` and `AdminWalletAdjustment`
3. **Controlled wallet adjustment:** Double-entry via E07 `LedgerModuleContract::postEntry()` with `admin_adjustment` entry type; compensating account = `admin_suspense`
4. **Transaction reversal vs. refund:** Reversal = compensating ledger entries on same wallet; refund = new credit entry via E07 to return funds
5. **Export:** Synchronous CSV for ≤10,000 rows; async job (`admin` queue) with signed download URL for larger exports

## Phase 1 — Design

Complete. Artifacts:
- `data-model.md` — 2 new entities (`AdminAction`, `AdminWalletAdjustment`)
- `contracts/admin-dashboard.yaml` — US-ADMIN-001–008
- `contracts/admin-users.yaml` — US-ADMIN-009–014
- `contracts/admin-wallets.yaml` — US-ADMIN-015–021
- `contracts/admin-transactions.yaml` — US-ADMIN-022–027
- `quickstart.md` — SC-1 through SC-10

## Implementation Approach

1. Confirm cross-module contracts (E01 UserService, E06 WalletModuleContract, E07 LedgerService, E11 TransactionModuleContract) exist and are importable.
2. Scaffold `AdminServiceProvider`, route file, and module folder structure.
3. Run migrations to create `admin` schema tables.
4. Implement `DashboardService` with Redis-cached aggregations.
5. Implement `UserAdminService`, `WalletAdminService`, `TransactionAdminService` — each delegating to respective domain contracts.
6. Implement `AdminWalletAdjustmentService` with maker-checker state machine.
7. Implement HTTP layer: controllers, form requests, resources.
8. Implement export job (`ExportTransactionsJob`) for large datasets.
9. Write backend tests (unit + integration + concurrency for maker-checker).
10. Implement Next.js admin portal pages consuming the new `/v1/admin/` endpoints.
11. Write PHPStan check; generate OpenAPI doc (`openapi/v1/admin.yaml`).

## Data / Financial Controls

- `AdminAction` is **append-only** — no UPDATE or DELETE (enforced in `AdminAction::booted()`)
- `AdminWalletAdjustment` enforces `maker_id ≠ checker_id` via DB CHECK constraint AND service-layer guard
- Wallet adjustment posts via `E07 LedgerModuleContract::postEntry()` — MUST balance (debit == credit); no direct wallet balance mutation
- Wallet adjustment **reject** does NOT call `postEntry()`; the `DB::transaction()` in `rejectAdjustment()` is used only to acquire `lockForUpdate()` atomicity
- Transaction reversal posts compensating entries via `E07 LedgerModuleContract::postEntry()` with `entry_type = 'admin_reversal'`; original transaction updated to `status = reversed`
- Transaction refund creates a new journal credit via `E07 LedgerModuleContract::postEntry()` with `entry_type = 'admin_refund'`
- `AdminWalletAdjustment` has UNIQUE constraint on `idempotency_key` — concurrent duplicate submissions return the existing record
- Dashboard stats cached in Redis with 5-minute TTL; stale reads preferred over slow queries during cache miss
- Export jobs run on dedicated `admin` queue to avoid starving transactional queues

## Audit Events

| Event Key | Trigger | Actor Type |
|-----------|---------|------------|
| `admin.user.suspended` | UserAdminService suspends user | admin |
| `admin.user.reactivated` | UserAdminService reactivates user | admin |
| `admin.user.auth_reset` | UserAdminService resets user credentials | admin |
| `admin.user.session_terminated` | UserAdminService terminates session | admin |
| `admin.wallet.frozen` | WalletAdminService places account hold | admin (maker + checker) |
| `admin.wallet.unfrozen` | WalletAdminService lifts account hold | admin |
| `admin.wallet.adjustment.created` | AdminWalletAdjustmentService maker creates | admin (maker) |
| `admin.wallet.adjustment.approved` | AdminWalletAdjustmentService checker approves | admin (checker) |
| `admin.wallet.adjustment.rejected` | AdminWalletAdjustmentService checker rejects | admin (checker) |
| `admin.wallet.adjustment.posted` | Ledger journal posted (inside checker's approve transaction) | admin (checker) |
| `admin.transaction.reversed` | TransactionAdminService posts compensating entries | admin |
| `admin.transaction.refunded` | TransactionAdminService posts refund credit | admin |
| `admin.transaction.exported` | TransactionAdminService generates export file | admin |

## Observability

**Metrics** (StatsD/Prometheus):

| Metric | Labels | Description |
|--------|--------|-------------|
| `admin.dashboard.load_ms` | `cached` | Dashboard KPI aggregation wall time |
| `admin.user.action` | `action_type` | User admin action count |
| `admin.wallet.action` | `action_type` | Wallet admin action count |
| `admin.wallet.adjustment.total` | `status` | Adjustment lifecycle events |
| `admin.transaction.action` | `action_type` | Transaction admin action count |
| `admin.export.rows` | `format` | Rows exported per export request |

**Logging:** All admin actions logged at INFO with `correlation_id`, `actor_id`, `subject_type`, `subject_id`. Financial actions logged at WARNING.

**SLA:** Dashboard KPI endpoint ≤ 500ms p99 (cache hit); ≤ 2s p99 (cache miss). All other admin endpoints ≤ 300ms p99.
