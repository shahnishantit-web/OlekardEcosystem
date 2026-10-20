# Implementation Plan — E27 Reliability & Transaction Integrity

## Constitution Check

- [x] Approved Laravel/Next.js/Flutter stack respected — no new stack additions; all implementation uses existing PHP/Laravel, Next.js admin, existing modules
- [x] No TBD value invented — all configuration defaults use env-var-backed values; TBD vendor selections remain TBD
- [x] Authorization remains server-side — new `operations.dead_letter.manage` permission enforced by `identity.require-permission` middleware
- [x] Financial integrity preserved — E27 strengthens financial integrity controls (idempotency enforcement, ledger scan, orphan recovery); no ledger mutations outside LedgerPostingService
- [x] Security/audit requirements incorporated — all dead-letter replay and dismissal actions are audit-logged; ledger discrepancies emit alert audit events
- [x] UX/design-system requirements incorporated — dead-letter admin portal uses shared design tokens and existing admin layout
- [x] QA strategy incorporated — SC-001 through SC-007 in quickstart.md; unit + feature tests per story; concurrency tests for duplicate replay prevention

## Technical Context

- Backend: Laravel / PHP 8.4+, `wallet-ecosystem/services/api`
- Database: PostgreSQL, `platform` schema for new tables; per-module schemas for existing transaction tables
- Queue/cache: Redis + Laravel Horizon — no new queue workers; new jobs added to existing `platform` and `reliability` queues
- Web: Next.js + TypeScript, `wallet-ecosystem/apps/admin` — new dead-letter admin section
- Mobile: Flutter — no E27 mobile scope
- API: REST/OpenAPI — `contracts/reliability-admin-api.yaml`
- Testing: PHPUnit feature + unit tests; concurrency tests for duplicate prevention

## Key Architecture Decisions (see research.md for rationale)

| Topic | Decision |
|-------|----------|
| Idempotency enforcement | Extend `IdempotencyMiddleware` with `require` parameter; add `idempotency:require` to all financial mutation routes |
| Callback deduplication | Audit `provider_reference_id` uniqueness guard in Withdrawal + QR (Funding already has it) |
| Orphan detection | Module-specific timeout jobs (Funding/Transfer/Withdrawal); `OrphanDetectableInterface` contract |
| Ledger integrity | New `LedgerIntegrityJob` (hourly); `platform.ledger_integrity_snapshots` table |
| Correlation ID propagation | New `HasCorrelationId` trait for jobs; audit all financial jobs |
| Dead-letter store | New `platform.dead_letter_items` table + `DeadLetterService` + admin portal page |
| Retry configuration | New `config/reliability.php` backed by `ConfigurationServiceInterface` |

## New Module Structure

### Platform module additions

```
app/
├── Http/Middleware/
│   └── IdempotencyMiddleware.php          ← MODIFY: add 'require' parameter
├── Jobs/
│   ├── LedgerIntegrityJob.php             ← NEW: hourly ledger scan
│   └── DeadLetterCleanupJob.php           ← NEW: purge expired items
├── Modules/Platform/
│   ├── Application/Services/
│   │   └── DeadLetterService.php          ← NEW: capture/list/replay/dismiss
│   ├── Concerns/
│   │   └── HasCorrelationId.php           ← NEW: job trait
│   ├── Domain/Contracts/
│   │   ├── DeadLetterServiceInterface.php ← NEW
│   │   └── OrphanDetectableInterface.php  ← NEW
│   ├── Infrastructure/
│   │   ├── Http/Controllers/
│   │   │   ├── DeadLetterAdminController.php      ← NEW
│   │   │   └── LedgerIntegrityAdminController.php ← NEW
│   │   ├── Http/Requests/
│   │   │   ├── ReplayDeadLetterRequest.php        ← NEW
│   │   │   └── DismissDeadLetterRequest.php       ← NEW
│   │   ├── Http/Resources/
│   │   │   ├── DeadLetterItemResource.php         ← NEW
│   │   │   └── LedgerIntegritySnapshotResource.php ← NEW
│   │   ├── Migrations/
│   │   │   ├── 2026_10_02_027001_create_platform_dead_letter_items_table.php ← NEW
│   │   │   └── 2026_10_02_027002_create_platform_ledger_integrity_snapshots_table.php ← NEW
│   │   ├── Models/
│   │   │   ├── DeadLetterItemModel.php            ← NEW
│   │   │   └── LedgerIntegritySnapshotModel.php   ← NEW
│   │   └── Repositories/
│   │       ├── EloquentDeadLetterRepository.php   ← NEW
│   │       └── DeadLetterRepositoryInterface.php  ← NEW
│   └── Providers/
│       └── PlatformServiceProvider.php            ← MODIFY: register new bindings + routes
└── Console/Kernel.php                             ← MODIFY: add LedgerIntegrityJob + DeadLetterCleanupJob schedules
```

### Per-module additions (Funding, Transfer, Withdrawal)

```
app/Modules/{Funding|Transfer|Withdrawal}/
├── Application/
│   ├── Jobs/
│   │   └── {Module}TransactionTimeoutJob.php  ← NEW per missing module
│   └── Services/
│       └── {Module}OrphanRecoveryService.php  ← NEW per missing module
└── Infrastructure/Migrations/
    └── add_dead_lettered_at_to_{module}_transactions.php ← NEW
```

### Admin Portal

```
apps/admin/
├── app/(admin)/
│   └── operations/
│       └── dead-letter/
│           └── page.tsx                  ← NEW route wrapper
└── src/modules/operations/pages/
    └── dead-letter.tsx                   ← NEW: list + replay + dismiss UI
```

### Routes

```
routes/modules/reliability.php            ← NEW: dead-letter + ledger-integrity routes
```

## Cross-Cutting Changes

| File | Change |
|------|--------|
| `app/Http/Middleware/IdempotencyMiddleware.php` | Add `require` mode — reject requests with no `Idempotency-Key` when `$requireKey = true` |
| All financial POST/PATCH routes (E08–E16 route files) | Add `idempotency:require` middleware to mutation routes |
| `app/Modules/Funding/Application/Services/ProviderCallbackService.php` | Confirm `provider_reference_id` deduplication exists ✓; add `HasCorrelationId` to `FundingInitiationJob` |
| `app/Modules/Withdrawal/Application/Services/WithdrawalConfirmationService.php` | Add `provider_reference_id` uniqueness check if missing |
| `app/Modules/Qr/Application/Services/QrPaymentService.php` | Add `provider_reference_id` uniqueness check if missing |
| All financial queued jobs | Add `HasCorrelationId` trait + `correlationId` constructor parameter |
| `app/Console/Kernel.php` | Add `LedgerIntegrityJob` (hourly), `DeadLetterCleanupJob` (daily), `FundingTransactionTimeoutJob`, `TransferTransactionTimeoutJob`, `WithdrawalTransactionTimeoutJob` (every 5 minutes) |

## New Permissions

| Permission | Description |
|-----------|-------------|
| `operations.dead_letter.manage` | View, replay, and dismiss dead-letter items |

Seed in `E27ReliabilityPermissionsSeeder`.

## Admin Portal — New Section

New page: `/admin/operations/dead-letter` — dead-letter item list with status filter, type filter, date range filter, replay action, dismiss action with required justification.

Permission guard: `operations.dead_letter.manage`.

## New Artisan Commands

| Command | Purpose |
|---------|---------|
| `reliability:detect-orphans` | Run orphan detection scan across all registered financial modules |
| `reliability:scan-ledger-integrity` | Run ledger integrity scan once |
| `reliability:purge-dead-letter` | Purge expired dead-letter items |
| `reliability:audit-idempotency-coverage` | Report financial endpoints missing Idempotency-Key enforcement |

## New Config File

```
config/reliability.php    ← NEW: env-var defaults for all reliability thresholds
```

## OpenAPI Documentation

```
openapi/v1/reliability.yaml   ← NEW: documents dead-letter + ledger-integrity endpoints
```

## Implementation Order

1. **Foundation**: `config/reliability.php`, `HasCorrelationId` trait, `OrphanDetectableInterface`
2. **IdempotencyMiddleware enhancement**: Add `require` mode; apply to all financial routes
3. **Dead-letter infrastructure**: migration, model, repository, `DeadLetterService`, routes, controller
4. **Ledger integrity**: migration, model, `LedgerIntegrityJob`, controller
5. **Orphan detection**: `FundingTransactionTimeoutJob`, `TransferTransactionTimeoutJob`, `WithdrawalTransactionTimeoutJob`; Kernel schedule
6. **Callback deduplication audit**: verify/add provider_reference_id guard in Withdrawal + QR
7. **Correlation ID in jobs**: Add `HasCorrelationId` trait to all financial module jobs
8. **Admin portal**: dead-letter page, nav section
9. **Artisan commands**: 4 CLI commands with tests
10. **Permissions seeder** + OpenAPI docs
11. **Tests**: idempotency coverage tests, dead-letter CRUD tests, orphan detection tests, ledger scan tests, correlation ID propagation tests

## Phase 0 — Research ✓ COMPLETE

See `research.md` — all decisions resolved.

## Phase 1 — Design ✓ COMPLETE

- `data-model.md` — `platform.dead_letter_items`, `platform.ledger_integrity_snapshots`, per-module `dead_lettered_at` columns, `OrphanDetectableInterface`
- `contracts/reliability-admin-api.yaml` — dead-letter + ledger integrity endpoints
- `quickstart.md` — SC-001 through SC-007 validation scenarios + Artisan command reference
