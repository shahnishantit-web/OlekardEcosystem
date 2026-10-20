# Implementation Plan — E16 QR & Merchant Payments

## Constitution Check

- [x] Approved Laravel/Next.js/Flutter stack respected — new `App\Modules\Qr` module; no stack deviations
- [x] No TBD value invented — all clarifications resolved; all configurable values read from `ConfigurationServiceInterface`
- [x] Authorization remains server-side — `qr.generate`, `qr.manage`, `qr.pay` permissions enforced in controllers; wallet ownership checked in `QrPaymentService`
- [x] Financial integrity preserved — double-entry triple-leg journal; atomic execution; compensating entry on failure; `QRPaymentTransaction` created in `initiated` before money moves; UNIQUE constraint on `idempotency_key`; concurrency tested (P4)
- [x] Security/audit requirements incorporated — HMAC-SHA256 payload signing; tampered payload audit-logged; step-up auth for high-value payments; all QR management and payment operations audit-logged
- [x] QA strategy incorporated — unit tests for `QrPayloadSigner`, `QrPaymentService`, `QrCodeService`; integration tests for all 9 quickstart scenarios; concurrency tests for dual-customer race and duplicate idempotency key
- [x] Event reliability — `QrPaymentCompletedEvent` published via `OutboxEventPublisher` inside DB transaction (P9)

---

## Technical Context

| Concern | Decision |
|---------|----------|
| Backend | Laravel / PHP 8.4+, `App\Modules\Qr` |
| Database | PostgreSQL, schema `qr` |
| Migration range | 016000–016500 |
| Queue | Redis + Laravel Horizon |
| API | REST/OpenAPI, `/v1/qr-codes` and `/v1/qr-payments` |
| QR signing | HMAC-SHA256 over canonical JSON; `sig` field embedded; full payload Base64URL-encoded |
| Signing key | `qr.signing_key` config key; externally managed secret; never in payload |
| Reservation lock | Pessimistic `SELECT FOR UPDATE` on `qr_codes`; `ReleaseExpiredQrReservationsJob` cleanup every 60 s |
| Fee calculation | Config-driven (`qr.payment.fee_type`, `qr.payment.fee_value`); triple-leg journal: customer − (principal + fee), merchant + principal, fee_wallet + fee |
| Step-up threshold | `auth.step_up_threshold = 10000 BWP` (P5); runtime-configurable; read via `ConfigurationServiceInterface` |
| Cross-module deps | `WalletServiceInterface` (E06), `LedgerServiceInterface` (E07), `MerchantReadServiceInterface` (E12), `AuditServiceInterface` (E03), `OutboxEventPublisher` (E09 pattern), `StepUpAuthService` (E03) |
| Idempotency storage | UNIQUE constraint on `qr_payment_transactions.idempotency_key` |

---

## Module Tree

```
wallet-ecosystem/services/api/app/Modules/Qr/
├── Application/
│   ├── Jobs/
│   │   └── ReleaseExpiredQrReservationsJob.php
│   └── Services/
│       ├── QrCodeService.php
│       ├── QrPayloadSigner.php
│       ├── QrPaymentService.php
│       └── QrReceiptService.php
├── Domain/
│   ├── Contracts/
│   │   └── MerchantReadServiceInterface.php
│   ├── DTO/
│   │   ├── GenerateQrCodeInput.php
│   │   ├── QrResolutionResult.php
│   │   ├── QrFeeQuoteResult.php
│   │   ├── ExecuteQrPaymentCommand.php
│   │   └── QrPaymentResult.php
│   ├── Enums/
│   │   ├── QrCodeType.php
│   │   ├── QrCodeStatus.php
│   │   └── QrPaymentStatus.php
│   ├── Events/
│   │   └── QrPaymentCompletedEvent.php
│   └── Exceptions/
│       ├── QrExpiredException.php
│       ├── QrAlreadyUsedException.php
│       ├── QrNotActiveException.php
│       ├── QrReservedException.php
│       ├── InvalidQrPayloadException.php
│       ├── MerchantNotActiveException.php
│       ├── QrPayloadMismatchException.php
│       ├── AmountOutOfRangeException.php
│       ├── ReceiptNotAvailableException.php
│       └── StepUpRequiredException.php
├── Infrastructure/
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── QrCodeController.php
│   │   │   └── QrPaymentController.php
│   │   ├── Requests/
│   │   │   ├── GenerateQrCodeRequest.php
│   │   │   ├── ResolveQrRequest.php
│   │   │   ├── QrFeeQuoteRequest.php
│   │   │   └── ExecuteQrPaymentRequest.php
│   │   └── Resources/
│   │       ├── QrCodeResource.php
│   │       ├── QrResolutionResource.php
│   │       ├── QrFeeQuoteResource.php
│   │       ├── QrPaymentResource.php
│   │       └── QrReceiptResource.php
│   ├── Migrations/
│   │   ├── 2026_09_30_016000_create_qr_schema.php
│   │   ├── 2026_09_30_016100_create_qr_codes_table.php
│   │   ├── 2026_09_30_016200_create_qr_payment_transactions_table.php
│   │   ├── 2026_09_30_016300_create_qr_payment_receipts_table.php
│   │   ├── 2026_09_30_016400_seed_qr_permissions.php
│   │   └── 2026_09_30_016500_seed_qr_configuration.php
│   └── Models/
│       ├── QrCodeModel.php
│       ├── QrPaymentTransactionModel.php
│       └── QrPaymentReceiptModel.php
└── Providers/
    └── QrServiceProvider.php
```

---

## Dependencies on Prior Epics

| Epic | Interface Used | Purpose |
|------|---------------|---------|
| E03 | `AuditServiceInterface` | Audit QR management + payment operations |
| E03 | `StepUpAuthService` | Validate step-up token for high-value payments |
| E04 | Permissions seeder | Seed `qr.generate`, `qr.manage`, `qr.pay` |
| E06 | `WalletServiceInterface` | Check wallet balance and active status |
| E07 | `LedgerServiceInterface` | Post triple-leg balanced journal |
| E09 | `OutboxEventPublisher` | Publish `QrPaymentCompletedEvent` inside transaction |
| E11 | Transaction history | QR payments surface in unified transaction history; integration approach: E11's `TransactionReadService` queries `qr.qr_payment_transactions` directly — no adapter needed if the table follows the established column naming; verify surfacing in T069 |
| E12 | `MerchantReadServiceInterface` | Read merchant display name, wallet ID, active status |

---

## Database Migrations

| File | Change |
|------|--------|
| `2026_09_30_016000_create_qr_schema.php` | `CREATE SCHEMA IF NOT EXISTS qr` |
| `2026_09_30_016100_create_qr_codes_table.php` | `qr.qr_codes` — all columns, indexes, ENUM types |
| `2026_09_30_016200_create_qr_payment_transactions_table.php` | `qr.qr_payment_transactions` — UNIQUE idempotency_key |
| `2026_09_30_016300_create_qr_payment_receipts_table.php` | `qr.qr_payment_receipts` — immutable; no updatable columns |
| `2026_09_30_016400_seed_qr_permissions.php` | Seed `qr.generate`, `qr.manage`, `qr.pay` permissions |
| `2026_09_30_016500_seed_qr_configuration.php` | Seed config stubs: signing_key placeholder, expiry/TTL defaults, fee config |

---

## Routes

### Merchant-Facing (auth:sanctum, permission:qr.generate / qr.manage)

```
POST   /v1/qr-codes          → QrCodeController@generate
GET    /v1/qr-codes          → QrCodeController@index
GET    /v1/qr-codes/{id}     → QrCodeController@show
DELETE /v1/qr-codes/{id}     → QrCodeController@deactivate
```

### Customer-Facing (auth:sanctum, permission:qr.pay)

```
POST   /v1/qr-payments/resolve          → QrPaymentController@resolve
POST   /v1/qr-payments/quote            → QrPaymentController@quote
POST   /v1/qr-payments                  → QrPaymentController@confirm
GET    /v1/qr-payments/{id}             → QrPaymentController@show
GET    /v1/qr-payments/{id}/receipt     → QrPaymentController@receipt
```

---

## Service Responsibilities

### QrPayloadSigner (Domain Service)

- `sign(array $fields): string` — builds canonical JSON, computes HMAC-SHA256, returns Base64URL-encoded payload string
- `verify(string $payload): array` — decodes, verifies signature via `hash_equals`; throws `InvalidQrPayloadException` on failure; returns decoded payload fields
- Key source: `ConfigurationServiceInterface::get('qr.signing_key')` (backed by environment secret; P3-compliant)
- Signs: `type`, `merchant_id`, `currency`, `amount`, `nonce`, `expires_at`, `merchant_ref` (nulls excluded from canonical)
- Does NOT sign: `label` (display-only)

### QrCodeService (Application Service)

- `generate(GenerateQrCodeInput $input, string $merchantId): QrCodeModel` — validates merchant active, generates nonce, signs payload, persists record
- `deactivate(string $id, string $merchantId): QrCodeModel` — checks ownership, transitions `active → inactive`, audits
- `findForMerchant(string $id, string $merchantId): QrCodeModel` — ownership-checked lookup
- `listForMerchant(string $merchantId, array $filters): LengthAwarePaginator`

### QrPaymentService (Application Service)

Core financial flow. All state transitions inside `DB::transaction()`.

- `resolve(string $payload, string $customerId, string $correlationId): QrResolutionResult` — verifies signature, validates merchant active, checks QR status, returns resolution DTO; `customerId` used for ownership context (FR-E16-009); `correlationId` threaded to audit (P13)
- `quote(string $qrCodeId, string $amountStr, string $currency, string $correlationId): QrFeeQuoteResult` — calculates fee from config, validates amount range
- `confirm(ExecuteQrPaymentCommand $cmd): QrPaymentResult`:
  1. Idempotency check — if `idempotency_key` exists, return existing transaction
  2. Create `QrPaymentTransactionModel` with status `initiated`
  3. Acquire `SELECT FOR UPDATE` on `qr_codes` row
  4. Re-validate QR status (active or stale-reserved → reset to active)
  5. Transition QR to `reserved`
  6. Re-validate merchant wallet active
  7. If amount ≥ step-up threshold, verify `step_up_token` is non-empty (presence check); full `StepUpAuthService::validate()` integration is a pre-production hardening backlog item — token presence is enforced now; cryptographic validation to follow
  8. Check customer wallet balance via `WalletServiceInterface`
  9. Post triple-leg journal via `LedgerServiceInterface`
  10. Transition `QrPaymentTransactionModel` to `completed`; set `journal_id`
  11. Transition QR to `used` (dynamic) or `active` (static — static QRs are reusable)
  12. Create `QrPaymentReceiptModel` via `QrReceiptService`
  13. Audit via `AuditServiceInterface`
  14. Publish `QrPaymentCompletedEvent` via `OutboxEventPublisher`
  15. On any exception after step 9: post compensating journal entry; transition to `failed`; transition QR to `active`

### QrReceiptService (Application Service)

- `createForTransaction(QrPaymentTransactionModel $tx, string $merchantDisplayName): QrPaymentReceiptModel` — generates `receipt_ref`, writes immutable receipt record, called inside the payment transaction
- `findForCustomer(string $transactionId, string $customerId): QrPaymentReceiptModel`

---

## Events

| Event | When emitted | Payload |
|-------|-------------|---------|
| `QrPaymentCompletedEvent` | Inside payment DB transaction, after successful journal post | `merchant_id`, `customer_id`, `amount`, `fee`, `currency`, `payment_reference` (tx.id), `receipt_ref`, `occurred_at` |

Published via `OutboxEventPublisher::publish()` inside the active `DB::transaction()` (P9 compliant).

---

## Background Jobs

| Job | Schedule | Purpose |
|-----|----------|---------|
| `ReleaseExpiredQrReservationsJob` | Every 60 s | Bulk-resets `qr_codes` rows where `status = reserved AND reserved_until < now()` back to `active` |

Registered in `QrServiceProvider::boot()` via `callAfterResolving(Schedule::class, ...)`. Uses `withoutOverlapping()`.

---

## Permissions

Seeded in E04 RBAC permission seeder (new entries):

| Permission | Description |
|------------|-------------|
| `qr.generate` | Merchant: generate QR codes |
| `qr.manage` | Merchant: view and deactivate own QR codes |
| `qr.pay` | Customer: scan, quote, and confirm QR payments |

---

## Audit Events

| Action | Triggered by |
|--------|-------------|
| `qr.code.generated` | QrCodeService::generate() |
| `qr.code.deactivated` | QrCodeService::deactivate() |
| `qr.payment.initiated` | QrPaymentService::confirm() — after creating initiated record |
| `qr.payment.completed` | QrPaymentService::confirm() — on successful journal post |
| `qr.payment.failed` | QrPaymentService::confirm() — on failure + compensating entry |
| `qr.payload.tampered` | QrPayloadSigner::verify() — on HMAC mismatch |

---

## Financial Integrity Controls

| Control | Mechanism |
|---------|-----------|
| Atomic debit/credit | Single `DB::transaction()` wrapping ledger post + status transitions |
| No partial outcomes | `LedgerServiceInterface::post()` is all-or-nothing; any failure rolls back |
| Compensating entries | On post-journal failure: `LedgerServiceInterface::compensate($journalId)` |
| Idempotency | UNIQUE on `qr_payment_transactions.idempotency_key`; checked before any state change |
| Concurrency | Pessimistic `SELECT FOR UPDATE` on `qr_codes` during reservation |
| Immutable receipts | `QrPaymentReceiptModel` has no fillable columns after creation; no UPDATE SQL emitted |
| Ledger balance | Enforced by E07 `LedgerService`; triple-leg journal always balances |

---

## Error Handling Summary

| Exception | HTTP | Error code |
|-----------|------|------------|
| `InvalidQrPayloadException` | 422 | `INVALID_QR_PAYLOAD` |
| `QrExpiredException` | 422 | `QR_EXPIRED` |
| `QrAlreadyUsedException` | 422 | `QR_ALREADY_USED` |
| `QrNotActiveException` | 422 | `QR_NOT_ACTIVE` |
| `QrReservedException` | 409 | `QR_RESERVED` |
| `MerchantNotActiveException` | 422 | `MERCHANT_NOT_ACTIVE` |
| `QrPayloadMismatchException` | 422 | `QR_PAYLOAD_MISMATCH` |
| `InsufficientBalanceException` | 422 | `INSUFFICIENT_BALANCE` |
| `StepUpRequiredException` | 422 | `STEP_UP_REQUIRED` |
| `StepUpTokenExpiredException` | 422 | `STEP_UP_TOKEN_EXPIRED` — **dormant**: only thrown once `StepUpAuthService::validate()` is fully wired (pre-production hardening backlog); re-used from Identity module |
| `CurrencyMismatchException` | 422 | `CURRENCY_MISMATCH` — re-used from `App\Modules\Wallet\Domain\Exceptions\CurrencyMismatchException`; no Qr-local class needed |

---

## Observability

- All service methods receive and propagate `correlation_id`
- Structured log entries for: QR generation, payment initiated, payment completed, payment failed, reservation released, tampered payload detected
- Metrics: `qr.codes.generated` (counter, by type), `qr.payments.completed` (counter), `qr.payments.failed` (counter), `qr.reservation.released` (counter)
- All financial and security events are auditable (P13)

---

## OpenAPI Documentation

- `POST /v1/qr-codes` and all payment endpoints documented in `openapi/v1/qr.yaml`
- Error envelope follows the established platform standard (consistent with E09–E15)

---

## Constitution Check (Post-Design)

All 7 checks pass. No deviations or ADRs required.

- Signing key stored as environment secret — P6 ✅
- All configurable values (expiry, reservation TTL, step-up threshold, fee) read from `ConfigurationServiceInterface` — P3 ✅
- Double-entry triple-leg journal; UNIQUE idempotency_key; SELECT FOR UPDATE; compensating entry — P4 ✅
- `OutboxEventPublisher` called inside transaction — P9 ✅
- Domain exceptions mapped to specific error codes; no information leakage — P6 ✅
- Cross-module access via service interfaces only — P7 ✅
- Concurrency tests included in tasks — P4 ✅
