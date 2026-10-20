# Tasks: E09 Wallet-to-Wallet Transfer

**Input**: `specs/009-wallet-to-wallet-transfer/`
**Prerequisites**: spec.md ✅ plan.md ✅ research.md ✅ data-model.md ✅ contracts/api.yaml ✅

## Format: `[ID] [P?] [Story] Description — file path`

- **[P]**: Can run in parallel (different files, no incomplete-task dependencies)
- **[US1/2/3/4]**: User story label
- All paths relative to `wallet-ecosystem/services/api/`

---

## Phase 1: Setup

**Purpose**: Module skeleton, migrations, route file, test infrastructure.

- [x] T001 Create Transfer module directory skeleton: `app/Modules/Transfer/{Application/Services,Application/Contracts,Domain/Enums,Domain/Events,Domain/Exceptions,Infrastructure/Http/Controllers,Infrastructure/Http/Requests,Infrastructure/Http/Resources,Infrastructure/Models,Infrastructure/Stubs,Console,Providers}/`
- [x] T002 [P] Create migration `create_transfer_transactions_table` with all 22 columns (20 application fields + `created_at`/`updated_at`), 5 indexes, and partial UNIQUE on `idempotency_key` per data-model.md — `database/migrations/YYYY_MM_DD_000001_create_transfer_transactions_table.php`
- [x] T003 [P] Create migration `create_transfer_receipts_table` with all 9 columns and 4 indexes per data-model.md — `database/migrations/YYYY_MM_DD_000002_create_transfer_receipts_table.php`
- [x] T004 [P] Create permission seeder: seeds `transfer.initiate`, `transfer.read`, `transfer.admin` into permissions table — `database/seeders/TransferPermissionsSeeder.php`
- [x] T005 [P] Create platform config seeder: seeds all 8 TBD keys with safe defaults (fee_rate=0, limits=0/unlimited, fee_account=null, pending_ttl=10, display_privacy=masked, lookup_rate=10) — `database/seeders/TransferPlatformConfigSeeder.php`
- [x] T006 Create route file with all 10 routes in correct order (static routes before `{id}` wildcard, `->whereUuid('id')` constraints per research.md Decision 3); apply per-customer throttle middleware to `POST /v1/transfers/recipient-lookup` using `transfer.lookup_rate_limit.per_minute` config value (SR-E09-007) — `routes/modules/transfer.php`
- [x] T007 Register `TransferPermissionsSeeder` and `TransferPlatformConfigSeeder` in `DatabaseSeeder` — `database/seeders/DatabaseSeeder.php`
- [x] T008 Register `routes/modules/transfer.php` in the application route loader (same pattern as `funding.php`) — `bootstrap/app.php` or `app/Providers/RouteServiceProvider.php`
- [x] T009 Create `TransferTestCase` base class (DB transaction rollback, module fake events, fake metrics) — `tests/Feature/Modules/Transfer/TransferTestCase.php`
- [x] T010 [P] Create `TransferBoundaryTest` asserting Transfer module does not directly import Funding/Wallet/Ledger concrete classes (architecture boundary) — `tests/Feature/Modules/Transfer/TransferBoundaryTest.php`

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: All shared domain types, exceptions, events, stubs, and models that every user story depends on.

**⚠️ CRITICAL**: No user story work can begin until this phase is complete.

- [x] T011 Create `TransferStatus` PHP enum (`pending`, `completed`, `failed`, `cancelled`) with `isTerminal(): bool` helper — `app/Modules/Transfer/Domain/Enums/TransferStatus.php`
- [x] T012 [P] Create `TransferFailureReason` PHP enum with 5 values: `INSUFFICIENT_BALANCE`, `DAILY_LIMIT_EXCEEDED`, `RISK_REJECTED`, `RECIPIENT_WALLET_SUSPENDED`, `SYSTEM_ERROR` — `app/Modules/Transfer/Domain/Enums/TransferFailureReason.php`
- [x] T013 [P] Create `TransferNotModifiableException` (used when status is not pending on confirm/cancel) — `app/Modules/Transfer/Domain/Exceptions/TransferNotModifiableException.php`
- [x] T014 [P] Create `TransferNotCancellableException` (used when status is not pending on cancel) — `app/Modules/Transfer/Domain/Exceptions/TransferNotCancellableException.php`
- [x] T015 [P] Create `TransferReceiptImmutableException` (thrown by `TransferReceiptModel::save()`, `update()`, `delete()` after insert) — `app/Modules/Transfer/Domain/Exceptions/TransferReceiptImmutableException.php`
- [x] T016 [P] Create `InsufficientBalanceException` with static `forAmount(int $gross, int $balance): self` factory — `app/Modules/Transfer/Domain/Exceptions/InsufficientBalanceException.php`
- [x] T017 [P] Create `DailyLimitExceededException` with `remaining: int` field — `app/Modules/Transfer/Domain/Exceptions/DailyLimitExceededException.php`
- [x] T018 [P] Create `InvalidLookupTokenException` — `app/Modules/Transfer/Domain/Exceptions/InvalidLookupTokenException.php`
- [x] T019 [P] Create `RecipientNotFoundException` — `app/Modules/Transfer/Domain/Exceptions/RecipientNotFoundException.php`
- [x] T020 [P] Create `RecipientWalletInactiveException` — `app/Modules/Transfer/Domain/Exceptions/RecipientWalletInactiveException.php`
- [x] T021 [P] Create `SelfTransferException` — `app/Modules/Transfer/Domain/Exceptions/SelfTransferException.php`
- [x] T022 [P] Create `FeeMismatchException` with `newFeeAmount: int`, `newNetAmount: int`, `newFeeRateBps: int` fields — `app/Modules/Transfer/Domain/Exceptions/FeeMismatchException.php`; also create `InvalidPinException` (Transfer domain wrapper; thrown by `TransferConfirmationService` when `IdentityModuleContract` returns PIN-failure result; allows `TransferController` to map to 422 `INVALID_PIN` without importing E05's exception class directly) — `app/Modules/Transfer/Domain/Exceptions/InvalidPinException.php`
- [x] T023 Create `TransferReferenceGenerator`: generates `TXF-{10-CHAR}` using unambiguous alphabet (A-Z 0-9 excluding I/O/1/0), same pattern as E08's `BankReferenceGenerator` — `app/Modules/Transfer/Application/Services/TransferReferenceGenerator.php`
- [x] T024 Create `LookupTokenService`: `issue(recipientCustomerId, recipientWalletId, pocketLedgerRef, currencyCode): string` stores JSON in Redis `transfer_lookup:{uuid}` with 300s TTL; `consume(token): array|null` uses GETDEL (atomic get+delete); returns null if missing/expired — `app/Modules/Transfer/Application/Services/LookupTokenService.php`
- [x] T025 [P] Create `TransferRiskService` interface with `evaluate(array $context): RiskResult` — `app/Modules/Transfer/Application/Contracts/TransferRiskService.php`
- [x] T026 [P] Create `TransferRiskServiceStub` implementing `TransferRiskService`; always returns `RiskResult::approved()` — `app/Modules/Transfer/Infrastructure/Stubs/TransferRiskServiceStub.php`
- [x] T027 [P] Create `TransferInitiated` event implementing `IntegrationEventInterface`; payload: `transferId, senderWalletId, recipientWalletId, currencyCode, grossAmount, feeAmount, netAmount, occurredAt, correlationId`; no PII — `app/Modules/Transfer/Domain/Events/TransferInitiated.php`
- [x] T028 [P] Create `TransferCompleted` event; payload: same + `receiptReference` — `app/Modules/Transfer/Domain/Events/TransferCompleted.php`
- [x] T029 [P] Create `TransferFailed` event; payload: `transferId, senderWalletId, currencyCode, grossAmount, failureReason, occurredAt, correlationId` — `app/Modules/Transfer/Domain/Events/TransferFailed.php`
- [x] T030 [P] Create `TransferCancelled` event; payload: `transferId, senderWalletId, currencyCode, grossAmount, occurredAt, correlationId` — `app/Modules/Transfer/Domain/Events/TransferCancelled.php`
- [x] T031 [P] Create `TransferRiskRejected` event; payload: `transferId, senderWalletId, currencyCode, grossAmount, riskReason, occurredAt, correlationId` — `app/Modules/Transfer/Domain/Events/TransferRiskRejected.php`
- [x] T032 Update `IdentityModuleContract::verifyTransactionPin()` to accept `bool $requireStepUp = false` (backward-compatible default; E08 callers unaffected) — `app/Modules/Identity/Application/Contracts/IdentityModuleContract.php`
- [x] T033 [P] Create `TransferMetricsService` with 8 methods mirroring FR-E09-052 (counter/histogram wrappers delegating to platform MetricsService) — `app/Modules/Transfer/Application/Services/TransferMetricsService.php`
- [x] T034 Create `TransferTransactionModel` Eloquent model: fillable, casts (status→TransferStatus, failure_reason→TransferFailureReason via nullable cast), `sender()` / `recipient()` belongsTo relations, `receipt()` hasOne — `app/Modules/Transfer/Infrastructure/Models/TransferTransactionModel.php`
- [x] T035 Create `TransferReceiptModel` Eloquent model: fillable, casts, `transaction()` belongsTo; override `save()`, `update()`, `delete()` to throw `TransferReceiptImmutableException` after first insert (check if `exists` before throwing) — `app/Modules/Transfer/Infrastructure/Models/TransferReceiptModel.php`
- [x] T036 Create `TransferServiceProvider`: bind `TransferRiskService` → `TransferRiskServiceStub`, bind `LookupTokenService` as singleton, register `transfer:expire-pending` command, schedule it via `Schedule` at every-minute frequency — `app/Modules/Transfer/Providers/TransferServiceProvider.php`
- [x] T037 Register `TransferServiceProvider` in `config/app.php` providers array — `config/app.php`
- [x] T038 Create unit tests for `TransferReferenceGenerator` (format `TXF-XXXXXXXXXX`, 10 chars, no ambiguous chars I/O/1/0) and `LookupTokenService` (issue returns UUID, consume returns payload, second consume returns null, expired key returns null) — `tests/Unit/Modules/Transfer/TransferFoundationTest.php`

**Checkpoint**: All domain types, exceptions, events, stubs, models, and provider exist. Migrations run cleanly. Tests T010 and T038 pass.

---

## Phase 3: US1 — Recipient Lookup + Transfer Initiation (P0) 🎯 MVP

**Goal**: Customers can look up a recipient (returns `lookup_token`) and initiate a pending transfer (fee quoted, limits validated, `pending` status created). No money moves yet.

**Independent Test**: `POST /v1/transfers/recipient-lookup` with a valid phone number returns a `lookup_token`. `POST /v1/transfers` with that token returns a `pending` transfer with correct `fee_amount` and `net_amount`.

- [x] T039 [US1] Create `TransferFeeService`: `calculate(int $grossAmount, int $rateBps): array` returns `['fee_amount'=>…, 'net_amount'=>…]` using `intval(round($gross * $bps / 10000))`; `getCurrentRateBps(string $tier, string $currency): int` reads from `PlatformConfigurationService` — `app/Modules/Transfer/Application/Services/TransferFeeService.php`
- [x] T040 [P] [US1] Create `TransferLimitService`: `validateMin(int $amount, string $currency): void`, `validateMax(int $amount, string $tier, string $currency): void`, `validateDailyLimit(string $senderCustomerId, int $newGrossAmount, string $tier, string $currency): void` (queries `status IN ('pending','completed')` per FR-E09-020) — `app/Modules/Transfer/Application/Services/TransferLimitService.php`
- [x] T041 [US1] Create `RecipientLookupService`: resolves customer by `phone_number` (E.164) or `wallet_number` via `IdentityModuleContract` / `WalletModuleContract`; validates active status; rejects self-transfer; calls `LookupTokenService::issue()`; returns sanitized response per FR-E09-007 — `app/Modules/Transfer/Application/Services/RecipientLookupService.php`
- [x] T042 [US1] Create `TransferInitiationService`: validates ownership (FR-E09-013), consumes `lookup_token` (FR-E09-014), resolves pocket ledger refs (FR-E09-012), checks currency match, runs fee+limits+balance pre-check, calls `TransferRiskService::evaluate()`, creates `TransferTransaction` record, emits `TransferInitiated` event, returns created model — `app/Modules/Transfer/Application/Services/TransferInitiationService.php`
- [x] T043 [US1] Create `RecipientLookupRequest` form request: validates `phone_number` XOR `wallet_number` (exactly one required, valid E.164 or non-empty string), rejects both-present — `app/Modules/Transfer/Infrastructure/Http/Requests/RecipientLookupRequest.php`
- [x] T044 [P] [US1] Create `TransferInitiateRequest` form request: validates `lookup_token` (UUID), `wallet_id` (UUID), `gross_amount` (integer ≥ 1), `currency_code` (3-char alpha); enforces `Idempotency-Key` header present — `app/Modules/Transfer/Infrastructure/Http/Requests/TransferInitiateRequest.php`
- [x] T045 [P] [US1] Create `TransferResource`: formats `TransferSummary` response shape per contracts/api.yaml — `app/Modules/Transfer/Infrastructure/Http/Resources/TransferResource.php`
- [x] T046 [US1] Create `RecipientLookupController::lookup()`: thin controller; delegates to `RecipientLookupService`; maps exceptions to HTTP responses (404 `RECIPIENT_NOT_FOUND`, 422 `RECIPIENT_WALLET_INACTIVE`/`SELF_TRANSFER_NOT_ALLOWED`) — `app/Modules/Transfer/Infrastructure/Http/Controllers/RecipientLookupController.php`
- [x] T047 [US1] Add `initiate()` action to `TransferController`: delegates to `TransferInitiationService`; handles idempotency (returns existing pending on duplicate key); returns 201; maps exceptions — `app/Modules/Transfer/Infrastructure/Http/Controllers/TransferController.php`
- [x] T048 [US1] Create unit tests: `TransferFeeServiceTest` (correct fee for various rates, zero rate → zero fee, rounding half-away-from-zero) and `TransferLimitServiceTest` (min/max/daily pass, daily excludes cancelled/failed, daily blocks at boundary) — `tests/Unit/Modules/Transfer/TransferFeeAndLimitTest.php`
- [x] T049 [US1] Create feature test `RecipientLookupTest`: happy path by phone, happy path by wallet_number, recipient not found (404), wallet inactive (422), self-transfer (422), both phone+wallet present (422), neither present (422) — `tests/Feature/Modules/Transfer/RecipientLookupTest.php`
- [x] T050 [US1] Create feature test `TransferInitiationTest`: happy path (201, correct fee), IDOR (403), invalid token (422), expired token (422), token reuse (422), INSUFFICIENT_BALANCE (422), DAILY_LIMIT_EXCEEDED (422), AMOUNT_BELOW_MINIMUM (422), AMOUNT_ABOVE_MAXIMUM (422), CURRENCY_MISMATCH (422), missing idempotency key (422), duplicate idempotency key (returns original 201) — `tests/Feature/Modules/Transfer/TransferInitiationTest.php`
- [x] T051 [US1] Create concurrency test `DailyLimitRaceTest`: two concurrent initiations where each would individually pass the daily limit but together exceed it; assert exactly one accepted (201) and one rejected (422 DAILY_LIMIT_EXCEEDED); assert single `transfer_transactions` row created — `tests/Concurrency/Modules/Transfer/DailyLimitRaceTest.php`

**Checkpoint**: Recipient lookup and transfer initiation work end-to-end. `pending` transfers are created with correct fee quotes. Daily limit prevents exceeding configured maximum under concurrency.

---

## Phase 4: US2 — Confirm and Authenticate Transfer (P0)

**Goal**: Customer confirms with PIN; system atomically posts double-entry journal, creates immutable receipt, transitions to `completed`. This is the financial core.

**Independent Test**: Confirm a pending transfer with correct PIN → `status = completed`, journal posted (balanced), `TransferReceipt` created, sender balance decreased by `gross_amount`, recipient balance increased by `net_amount`.

- [x] T052 [US2] Create `TransferConfirmationService::execute(string $transferId, string $customerId, string $pin, string $idempotencyKey, string $correlationId)`: (1) load and lock transfer (pending + ownership), (2) idempotency check on confirmation key, (3) fee re-validation (FR-E09-026 step 1), (4) daily limit re-validation **excluding the current transfer's own `pending` record from the sum** (FR-E09-026 step 2 — prevents double-counting the transfer being confirmed; query: `WHERE id != $transferId AND status IN ('pending','completed')`), (5) `verifyTransactionPin(requireStepUp)`; on failure throw `InvalidPinException` (do NOT transition to `failed`), (6) DB transaction: balance check + `postEntry()` (3-line or 2-line) + status→completed + completed_at + receipt insert; on `postEntry()` failure catch exception, transition to `failed(SYSTEM_ERROR)`, emit `Transfer.Failed`, rethrow as 500, (7) emit `TransferCompleted`, return completed model — `app/Modules/Transfer/Application/Services/TransferConfirmationService.php`
- [x] T053 [US2] Create `TransferConfirmRequest` form request: validates `transaction_pin` (string, writeOnly, never logged); enforces `Idempotency-Key` header — `app/Modules/Transfer/Infrastructure/Http/Requests/TransferConfirmRequest.php`
- [x] T054 [US2] Create `TransferReceiptResource`: formats `TransferReceipt` response per contracts/api.yaml — `app/Modules/Transfer/Infrastructure/Http/Resources/TransferReceiptResource.php`
- [x] T055 [US2] Add `confirm()` action to `TransferController`: delegates to `TransferConfirmationService`; maps `FeeMismatchException` → 409 with new fee details, `InvalidPinException` (Transfer domain exception, created in T022) → 422 `INVALID_PIN`, `TransferNotModifiableException` → 422 `TRANSFER_NOT_MODIFIABLE`; includes receipt in successful response — `app/Modules/Transfer/Infrastructure/Http/Controllers/TransferController.php`
- [x] T056 [US2] Create `TransferReceiptController::show(string $id)`: looks up receipt by `transfer_transaction_id`; authorizes caller is sender or recipient; returns 404 if no receipt (not completed) — `app/Modules/Transfer/Infrastructure/Http/Controllers/TransferReceiptController.php`
- [x] T057 [US2] Add `showByReference(string $receiptReference)` to `TransferReceiptController`: looks up by `receipt_reference` UUID; same authorization — `app/Modules/Transfer/Infrastructure/Http/Controllers/TransferReceiptController.php`
- [x] T058 [US2] Create feature test `TransferConfirmationTest`: happy path 3-line journal (fee > 0), happy path 2-line journal (fee = 0), FEE_MISMATCH 409 (change rate between init and confirm), INVALID_PIN 422 (transfer stays `pending`; not transitioned to `failed`), INSUFFICIENT_BALANCE 422 (balance drops between init and confirm), RECIPIENT_WALLET_SUSPENDED (recipient wallet suspended after initiation → transfer transitions to `failed(RECIPIENT_WALLET_SUSPENDED)`; no receipt), TRANSFER_NOT_MODIFIABLE on completed/failed/cancelled, idempotent replay (duplicate confirmation key returns 200 with existing receipt), missing idempotency key (422), journal post failure (mock `postEntry()` to throw → transfer transitions to `failed(SYSTEM_ERROR)` → no receipt row → 500 response; verifies FR-E09-033 rollback) — `tests/Feature/Modules/Transfer/TransferConfirmationTest.php`
- [x] T059 [US2] Create feature test `TransferJournalBalanceTest`: assert `sum(debit_lines) = sum(credit_lines) = gross_amount` for a fee > 0 case and a fee = 0 case; assert `ledger_journal_id IS NOT NULL` after completion; assert no journal entry exists for failed/cancelled transfer — `tests/Feature/Modules/Transfer/TransferJournalBalanceTest.php`
- [x] T060 [US2] Create feature test `TransferReceiptImmutabilityTest`: assert `TransferReceiptModel::save()` throws `TransferReceiptImmutableException` on a model already inserted; assert `delete()` also throws; assert initial insert succeeds — `tests/Feature/Modules/Transfer/TransferReceiptImmutabilityTest.php`
- [x] T061 [US2] Create concurrency test `TransferConfirmationConcurrencyTest` (CC-E09-003): fork two concurrent HTTP calls to `POST /v1/transfers/{id}/confirm` for the same `transfer_id`; assert exactly one returns 200, one returns 422 TRANSFER_NOT_MODIFIABLE; assert exactly one `ledger_journal_id` set, exactly one `transfer_receipts` row — `tests/Concurrency/Modules/Transfer/TransferConfirmationConcurrencyTest.php`
- [x] T062 [US2] Create concurrency test `TransferBalanceRaceTest` (CC-E09-001): create two separate pending transfers from the same sender wallet where confirming both would overdraft; assert exactly one succeeds, one fails with INSUFFICIENT_BALANCE; assert sender balance never goes negative — `tests/Concurrency/Modules/Transfer/TransferBalanceRaceTest.php`
- [x] T063 [US2] Create `TransferAuditTrailTest`: assert platform audit log receives entries for: initiation, confirmation (success), confirmation (PIN failure), cancellation, expiry; assert PIN value is absent from all log entries — `tests/Feature/Modules/Transfer/TransferAuditTrailTest.php`
- [x] T064 [US2] Create feature test `TransferStepUpTest`: initiate a transfer at/above `auth.step_up_threshold`; confirm without step-up session; assert `IdentityModuleContract::verifyTransactionPin` is called with `requireStepUp = true`; assert rejection when step-up state is not satisfied — `tests/Feature/Modules/Transfer/TransferStepUpTest.php`

**Checkpoint**: Financial core complete. Journal always balanced. Concurrent confirms are safe. Receipt is immutable. Audit trail is verified.

---

## Phase 5: US3 — Cancel Pending Transfer + Pending Expiry (P0)

**Goal**: Customers can cancel a pending transfer before confirming; expired pending transfers are cleaned up automatically.

**Independent Test**: Cancel a `pending` transfer → `status = cancelled`, no ledger entry. Run `transfer:expire-pending` → `pending` transfers past TTL transition to `failed`.

- [x] T065 [US3] Create `TransferCancellationService::cancel(string $transferId, string $customerId, string $correlationId)`: verify ownership + status = pending (throw `TransferNotCancellableException` if not), update status → cancelled + `cancelled_at`, emit `TransferCancelled` — `app/Modules/Transfer/Application/Services/TransferCancellationService.php`
- [x] T066 [US3] Add `cancel()` action to `TransferController`: delegates to `TransferCancellationService`; maps `TransferNotCancellableException` → 422 TRANSFER_NOT_CANCELLABLE — `app/Modules/Transfer/Infrastructure/Http/Controllers/TransferController.php`
- [x] T067 [US3] Create `ExpirePendingTransfersCommand`: queries `status = 'pending'` AND `created_at < NOW() - INTERVAL transfer.pending_ttl_minutes MINUTE`; batches updates to status = 'failed', `failure_reason = 'SYSTEM_ERROR'`; emits `TransferFailed` per expired transfer; logs count; idempotent — `app/Modules/Transfer/Console/ExpirePendingTransfersCommand.php`
- [x] T068 [US3] Register `ExpirePendingTransfersCommand` in `TransferServiceProvider` and schedule at `everyMinute()` (already done in T036; verify it is complete) — `app/Modules/Transfer/Providers/TransferServiceProvider.php`
- [x] T069 [US3] Create feature test `TransferCancellationTest`: cancel pending transfer (200, status=cancelled, no ledger entry, event emitted), cancel completed transfer (422 TRANSFER_NOT_CANCELLABLE), cancel non-owned transfer (404), cancel already-cancelled (422) — `tests/Feature/Modules/Transfer/TransferCancellationTest.php`
- [x] T070 [US3] Create feature test `TransferExpiryTest`: create pending transfer; invoke `transfer:expire-pending` with TTL=0 (test config); assert status=failed, failure_reason=SYSTEM_ERROR, Transfer.Failed event emitted, balances unchanged; assert re-running command has no effect on already-failed transfer — `tests/Feature/Modules/Transfer/TransferExpiryTest.php`

**Checkpoint**: Cancellation and expiry work. Cancelled transfers do not create journal entries. Expired transfers transition cleanly.

---

## Phase 6: US4 — View Transfer History and Receipts (P1)

**Goal**: Customers can paginate their transfer history (sender or recipient), retrieve individual transfers, and look up receipts by two routes.

**Independent Test**: `GET /v1/transfers` returns the authenticated customer's transfers as both sender and recipient. `GET /v1/transfers/{id}/receipt` and `GET /v1/transfers/receipts/{ref}` return the same receipt.

- [x] T071 [US4] Add `index()` action to `TransferController`: queries `transfer_transactions` where `sender_customer_id OR recipient_customer_id = auth`, applies status/date filters, paginates (default 20, max 100), returns `TransferResource` collection — `app/Modules/Transfer/Infrastructure/Http/Controllers/TransferController.php`
- [x] T072 [P] [US4] Add `show()` action to `TransferController`: loads by ID, verifies caller is sender or recipient (404 otherwise), returns `TransferResource` — `app/Modules/Transfer/Infrastructure/Http/Controllers/TransferController.php`
- [x] T073 [US4] Create feature test `TransferQueryTest`: list as sender (only own transfers), list as recipient (shows transfers received), filter by status, filter by date range, per_page limit enforcement, pagination meta correct, show own transfer by ID, show non-owned transfer (404) — `tests/Feature/Modules/Transfer/TransferQueryTest.php`
- [x] T074 [US4] Create feature test `TransferReceiptTest`: retrieve receipt by transaction ID as sender (200), as recipient (200), as third party (404), retrieve by receipt_reference as sender (200), as recipient (200), as third party (404), receipt for non-completed transfer (404), receipt contents match transfer amounts — `tests/Feature/Modules/Transfer/TransferReceiptTest.php`

**Checkpoint**: History and receipt retrieval works correctly with authorization enforcement.

---

## Phase 7: Admin Endpoints

**Purpose**: Admin operators can view all transfers and receipts for support and reconciliation.

- [x] T075 Create `AdminTransferController::index()`: queries all transfers with optional filters (status, sender_customer_id, recipient_customer_id, currency_code, from_date, to_date), paginates, includes `ledger_journal_id`, `correlation_id`, `fee_rate_bps` in response — `app/Modules/Transfer/Infrastructure/Http/Controllers/AdminTransferController.php`
- [x] T076 [P] Add `show()` to `AdminTransferController`: returns full transfer detail including `sender_pocket_ledger_ref`, `recipient_pocket_ledger_ref` — `app/Modules/Transfer/Infrastructure/Http/Controllers/AdminTransferController.php`
- [x] T077 Create feature test `AdminTransferTest`: admin can list all transfers including those not owned by admin, admin can show transfer detail with ledger refs, customer cannot access admin endpoints (403), admin cannot initiate or confirm transfers (403) — `tests/Feature/Modules/Transfer/AdminTransferTest.php`

---

## Phase 8: Polish & Cross-Cutting Concerns

**Purpose**: Metrics wiring, observability integration, final hardening, and documentation updates.

- [x] T078 Wire `TransferMetricsService` into all services: `RecipientLookupService` (no metric), `TransferInitiationService` (increment `transfer.initiated`), `TransferConfirmationService` (increment `transfer.completed` / `transfer.failed`, record `confirmation.duration_ms`, `journal.post.duration_ms`, `fee.amount`), `TransferCancellationService` (increment `transfer.cancelled`), `ExpirePendingTransfersCommand` (increment `transfer.expired`) — across affected service files
- [x] T079 [P] Wire platform `AuditService` calls into all mutation paths (initiation, confirmation attempt, PIN failure, cancellation, expiry, admin read) per FR-E09-050; verify PIN value is not included — across affected service/controller files
- [x] T080 [P] Create feature test `TransferMetricsTest`: assert `transfer.initiated` counter incremented after initiation, `transfer.completed` after confirmation, `transfer.failed` after failed confirmation, `transfer.cancelled` after cancellation, `transfer.expired` after expiry command — `tests/Feature/Modules/Transfer/TransferMetricsTest.php`
- [x] T081 [P] Update `specs/009-wallet-to-wallet-transfer/spec.md` if any genuine requirement gaps were discovered during implementation (per CLAUDE.md); do not silently modify requirements
- [x] T082 [P] Update `specs/009-wallet-to-wallet-transfer/plan.md` Constitution Check checkboxes once all phases are complete
- [x] T083 Run all Transfer module tests and confirm all pass: `php artisan test --filter=Transfer` — verify 0 failures

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: No dependencies — start immediately
- **Foundational (Phase 2)**: Depends on Phase 1 ✅ — BLOCKS all user stories
- **US1 (Phase 3)**: Depends on Phase 2 ✅ — first buildable feature
- **US2 (Phase 4)**: Depends on Phase 3 ✅ (requires pending transfers to exist for confirmation)
- **US3 (Phase 5)**: Depends on Phase 3 ✅ (requires pending transfers to cancel/expire)
- **US4 (Phase 6)**: Depends on Phase 4 ✅ (requires completed transfers and receipts for meaningful queries)
- **Admin (Phase 7)**: Depends on Phase 2 ✅ (can run in parallel with US1–US4)
- **Polish (Phase 8)**: Depends on Phases 3–7 complete

### User Story Dependencies

- **US1 (P0)**: Can start after Phase 2 — no dependency on other user stories
- **US2 (P0)**: Depends on US1 (needs pending transfers to confirm)
- **US3 (P0)**: Depends on US1 (needs pending transfers to cancel); independent of US2
- **US4 (P1)**: Depends on US2 (needs completed transfers and receipts)

### Within Each Phase

- Models before services
- Services before controllers
- Concurrent [P] tasks touch different files — safe to parallelize
- Tests can be written alongside implementation or after (spec requires them, not TDD)

### Parallel Opportunities

**Phase 2 (Foundational)**: T012–T022 [P] (all exceptions + enums), T027–T031 [P] (all events), T025–T026 [P] (risk interface + stub) can all run in parallel.

**Phase 3 (US1)**: T039 and T040 [P] (fee + limit services) in parallel; T043 and T044 [P] (form requests) in parallel; T048 (unit tests) alongside implementation.

**Phase 4 (US2)**: T061 and T062 [P] (concurrency tests) can run in parallel after T052 is complete.

---

## Parallel Examples

```
# Phase 2: run all exception creation in parallel (T013-T022)
T013 TransferNotModifiableException
T014 TransferNotCancellableException
T015 TransferReceiptImmutableException
T016 InsufficientBalanceException
T017 DailyLimitExceededException
T018 InvalidLookupTokenException
T019 RecipientNotFoundException
T020 RecipientWalletInactiveException
T021 SelfTransferException
T022 FeeMismatchException

# Phase 2: run all event creation in parallel (T027-T031)
T027 TransferInitiated
T028 TransferCompleted
T029 TransferFailed
T030 TransferCancelled
T031 TransferRiskRejected

# Phase 3: run fee+limit services in parallel
T039 TransferFeeService
T040 TransferLimitService

# Phase 4: run concurrency tests in parallel after T052
T061 TransferConfirmationConcurrencyTest
T062 TransferBalanceRaceTest
```

---

## Implementation Strategy

### MVP First (US1 Only — Lookup + Initiate)

1. Complete Phase 1 (Setup)
2. Complete Phase 2 (Foundational — **critical blocker**)
3. Complete Phase 3 (US1: Recipient Lookup + Initiation)
4. **VALIDATE**: `POST /v1/transfers/recipient-lookup` and `POST /v1/transfers` work; pending transfers created correctly; T049–T051 pass
5. Proceed to US2 (Confirmation — the financial core)

### Full Incremental Delivery

1. Setup + Foundational → module skeleton ready
2. US1 → lookup + initiation testable independently
3. US2 → money actually moves; journal balance verified; concurrency safe
4. US3 → cancellation and expiry working
5. US4 → history and receipts
6. Admin → support tooling
7. Polish → metrics, audit, documentation

---

## Notes

- [P] = different files, no incomplete-task dependencies — safe to parallelize
- [USN] label maps to user story N from spec.md for traceability
- Tests are required by spec DoD (constitution Principles 4 and 12)
- PIN values must never appear in logs, audit entries, or events — verified in T063 (audit trail test)
- CC-E09-001, CC-E09-002, CC-E09-003 are **constitution-mandated** concurrency tests — do not skip
- `TransferReceiptModel` immutability is verified by T060 — do not merge with other test classes
- All 5 domain events must have `correlationId` in payload (verified across feature tests)
