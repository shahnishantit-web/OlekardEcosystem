# Tasks: P18 â€” Beneficiary Verification and Payout Lifecycle

**Input**: Design documents from `specs/065-beneficiary-payout-lifecycle/`

**Prerequisites**: plan.md âœ“, spec.md âœ“, research.md âœ“, data-model.md âœ“, contracts/payout-api.yaml âœ“, quickstart.md âœ“

**Source paths**: `wallet-ecosystem/services/api/`

**Blocked phases**: US3 (AD-14) and US4 (AD-10 + LC14) are scaffolded as empty stubs with `@todo` comments â€” do not implement until architectural decisions are resolved.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies on incomplete tasks)
- **[Story]**: Which user story this task belongs to

---

## Phase 1: Setup

**Purpose**: Verify P18 directory structure exists within the Payment module; no new top-level project required.

- [X] T001 Verify `app/Modules/Payment/` directory structure exists and confirm migration sequence starts at `2026_10_25_000019` (check that 000018 is the last existing P17 migration in `app/Modules/Payment/Infrastructure/Migrations/`)

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Enums, exceptions, and the stub adapter interface â€” shared by all user stories. Must be complete before any story implementation starts.

**âš ï¸ CRITICAL**: No user story tasks can begin until this phase is complete.

- [X] T002 [P] Create `PayoutRequestStatus` enum in `app/Modules/Payment/Domain/Enums/PayoutRequestStatus.php` with cases: `PendingVerification`, `AwaitingApproval`, `Reserved`, `Approved`, `Executing`, `PendingSettlement`, `Settled`, `Failed`, `ManualReview`, `Cancelled` (string-backed)
- [X] T003 [P] Create `PayoutReservationStatus` enum in `app/Modules/Payment/Domain/Enums/PayoutReservationStatus.php` with cases: `AwaitingApproval`, `Approved`, `Invalidated`, `Rejected`, `Expired`, `Released` (string-backed)
- [X] T004 [P] Create `PayoutBeneficiaryStatus` enum in `app/Modules/Payment/Domain/Enums/PayoutBeneficiaryStatus.php` with cases: `PendingVerification`, `Verified`, `Suspended`, `Rejected` (string-backed)
- [X] T005 [P] Create `PayoutAttemptStatus` enum in `app/Modules/Payment/Domain/Enums/PayoutAttemptStatus.php` with cases: `Submitted`, `Pending`, `Succeeded`, `Failed`, `Unknown` (string-backed)
- [X] T006 [P] Create `PayoutSettlementReconciliationStatus` enum in `app/Modules/Payment/Domain/Enums/PayoutSettlementReconciliationStatus.php` with cases: `Pending`, `Matched`, `Discrepancy`, `ExceptionRaised` (string-backed)
- [X] T007 [P] Create `BeneficiaryNotVerifiedException` in `app/Modules/Payment/Domain/Exceptions/BeneficiaryNotVerifiedException.php` â€” extends `\RuntimeException`; static factory `forBeneficiary(string $beneficiaryId): self` with message `"Beneficiary {$beneficiaryId} is not in verified status"`
- [X] T008 [P] Create `InsufficientPayableBalanceException` in `app/Modules/Payment/Domain/Exceptions/InsufficientPayableBalanceException.php` â€” extends `\RuntimeException`; static factory `forAmount(int $requestedMinor, int $availableMinor): self` with message `"Requested {$requestedMinor} exceeds payable balance {$availableMinor}"`
- [X] T009 [P] Create `PayoutSelfApprovalException` in `app/Modules/Payment/Domain/Exceptions/PayoutSelfApprovalException.php` â€” extends `\RuntimeException`; static factory `notPermitted(): self` with message `"Maker cannot approve their own payout reservation"` (mirrors `CorrectionApprovalException` pattern from P17)
- [X] T010 [P] Create `PayoutApprovalInvalidatedException` in `app/Modules/Payment/Domain/Exceptions/PayoutApprovalInvalidatedException.php` â€” extends `\RuntimeException`; static factory `afterAmountChange(): self` and `afterDestinationChange(): self`
- [X] T011 [P] Create `PayoutReservationExpiredException` in `app/Modules/Payment/Domain/Exceptions/PayoutReservationExpiredException.php` â€” extends `\RuntimeException`; static factory `forReservation(string $reservationId): self`
- [X] T012 [P] Create `PayoutAdapterInterface` stub in `app/Modules/Payment/Domain/Contracts/PayoutAdapterInterface.php` â€” **BLOCKED AD-14**: empty interface with PHP-doc comment `@todo AD-14 â€” Payout contract ownership must be resolved before implementing initiatePayout() and queryPayoutStatus() signatures`; no method bodies
- [X] T012a [P] Create `DomainEntitlementDeniedException` in `app/Modules/Payment/Domain/Exceptions/DomainEntitlementDeniedException.php` â€” extends `\RuntimeException`; static factory `forFund(string $fundReferenceId, string $purposeType): self` with message `"Domain entitlement not granted for fund {$fundReferenceId} (purpose: {$purposeType}) â€” business lifecycle must be confirmed complete before disbursement"`
- [X] T012b [P] Create `PayoutDomainEntitlementGatewayInterface` in `app/Modules/Payment/Domain/Contracts/PayoutDomainEntitlementGatewayInterface.php` â€” single method `isEntitled(string $fundReferenceId, string $purposeType, string $organisationId): bool`; PHP-doc: "Domain modules implement and bind this interface in their own ServiceProvider to confirm their fund lifecycle is complete and disbursement is authorised (FR-002)"

**Checkpoint**: Foundation complete â€” all enums, exceptions, stub adapter interface, and domain entitlement gateway contract exist. User story implementation can now begin.

---

## Phase 3: User Story 1 â€” Beneficiary Verification and Payable Balance (Priority: P1) ðŸŽ¯ MVP

**Goal**: Register beneficiaries, record verification, compute server-authoritative cleared payable balance.

**Independent Test**: `GET /api/v1/admin/payment/payout/requests/{id}/balance` returns a breakdown of custody âˆ’ refunds âˆ’ dispute_holds âˆ’ existing_reservations; `POST /payout/beneficiaries/{id}/verify` transitions `pending_verification â†’ verified`.

### Data Layer

- [X] T013 [P] [US1] Create migration `2026_10_25_000019_create_payout_beneficiaries_table` in `app/Modules/Payment/Infrastructure/Migrations/` â€” table `payment.payout_beneficiaries` per data-model.md: uuid PK, organisation_id, account_type CHECK constraint (`bank_account`,`mobile_money`,`wallet`), account_number (encrypted text), bank_code, bank_name, account_holder_name, currency_code char(3), status varchar(30) DEFAULT `pending_verification`, verified_at, verification_reference, created_by, timestamps; indexes on `(organisation_id)`, `(status)`, `(created_at)`
- [X] T014 [P] [US1] Create migration `2026_10_25_000020_create_payout_requests_table` in `app/Modules/Payment/Infrastructure/Migrations/` â€” table `payment.payout_requests` per data-model.md: uuid PK, idempotency_key UNIQUE, fund_reference_id, purpose_type, organisation_id, beneficiary_id FK â†’ payout_beneficiaries.id, currency_code char(3), requested_amount_minor bigint, computed_payable_minor bigint, status varchar(30) DEFAULT `pending_verification`, operation_id char(64) nullable, provider_code nullable, ledger_entry_id nullable, created_by, settled_at nullable, timestamps; UNIQUE `(idempotency_key)`, indexes on `(fund_reference_id)`, `(organisation_id)`, `(beneficiary_id)`, `(status)`, `(created_at)`
- [X] T015 [P] [US1] Create `PayoutBeneficiaryModel` in `app/Modules/Payment/Infrastructure/Models/PayoutBeneficiaryModel.php` â€” Eloquent model with `$connection = 'payment'`, `$table = 'payout_beneficiaries'`, `$keyType = 'string'`, `$incrementing = false`, `$casts = ['id' => 'string', 'account_number' => 'encrypted', 'verified_at' => 'datetime', 'status' => PayoutBeneficiaryStatus::class]`, `$fillable` per migration columns (depends on T013)
- [X] T016 [P] [US1] Create `PayoutRequestModel` in `app/Modules/Payment/Infrastructure/Models/PayoutRequestModel.php` â€” Eloquent model with `$connection = 'payment'`, `$table = 'payout_requests'`, UUID PK, `$casts` including `status` â†’ `PayoutRequestStatus::class`, `settled_at` â†’ `datetime`, `belongsTo(PayoutBeneficiaryModel::class)` relationship (depends on T014, T015)
- [X] T017 [P] [US1] Create `PayoutBeneficiaryRepositoryInterface` in `app/Modules/Payment/Domain/Contracts/PayoutBeneficiaryRepositoryInterface.php` â€” methods: `findById(string $id): ?PayoutBeneficiaryModel`, `findByOrganisation(string $organisationId, ?PayoutBeneficiaryStatus $status, int $perPage): LengthAwarePaginator`, `save(PayoutBeneficiaryModel $model): void`
- [X] T018 [P] [US1] Create `PayoutRequestRepositoryInterface` in `app/Modules/Payment/Domain/Contracts/PayoutRequestRepositoryInterface.php` â€” methods: `findById(string $id): ?PayoutRequestModel`, `findByIdempotencyKey(string $key): ?PayoutRequestModel`, `findByFund(string $fundReferenceId, string $currencyCode): Collection`, `sumActiveReservationsForFund(string $fundReferenceId, string $currencyCode): int`, `save(PayoutRequestModel $model): void`, `paginate(array $filters, int $perPage): LengthAwarePaginator`
- [X] T019 [US1] Create `EloquentPayoutBeneficiaryRepository` in `app/Modules/Payment/Infrastructure/Repositories/EloquentPayoutBeneficiaryRepository.php` â€” implements `PayoutBeneficiaryRepositoryInterface`; uses `PayoutBeneficiaryModel`; `findByOrganisation` builds query with optional status filter and `->paginate($perPage)` (depends on T015, T017)
- [X] T020 [US1] Create `EloquentPayoutRequestRepository` in `app/Modules/Payment/Infrastructure/Repositories/EloquentPayoutRequestRepository.php` â€” implements `PayoutRequestRepositoryInterface`; `sumActiveReservationsForFund` queries `payout_reservations` table via JOIN on `payout_request_id` WHERE `payout_requests.fund_reference_id = $fundReferenceId` AND `payout_reservations.status IN ('awaiting_approval','approved')` SUM `reserved_amount_minor` (depends on T016, T018)

### Domain Layer

- [X] T021 [P] [US1] Create `PayableBalance` value object in `app/Modules/Payment/Domain/ValueObjects/PayableBalance.php` â€” readonly class with fields: `fundReferenceId`, `currencyCode`, `custodyBalanceMinor`, `confirmedRefundsMinor`, `openDisputeHoldsMinor`, `existingReservationsMinor`, `computedAt` (Carbon); computed property `payableMinor(): int` returning `max(0, custody - refunds - holds - reservations)`; `toArray(): array` method
- [X] T022 [US1] Implement `PayoutBeneficiaryService` in `app/Modules/Payment/Application/Services/PayoutBeneficiaryService.php` â€” constructor injects `PayoutBeneficiaryRepositoryInterface` and `AuditService`; methods: `register(array $data, string $actorId): PayoutBeneficiaryModel` (creates with `status=pending_verification`, audits `payment.payout.beneficiary_registered`); `recordVerification(string $beneficiaryId, string $verificationRef, string $actorId): PayoutBeneficiaryModel` (sets `status=verified`, `verified_at=now()`, audits `payment.payout.beneficiary_verified`); `assertVerified(string $beneficiaryId): void` (throws `BeneficiaryNotVerifiedException` if not verified) (depends on T017, T019, T007)
- [X] T022a [US1] Implement `PayoutDomainEntitlementService` in `app/Modules/Payment/Application/Services/PayoutDomainEntitlementService.php` â€” constructor injects `PayoutDomainEntitlementGatewayInterface` and `AuditService`; single method `assertEntitled(string $fundReferenceId, string $purposeType, string $organisationId): void`; calls `$this->gateway->isEntitled(...)` and throws `DomainEntitlementDeniedException::forFund()` if false; audits `payment.payout.entitlement_checked` on each call; bind `PayoutDomainEntitlementGatewayInterface` in `PaymentServiceProvider` to a `PermissiveEntitlementGateway` default (always returns true, logs a Laravel warning channel entry) â€” domain modules replace this binding in their own ServiceProvider (covers FR-002) (depends on T012a, T012b)
- [X] T023 [US1] Implement `PayoutBalanceService` in `app/Modules/Payment/Application/Services/PayoutBalanceService.php` â€” constructor injects `WalletModuleContract`, `PayoutRequestRepositoryInterface`, and DB connection; `computeForFund(string $fundReferenceId, string $purposeType, string $currencyCode, string $custodyWalletId): PayableBalance`; logic: (1) `WalletModuleContract::availableBalance($custodyWalletId, $currencyCode)` â†’ `custodyBalance`; (2) confirmed refunds: `DB::connection('payment')->table('payment_refunds')->join('payment_intents', 'payment_refunds.payment_intent_id', '=', 'payment_intents.id')->where('payment_intents.purpose_reference', $fundReferenceId)->where('payment_intents.purpose_type', $purposeType)->where('payment_refunds.currency_code', $currencyCode)->whereIn('payment_refunds.status', ['confirmed', 'settled'])->sum('payment_refunds.amount_minor')` â†’ `confirmedRefunds`; (3) open dispute holds: `payment_dispute_holds` JOIN `clearing_records` ON `clearing_record_id` JOIN `payment_attempts` ON `payment_attempt_id` JOIN `payment_intents` ON `payment_intent_id` WHERE `payment_intents.purpose_reference = $fundReferenceId` AND `payment_intents.purpose_type = $purposeType` AND `dispute_holds.status IN ('open','under_review','awaiting_evidence')` SUM `held_amount_minor` â†’ `disputeHolds`; (4) `PayoutRequestRepositoryInterface::sumActiveReservationsForFund($fundReferenceId, $currencyCode)` â†’ `existingReservations`; return `new PayableBalance(fundReferenceId: $fundReferenceId, currencyCode: $currencyCode, custodyBalanceMinor: $custodyBalance, confirmedRefundsMinor: $confirmedRefunds, openDisputeHoldsMinor: $disputeHolds, existingReservationsMinor: $existingReservations, computedAt: now())` (depends on T018, T020, T021)

### HTTP Layer

- [X] T024 [P] [US1] Create `PayoutBeneficiaryResource` in `app/Modules/Payment/Http/Resources/PayoutBeneficiaryResource.php` â€” extends `JsonResource`; exposes: id, organisation_id, account_type, account_holder_name, bank_name, currency_code, status (string value), verified_at (ISO-8601), created_at; **NOTE: account_number is NEVER exposed in API responses**
- [X] T025 [P] [US1] Create `PayoutRequestResource` in `app/Modules/Payment/Http/Resources/PayoutRequestResource.php` â€” exposes: id, fund_reference_id, purpose_type, organisation_id, beneficiary_id, currency_code, requested_amount_minor, computed_payable_minor, status (string value), operation_id, settled_at, created_at; optional `reservation` key if the relationship is loaded
- [X] T026 [US1] Implement `PayoutBeneficiaryAdminController` in `app/Modules/Payment/Http/Controllers/Admin/PayoutBeneficiaryAdminController.php` â€” `final class`, thin controller; actions: `index(Request)` (paginate with filters), `store(Request)` (validate `Idempotency-Key` header present and non-empty â†’ 422 if absent; validate body: organisation_id uuid required, account_type in [bank_account,mobile_money,wallet] required, account_number required min:4 max:200, account_holder_name required min:2, currency_code size:3 required, bank_code nullable, bank_name nullable; catch `QueryException` with unique violation â†’ 409; call `PayoutBeneficiaryService::register()`), `show(string $id)`, `verify(Request, string $id)` (validate `Idempotency-Key` header present â†’ 422 if absent; validate body: verification_reference required max:200, verification_note nullable max:500; call `PayoutBeneficiaryService::recordVerification()`); all return `PayoutBeneficiaryResource` (depends on T022, T024)
- [X] T027 [US1] Implement `PayoutRequestAdminController` (US1 scope: initiate + show + list + balance) in `app/Modules/Payment/Http/Controllers/Admin/PayoutRequestAdminController.php` â€” `final class`; `initiate(Request)`: (1) validates `Idempotency-Key` header present and non-empty â†’ 422 if absent; (2) validates body: fund_reference_id uuid, purpose_type max:100, organisation_id uuid, beneficiary_id uuid, currency_code size:3, custody_wallet_id uuid required; (3) calls `PayoutDomainEntitlementService::assertEntitled()` â†’ 403 on `DomainEntitlementDeniedException`; (4) calls `PayoutBeneficiaryService::assertVerified()` â†’ 400 on `BeneficiaryNotVerifiedException`; (5) calls `PayoutBalanceService::computeForFund($fundReferenceId, $purposeType, $currencyCode, $custodyWalletId)`; creates `PayoutRequestModel` with `computed_payable_minor`; catches idempotency key `QueryException` â†’ 409; `show(string $id)`, `index(Request)`, `balance(string $id)` returns `PayableBalance::toArray()` (depends on T022, T022a, T023, T025)
- [X] T028 [US1] Append P18 beneficiary and payout request admin routes to `wallet-ecosystem/services/api/routes/modules/payment.php` â€” add imports and route group under `Route::prefix('admin/payment')->middleware(['auth:sanctum', 'payment.scope'])`: `POST /payout/beneficiaries` â†’ `PayoutBeneficiaryAdminController@store`, `GET /payout/beneficiaries` â†’ `index`, `GET /payout/beneficiaries/{beneficiaryId}` â†’ `show`, `POST /payout/beneficiaries/{beneficiaryId}/verify` â†’ `verify`; `POST /payout/requests` â†’ `PayoutRequestAdminController@initiate`, `GET /payout/requests` â†’ `index`, `GET /payout/requests/{payoutId}` â†’ `show`, `GET /payout/requests/{payoutId}/balance` â†’ `balance` (depends on T026, T027)
- [X] T029 [US1] Add US1 bindings to `PaymentServiceProvider` in `app/Modules/Payment/Providers/PaymentServiceProvider.php` â€” bind: `PayoutBeneficiaryRepositoryInterface â†’ EloquentPayoutBeneficiaryRepository`, `PayoutRequestRepositoryInterface â†’ EloquentPayoutRequestRepository`; register singletons: `PayoutBeneficiaryService`, `PayoutBalanceService` (depends on T019, T020, T022, T023)

### Tests

- [X] T030 [P] [US1] Write unit test `PayoutBeneficiaryServiceTest` in `wallet-ecosystem/services/api/tests/Unit/Payment/PayoutBeneficiaryServiceTest.php` â€” mock repository; tests: `register_creates_beneficiary_with_pending_verification_status`, `recordVerification_sets_verified_status_and_timestamp`, `assertVerified_throws_for_unverified_beneficiary`, `assertVerified_does_not_throw_for_verified_beneficiary`, `PayoutBeneficiaryStatus_has_all_four_cases`
- [X] T031 [P] [US1] Write unit test `PayoutBalanceServiceTest` in `wallet-ecosystem/services/api/tests/Unit/Payment/PayoutBalanceServiceTest.php` â€” mock WalletModuleContract + repository + DB facade; tests: `payable_balance_deducts_refunds_holds_and_reservations`, `payable_balance_floors_at_zero_when_deductions_exceed_custody`, `PayableBalance_toArray_contains_all_required_keys`, `PayableBalance_payableMinor_never_negative`, `refund_query_joins_through_payment_intents_purpose_reference_not_direct_column` (asserts query builder calls `.join('payment_intents', 'payment_refunds.payment_intent_id', '=', 'payment_intents.id')` and `.where('payment_intents.purpose_reference', ...)` â€” not a direct `fund_reference_id` filter on `payment_refunds`)

**Checkpoint**: US1 complete â€” beneficiary registration, verification, and payable balance endpoint are independently functional and unit-tested.

---

## Phase 4: User Story 2 â€” Funds Reservation with Maker/Checker (Priority: P1)

**Goal**: Create a locked reservation against the custody balance; separate maker and approver actors; invalidate approval on post-approval edits.

**Independent Test**: Maker creates reservation â†’ checker approves â†’ same maker cannot approve (403) â†’ changing amount invalidates approval â†’ `PayoutReservationExpiryJob` expires stale `awaiting_approval` reservations.

### Data Layer

- [X] T032 [US2] Create migration `2026_10_25_000021_create_payout_reservations_table` in `app/Modules/Payment/Infrastructure/Migrations/` â€” table `payment.payout_reservations` per data-model.md: uuid PK, payout_request_id FK â†’ payout_requests.id UNIQUE (one reservation per request), reserved_amount_minor bigint, currency_code char(3), custody_wallet_id uuid, status varchar(30) DEFAULT `awaiting_approval`, maker_id uuid, approver_id uuid nullable, approved_at nullable, rejection_reason text nullable, expires_at timestamptz NOT NULL, amount_hash char(64) NOT NULL, timestamps; UNIQUE `(payout_request_id)`, indexes on `(maker_id)`, `(approver_id)`, `(status)`, `(expires_at)` (depends on T014 migration being applied first)
- [X] T033 [US2] Create `PayoutReservationModel` in `app/Modules/Payment/Infrastructure/Models/PayoutReservationModel.php` â€” Eloquent model, `$connection = 'payment'`, `$table = 'payout_reservations'`, UUID PK, `$casts`: status â†’ `PayoutReservationStatus::class`, approved_at â†’ datetime, expires_at â†’ datetime; `belongsTo(PayoutRequestModel::class)` relationship (depends on T032, T016)
- [X] T034 [P] [US2] Create `PayoutReservationRepositoryInterface` in `app/Modules/Payment/Domain/Contracts/PayoutReservationRepositoryInterface.php` â€” methods: `findByPayoutRequest(string $payoutRequestId): ?PayoutReservationModel`, `findExpired(): Collection` (status=awaiting_approval AND expires_at < NOW()), `save(PayoutReservationModel $model): void`
- [X] T035 [US2] Create `EloquentPayoutReservationRepository` in `app/Modules/Payment/Infrastructure/Repositories/EloquentPayoutReservationRepository.php` â€” implements `PayoutReservationRepositoryInterface`; `findExpired()` queries `WHERE status = 'awaiting_approval' AND expires_at < NOW()` (depends on T033, T034)

### Domain Layer

- [X] T036 [P] [US2] Create `PayoutOperationId` value object in `app/Modules/Payment/Domain/ValueObjects/PayoutOperationId.php` â€” `final class` with single static method `fromReservation(string $reservationId): string` returning `bin2hex(hash('sha256', "payout:{$reservationId}", true))` â€” 64-char lowercase hex; used to set `payout_requests.operation_id` when reservation is first approved
- [X] T037 [P] [US2] Create `PayoutReservationApprovedEvent` in `app/Modules/Payment/Domain/Events/PayoutReservationApprovedEvent.php` â€” implements `IntegrationEventInterface`; carries `payoutRequestId`, `reservationId`, `approvedBy`, `reservedAmountMinor`, `currencyCode`, `occurredAt`; `eventType()` returns `'payment.payout.reservation_approved'`; `idempotencyKey()` returns `"payout-reservation-approved:{$this->reservationId}"`
- [X] T038 [US2] Implement `PayoutReservationService` in `app/Modules/Payment/Application/Services/PayoutReservationService.php` â€” constructor injects `PayoutReservationRepositoryInterface`, `PayoutRequestRepositoryInterface`, `WalletModuleContract`, `OutboxEventPublisher`, `AuditService`; methods:
  - `createReservation(string $payoutRequestId, int $reservedAmountMinor, string $makerId): PayoutReservationModel` â€” opens `DB::connection('payment')->transaction()` with `SELECT FOR UPDATE` on payout_request; recomputes payable balance inside lock; throws `InsufficientPayableBalanceException` if insufficient; computes `expires_at = NOW() + config('payment.payout.reservation_expiry_hours', 48) hours`; computes `amount_hash = hash('sha256', "{$reservedAmountMinor}|{$beneficiaryId}")`; inserts reservation; updates payout_request status to `reserved`; audits `payment.payout.reservation_created`
  - `approveReservation(string $payoutRequestId, string $approverId): PayoutReservationModel` â€” throws `PayoutSelfApprovalException::notPermitted()` if `$approverId == $reservation->maker_id`; sets status=approved, approver_id, approved_at; sets `payout_requests.operation_id = PayoutOperationId::fromReservation($reservation->id)`; publishes `PayoutReservationApprovedEvent` via outbox; audits `payment.payout.reservation_approved`
  - `rejectReservation(string $payoutRequestId, string $rejectionReason, string $rejectorId): PayoutReservationModel` â€” sets status=rejected, rejection_reason; updates payout_request status to awaiting_approval; audits `payment.payout.reservation_rejected`
  - `invalidateIfChanged(PayoutReservationModel $reservation, int $newAmountMinor, string $newBeneficiaryId): bool` â€” recomputes hash; if changed: sets status=invalidated, clears approver_id+approved_at, updates payout_request to awaiting_approval, audits `payment.payout.reservation_invalidated`; returns true if invalidated
  - `expireStale(): int` â€” queries `findExpired()`, sets each to status=expired, audits, returns count
  (depends on T034, T035, T036, T037, T008, T009)
- [X] T039 [P] [US2] Create `PayoutReservationExpiryJob` in `app/Modules/Payment/Application/Jobs/PayoutReservationExpiryJob.php` â€” implements `ShouldQueue`, `ShouldBeUniqueUntilProcessing`; `public int $tries = 1`; `handle(PayoutReservationService $service): void` calls `$service->expireStale()`; `uniqueId()` returns `'payout-reservation-expiry'`

### HTTP Layer

- [X] T040 [P] [US2] Create `PayoutReservationResource` in `app/Modules/Payment/Http/Resources/PayoutReservationResource.php` â€” exposes: id, payout_request_id, reserved_amount_minor, currency_code, status (string value), maker_id, approver_id (nullable), approved_at (ISO-8601 nullable), rejection_reason (nullable), expires_at (ISO-8601), created_at
- [X] T041 [US2] Implement `PayoutReservationAdminController` in `app/Modules/Payment/Http/Controllers/Admin/PayoutReservationAdminController.php` â€” `final class`; actions:
  - `createReservation(Request $request, string $payoutId)`: validate `Idempotency-Key` header present and non-empty â†’ 422 if absent; validates `reserved_amount_minor` min:1 required; catches `QueryException` duplicate UNIQUE â†’ 409; catches `InsufficientPayableBalanceException` â†’ 400; returns `PayoutReservationResource` 201
  - `approveReservation(Request $request, string $payoutId)`: validate `Idempotency-Key` header present â†’ 422 if absent; catches `PayoutSelfApprovalException` (message "Maker cannot approve") â†’ 403; catches other exceptions â†’ 409; returns `PayoutReservationResource` 200
  - `rejectReservation(Request $request, string $payoutId)`: validates `rejection_reason` required min:10; returns `PayoutReservationResource` 200
  (depends on T038, T040)
- [X] T042 [US2] Append P18 reservation routes to `wallet-ecosystem/services/api/routes/modules/payment.php` â€” add under existing admin/payment group: `POST /payout/requests/{payoutId}/reserve` â†’ `PayoutReservationAdminController@createReservation`, `POST /payout/requests/{payoutId}/approve-reservation` â†’ `approveReservation`, `POST /payout/requests/{payoutId}/reject-reservation` â†’ `rejectReservation` (depends on T041, T028)
- [X] T043 [US2] Add US2 bindings + scheduler to `PaymentServiceProvider` and `wallet-ecosystem/services/api/routes/console.php`:
  - `PaymentServiceProvider::register()`: bind `PayoutReservationRepositoryInterface â†’ EloquentPayoutReservationRepository`; singleton `PayoutReservationService`
  - `console.php`: add `use App\Modules\Payment\Application\Jobs\PayoutReservationExpiryJob;` and `Schedule::job(PayoutReservationExpiryJob::class)->hourly()->withoutOverlapping();`
  (depends on T035, T038, T039)

### Tests

- [X] T044 [P] [US2] Write unit test `PayoutReservationServiceTest` in `wallet-ecosystem/services/api/tests/Unit/Payment/PayoutReservationServiceTest.php` â€” mock repositories + WalletModuleContract; tests:
  - `approveReservation_throws_PayoutSelfApprovalException_when_approver_is_maker`
  - `PayoutSelfApprovalException_message_contains_cannot_approve_own_reservation`
  - `invalidateIfChanged_returns_true_and_clears_approval_when_amount_changes`
  - `invalidateIfChanged_returns_false_when_hash_matches`
  - `amount_hash_format_is_sha256_of_amount_and_beneficiary`
  - `PayoutOperationId_fromReservation_is_deterministic_64_char_hex`
  - `PayoutOperationId_fromReservation_changes_with_different_reservation_ids`
  - `expireStale_returns_count_of_expired_reservations`

**Checkpoint**: US2 complete â€” reservation creation, maker/checker approval, invalidation-on-change, and expiry job are all functional and unit-tested. US1+US2 together form a complete, independently deployable increment.

---

## Phase 5: User Story 3 â€” Payout Execution via Adapter [BLOCKED: AD-14] (Priority: P2)

**Goal**: Scaffold empty stubs so the module compiles. Execution cannot be implemented until the architect resolves AD-14 (payout contract ownership).

**âš ï¸ ALL TASKS IN THIS PHASE ARE STUBS â€” no service logic is implemented.**

- [X] T045 [P] [US3] Create migration `2026_10_25_000022_create_payout_attempts_table` (stub â€” BLOCKED AD-14) in `app/Modules/Payment/Infrastructure/Migrations/` â€” table `payment.payout_attempts` per data-model.md columns; all columns created; up() and down() implemented; comment in migration: `// STUB â€” service layer blocked pending AD-14 resolution`
- [X] T046 [P] [US3] Create `PayoutAttemptModel` stub in `app/Modules/Payment/Infrastructure/Models/PayoutAttemptModel.php` â€” Eloquent model with correct `$table`, `$connection`, casts for `status â†’ PayoutAttemptStatus::class`, `submitted_at` and `completed_at` â†’ datetime; `belongsTo(PayoutRequestModel::class)` relationship; `// STUB â€” service layer blocked pending AD-14` comment (depends on T045)
- [X] T047 [P] [US3] Create `StubPayoutAdapter` (DEV MOCK) in `app/Modules/Payment/Infrastructure/Adapters/StubPayoutAdapter.php` â€” class header comment: `// DEV MOCK â€” NOT FOR PRODUCTION â€” StubPayoutAdapter returns configurable responses for local development and testing only`; implements `PayoutAdapterInterface` (empty interface from T012); reads response mode from `config('payment.payout.stub_adapter_response', 'succeeded')`; for local testing of US1+US2 flows only
- [X] T048 [P] [US3] Create `PayoutExecutionSubmittedEvent` stub in `app/Modules/Payment/Domain/Events/PayoutExecutionSubmittedEvent.php` â€” implements `IntegrationEventInterface`; `eventType()` returns `'payment.payout.execution_submitted'`; constructor takes `payoutRequestId`, `operationId`; `// STUB â€” blocked AD-14` comment
- [X] T049 [US3] Create `PayoutExecutionService` stub in `app/Modules/Payment/Application/Services/PayoutExecutionService.php` â€” class-level comment: `// STUB â€” BLOCKED AD-14: payout contract ownership unresolved; all methods throw LogicException until AD-14 is resolved`; single method `execute(string $payoutRequestId): never` that throws `new \LogicException('PayoutExecutionService is blocked: AD-14 payout contract ownership must be resolved before implementation.')`
- [X] T050 [P] [US3] Create `PayoutAttemptQueryJob` stub in `app/Modules/Payment/Application/Jobs/PayoutAttemptQueryJob.php` â€” `// STUB â€” BLOCKED AD-14`; `handle(): never` throws `new \LogicException('PayoutAttemptQueryJob is blocked: AD-14 payout contract ownership must be resolved.')`
- [X] T051 [US3] Add execute route stub to `wallet-ecosystem/services/api/routes/modules/payment.php` â€” `POST /payout/requests/{payoutId}/execute` route that returns `response()->json(['message' => 'Not implemented â€” blocked pending AD-14 resolution'], 501)` directly in the route closure (no controller method needed for stub)

**Checkpoint**: US3 stubbed â€” module compiles; execute endpoint returns 501; all stub files carry BLOCKED comments.

---

## Phase 6: User Story 4 â€” Settlement Confirmation and Ledger Disbursement [BLOCKED: AD-10, LC14] (Priority: P2)

**Goal**: Scaffold empty stubs for settlement reconciliation and ledger disbursement. Cannot be implemented until AD-10 (custody account type) and LC14 (disbursement ledger contract) are resolved.

**âš ï¸ ALL TASKS IN THIS PHASE ARE STUBS.**

- [X] T052 [US4] Create migration `2026_10_25_000023_create_payout_settlement_records_table` (stub â€” BLOCKED AD-10) in `app/Modules/Payment/Infrastructure/Migrations/` â€” table `payment.payout_settlement_records` per data-model.md; **ALSO** includes `ALTER TABLE payment.reconciliation_exceptions DROP CONSTRAINT IF EXISTS recon_exceptions_type_check` and recreates the CHECK constraint with ALL current exception types including new `payout_amount_discrepancy` and `payout_missing_evidence` (full list: all 16 from P17 + these 2 = 18 total); comment: `// STUB â€” settlement service blocked pending AD-10 and LC14 resolution`
- [X] T053 [P] [US4] Create `PayoutSettlementRecordModel` stub in `app/Modules/Payment/Infrastructure/Models/PayoutSettlementRecordModel.php` â€” Eloquent model with correct table/connection/casts; `belongsTo(PayoutAttemptModel::class)`; `// STUB â€” blocked AD-10` (depends on T052)
- [X] T054 [P] [US4] Create `PayoutSettledEvent` stub in `app/Modules/Payment/Domain/Events/PayoutSettledEvent.php` â€” implements `IntegrationEventInterface`; `eventType()` returns `'payment.payout.settled'`; constructor takes `payoutRequestId`, `ledgerEntryId`; `// STUB â€” blocked AD-10` comment
- [X] T055 [US4] Create `PayoutSettlementService` stub in `app/Modules/Payment/Application/Services/PayoutSettlementService.php` â€” comment: `// STUB â€” BLOCKED AD-10 + LC14: custody account type and disbursement ledger contract must be resolved before implementation`; single method `confirmSettlement(string $payoutRequestId, array $providerEvidence): never` throws `new \LogicException('PayoutSettlementService is blocked: AD-10 custody account type and LC14 disbursement ledger contract must be resolved.')`
- [X] T056 [US4] Add confirm-settlement route stub to `wallet-ecosystem/services/api/routes/modules/payment.php` â€” `POST /payout/requests/{payoutId}/confirm-settlement` route returning 501 with message `'Not implemented â€” blocked pending AD-10 and LC14 resolution'`

**Checkpoint**: US4 stubbed â€” module compiles; confirm-settlement returns 501; reconciliation_exceptions CHECK constraint extended for payout exception types.

---

## Phase 7: Polish & Cross-Cutting Concerns

- [X] T057 [P] Append P18 OpenAPI paths and schemas to `wallet-ecosystem/services/api/openapi/v1/payment.yaml` â€” append after the last existing schema (end of file): all 13 endpoints from `contracts/payout-api.yaml` (beneficiaries, payout requests, reservations, execute stub marked `x-blocked`, confirm-settlement stub marked `x-blocked`); all schemas (`RegisterBeneficiaryRequest`, `BeneficiarySummary`, `PayableBalanceResponse`, `PayoutRequestSummary`, `ReservationSummary`, `PayoutBeneficiaryStatus`, `PayoutRequestStatus`, `PayoutReservationStatus` enums); follow existing YAML style (indent 2 spaces, no tabs)
- [X] T058 [P] Write operator runbook at `wallet-ecosystem/services/api/docs/payment/p18-payout-lifecycle-operator-runbook.md` â€” sections: Overview, Flow 1 (Beneficiary registration and verification), Flow 2 (Payable balance computation), Flow 3 (Reservation and maker/checker approval), Flow 4 (Execution â€” BLOCKED AD-14), Flow 5 (Settlement â€” BLOCKED AD-10), Key Tables (`payout_beneficiaries`, `payout_requests`, `payout_reservations`, `payout_attempts` stub, `payout_settlement_records` stub), Alert conditions, Runbook scenarios (re-verify a beneficiary, release an expired reservation manually, check balance for a fund, escalate AD-14/AD-10 blockers)
- [ ] T059 Run PHPStan level-8 against all P18 files in `app/Modules/Payment/` to confirm 0 errors â€” `@group docker` (Docker-gated)
- [ ] T060 Run quickstart.md validation scenarios SC-001â€“SC-008 against running Docker stack â€” `@group docker` (Docker-gated)
- [X] T061 [P] Write `PayoutConcurrentReservationTest` in `wallet-ecosystem/services/api/tests/Feature/Payment/PayoutConcurrentReservationTest.php` â€” `@group docker`; test: `concurrent_reservations_cannot_together_exceed_payable_balance`; setup: seed a fund with exactly 5,000 minor units available; submit two simultaneous `POST /payout/requests/{id}/reserve` requests each requesting 5,000 (use Guzzle async or parallel processes); assert exactly one response is `201 Created` and the other is `400 Bad Request` with an insufficient-balance error; assert `SUM(reserved_amount_minor) WHERE status IN ('awaiting_approval','approved')` = 5,000 never 10,000; satisfies SC-001 and the SELECT FOR UPDATE concurrency guarantee from FR-006 (Docker-gated)

---

## Dependencies & Execution Order

### Phase Dependencies

- **Phase 1 (Setup)**: No dependencies â€” start immediately
- **Phase 2 (Foundational)**: Depends on Phase 1 â€” BLOCKS all user stories
- **Phase 3 (US1)**: Depends on Phase 2 â€” independent of US2/US3/US4
- **Phase 4 (US2)**: Depends on Phase 3 (needs payout_requests FK and PayoutRequestModel) + Phase 2
- **Phase 5 (US3 stub)**: Depends on Phase 4 (needs payout_requests FK) â€” stub only
- **Phase 6 (US4 stub)**: Depends on Phase 5 (needs payout_attempts FK) â€” stub only
- **Phase 7 (Polish)**: Depends on all prior phases complete; T061 (concurrency feature test) additionally requires Docker stack for real DB-level lock verification

### Within Phase 3 (US1)

- T013, T014 (migrations) â†’ T015, T016 (models) â†’ T017, T018 (interfaces) â†’ T019, T020 (repositories) â†’ T022, T023 (services) â†’ T026, T027 (controllers) â†’ T028 (routes) â†’ T029 (provider)
- T021 (PayableBalance VO) can run in parallel with T013â€“T020

### Within Phase 4 (US2)

- T032 (migration) â†’ T033 (model) â†’ T034, T035 (interface + repo) â†’ T038 (service) â†’ T041 (controller) â†’ T042 (routes) â†’ T043 (provider + scheduler)
- T036, T037, T039, T040 can run in parallel with repository setup

### Parallel Opportunities

Within Phase 2: T002â€“T012 are all [P] â€” run in parallel
Within Phase 3 â€” Models: T013, T014 [P]; T015, T016 [P]; T017, T018 [P]; T021 [P]
Within Phase 3 â€” Resources and tests: T024, T025 [P]; T030, T031 [P]
Within Phase 4 â€” Domain: T036, T037, T039, T040 [P]
Within Phase 4 â€” Tests: T044 [P]
Within Phase 5/6: Most stub tasks [P]
Within Phase 7: T057, T058 [P]

---

## Parallel Example: Phase 3 (US1) Models

```
Parallel run:
  T013: migration 000019 (beneficiaries)
  T014: migration 000020 (requests)
  T021: PayableBalance value object

Then parallel:
  T015: PayoutBeneficiaryModel
  T016: PayoutRequestModel
  T017: PayoutBeneficiaryRepositoryInterface
  T018: PayoutRequestRepositoryInterface

Then sequential:
  T019: EloquentPayoutBeneficiaryRepository (needs T015 + T017)
  T020: EloquentPayoutRequestRepository (needs T016 + T018)
  T022: PayoutBeneficiaryService (needs T019)
  T023: PayoutBalanceService (needs T020 + T021)
```

---

## Implementation Strategy

### MVP (US1 only â€” Phase 3)

1. Complete Phase 1 (Setup â€” 1 task)
2. Complete Phase 2 (Foundational â€” 11 tasks)
3. Complete Phase 3 (US1 â€” 19 tasks)
4. **STOP and VALIDATE**: Run SC-001, SC-002, SC-003 from quickstart.md
5. US1 is independently testable and demonstrable

### Full Unblocked Delivery (US1 + US2)

1. Add Phase 4 (US2 â€” 13 tasks) after MVP validates
2. Run SC-004 through SC-007 from quickstart.md
3. US1 + US2 together form the complete reservation lifecycle

### Stub Scaffolding (US3 + US4 â€” run independently)

4. Add Phase 5 (US3 stubs â€” 7 tasks) â€” safe to run in parallel with US2 after Phase 2
5. Add Phase 6 (US4 stubs â€” 5 tasks) â€” depends on Phase 5 stubs for migration FK
6. Stubs do not add business value; they prevent compile errors and document BLOCKED status

### Blocker Resolution Path

- Once **AD-14** is resolved by architect: Replace T049 stub with real `PayoutExecutionService` + `PayoutAttemptQueryJob` + real `PayoutAdapterInterface` method signatures + real bank adapter implementation
- Once **AD-10 + LC14** are resolved by Finance Lead: Replace T055 stub with real `PayoutSettlementService` that calls `LedgerModuleContract::postEntry()` with confirmed debit/credit account references

---

## Notes

- [P] tasks write to different files and have no dependencies on incomplete tasks in the same phase
- US1 + US2 use no stub logic; all business rules are fully implemented
- Stub tasks (US3, US4) throw `\LogicException` to surface clearly at test time rather than silently failing
- `account_number` is **never** included in API responses â€” enforced by omitting it from `PayoutBeneficiaryResource`
- All money computations use integer minor units; no floating-point arithmetic
- `PayoutReservationExpiryJob` only expires `awaiting_approval` reservations â€” approved reservations require explicit admin cancellation
