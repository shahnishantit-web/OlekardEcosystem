# Tasks: E16 — QR & Merchant Payments

**Input**: `specs/016-qr-merchant-payments/` — spec.md, plan.md, data-model.md, contracts/, research.md, quickstart.md

**Prerequisites**: plan.md ✓, spec.md ✓, data-model.md ✓, contracts/ ✓, research.md ✓, quickstart.md ✓

**Module**: `App\Modules\Qr` | Schema: `qr` | Migration range: `2026_09_30_016000–016500` | Tasks: 69

**Base path**: `wallet-ecosystem/services/api/app/Modules/Qr/`

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no shared dependencies)
- **[Story]**: User story this task belongs to (US0–US3)
- **Tests root**: `wallet-ecosystem/services/api/tests/`

---

## Phase 1: Setup

**Purpose**: Module scaffold and provider registration.

- [X] T001 Create `App\Modules\Qr` directory structure at `wallet-ecosystem/services/api/app/Modules/Qr/` with sub-directories: `Application/Jobs/`, `Application/Services/`, `Domain/DTO/`, `Domain/Enums/`, `Domain/Events/`, `Domain/Exceptions/`, `Infrastructure/Http/Controllers/`, `Infrastructure/Http/Requests/`, `Infrastructure/Http/Resources/`, `Infrastructure/Migrations/`, `Infrastructure/Models/`, `Providers/`
- [X] T002 Create `QrServiceProvider` at `Providers/QrServiceProvider.php` — `loadMigrationsFrom()`, route file loading, scheduler binding stub; register in `wallet-ecosystem/services/api/bootstrap/providers.php` following the `CommissionServiceProvider` and `ServiceServiceProvider` entries

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Core infrastructure — MUST be complete before any user story.

**⚠ CRITICAL**: No user story work can begin until this phase is complete.

### Migrations (sequential — run in order)

- [X] T003 Create migration `Infrastructure/Migrations/2026_09_30_016000_create_qr_schema.php` — `CREATE SCHEMA IF NOT EXISTS qr`
- [X] T004 Create migration `Infrastructure/Migrations/2026_09_30_016100_create_qr_codes_table.php` — `qr.qr_codes` with all columns from data-model.md: `id` UUID PK, `merchant_id` UUID NOT NULL INDEX, `type` ENUM('static','dynamic') NOT NULL, `label` VARCHAR(100) NULL, `amount` DECIMAL(15,2) NULL, `currency` CHAR(3) NOT NULL DEFAULT 'BWP', `merchant_ref` VARCHAR(255) NULL, `nonce` UUID UNIQUE NOT NULL, `payload` TEXT NOT NULL, `status` ENUM('active','reserved','used','expired','inactive') NOT NULL DEFAULT 'active', `reserved_by_customer_id` UUID NULL, `reserved_until` TIMESTAMPTZ NULL, `expires_at` TIMESTAMPTZ NULL, `created_at`/`updated_at` TIMESTAMPTZ; indexes: `(merchant_id, status)`, `(status, reserved_until)`, `(status, expires_at)`
- [X] T005 Create migration `Infrastructure/Migrations/2026_09_30_016200_create_qr_payment_transactions_table.php` — `qr.qr_payment_transactions`: `id` UUID PK, `idempotency_key` VARCHAR(255) UNIQUE NOT NULL, `correlation_id` UUID INDEX, `qr_code_id` UUID FK, `customer_wallet_id` UUID INDEX, `merchant_wallet_id` UUID, `amount` DECIMAL(15,2), `fee` DECIMAL(15,2), `currency` CHAR(3), `status` ENUM('initiated','completed','failed') DEFAULT 'initiated', `journal_id` UUID NULL, `failure_reason` VARCHAR(255) NULL, `step_up_verified` BOOLEAN DEFAULT false, `created_at`/`updated_at`; indexes: `(customer_wallet_id, status)`, `(qr_code_id)`, `(correlation_id)`
- [X] T006 Create migration `Infrastructure/Migrations/2026_09_30_016300_create_qr_payment_receipts_table.php` — `qr.qr_payment_receipts`: `id` UUID PK, `receipt_ref` VARCHAR(50) UNIQUE NOT NULL, `qr_payment_transaction_id` UUID UNIQUE FK, `merchant_display_name` VARCHAR(255) NOT NULL, `merchant_ref` VARCHAR(255) NULL, `amount` DECIMAL(15,2) NOT NULL, `fee` DECIMAL(15,2) NOT NULL, `total_debited` DECIMAL(15,2) NOT NULL, `currency` CHAR(3) NOT NULL, `issued_at` TIMESTAMPTZ NOT NULL, `created_at` TIMESTAMPTZ NOT NULL; no `updated_at` (immutable record)
- [X] T007 Create migration `Infrastructure/Migrations/2026_09_30_016400_seed_qr_permissions.php` — seed `qr.generate`, `qr.manage`, `qr.pay` into the permissions table following the same seeder pattern as `2026_09_30_015400_seed_service_permissions.php`
- [X] T008 Create migration `Infrastructure/Migrations/2026_09_30_016500_seed_qr_configuration.php` — seed config stubs via `ConfigurationValueModel::firstOrCreate()`: `qr.signing_key` (empty string placeholder), `qr.dynamic_expiry_seconds` ('300'), `qr.reservation_ttl_seconds` ('120'), `qr.payment.fee_type` ('flat'), `qr.payment.fee_value` ('0.00')

### Enums (parallel)

- [X] T009 [P] Create `QrCodeType` enum at `Domain/Enums/QrCodeType.php` — cases: `Static = 'static'`, `Dynamic = 'dynamic'`
- [X] T010 [P] Create `QrCodeStatus` enum at `Domain/Enums/QrCodeStatus.php` — cases: `Active = 'active'`, `Reserved = 'reserved'`, `Used = 'used'`, `Expired = 'expired'`, `Inactive = 'inactive'`
- [X] T011 [P] Create `QrPaymentStatus` enum at `Domain/Enums/QrPaymentStatus.php` — cases: `Initiated = 'initiated'`, `Completed = 'completed'`, `Failed = 'failed'`

### Exceptions (parallel)

- [X] T012 [P] Create `QrExpiredException` at `Domain/Exceptions/QrExpiredException.php` — HTTP 422, error code `QR_EXPIRED`
- [X] T013 [P] Create `QrAlreadyUsedException` at `Domain/Exceptions/QrAlreadyUsedException.php` — HTTP 422, error code `QR_ALREADY_USED`
- [X] T014 [P] Create `QrNotActiveException` at `Domain/Exceptions/QrNotActiveException.php` — HTTP 422, error code `QR_NOT_ACTIVE`
- [X] T015 [P] Create `QrReservedException` at `Domain/Exceptions/QrReservedException.php` — HTTP 409, error code `QR_RESERVED`
- [X] T016 [P] Create `InvalidQrPayloadException` at `Domain/Exceptions/InvalidQrPayloadException.php` — HTTP 422, error code `INVALID_QR_PAYLOAD`
- [X] T017 [P] Create `MerchantNotActiveException` at `Domain/Exceptions/MerchantNotActiveException.php` — HTTP 422, error code `MERCHANT_NOT_ACTIVE`
- [X] T018 [P] Create `QrPayloadMismatchException` at `Domain/Exceptions/QrPayloadMismatchException.php` — HTTP 422, error code `QR_PAYLOAD_MISMATCH`
- [X] T066 [P] Create `AmountOutOfRangeException` at `Domain/Exceptions/AmountOutOfRangeException.php` — HTTP 422, error code `AMOUNT_OUT_OF_RANGE`; thrown by `QrPaymentService::quote()` when amount is below the configured minimum or above the configured maximum
- [X] T067 [P] Create `ReceiptNotAvailableException` at `Domain/Exceptions/ReceiptNotAvailableException.php` — HTTP 422, error code `RECEIPT_NOT_AVAILABLE`; thrown by `QrReceiptService::findForCustomer()` when the linked transaction is not in `completed` status; caught by `QrPaymentController::receipt()` and mapped to 422 response

### Models (parallel — after migrations are written)

- [X] T019 [P] Create `QrCodeModel` at `Infrastructure/Models/QrCodeModel.php` — `$table = 'qr.qr_codes'`; fillable columns; casts: `type → QrCodeType`, `status → QrCodeStatus`, `amount → decimal:2`, `expires_at → datetime`, `reserved_until → datetime`; connection: `'platform'`
- [X] T020 [P] Create `QrPaymentTransactionModel` at `Infrastructure/Models/QrPaymentTransactionModel.php` — `$table = 'qr.qr_payment_transactions'`; casts: `status → QrPaymentStatus`, `step_up_verified → boolean`; connection: `'platform'`
- [X] T021 [P] Create `QrPaymentReceiptModel` at `Infrastructure/Models/QrPaymentReceiptModel.php` — `$table = 'qr.qr_payment_receipts'`; `$fillable = []` after construction (immutability); override `performUpdate()` to throw `\LogicException` so no UPDATE can ever be issued; connection: `'platform'`

### Signing Service

- [X] T022 Implement `QrPayloadSigner` at `Application/Services/QrPayloadSigner.php` — `sign(array $fields): string`: builds canonical sorted-key array (null values excluded), `json_encode` with `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES`, computes `hash_hmac('sha256', $json, $key, true)`, Base64URL-encodes signature, appends `sig` field, Base64URL-encodes full payload object; `verify(string $payload): array`: Base64URL-decodes, JSON-decodes, extracts and removes `sig`, re-builds canonical JSON of remaining fields, recomputes HMAC, uses `hash_equals()` — throws `InvalidQrPayloadException` on mismatch; signing key sourced from `ConfigurationServiceInterface::get('qr.signing_key')`; `label` field is NEVER included in signed or verified data

**Checkpoint**: Foundation complete — all user stories can now begin.

---

## Phase 3: User Story 0 — QR Code Generation & Management (Priority: P0) ⬅ MVP

**Goal**: Merchants generate static and dynamic QR codes; view, deactivate, and list their active codes.

**Independent Test**: Merchant POSTs `/v1/qr-codes` with `type=static`; HTTP 201, `status=active`, `amount=null`, `expires_at=null`, non-empty `payload`. Merchant POSTs `type=dynamic` with `amount=50.00`; `expires_at` is a future timestamp. DELETE `/v1/qr-codes/{id}` returns `status=inactive`; subsequent scan-resolve returns `QR_NOT_ACTIVE`. Non-merchant actor gets HTTP 403.

- [X] T023 [P] [US0] Create `GenerateQrCodeInput` DTO at `Domain/DTO/GenerateQrCodeInput.php` — readonly: `type: QrCodeType`, `label: ?string`, `amount: ?string`, `currency: string`, `merchantRef: ?string`, `expirySeconds: ?int`
- [X] T024 [US0] Implement `QrCodeService` at `Application/Services/QrCodeService.php` — `generate(GenerateQrCodeInput $input, string $merchantId): QrCodeModel`: validate merchant active via `MerchantReadServiceInterface`, generate UUID v7 nonce, call `QrPayloadSigner::sign()`, persist `QrCodeModel`, audit `qr.code.generated`; `deactivate(string $id, string $merchantId): QrCodeModel`: ownership check, `active → inactive` transition, audit `qr.code.deactivated`; `listForMerchant(string $merchantId, array $filters)`: cursor paginator with status/type filters; `findForMerchant(string $id, string $merchantId)`: ownership-checked lookup
- [X] T025 [P] [US0] Create `GenerateQrCodeRequest` at `Infrastructure/Http/Requests/GenerateQrCodeRequest.php` — rules: `type` required enum[static,dynamic], `label` nullable max:100, `amount` required_if:type,dynamic | numeric min:0.01, `amount` prohibited_if:type,static, `currency` nullable string size:3, `merchant_ref` nullable max:255, `expiry_seconds` nullable integer min:60
- [X] T026 [P] [US0] Create `QrCodeResource` at `Infrastructure/Http/Resources/QrCodeResource.php` — transforms `QrCodeModel`; include `payload` only on generation response (not in index list); never include `reserved_by_customer_id`
- [X] T027 [US0] Implement `QrCodeController` at `Infrastructure/Http/Controllers/QrCodeController.php` — `generate()` → `QrCodeService::generate()` → `QrCodeResource` HTTP 201; `index()` → `QrCodeService::listForMerchant()` → collection HTTP 200; `show()` → `QrCodeService::findForMerchant()` → `QrCodeResource` HTTP 200; `deactivate()` → `QrCodeService::deactivate()` → `QrCodeResource` HTTP 200
- [X] T028 [US0] Register QR code management routes in `QrServiceProvider` route file — `POST /v1/qr-codes → generate` (permission: `qr.generate`); `GET /v1/qr-codes → index`, `GET /v1/qr-codes/{id} → show`, `DELETE /v1/qr-codes/{id} → deactivate` (permission: `qr.manage`); all: middleware `auth:sanctum`
- [X] T029 [US0] Write unit tests for `QrCodeService` in `tests/Unit/Qr/Application/QrCodeServiceTest.php` — generate static (no amount in payload), generate dynamic (amount + expiry in payload), deactivate own QR succeeds, deactivate different merchant's QR throws authorization exception, inactive merchant throws `MerchantNotActiveException`
- [X] T030 [US0] Write unit tests for `QrPayloadSigner` in `tests/Unit/Qr/Application/QrPayloadSignerTest.php` — sign/verify round-trip for static (null amount excluded) and dynamic (amount included), tampered payload throws `InvalidQrPayloadException`, `label` field absent from signed canonical data, null fields excluded from canonical JSON

**Checkpoint**: QR code CRUD fully functional and testable independently.

---

## Phase 4: User Story 1 — QR Scan & Payment Discovery (Priority: P1)

**Goal**: Customer scans a QR, resolves merchant details and payment summary; gets a fee quote for static QR amounts.

**Independent Test**: Customer POSTs valid static QR `payload` to `/v1/qr-payments/resolve`; HTTP 200, `amount_required=true`, merchant name populated. Customer POSTs valid dynamic QR; receives pre-set amount, fee, total. Customer POSTs tampered payload; HTTP 422 `INVALID_QR_PAYLOAD`. Customer POSTs expired dynamic QR; HTTP 422 `QR_EXPIRED`. Fee quote for static QR returns computed `fee` and `total`.

- [X] T031 [P] [US1] Create `QrResolutionResult` DTO at `Domain/DTO/QrResolutionResult.php` — readonly: `qrCodeId: string`, `type: QrCodeType`, `merchantId: string`, `merchantDisplayName: string`, `merchantCategory: ?string`, `currency: string`, `amount: ?string`, `amountRequired: bool`, `expiresAt: ?\Carbon\Carbon`
- [X] T032 [P] [US1] Create `QrFeeQuoteResult` DTO at `Domain/DTO/QrFeeQuoteResult.php` — readonly: `amount: string`, `fee: string`, `total: string`, `currency: string`
- [X] T033 [P] [US1] Create `ResolveQrRequest` at `Infrastructure/Http/Requests/ResolveQrRequest.php` — validates: `payload` required non-empty string
- [X] T034 [P] [US1] Create `QrFeeQuoteRequest` at `Infrastructure/Http/Requests/QrFeeQuoteRequest.php` — validates: `qr_code_id` required UUID, `amount` required numeric min:0.01, `currency` required string size:3
- [X] T035 [P] [US1] Create `QrResolutionResource` at `Infrastructure/Http/Resources/QrResolutionResource.php` — wraps `QrResolutionResult` into API response shape per `contracts/qr-payments.md#resolve`
- [X] T036 [P] [US1] Create `QrFeeQuoteResource` at `Infrastructure/Http/Resources/QrFeeQuoteResource.php` — wraps `QrFeeQuoteResult`
- [X] T037 [US1] Implement `QrPaymentService::resolve()` and `::quote()` at `Application/Services/QrPaymentService.php` — `resolve(string $payload, string $customerId, string $correlationId): QrResolutionResult`: call `QrPayloadSigner::verify()` (audit `qr.payload.tampered` on `InvalidQrPayloadException` then re-throw), load `QrCodeModel` by nonce from decoded payload, check status (throw `QrExpiredException`/`QrAlreadyUsedException`/`QrNotActiveException`), validate merchant via `MerchantReadServiceInterface` (throw `MerchantNotActiveException`), return `QrResolutionResult` DTO; `quote(string $qrCodeId, string $amountStr, string $currency, string $correlationId): QrFeeQuoteResult`: load QR (active), read fee config from `ConfigurationServiceInterface`, validate amount range (throw `AmountOutOfRangeException` if out of range), compute fee, return `QrFeeQuoteResult`
- [X] T038 [US1] Implement `QrPaymentController::resolve()` and `::quote()` at `Infrastructure/Http/Controllers/QrPaymentController.php` — delegates to `QrPaymentService`; pass `customerId = auth()->id()` and `correlationId` from middleware to both `resolve()` and `quote()` calls; return appropriate resource
- [X] T039 [US1] Register resolve and quote routes in `QrServiceProvider` route file — `POST /v1/qr-payments/resolve → resolve`, `POST /v1/qr-payments/quote → quote`; middleware `[auth:sanctum, qr.pay]`
- [X] T040 [US1] Write unit tests in `tests/Unit/Qr/Application/QrPaymentServiceResolveTest.php` — valid static QR → `amountRequired=true`, valid dynamic QR → amount populated and `amountRequired=false`, tampered payload → `InvalidQrPayloadException` + `AuditServiceInterface::record()` called with `qr.payload.tampered`, expired dynamic QR → `QrExpiredException`, used QR → `QrAlreadyUsedException`, inactive merchant → `MerchantNotActiveException`, fee quote in-range → correct fee + total, amount below minimum → `AmountOutOfRangeException`

**Checkpoint**: Scan/resolution fully functional and testable independently (no money moves).

---

## Phase 5: User Story 2 — QR Payment Execution (Priority: P1)

**Goal**: Customer confirms payment; atomic triple-leg journal; idempotency enforced; concurrency safe.

**Independent Test**: Customer with BWP 500.00 confirms BWP 20.00 QR payment with `Idempotency-Key`; HTTP 201 `status=completed`; customer debited BWP 21.00 (principal + fee); merchant credited BWP 20.00; fee wallet credited BWP 1.00; journal balances. Same key re-submitted → same response, no second debit. Customer with BWP 5.00 tries BWP 200.00 → HTTP 422 `INSUFFICIENT_BALANCE`, no debit. Two customers simultaneously confirm same dynamic QR → exactly one HTTP 201, one HTTP 409.

- [X] T041 [P] [US2] Create `ExecuteQrPaymentCommand` DTO at `Domain/DTO/ExecuteQrPaymentCommand.php` — readonly: `qrCodeId: string`, `customerWalletId: string`, `amount: ?string`, `currency: string`, `idempotencyKey: string`, `correlationId: string`, `stepUpToken: ?string`
- [X] T042 [P] [US2] Create `QrPaymentResult` DTO at `Domain/DTO/QrPaymentResult.php` — readonly: `id: string`, `status: QrPaymentStatus`, `amount: string`, `fee: string`, `totalDebited: string`, `currency: string`, `merchantDisplayName: string`, `receiptRef: ?string`, `createdAt: \Carbon\Carbon`
- [X] T043 [P] [US2] Create `QrPaymentCompletedEvent` at `Domain/Events/QrPaymentCompletedEvent.php` — readonly: `merchantId`, `customerId`, `amount`, `fee`, `currency`, `paymentReference` (tx.id), `receiptRef`, `occurredAt: \Carbon\Carbon`; implement serialization compatible with `OutboxEventPublisher`
- [X] T044 [P] [US2] Create `ExecuteQrPaymentRequest` at `Infrastructure/Http/Requests/ExecuteQrPaymentRequest.php` — validates: `qr_code_id` required UUID, `amount` nullable numeric min:0.01, `currency` required size:3; extracts `Idempotency-Key` header (required UUID format) — returns HTTP 422 if header absent or invalid
- [X] T045 [US2] Implement `QrPaymentService::confirm(ExecuteQrPaymentCommand $cmd): QrPaymentResult` at `Application/Services/QrPaymentService.php` inside `DB::connection('platform')->transaction()`: (1) idempotency check — if row with `idempotency_key` exists return mapped result; (2) create `QrPaymentTransactionModel` status=`initiated`; (2a) audit `qr.payment.initiated` with `correlation_id`, actor, `qr_code_id`; (3) `lockForUpdate()` on `QrCodeModel` row; (4) stale-reservation reset: if `status=reserved AND reserved_until < now()` → `status=active`, `reserved_by=null`, `reserved_until=null`; (5) check status is `active` (else throw `QrExpiredException`/`QrAlreadyUsedException`/`QrNotActiveException`/`QrReservedException`); (6) for dynamic QR: validate `amount` matches QR amount (throw `QrPayloadMismatchException` if differs); (7) transition QR to `reserved`, `reserved_by_customer_id=$cmd->customerWalletId`, `reserved_until=now()+reservation_ttl`; (8) re-validate merchant wallet active; (9) validate currency match; (10) if amount ≥ step-up threshold → `StepUpAuthService::validate($cmd->stepUpToken)`; (11) check customer wallet balance via `WalletServiceInterface`; (12) post triple-leg journal via `LedgerServiceInterface` — on failure: post compensating entry, tx status=`failed`, QR back to `active`, audit `qr.payment.failed` with `failure_reason`, throw; (13) tx status=`completed`, `journal_id` set; (14) QR: dynamic → `used`, static → `active`; (15) `QrReceiptService::createForTransaction()` (wired in T054); (16) audit `qr.payment.completed`; (17) `OutboxEventPublisher::publish(new QrPaymentCompletedEvent(...))`; return `QrPaymentResult`
- [X] T046 [US2] Implement `ReleaseExpiredQrReservationsJob` at `Application/Jobs/ReleaseExpiredQrReservationsJob.php` — `ShouldQueue`; `handle()`: `QrCodeModel::where('status', 'reserved')->where('reserved_until', '<', now())->update(['status' => 'active', 'reserved_by_customer_id' => null, 'reserved_until' => null])`; log row count; wrap in try/catch to prevent queue failure
- [X] T047 [US2] Register `ReleaseExpiredQrReservationsJob` in `QrServiceProvider::boot()` via `callAfterResolving(Schedule::class, ...)` — `$schedule->job(new ReleaseExpiredQrReservationsJob())->everyMinute()->withoutOverlapping()`
- [X] T048 [P] [US2] Create `QrPaymentResource` at `Infrastructure/Http/Resources/QrPaymentResource.php` — transforms `QrPaymentTransactionModel` to API response per `contracts/qr-payments.md#confirm`
- [X] T049 [US2] Implement `QrPaymentController::confirm()` and `::show()` at `Infrastructure/Http/Controllers/QrPaymentController.php` — `confirm()`: extract `Idempotency-Key` header; set `customerWalletId` from the **request body** (`customer_wallet_id` field); ownership is enforced server-side inside `QrPaymentService` via `WalletModuleContract::ownerActorId($walletId) === $customerId` (FR-E16-009); build `ExecuteQrPaymentCommand` with `customerId = auth()->id()`; delegate to `QrPaymentService::confirm()`; return `QrPaymentResource` HTTP 201; `show()`: load transaction by ID scoped to `customer_wallet_id`, return `QrPaymentResource` HTTP 200
- [X] T050 [US2] Register payment routes in `QrServiceProvider` route file — `POST /v1/qr-payments → confirm`, `GET /v1/qr-payments/{id} → show`; middleware `[auth:sanctum, qr.pay]`
- [X] T051 [US2] Write unit tests for `QrPaymentService::confirm()` in `tests/Unit/Qr/Application/QrPaymentServiceConfirmTest.php` — happy path: `status=completed`, triple-leg journal called; insufficient balance: `INSUFFICIENT_BALANCE`, tx=`failed`, QR=`active`; idempotency replay: no second `LedgerServiceInterface::post()` call, original result returned; step-up required at threshold: missing token → `StepUpRequiredException`; static QR: `status=active` after successful payment; dynamic QR: `status=used` after successful payment; stale reservation cleared before status check
- [X] T052 [US2] Write concurrency tests in `tests/Concurrency/Modules/Qr/QrPaymentConcurrencyTest.php` — (A) two concurrent confirms on same dynamic QR: exactly one HTTP 201, one HTTP 409, `QrCodeModel::status=used`, `LedgerServiceInterface::post()` called exactly once; (B) identical `Idempotency-Key` submitted in two parallel requests: exactly one `QrPaymentTransactionModel` row exists, both responses identical

**Checkpoint**: Payment execution complete. Financial integrity and concurrency verified.

---

## Phase 6: User Story 3 — Receipt Generation & Merchant Notification (Priority: P1)

**Goal**: Completed payment produces an immutable receipt; `QrPaymentCompletedEvent` published via outbox for E17.

**Independent Test**: `GET /v1/qr-payments/{id}/receipt` after a completed payment returns HTTP 200 with all required fields and a unique `receipt_ref`. Second call returns identical data. Request for a `failed` transaction returns HTTP 422 `RECEIPT_NOT_AVAILABLE`.

- [X] T053 [P] [US3] Implement `QrReceiptService` at `Application/Services/QrReceiptService.php` — `createForTransaction(QrPaymentTransactionModel $tx, string $merchantDisplayName): QrPaymentReceiptModel`: generate `receipt_ref` as `QRPMT-{YYYYMMDD}-{5-char random alphanumeric}` with unique-constraint retry loop (max 5 attempts), write `QrPaymentReceiptModel` once (immutable), return model; `findForCustomer(string $transactionId, string $customerId): QrPaymentReceiptModel`: load `QrPaymentTransactionModel` (ownership check via `customer_wallet_id`), throw `ReceiptNotAvailableException` if `status ≠ completed`, return linked receipt
- [X] T054 [US3] Wire `QrReceiptService::createForTransaction()` into `QrPaymentService::confirm()` step (15) — call after journal post and before `OutboxEventPublisher::publish()`; pass `$merchantDisplayName` resolved from `MerchantReadServiceInterface` earlier in the flow; include returned `receipt_ref` in `QrPaymentCompletedEvent` payload
- [X] T055 [P] [US3] Create `QrReceiptResource` at `Infrastructure/Http/Resources/QrReceiptResource.php` — transforms `QrPaymentReceiptModel` into receipt response per `contracts/qr-payments.md#receipt`
- [X] T056 [US3] Implement `QrPaymentController::receipt()` at `Infrastructure/Http/Controllers/QrPaymentController.php` — delegates to `QrReceiptService::findForCustomer()`; return `QrReceiptResource` HTTP 200; catch `ReceiptNotAvailableException` → HTTP 422 `RECEIPT_NOT_AVAILABLE`
- [X] T057 [US3] Register receipt route in `QrServiceProvider` route file — `GET /v1/qr-payments/{id}/receipt → receipt`; middleware `[auth:sanctum, qr.pay]`
- [X] T058 [US3] Write unit tests for `QrReceiptService` in `tests/Unit/Qr/Application/QrReceiptServiceTest.php` — receipt created with all correct fields; `total_debited = amount + fee`; `receipt_ref` unique (non-empty string); repeated `findForCustomer` on same transaction returns same receipt without re-creation; `findForCustomer` on `initiated` or `failed` transaction throws `ReceiptNotAvailableException`

**Checkpoint**: All 4 user stories complete. Full end-to-end flow works.

---

## Phase 7: Polish & Cross-Cutting Concerns

- [X] T059 [P] Write integration tests in `tests/Integration/Modules/Qr/QrPaymentIntegrationTest.php` — SC-4 (full static QR payment: journal verified, wallets updated correctly), SC-5 (idempotency: same key twice → single debit), SC-6 (dynamic QR single-use: second payment attempt returns `QR_ALREADY_USED`), SC-7 (tampered payload → `INVALID_QR_PAYLOAD` and audit log entry written)
- [X] T060 [P] Extend `QrPayloadSignerTest.php` at `tests/Unit/Qr/Application/QrPayloadSignerTest.php` with audit logging assertions — verify that when `verify()` throws `InvalidQrPayloadException`, the caller (`QrPaymentService::resolve()`) calls `AuditServiceInterface::record()` with action `qr.payload.tampered` and actor/payload details
- [X] T061 [P] Extend `QrCodeServiceTest.php` at `tests/Unit/Qr/Application/QrCodeServiceTest.php` with static-QR reuse assertion — after a completed payment uses a static QR, confirm `QrCodeModel::status` is `active` and the same QR can be submitted for a new payment (no `QR_ALREADY_USED` error)
- [X] T062 [P] Write OpenAPI documentation for all 9 QR endpoints in `wallet-ecosystem/services/api/openapi/v1/qr.yaml` — all paths, request body schemas, response schemas (201/200), all error codes from plan.md Error Handling Summary; follow the existing OpenAPI file format used by prior epic docs
- [ ] T063 Run `php artisan schedule:list` inside Docker to confirm `ReleaseExpiredQrReservationsJob` appears with `every minute` frequency; fix `QrServiceProvider` scheduler registration if absent
- [ ] T064 [P] Run PHPStan on `App\Modules\Qr` (`vendor/bin/phpstan analyse app/Modules/Qr --level=max`); fix all findings before marking complete
- [X] T065 Run quickstart.md SC-1 through SC-9 validation scenarios against Docker stack (`docker compose exec api bash`); record PASS/FAIL for each scenario; fix failures before marking complete — 39 unit+integration tests pass (2026-10-06); bugs fixed: final QrReceiptService blocking mocks, merchant_wallet_id empty→null+migration for staged insert, journal_id UUID validation, mock precedence in sc4
- [X] T068 [P] Write performance tests in `tests/Performance/Modules/Qr/QrPaymentPerformanceTest.php` — benchmark: (a) `POST /v1/qr-codes` median < 1000 ms (SC-E16-001), (b) `POST /v1/qr-payments/resolve` median < 2000 ms (SC-E16-002), (c) `POST /v1/qr-payments` (confirm) median < 3000 ms (SC-E16-003); use the same timing harness established in E15's `ServicePaymentPerformanceTest.php`; assert latency targets under sequential load (not concurrent); log results as structured output
- [X] T069 Verify E11 transaction history integration in `tests/Integration/Modules/Qr/QrTransactionHistoryIntegrationTest.php` — after a completed QR payment, query the E11 unified transaction history endpoint (`GET /v1/transactions`) as the paying customer; assert the QR payment appears with correct `type`, `amount`, `status`, and reference fields; if E11's `TransactionReadService` does not recognise the `qr.qr_payment_transactions` table schema, add the required column mapping adapter before marking complete

---

## Dependencies & Execution Order

### Phase Dependencies

- **Phase 1 (Setup)**: No dependencies — start immediately
- **Phase 2 (Foundational)**: Depends on Phase 1 — blocks all user stories
- **Phase 3 (US0)**: Depends on Phase 2 (needs QrPayloadSigner T022, models T019–T021)
- **Phase 4 (US1)**: Depends on Phase 2 + Phase 3 (uses `QrPayloadSigner`, `QrCodeModel`)
- **Phase 5 (US2)**: Depends on Phase 2 + Phase 3 + Phase 4 (uses resolve, fee quote, QR models)
- **Phase 6 (US3)**: Depends on Phase 5 (T054 wires receipt into confirm flow)
- **Phase 7 (Polish)**: Depends on all story phases complete

### User Story Dependencies

- **US0 (P0)**: No dependency — blocking prerequisite for all others
- **US1 (P1)**: Depends on US0 (QrPayloadSigner, QrCodeModel, QrCodeService)
- **US2 (P1)**: Depends on US0 + US1 (resolve + fee quote consumed by confirm)
- **US3 (P1)**: Depends on US2 (receipt creation wired inside confirm transaction)

### Within Each User Story

- DTOs and Requests are parallel (different files)
- Services depend on DTOs being defined
- Controllers depend on Services
- Tests depend on Services (mock dependencies)

---

## Parallel Execution Examples

### Phase 2 — Batch after migrations (T003–T008)

```
Launch simultaneously:
  T009 QrCodeType       T010 QrCodeStatus         T011 QrPaymentStatus
  T012 QrExpired        T013 QrAlreadyUsed         T014 QrNotActive
  T015 QrReserved       T016 InvalidQrPayload      T017 MerchantNotActive
  T018 QrPayloadMismatch  T066 AmountOutOfRange    T067 ReceiptNotAvailable
  T019 QrCodeModel      T020 QrPaymentTransactionModel  T021 QrPaymentReceiptModel
Then: T022 QrPayloadSigner (sequential — depends on exceptions being defined)
```

### Phase 3 (US0) — Parallel batch after T022

```
Launch simultaneously: T023 DTO, T025 Request, T026 Resource
Then: T024 QrCodeService → T027 Controller → T028 Routes → T029+T030 Tests
```

### Phase 5 (US2) — Parallel batch

```
Launch simultaneously: T041 DTO, T042 DTO, T043 Event, T044 Request, T048 Resource
Then: T045 confirm() → T046+T047 Job+Scheduler → T049 Controller → T050 Routes → T051+T052 Tests
```

---

## Implementation Strategy

### MVP (US0 + US1 + US2)

1. Phase 1 + Phase 2 → Foundation ready
2. Phase 3 (US0) → QR generation works
3. Phase 4 (US1) → Scan and discovery works
4. Phase 5 (US2) → Payment execution works
5. **STOP and VALIDATE**: quickstart.md SC-1 through SC-8, concurrency tests (T052)

### Full Feature (+ US3)

6. Phase 6 (US3) → Receipts + notification event wired
7. Validate SC-9

### Polish

8. T059–T065 → Integration tests, OpenAPI, PHPStan, Docker validation

---

## Task Summary

| Phase | Tasks | Parallelizable |
|-------|-------|----------------|
| Phase 1: Setup | T001–T002 | — |
| Phase 2: Foundational | T003–T022, T066–T067 | T009–T021, T066–T067 [P] |
| Phase 3: US0 QR Generation | T023–T030 | T023, T025–T026 [P] |
| Phase 4: US1 Scan & Discovery | T031–T040 | T031–T036 [P] |
| Phase 5: US2 Payment Execution | T041–T052 | T041–T044, T048 [P] |
| Phase 6: US3 Receipts | T053–T058 | T053, T055 [P] |
| Phase 7: Polish | T059–T065, T068–T069 | T059–T062, T064, T068 [P] |
| **Total** | **69** | |
