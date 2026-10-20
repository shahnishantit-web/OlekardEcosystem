# Implementation Plan — E09 Wallet-to-Wallet Transfer

## Constitution Check

- [x] Approved Laravel/Next.js/Flutter stack respected (backend: Laravel/PHP 8.4+, DB: PostgreSQL, cache/queue: Redis+Horizon)
- [x] No TBD value invented — 5 TBDs remain configurable via `PlatformConfigurationService`; no hard-coded values
- [x] Authorization remains server-side — ownership, permissions, and step-up enforced in application/domain services
- [x] Financial integrity preserved — double-entry journal, LedgerModuleContract::postEntry(), atomic DB transaction, no direct balance mutation
- [x] Security/audit requirements incorporated — PIN never logged, audit on all mutations, IDOR guard via lookup_token, rate limiting
- [x] QA strategy incorporated — unit, integration/feature, concurrency, audit integration tests specified
- [x] IdentityModuleContract::verifyTransactionPin() extended with `requireStepUp` bool param (backward-compatible default=false)

## Technical Context

- Backend: Laravel / PHP 8.4+
- Database: PostgreSQL (schema: `public`, module prefix: `transfer_`)
- Queue/cache: Redis + Laravel Horizon (lookup tokens in Redis; outbox events via Horizon)
- Web: Next.js + TypeScript (Admin portal: read-only transfer views via E19)
- Mobile: Flutter (Customer app: E31 scope; API contract defined here)
- API: REST/OpenAPI — all routes under `/api/v1/transfers` and `/api/v1/admin/transfers`
- Testing: Pest/PHPUnit for backend; concurrency tests with forked processes
- Observability: structured logging with correlation_id, metrics via `TransferMetricsService`, audit via platform AuditService

## Module Structure

```
app/Modules/Transfer/
├── Application/
│   ├── Services/
│   │   ├── RecipientLookupService.php        — resolve recipient, issue lookup_token
│   │   ├── TransferInitiationService.php     — validate, fee-calc, create pending tx
│   │   ├── TransferConfirmationService.php   — re-validate, PIN, journal post, receipt
│   │   ├── TransferCancellationService.php   — pending → cancelled
│   │   ├── TransferFeeService.php            — fee_amount = round(gross * bps / 10000)
│   │   ├── TransferLimitService.php          — min/max/daily limit checks
│   │   ├── TransferReferenceGenerator.php    — TXF-{10CHAR} unambiguous alphabet
│   │   └── LookupTokenService.php            — Redis issue/consume/expire
│   └── Contracts/
│       └── TransferRiskService.php           — interface; E09 stub always approves
├── Domain/
│   ├── Enums/
│   │   ├── TransferStatus.php               — pending, completed, failed, cancelled
│   │   └── TransferFailureReason.php        — INSUFFICIENT_BALANCE, DAILY_LIMIT_EXCEEDED,
│   │                                           RISK_REJECTED, RECIPIENT_WALLET_SUSPENDED, SYSTEM_ERROR
│   ├── Events/
│   │   ├── TransferInitiated.php
│   │   ├── TransferCompleted.php
│   │   ├── TransferFailed.php
│   │   ├── TransferCancelled.php
│   │   └── TransferRiskRejected.php
│   └── Exceptions/
│       ├── TransferNotModifiableException.php
│       ├── TransferNotCancellableException.php
│       ├── TransferReceiptImmutableException.php
│       ├── InsufficientBalanceException.php
│       ├── DailyLimitExceededException.php
│       ├── InvalidLookupTokenException.php
│       ├── RecipientNotFoundException.php
│       ├── RecipientWalletInactiveException.php
│       ├── SelfTransferException.php
│       └── FeeMismatchException.php
├── Infrastructure/
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── RecipientLookupController.php
│   │   │   ├── TransferController.php        — initiate, confirm, cancel, index, show
│   │   │   ├── TransferReceiptController.php — show (by tx id), showByReference
│   │   │   └── AdminTransferController.php
│   │   ├── Requests/
│   │   │   ├── RecipientLookupRequest.php
│   │   │   ├── TransferInitiateRequest.php
│   │   │   └── TransferConfirmRequest.php
│   │   └── Resources/
│   │       ├── TransferResource.php
│   │       └── TransferReceiptResource.php
│   ├── Models/
│   │   ├── TransferTransactionModel.php      — immutability enforced on completed/failed/cancelled
│   │   └── TransferReceiptModel.php          — immutable after insert
│   └── Stubs/
│       └── TransferRiskServiceStub.php       — always returns RiskResult::approved()
├── Console/
│   └── ExpirePendingTransfersCommand.php     — transfer:expire-pending (scheduled 1 min)
├── Providers/
│   └── TransferServiceProvider.php
└── TransferModuleContract.php                — public interface for cross-module queries (if needed)
```

## Database Migrations

| Migration | Description |
|-----------|-------------|
| `create_transfer_transactions_table` | Core transfer entity with all fields from FR-E09-001 |
| `create_transfer_receipts_table` | Immutable receipt entity (FR-E09-005) |
| `seed_transfer_permissions` | Seeds `transfer.initiate`, `transfer.read`, `transfer.admin` permissions |
| `seed_transfer_platform_config` | Seeds placeholder config keys (all TBDs) with null/0 defaults |

### Key Indexes

**transfer_transactions:**
- `PRIMARY KEY (id)`
- `UNIQUE (transfer_reference)`
- `UNIQUE (idempotency_key) WHERE idempotency_key IS NOT NULL`
- `INDEX (sender_customer_id, created_at DESC)`
- `INDEX (recipient_customer_id, created_at DESC)`
- `INDEX (status, created_at DESC)`

**transfer_receipts:**
- `PRIMARY KEY (id)`
- `UNIQUE (transfer_transaction_id)`
- `UNIQUE (receipt_reference)`
- `INDEX (sender_customer_id)`
- `INDEX (recipient_customer_id)`

## Service Dependency Map

```
HTTP Request
    └── Controller (thin)
            └── TransferInitiationService
                    ├── LookupTokenService          (Redis GET + DEL)
                    ├── WalletModuleContract         (getPocketLedgerAccountRef × 2)
                    ├── WalletModuleContract         (getWalletOwnerId)
                    ├── IdentityModuleContract       (getCustomerKycTier)
                    ├── TransferFeeService           (fee_amount, net_amount, fee_rate_bps)
                    ├── TransferLimitService         (min/max/daily checks)
                    ├── LedgerModuleContract         (getBalance — soft pre-check)
                    └── TransferRiskService          (evaluate — stub)

    └── TransferConfirmationService
            ├── IdentityModuleContract::verifyTransactionPin(requireStepUp)
            ├── TransferFeeService                  (re-validate fee_rate_bps)
            ├── TransferLimitService                (daily limit re-check)
            └── LedgerModuleContract::postEntry()   ← atomic DB transaction
                    ├── UPDATE transfer_transactions SET status = 'completed', ledger_journal_id, completed_at
                    └── INSERT transfer_receipts
```

## Events

| Class | Event Name | Emitted By |
|-------|-----------|------------|
| `TransferInitiated` | `Transfer.Initiated` | TransferInitiationService (after DB commit) |
| `TransferCompleted` | `Transfer.Completed` | TransferConfirmationService (after DB commit) |
| `TransferFailed` | `Transfer.Failed` | TransferConfirmationService / ExpirePendingTransfersCommand |
| `TransferCancelled` | `Transfer.Cancelled` | TransferCancellationService |
| `TransferRiskRejected` | `Transfer.RiskRejected` | TransferInitiationService (risk rejection path) |

All events implement `IntegrationEventInterface`. Emitted via `IntegrationEventPublisher` after durable state change (outbox pattern, Principle 9).

## Routes

File: `routes/modules/transfer.php`

```
Authenticated (identity.auth middleware):
  POST   /api/v1/transfers/recipient-lookup              → RecipientLookupController::lookup
  GET    /api/v1/transfers/receipts/{receipt_reference}  → TransferReceiptController::showByReference
  POST   /api/v1/transfers                               → TransferController::initiate
  GET    /api/v1/transfers                               → TransferController::index
  GET    /api/v1/transfers/{id}    →whereUuid('id')      → TransferController::show
  POST   /api/v1/transfers/{id}/confirm  →whereUuid      → TransferController::confirm
  POST   /api/v1/transfers/{id}/cancel   →whereUuid      → TransferController::cancel
  GET    /api/v1/transfers/{id}/receipt  →whereUuid      → TransferReceiptController::show

Admin (identity.auth + transfer.admin):
  GET    /api/v1/admin/transfers                         → AdminTransferController::index
  GET    /api/v1/admin/transfers/{id}  →whereUuid        → AdminTransferController::show
```

Static routes (`recipient-lookup`, `receipts/{ref}`) MUST be registered BEFORE the `{id}` wildcard routes.

## Observability

- Structured logging with `correlation_id` on every request
- Metrics via `TransferMetricsService`: 8 counters/histograms (FR-E09-052)
- Audit via platform `AuditService`: 6 action types (FR-E09-050)
- All domain events carry `correlationId` payload field

## Integration Contract Changes

| Contract | Change | Backward Compatible |
|---------|--------|---------------------|
| `IdentityModuleContract::verifyTransactionPin()` | Add `requireStepUp: bool = false` parameter | ✅ Yes — default false |

## Platform Configuration Seeds (TBDs)

| Key | Default Seeded | TBD |
|-----|---------------|-----|
| `transfer.fee_rate_bps.{tier}.{currency_code}` | `0` (no fee) | TBD-E09-001 |
| `transfer.min_amount.{currency_code}` | `100` (minor units) | TBD-E09-002 |
| `transfer.max_amount.{tier}.{currency_code}` | `0` (unlimited — safe disabled) | TBD-E09-002 |
| `transfer.daily_limit.{tier}.{currency_code}` | `0` (unlimited — safe disabled) | TBD-E09-002 |
| `transfer.fee_account.{currency_code}` | `null` (boot-time warning if fee > 0) | TBD-E09-003 |
| `transfer.pending_ttl_minutes` | `10` | TBD-E09-004 |
| `transfer.recipient_display_privacy` | `masked` | TBD-E09-005 |
| `transfer.lookup_rate_limit.per_minute` | `10` | SR-E09-007 |

## Implementation Phases

### Phase 1 — Foundation (Migrations, Enums, Exceptions, Stubs)
Migrations, `TransferStatus`, `TransferFailureReason`, all domain exceptions, `TransferRiskServiceStub`, `TransferReferenceGenerator`, `LookupTokenService`, `TransferServiceProvider`.

### Phase 2 — Recipient Lookup
`RecipientLookupService`, `RecipientLookupController`, `RecipientLookupRequest`, route registration, feature test for lookup happy path and error cases.

### Phase 3 — Transfer Initiation
`TransferFeeService`, `TransferLimitService`, `TransferInitiationService`, Eloquent models (`TransferTransactionModel`), `TransferController::initiate`, `TransferInitiateRequest`, `TransferResource`, `TransferInitiated` event, idempotency, audit, metrics.

### Phase 4 — Transfer Confirmation (Financial Core)
`TransferConfirmationService` (3-line journal, 2-line journal, CC-E09-001–005 guards), `TransferCompleted`/`TransferFailed` events, `TransferReceiptModel` (immutable), `TransferReceiptResource`, receipt creation, `TransferController::confirm`.

### Phase 5 — Cancellation and Expiry
`TransferCancellationService`, `TransferController::cancel`, `ExpirePendingTransfersCommand`, scheduler registration, `TransferCancelled` event.

### Phase 6 — Query Endpoints
`TransferController::index` and `show`, `TransferReceiptController::show` and `showByReference`, pagination, filters.

### Phase 7 — Admin Endpoints
`AdminTransferController`, permission seeder, `transfer.admin` guard.

### Phase 8 — Tests and Hardening
`TransferTestCase` (base), `TransferBoundaryTest` (architecture), feature tests per phase, `TransferAuditTrailTest`, concurrency tests (`TransferConfirmationConcurrencyTest`, `DailyLimitRaceTest`, `DuplicateTransferInitiationTest`), unit tests for fee, limits, reference generator.

## Complexity Tracking

Any deviation from this plan requires an ADR and Constitution Check update. Specifically:
- Adding async processing for transfers requires ADR (changes synchronous contract)
- Adding cross-currency support requires ADR (E29 scope)
- Changing fee model (gross/net vs. net/gross) requires spec and ADR update
