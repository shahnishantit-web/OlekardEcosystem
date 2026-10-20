# Tasks: E10 â€” Withdrawal / Cash-Out

**Feature**: `010-withdrawal-cash-out` | **Epic**: E10
**Input**: `specs/010-withdrawal-cash-out/`
**Prerequisites**: plan.md âœ“ | spec.md âœ“ | data-model.md âœ“ | contracts/api.md âœ“ | research.md âœ“ | quickstart.md âœ“

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies on incomplete tasks)
- **[Story]**: US1â€“US12 mapped to US-WITHDRAW-001 through US-WITHDRAW-012
- All paths relative to `wallet-ecosystem/services/api/`

---

## Phase 1: Setup (Module Skeleton & Infrastructure)

**Purpose**: Create the Withdrawal module directory structure, register the service provider, configure routing, and write all schema/table migrations.

- [X] T001 Create app/Modules/Withdrawal/ directory tree per plan.md (Application/Contracts, Application/Jobs, Application/Services, Console/Commands, Domain/Contracts, Domain/Enums, Domain/Events, Domain/Exceptions, Infrastructure/Adapters, Infrastructure/Http/Controllers, Infrastructure/Http/Requests, Infrastructure/Http/Resources, Infrastructure/Migrations, Infrastructure/Models, Infrastructure/Stubs, Providers)
- [X] T002 Create app/Modules/Withdrawal/Providers/WithdrawalServiceProvider.php shell (extends ServiceProvider; empty register() and boot() stubs; $commands = [] placeholder)
- [X] T003 Register WithdrawalServiceProvider::class in bootstrap/app.php providers array
- [X] T004 Create routes/modules/withdrawal.php with route group skeleton (prefix /api/v1, middleware auth:sanctum â€” actual routes populated per phase)
- [X] T005 [P] Add 'withdrawal' queue to config/horizon.php balancing and environments sections (matching pattern of 'transfer' queue from E09)
- [X] T006 Create app/Modules/Withdrawal/Infrastructure/Migrations/2026_09_21_001000_create_withdrawal_schema.php (CREATE SCHEMA IF NOT EXISTS withdrawal; grant usage to platform DB user)
- [X] T007 Create app/Modules/Withdrawal/Infrastructure/Migrations/2026_09_21_001100_create_withdrawal_transactions_table.php (all columns per data-model.md Â§2.1 including wallet_hold_id UUID NULLABLE; net_payout_amount CHECK constraint; status CHECK constraint; all indexes from data-model.md Â§2.1; $connection = 'platform')
- [X] T008 [P] Create app/Modules/Withdrawal/Infrastructure/Migrations/2026_09_21_001200_create_withdrawal_provider_callbacks_table.php (columns per data-model.md Â§2.2; indexes on withdrawal_transaction_id, provider_reference_id, received_at DESC)
- [X] T009 [P] Create app/Modules/Withdrawal/Infrastructure/Migrations/2026_09_21_001300_create_withdrawal_receipts_table.php (columns per data-model.md Â§2.3; UNIQUE on withdrawal_transaction_id; UNIQUE on receipt_reference; index on customer_id)
- [X] T010 Create app/Modules/Withdrawal/Infrastructure/Migrations/2026_09_21_001400_seed_withdrawal_permissions.php (insert withdrawal.initiate, withdrawal.read, withdrawal.admin into platform.permissions)
- [X] T011 Create app/Modules/Withdrawal/Infrastructure/Migrations/2026_09_21_001500_seed_withdrawal_configuration_stubs.php (insert all config keys from data-model.md Â§6 with safe defaults: fee rates = 0, limits = permissive 100 min / 1000000 max / 5000000 daily, hold_ttl_minutes = 60, channel currencies = BWP, ledger transit/fee account placeholder references)

**Checkpoint**: Module skeleton created; 6 migrations registered; Horizon queue wired.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Domain primitives, models, contracts, and cross-module extensions required by ALL user story phases. No story work begins until this phase is complete.

**âš ï¸ CRITICAL**: Complete every task in this phase before starting Phase 3+.

### Domain Enums

- [X] T012 [P] Create app/Modules/Withdrawal/Domain/Enums/WithdrawalStatus.php (Pending = 'pending', Processing = 'processing', Completed = 'completed', Failed = 'failed', Cancelled = 'cancelled' â€” backed string enum)
- [X] T013 [P] Create app/Modules/Withdrawal/Domain/Enums/WithdrawalChannel.php (MobileMoney = 'mobile_money', BankTransfer = 'bank_transfer')
- [X] T014 [P] Create app/Modules/Withdrawal/Domain/Enums/WithdrawalFailureReason.php (ProviderDeclined, ProcessingTimeout, JobDispatchFailed, AmountMismatch, RiskRejected, AdminForceFailed, SystemError)

### Domain Exceptions

- [X] T015 [P] Create app/Modules/Withdrawal/Domain/Exceptions/WithdrawalNotFoundException.php
- [X] T016 [P] Create app/Modules/Withdrawal/Domain/Exceptions/WithdrawalNotModifiableException.php (maps to HTTP 422 TRANSACTION_NOT_MODIFIABLE)
- [X] T017 [P] Create app/Modules/Withdrawal/Domain/Exceptions/WithdrawalNotCancellableException.php (maps to HTTP 422 TRANSACTION_NOT_CANCELLABLE)
- [X] T018 [P] Create app/Modules/Withdrawal/Domain/Exceptions/WithdrawalReceiptImmutableException.php
- [X] T019 [P] Create app/Modules/Withdrawal/Domain/Exceptions/WithdrawalRiskRejectedException.php
- [X] T020 [P] Create app/Modules/Withdrawal/Domain/Exceptions/FeeMismatchException.php (carries int $updatedFeeAmount and int $updatedNetPayoutAmount properties for 409 FEE_CHANGED response body)
- [X] T021 [P] Create app/Modules/Withdrawal/Domain/Exceptions/WithdrawalAlreadyTerminalException.php (maps to HTTP 422 WITHDRAWAL_ALREADY_TERMINAL)

### Domain Contracts

- [X] T022 [P] Create app/Modules/Withdrawal/Domain/Contracts/PayoutProviderAdapter.php interface (initiate(array $transaction): array; verifyCallback(array $payload, string $signature, string $provider): array â€” per data-model.md Â§8)

### Domain Events

- [X] T023 [P] Create app/Modules/Withdrawal/Domain/Events/WithdrawalInitiated.php (public readonly props: string $withdrawalTransactionId, string $walletId, string $currencyCode, int $grossAmount, string $channel, string $occurredAt, string $correlationId â€” no payout_destination PII per FR-E10-042)
- [X] T024 [P] Create app/Modules/Withdrawal/Domain/Events/WithdrawalProcessing.php (core fields + string $walletHoldId)
- [X] T025 [P] Create app/Modules/Withdrawal/Domain/Events/WithdrawalCompleted.php (core fields + string $ledgerJournalId, int $netPayoutAmount, int $feeAmount)
- [X] T026 [P] Create app/Modules/Withdrawal/Domain/Events/WithdrawalFailed.php (core fields + string $failureReason)
- [X] T027 [P] Create app/Modules/Withdrawal/Domain/Events/WithdrawalCancelled.php (core fields)
- [X] T028 [P] Create app/Modules/Withdrawal/Domain/Events/WithdrawalExpired.php (core fields)
- [X] T029 [P] Create app/Modules/Withdrawal/Domain/Events/WithdrawalAmountMismatch.php (core fields + int $expectedAmount, int $callbackAmount)
- [X] T030 [P] Create app/Modules/Withdrawal/Domain/Events/WithdrawalRiskRejected.php (core fields + string $rejectionReason)
- [X] T031 [P] Create app/Modules/Withdrawal/Domain/Events/WithdrawalLateCallback.php (core fields + string $providerReferenceId)

### Risk Service

- [X] T032 [P] Create app/Modules/Withdrawal/Application/Contracts/WithdrawalRiskResult.php value object (static approved(): self; static rejected(string $reason): self; isApproved(): bool; getReason(): ?string â€” do NOT reuse Transfer module's RiskResult; per research.md Decision 3)
- [X] T033 [P] Create app/Modules/Withdrawal/Application/Contracts/WithdrawalRiskService.php interface (evaluate(array $context): WithdrawalRiskResult â€” context keys: customer_id, wallet_id, channel, gross_amount, currency_code, correlation_id; no payout_destination PII)
- [X] T034 [P] Create app/Modules/Withdrawal/Infrastructure/Stubs/WithdrawalRiskServiceStub.php (implements WithdrawalRiskService; always returns WithdrawalRiskResult::approved())

### Metrics Service

- [X] T035 [P] Create app/Modules/Withdrawal/Application/Services/WithdrawalMetricsService.php (9 public methods per FR-E10-044: recordInitiated(string $channel), recordCompleted(string $channel), recordFailed(string $channel, string $reason), recordCancelled(string $channel), recordExpired(), recordCallbackDuration(string $provider, string $outcome, float $seconds), recordHoldCreated(string $channel), recordHoldReleased(string $reason), recordRiskCheck(string $result) â€” each wraps MetricsCollectorInterface::increment()/histogram() in try-catch so emission failure never propagates per FR-E10-045)

### Eloquent Models

- [X] T036 Create app/Modules/Withdrawal/Infrastructure/Models/WithdrawalTransactionModel.php ($connection='platform'; $table='withdrawal.withdrawal_transactions'; $casts: 'payout_destination' => 'encrypted:array', 'status' => WithdrawalStatus::class, 'channel' => WithdrawalChannel::class; $fillable includes all writable columns; helper methods: isCancellable(): bool (status===Pending), isTerminal(): bool (Completed|Failed|Cancelled), scopeForCustomer(string $customerId), scopeProcessingAndStale(int $ttlMinutes))
- [X] T037 [P] Create app/Modules/Withdrawal/Infrastructure/Models/WithdrawalProviderCallbackModel.php ($connection='platform'; $table='withdrawal.withdrawal_provider_callbacks'; $fillable; $casts: 'raw_payload' => 'array')
- [X] T038 Create app/Modules/Withdrawal/Infrastructure/Models/WithdrawalReceiptModel.php ($connection='platform'; $table='withdrawal.withdrawal_receipts'; $fillable; override performUpdate(): never and performDeleteOnModel(): never to throw WithdrawalReceiptImmutableException when $this->exists â€” enforces immutability after initial insert per FR-E10-040)

### WalletModuleContract Extension

- [X] T039 Add createHold(string $walletId, string $currencyCode, int $amount, string $actorId, string $correlationId, string $transactionReference, ?\DateTimeImmutable $expiresAt = null): string and releaseHold(string $holdId, string $correlationId): void signatures to app/Modules/Wallet/Domain/Contracts/WalletModuleContract.php (per research.md Decision 1 and data-model.md Â§7)
- [X] T040 Implement createHold() and releaseHold() in app/Modules/Wallet/Infrastructure/Adapters/WalletModuleContractAdapter.php (createHold: delegate to WalletHoldService::place() â€” returns UUID v7 hold id; releaseHold: delegate to WalletHoldService::release() â€” idempotent no-op if already released)

### Mock Provider Adapter

- [X] T041 [P] Create app/Modules/Withdrawal/Infrastructure/Adapters/MockPayoutProviderAdapter.php (implements PayoutProviderAdapter; initiate() returns ['status'=>'initiated', 'provider_reference_id'=>'mock-payout-'.$transaction['id'], 'raw_response'=>[]]; verifyCallback() returns valid=true when $signature==='mock-valid-sig', valid=false otherwise; status from $payload['status'])

### Service Provider Bindings

- [X] T042 Fill app/Modules/Withdrawal/Providers/WithdrawalServiceProvider.php IoC bindings (bind PayoutProviderAdapter::class â†’ MockPayoutProviderAdapter::class; bind WithdrawalRiskService::class â†’ WithdrawalRiskServiceStub::class; register Application/Services as singletons; load routes/modules/withdrawal.php in boot(); register ExpireStaleWithdrawalsCommand in commands())

**Checkpoint**: All domain primitives, models, and contracts in place. WalletModuleContract extended. User story phases may now begin.

---

## Phase 3: US1, US2, US3, US4, US5 â€” Initiation, Channel & Amount Validation, Limits & Fee

**Goal**: Customer can call `POST /api/v1/withdrawals`; system validates wallet, channel, amount, limits, and fee; `WithdrawalTransaction` created in `pending` status with fee breakdown and limits in response.

**Independent Test**: `POST /api/v1/withdrawals` with valid inputs â†’ 201 `status=pending`, `fee_amount`, `net_payout_amount`, `limits`; same `Idempotency-Key` â†’ 200 with original; missing key â†’ 400.

### Implementation

- [X] T043 [US5] Create app/Modules/Withdrawal/Application/Services/WithdrawalFeeService.php (calculate(int $grossAmount, WithdrawalChannel $channel, string $currencyCode): array â€” reads rate_bps from ConfigurationService key 'withdrawal.fee.{channel}.{currency}'; fee = round(gross Ã— rate / 10000); defaults rate to 0 if key absent; returns ['fee_amount', 'net_payout_amount', 'fee_rate_bps'])
- [X] T044 [US4] Create app/Modules/Withdrawal/Application/Services/WithdrawalLimitService.php (validate(int $grossAmount, WithdrawalChannel $channel, string $currencyCode, string $customerId, ?string $excludeTransactionId = null): void â€” reads min/max/daily_max from ConfigurationService keyed by channel + KYC tier via KycModuleContract; daily sum queries status IN (pending, processing, completed) for current UTC day excluding $excludeTransactionId; throws typed exceptions per violation)
- [X] T045 [US1] Create app/Modules/Withdrawal/Application/Services/WithdrawalInitiationService.php (orchestrate per FR-E10-005 through FR-E10-013: idempotency check via IdempotencyService; wallet ownership + pocket existence via WalletModuleContract; channel availability from config; payout_destination schema validation per channel; availableBalance â‰¥ grossAmount check via WalletModuleContract; WithdrawalFeeService::calculate(); [CC-E10-002] wrap inside DB::transaction(): WithdrawalLimitService::validate() + INSERT WithdrawalTransactionModel with UUID v7 id + transaction_reference + encrypted payout_destination + IdempotencyService::set() + OutboxEventPublisher::publish(new WithdrawalInitiated()) + AuditService::record('withdrawal.transaction.initiated') â€” limit check and INSERT MUST be atomic to prevent daily-limit race; return model)
- [X] T046 [US1] Create app/Modules/Withdrawal/Infrastructure/Http/Requests/WithdrawalInitiateRequest.php (rules: wallet_id uuid required; currency_code alpha:3 required; channel in:mobile_money,bank_transfer required; gross_amount integer min:1 required; payout_destination array required; custom rule for required Idempotency-Key header)
- [X] T047 [P] [US1] Create app/Modules/Withdrawal/Infrastructure/Http/Resources/WithdrawalTransactionResource.php (all public fields per contracts/api.md Â§WithdrawalTransaction Object; payout_destination EXCLUDED from all contexts; payout_destination_masked INCLUDED; optional nested 'limits' key included in initiation response)
- [X] T048 [US1] Create app/Modules/Withdrawal/Infrastructure/Http/Controllers/WithdrawalController.php (initiate(): validates WithdrawalInitiateRequest, calls WithdrawalInitiationService, returns 201 WithdrawalTransactionResource; index(): paginated list scoped by authenticated customer_id; show(): scope by customer_id â€” 404 if not owned; thin controller â€” all logic in services)
- [X] T049 [US1] Register POST /api/v1/withdrawals, GET /api/v1/withdrawals, GET /api/v1/withdrawals/{id} in routes/modules/withdrawal.php

### Tests

- [X] T050 [P] [US1] Write tests/Feature/Modules/Withdrawal/WithdrawalInitiationTest.php (happy path mobile_money 201; happy path bank_transfer 201; idempotency replay 200 with original; missing Idempotency-Key 400; wallet not found 404; pocket not found for currency 422; invalid channel 422 UNSUPPORTED_CHANNEL; channel unavailable for currency 422 CHANNEL_UNAVAILABLE; KYC insufficient 422; zero amount 422 INVALID_AMOUNT; amount below min 422; amount above max 422; daily limit exceeded 422; insufficient balance 422; invalid payout_destination missing phone 422; unauthenticated 401; missing withdrawal.initiate permission 403; payout_destination absent from response)
- [X] T051 [P] [US5] Write tests/Unit/Modules/Withdrawal/WithdrawalFeeServiceTest.php (rate=0 â†’ fee=0, net=gross; rate=50bps, amount=100000 â†’ fee=500; rounding of fractional minor units; missing config key defaults to 0; net_payout_amount = gross - fee_amount)
- [X] T052 [P] [US4] Write tests/Unit/Modules/Withdrawal/WithdrawalLimitServiceTest.php (amount below min throws; amount above max throws; daily sum includes pending; daily sum includes processing; daily sum includes completed; daily sum excludes cancelled; daily limit exceeded throws with remaining; within limits passes; excludeTransactionId excludes own record at confirmation re-check)

**Checkpoint**: Initiation flow fully functional and independently testable. `POST /api/v1/withdrawals` working.

---

## Phase 4: US6, US7, US8, US10 (cancel) â€” Confirmation, Risk Check, Reserve Funds, Dispatch Payout, Cancellation

**Goal**: Customer confirms with PIN; risk evaluation passes; hold is created atomically; payout dispatched asynchronously. Customer can cancel a pending transaction.

**Independent Test**: `POST /api/v1/withdrawals/{id}/confirm` with valid PIN â†’ 200 `status=processing`, `wallet_hold_id` set; row in `wallet.wallet_holds` with `status=active`. Wrong PIN â†’ 422 stays `pending`. Cancel pending â†’ 200 `status=cancelled`.

### Implementation

- [X] T053 [US8] Create app/Modules/Withdrawal/Application/Jobs/WithdrawalPayoutJob.php (implements ShouldQueue; public string $queue = 'withdrawal'; public int $tries = 3; public int $timeout = 30; handle(): resolve PayoutProviderAdapter from container; call initiate($transactionArray â€” payout_destination decrypted from model); store provider_reference_id on transaction; failed(): on all retries exhausted: DB transaction â€” WalletModuleContract::releaseHold($holdId), transition to failed failure_reason=job_dispatch_failed, set failed_at, OutboxEventPublisher::publish(WithdrawalFailed), AuditService::record('withdrawal.transaction.failed'))
- [X] T054 [US7] Create app/Modules/Withdrawal/Application/Services/WithdrawalConfirmationService.php (full confirmation sequence per FR-E10-019: 1. ownership check â†’ WithdrawalNotFoundException; 2. status=pending â†’ WithdrawalNotModifiableException; 3. fee re-validate: recalculate and compare to quoted_fee_amount â†’ FeeMismatchException on mismatch; 4. daily limit re-check (excludeTransactionId=this tx); 5. PIN via IdentityModuleContract::verifyTransactionPin() â†’ on wrong PIN: AuditService::record('withdrawal.pin_failed'), throw InvalidPinException â€” NO status change; 6. step-up check: if amount >= step_up_threshold config and no step-up session assurance â†’ throw StepUpRequiredException; 7. WithdrawalRiskService::evaluate() â†’ rejected: transition to cancelled + set failed_at + emit WithdrawalRiskRejected + audit â†’ throw WithdrawalRiskRejectedException; 8. Call WalletModuleContract::createHold(walletId, currency, grossAmount, actorId, correlationId, transactionReference, expiresAt=now()+hold_ttl) â€” the contract implementation handles SELECT FOR UPDATE + balance check + hold creation atomically inside WalletHoldService; catch InsufficientBalanceException (â†’ 422 INSUFFICIENT_BALANCE) and WalletNotTransactableException (â†’ 422 WALLET_SUSPENDED) â€” do NOT add a separate SELECT FOR UPDATE in ConfirmationService; on success store wallet_hold_id; 9. dispatch WithdrawalPayoutJob::dispatch($tx); 10. transition to processing + emit WithdrawalProcessing + AuditService::record('withdrawal.transaction.confirmed'))
- [X] T055 [US7] Create app/Modules/Withdrawal/Infrastructure/Http/Requests/WithdrawalConfirmRequest.php (rules: transaction_pin string required; quoted_fee_amount integer min:0 required; Idempotency-Key header required)
- [X] T056 [US8] Add confirm() action to app/Modules/Withdrawal/Infrastructure/Http/Controllers/WithdrawalController.php (delegates to WithdrawalConfirmationService; map exceptions: FeeMismatchException â†’ 409 FEE_CHANGED with updated_fee_amount + updated_net_payout_amount; WithdrawalRiskRejectedException â†’ 422 WITHDRAWAL_RISK_REJECTED; InvalidPinException â†’ 422 INVALID_PIN; StepUpRequiredException â†’ 403 STEP_UP_REQUIRED; InsufficientBalanceException â†’ 422 INSUFFICIENT_BALANCE; WalletNotTransactableException â†’ 422 WALLET_SUSPENDED per EC-E10-05)
- [X] T057 [US10] Create app/Modules/Withdrawal/Application/Services/WithdrawalCancellationService.php (ownership check; status must be pending â†’ WithdrawalNotCancellableException else; transition to cancelled; set failed_at; OutboxEventPublisher::publish(WithdrawalCancelled); AuditService::record('withdrawal.transaction.cancelled'); no hold exists at pending stage)
- [X] T058 [US10] Add cancel() action to app/Modules/Withdrawal/Infrastructure/Http/Controllers/WithdrawalController.php (delegates to WithdrawalCancellationService; maps WithdrawalNotCancellableException â†’ 422 TRANSACTION_NOT_CANCELLABLE)
- [X] T059 [US8] Register POST /api/v1/withdrawals/{id}/confirm and POST /api/v1/withdrawals/{id}/cancel in routes/modules/withdrawal.php

### Tests

- [X] T060 [US7] Write tests/Feature/Modules/Withdrawal/WithdrawalConfirmationTest.php (happy path â†’ processing + hold active; wrong PIN â†’ 422 INVALID_PIN stays pending; PIN value absent from all audit events; fee changed â†’ 409 FEE_CHANGED with updated amounts, stays pending; insufficient balance race condition â†’ 422 no hold; risk rejected â†’ 422 status=cancelled; step-up required â†’ 403 stays pending; idempotent replay of already-processing confirmation â†’ 200 current state; cancel pending â†’ 200 cancelled; cancel processing â†’ 422 TRANSACTION_NOT_CANCELLABLE; cancel another customer's tx â†’ 404; SC-E10-006, SC-E10-007)
- [X] T061 [US7] Write tests/Feature/Modules/Withdrawal/WithdrawalConcurrencyTest.php CC-E10-001 + CC-E10-002 (CC-E10-001: two simultaneous confirmations with balance for one â†’ exactly one creates hold + processing, other returns 422; exactly one wallet.wallet_holds row active; CC-E10-002: two simultaneous initiations that would breach daily limit â†’ only one succeeds; SC-E10-005)

**Checkpoint**: Full initiation + confirmation + cancellation flow tested. Hold created on confirmation; payout dispatched.

---

## Phase 5: US9, US10, US11 â€” Provider Callbacks, Failed Payout, Release Funds, Expiry

**Goal**: Provider success/failure callbacks correctly complete or fail the withdrawal; hold released atomically; balance restored on failure; stale transactions expired by command.

**Independent Test**: Success callback â†’ `completed` + 2-line ledger debit + receipt created. Failure callback â†’ `failed` + balance restored + no receipt. Duplicate success callback â†’ 200 idempotent, no second debit.

### Implementation

- [X] T062 [US12] Create app/Modules/Withdrawal/Application/Services/WithdrawalReceiptService.php (createReceipt(WithdrawalTransactionModel $tx, string $journalId, string $providerReferenceId): WithdrawalReceiptModel â€” generate UUID v7 receipt_reference; mask payout_destination_masked from decrypted $tx->payout_destination (last 4 chars of phone or account); INSERT new WithdrawalReceiptModel; must be called inside WithdrawalCallbackService DB transaction for atomicity)
- [X] T063 [US9] Create app/Modules/Withdrawal/Application/Services/WithdrawalCallbackService.php (processCallback(string $provider, array $rawPayload, string $signature): void â€” 1. resolve adapter by provider key; 2. call verifyCallback(): invalid â†’ persist callback signature_valid=false, throw InvalidCallbackSignatureException; 3. INSERT WithdrawalProviderCallbackModel raw; 4. idempotency: if provider_reference_id already processed and tx completed â†’ emit WithdrawalLateCallback, return; 5. resolve WithdrawalTransactionModel by provider_reference_id; 6. processSuccess() or processFailure() based on callback status; processSuccess(): verify amounts match â†’ mismatch: DB tx releaseHold + failed + emit AmountMismatch; match: DB tx: LedgerModuleContract::postEntry(idempotency_key='withdrawal_complete:{tx.id}', 2-line fee=0 or 3-line fee>0 per FR-E10-029) â†’ WalletModuleContract::releaseHold(wallet_hold_id) â†’ transition completed + set completed_at + ledger_journal_id â†’ WithdrawalReceiptService::createReceipt() â†’ OutboxEventPublisher::publish(WithdrawalCompleted) â†’ AuditService::record('withdrawal.transaction.completed'); processFailure(): DB tx: releaseHold + transition failed + set failed_at + failure_reason â†’ OutboxEventPublisher::publish(WithdrawalFailed) â†’ audit; mark callback processed_at)
- [X] T064 [US9] Create app/Modules/Withdrawal/Infrastructure/Http/Controllers/WithdrawalCallbackController.php (receive(string $provider, Request $request): calls WithdrawalCallbackService::processCallback(); returns 200 {status:acknowledged} on valid signature; returns 400 INVALID_CALLBACK_SIGNATURE on invalid; no auth:sanctum middleware; no Idempotency-Key required)
- [X] T065 [US9] Register POST /api/v1/withdrawals/callbacks/{provider} in routes/modules/withdrawal.php outside the auth:sanctum middleware group
- [X] T066 [US11] Create app/Modules/Withdrawal/Application/Services/WithdrawalExpiryService.php (expireStale(bool $dryRun = false): int â€” query WHERE status=processing AND created_at < NOW() - hold_ttl_minutes; for each (individual DB transaction per record): SELECT FOR UPDATE; skip if hold already released (idempotent); WalletModuleContract::releaseHold(); transition failed failure_reason=processing_timeout; set failed_at; OutboxEventPublisher::publish(WithdrawalExpired); AuditService::record('withdrawal.transaction.expired'); if dryRun: rollback, still count; individual tx failures do not block remaining; return count)
- [X] T067 [US11] Create app/Modules/Withdrawal/Console/Commands/ExpireStaleWithdrawalsCommand.php (Artisan signature: 'withdrawal:expire-stale'; --dry-run option; delegates to WithdrawalExpiryService::expireStale($dryRun); outputs count; exit 0; idempotent)
- [X] T068 [US11] Register ExpireStaleWithdrawalsCommand in WithdrawalServiceProvider $commands and schedule() (everyFiveMinutes() default per FR-E10-037)

### Tests

- [X] T069 [US9] Write tests/Feature/Modules/Withdrawal/WithdrawalCallbackTest.php (success callback â†’ completed; fee=0: 2-line journal; fee>0: 3-line journal; failure callback â†’ failed + balance restored + no receipt; invalid signature â†’ 400 no state change; duplicate success callback â†’ 200 idempotent; late callback on already-completed â†’ LateCallback event no re-debit; amount mismatch â†’ AmountMismatch event + failed + hold released; [FR-E10-030] postEntry() throws on success callback â†’ DB rolls back â†’ transaction remains processing + hold remains active â†’ retry succeeds on subsequent callback (no double-debit, no partial state); SC-E10-003, SC-E10-011)
- [X] T070 [US9] Write tests/Feature/Modules/Withdrawal/WithdrawalJournalBalanceTest.php (2-line journal: SUM(debits) = SUM(credits) = gross; 3-line journal: SUM balanced; no journal on failure callback; duplicate success callback: exactly one journal with idempotency_key='withdrawal_complete:{tx_id}'; LedgerModuleContract::getBalance() reflects debit after completion; SC-E10-001, SC-E10-002)
- [X] T071 [US11] Write tests/Feature/Modules/Withdrawal/ExpireStaleWithdrawalsTest.php (stale processing â†’ failed; hold released; Expired event emitted; balance restored; command idempotent on re-run; --dry-run reports count without changes; SC-E10-008; CC-E10-006: two concurrent expiry runs â†’ only one hold release)
- [X] T072 [US11] Write tests/Feature/Modules/Withdrawal/WithdrawalConcurrencyTest.php CC-E10-003 + CC-E10-004 (CC-E10-003: duplicate concurrent success callbacks â†’ one processes, one idempotent no-op, exactly one ledger debit; CC-E10-004: failure callback then expiry command â†’ second is no-op, no double balance restore)

**Checkpoint**: Full payment lifecycle complete. All state transitions tested end-to-end.

---

## Phase 6: US12 â€” Withdrawal Receipt (HTTP Query Layer)

**Goal**: Completed withdrawal receipt is queryable by transaction ID and by receipt reference. Receipt is immutable after creation.

**Independent Test**: After success callback, `GET /api/v1/withdrawals/{id}/receipt` â†’ 200 all fields no PII. `GET /api/v1/withdrawals/receipts/{ref}` â†’ same. No receipt for failed â†’ 404.

### Implementation

- [X] T073 [P] [US12] Create app/Modules/Withdrawal/Infrastructure/Http/Resources/WithdrawalReceiptResource.php (all fields per contracts/api.md Â§WithdrawalReceipt Object; no payout_destination; payout_destination_masked exposed)
- [X] T074 [US12] Create app/Modules/Withdrawal/Infrastructure/Http/Controllers/WithdrawalReceiptController.php (show(string $id): resolve tx by id scoped to customer_id â†’ find receipt or 404 RECEIPT_NOT_FOUND; showByReference(string $reference): resolve receipt by receipt_reference scoped to customer_id or 404)
- [X] T075 [US12] Register GET /api/v1/withdrawals/receipts/{receiptReference} (MUST be registered BEFORE dynamic /{id} route to prevent routing conflict) and GET /api/v1/withdrawals/{id}/receipt in routes/modules/withdrawal.php

### Tests

- [X] T076 [P] [US12] Write tests/Feature/Modules/Withdrawal/WithdrawalReceiptTest.php (receipt returned after completion with all fields; receipt by transaction ID; receipt by receipt_reference; no receipt for failed tx â†’ 404 RECEIPT_NOT_FOUND; no receipt for pending tx â†’ 404; IDOR: customer cannot fetch other customer's receipt â†’ 404; SC-E10-012)
- [X] T077 [P] [US12] Write tests/Unit/Modules/Withdrawal/WithdrawalReceiptImmutabilityTest.php (performUpdate() on existing model throws WithdrawalReceiptImmutableException; performDeleteOnModel() throws; initial insert on new model succeeds; SC-E10-012, FR-E10-040)

**Checkpoint**: Receipt flow complete. Receipts immutable, queryable by two reference types, IDOR-safe.

---

## Phase 7: Admin, Metrics, Audit, PHPStan & Documentation

**Purpose**: Admin endpoints, observability wiring, audit trail verification, static analysis, and OpenAPI documentation.

### Admin Endpoints

- [X] T078 Create app/Modules/Withdrawal/Infrastructure/Http/Controllers/AdminWithdrawalController.php (index(): list all withdrawals no ownership filter, filters: status, channel, customer_id, from_date, to_date, page, per_page; show(): get any by ID â†’ 404 if not found; forceFail(): status must be processing â†’ WithdrawalAlreadyTerminalException on completed/failed/cancelled; WalletModuleContract::releaseHold(wallet_hold_id); transition to failed failure_reason=admin_force_failed; set failed_at; OutboxEventPublisher::publish(WithdrawalFailed); AuditService::record('withdrawal.admin.force_failed', actor=admin); return 200)
- [X] T079 Register GET /api/v1/admin/withdrawals, GET /api/v1/admin/withdrawals/{id}, POST /api/v1/admin/withdrawals/{id}/force-fail in routes/modules/withdrawal.php behind can:withdrawal.admin middleware

### Metrics Wiring

- [X] T080 Inject WithdrawalMetricsService into WithdrawalInitiationService; call recordInitiated(channel) after successful DB commit (AFTER idempotency store write, outside DB transaction per FR-E10-045)
- [X] T081 Inject WithdrawalMetricsService into WithdrawalConfirmationService; call recordHoldCreated(channel) after createHold(); call recordRiskCheck('approved'|'rejected') after risk evaluation
- [X] T082 Inject WithdrawalMetricsService into WithdrawalCallbackService; call recordCompleted(channel) + recordCallbackDuration(provider, 'success', $elapsed) on success; recordFailed(channel, reason) + recordHoldReleased('failure') + recordCallbackDuration on failure
- [X] T083 Inject WithdrawalMetricsService into WithdrawalCancellationService; call recordCancelled(channel) after transition
- [X] T084 Inject WithdrawalMetricsService into WithdrawalExpiryService; call recordExpired() + recordHoldReleased('expiry') per expired record after individual DB transaction commits

### Tests

- [X] T085 Write tests/Feature/Modules/Withdrawal/AdminWithdrawalTest.php (admin lists all â€” not restricted to own customer; admin shows any tx by ID; force-fail processing â†’ 200 failed + hold released + audit admin.force_failed; force-fail completed â†’ 422 WITHDRAWAL_ALREADY_TERMINAL; force-fail failed â†’ 422; customer GET other customer's tx â†’ 404 not 403; customer cannot call admin endpoints â†’ 403)
- [X] T086 Write tests/Feature/Modules/Withdrawal/WithdrawalAuditTrailTest.php (all 9 audit actions from FR-E10-043 recorded: initiated, confirmed, completed, failed, cancelled, expired, pin_failed, risk.rejected, admin.force_failed; PIN value NOT present in any audit event payload for confirmed or pin_failed events; SC-E10-010)
- [X] T087 Write tests/Unit/Modules/Withdrawal/WithdrawalMetricsServiceTest.php (MetricsCollectorInterface throw does NOT propagate from any emit method; all 9 methods call interface with correct metric name and labels per FR-E10-044; FR-E10-045)

### Static Analysis & Documentation

- [X] T088 Run PHPStan level 8 over app/Modules/Withdrawal/ (php vendor/bin/phpstan analyse app/Modules/Withdrawal --level=8) and resolve all type errors
- [X] T089 Update OpenAPI documentation (docs/openapi.yaml or equivalent) for all 11 E10 endpoints per contracts/api.md (request/response bodies, error codes, auth, Idempotency-Key header)

### Final Validation

- [X] T090 Run php artisan test --filter=Withdrawal and verify all feature + unit tests pass
- [X] T091 Run php artisan test --filter=ExpireStale and verify expiry command tests pass
- [X] T092 Run php artisan test --filter=WithdrawalJournal and verify journal balance tests pass
- [X] T093 Execute quickstart.md Scenarios 1â€“11 against local Docker stack and verify each expected outcome
- [X] T094 Review spec.md Â§19 Epic DoD checklist and confirm all 21 items are satisfied

**Checkpoint**: All 94 tasks complete. E10 RELEASE READY.

---

## Dependencies & Execution Order

### Phase Dependencies

- **Phase 1 (Setup)**: No dependencies â€” start immediately
- **Phase 2 (Foundational)**: Depends on Phase 1 complete
- **Phases 3â€“6 (User Stories)**: All depend on Phase 2; must execute in order (3 â†’ 4 â†’ 5 â†’ 6) due to service call chains
- **Phase 7 (Polish)**: Depends on Phases 3â€“6 complete

### Critical Cross-Phase Task Dependencies

| Task | Must Complete Before |
|------|---------------------|
| T039-T040 (WalletModuleContract extension) | T054 (ConfirmationService calls createHold/releaseHold) |
| T036 (WithdrawalTransactionModel) | T045 (InitiationService creates records) |
| T038 (WithdrawalReceiptModel) | T062 (WithdrawalReceiptService inserts receipts) |
| T062 (WithdrawalReceiptService) | T063 (CallbackService calls createReceipt inside same DB tx) |
| T043-T044 (FeeService, LimitService) | T054 (ConfirmationService re-validates fee + limit) |
| T075 (static /receipts/{ref} route registration) | Routing works â€” static must precede dynamic /{id} |

### Within-Phase Parallel Groups

**Phase 2 Parallel Groups**:
```text
Group A (all [P], independent files):
  Enums:    T012, T013, T014
  Exceptions: T015, T016, T017, T018, T019, T020, T021
  Contract: T022
  Events:   T023-T031
  Risk:     T032, T033, T034
  Metrics:  T035
  Mock Adapter: T041

Group B (depends on Group A enums):
  Models:   T036, T037, T038

Group C (sequential):
  T039 â†’ T040 (interface then implementation)
```

**Phase 3 Parallel**:
```text
T043 (FeeService) âˆ¥ T044 (LimitService)
T047 (TransactionResource) âˆ¥ T050 (feature tests) âˆ¥ T051 (fee unit) âˆ¥ T052 (limit unit)
```

---

## Implementation Strategy

### MVP (Phases 1â€“4): Initiation + Confirmation

1. Phase 1 (Setup) â†’ Phase 2 (Foundational) â†’ Phase 3 (Initiation) â†’ Phase 4 (Confirmation)
2. **Validate**: `POST /v1/withdrawals` â†’ `POST /v1/withdrawals/{id}/confirm` â†’ `processing` state; hold exists in `wallet.wallet_holds` status=active; cancel pending â†’ cancelled
3. Payout stays in `processing` â€” no callbacks yet; MockPayoutProviderAdapter used

### Full Lifecycle (Phases 5â€“6): Callbacks + Receipt

4. Phase 5 (Callbacks + Expiry) â†’ Phase 6 (Receipt HTTP)
5. **Validate**: quickstart.md Scenarios 1, 3, 5, 6, 7, 8, 9, 11

### Production-Ready (Phase 7): Admin + Observability + Docs

6. Phase 7 (Admin, Metrics, Tests, PHPStan, Docs) â†’ run all test suites â†’ full quickstart validation

---

## Task Summary

| Phase | Task Range | Count | Stories Covered |
|-------|-----------|-------|----------------|
| 1 Setup | T001â€“T011 | 11 | â€” |
| 2 Foundational | T012â€“T042 | 31 | â€” |
| 3 Initiation | T043â€“T052 | 10 | US1, US2, US3, US4, US5 |
| 4 Confirmation | T053â€“T061 | 9 | US6, US7, US8, US10 |
| 5 Callbacks | T062â€“T072 | 11 | US9, US10, US11, US12 (receipt svc) |
| 6 Receipt HTTP | T073â€“T077 | 5 | US12 |
| 7 Admin & Polish | T078â€“T094 | 17 | Admin, all stories |
| **Total** | **T001â€“T094** | **94** | **US1â€“US12** |

---

## Notes

- All paths relative to `wallet-ecosystem/services/api/`
- `payout_destination` MUST NEVER appear in logs, events, API responses, or queue payloads â€” only `payout_destination_masked` (last 4 chars) per FR-E10-013, SR-E10-007
- PIN MUST NEVER appear in any audit event, log, or queue payload â€” verify absence in T086 per SR-E10-005
- Static route `/receipts/{receiptReference}` MUST be registered BEFORE dynamic `/{id}` in routes/modules/withdrawal.php â€” wrong order causes routing conflict (T075 is critical ordering task)
- E10 does NOT create a `withdrawal.wallet_holds` table â€” holds stored in `wallet.wallet_holds` (E06) via WalletModuleContract extension (research.md Decision 1)
- TBD-E10-004 (ledger payout transit account config keys) must be seeded with valid ledger account references before T063 ledger posting works end-to-end â€” T011 seeds placeholder values; real values required before go-live
- Journal idempotency key is server-generated: `withdrawal_complete:{withdrawal_transaction_id}` â€” never client-supplied per FR-E10-036
