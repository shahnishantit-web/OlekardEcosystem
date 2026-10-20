# Tasks: E18 — Fraud, Risk & AML

**Feature directory**: `specs/018-fraud-risk-aml/`
**Module path**: `wallet-ecosystem/services/api/app/Modules/Risk/`
**Migrations path**: `wallet-ecosystem/services/api/app/Modules/Risk/Infrastructure/Migrations/`
**Tests path**: `wallet-ecosystem/services/api/tests/`

---

## Phase 1: Setup

**Purpose**: Scaffold the Risk module skeleton so all subsequent phases have a home.

- [X] T001 Create module directory tree: `app/Modules/Risk/{Domain/{Contracts,Enums,Models,ValueObjects},Application/{Commands,Services,Jobs,Listeners},Infrastructure/{Http/{Controllers,Requests,Resources},Persistence/Migrations,Providers},routes}`
- [X] T002 Create `app/Modules/Risk/Infrastructure/Providers/RiskServiceProvider.php` — extend `Illuminate\Support\ServiceProvider`; register module; bind `RiskEvaluatorInterface`, `WatchlistScreenerInterface`, `RiskMetricsInterface`, `FiaReportingInterface` to their concrete implementations (`FiaReportingInterface` → `FiaReportingStub`; others registered in Phase 2/3)
- [X] T003 Register `RiskServiceProvider` in `config/app.php` (or module auto-discovery bootstrap)
- [X] T004 [P] Create `app/Modules/Risk/routes/risk.php` — define all 22 route stubs pointing to placeholder controller methods; prefix `/v1/risk`; apply `auth:sanctum` middleware group
- [X] T005 [P] Create migration `app/Modules/Risk/Infrastructure/Migrations/2026_09_26_000000_enable_pg_trgm_extension.php` — `CREATE EXTENSION IF NOT EXISTS pg_trgm` in `risk` schema; required for WatchlistEntry fuzzy-name matching
- [X] T006 [P] Create `database/seeders/RiskPermissionSeeder.php` — seed permissions: `risk.evaluate`, `risk.configure_rules`, `risk.view_alerts`, `risk.manage_alerts`, `risk.freeze_account`, `risk.file_sar`; assign to risk-officer role via E04 permission infrastructure
- [X] T007 [P] Add `risk` queue to `config/horizon.php` queue worker configuration
- [X] T008 [P] Verify cross-module contracts exist and are importable by running `grep -r 'interface KycModuleContract' app/ && grep -r 'interface AgentModuleContract' app/ && grep -r 'interface WalletModuleContract' app/` — if any are absent, raise a blocker and halt Phase 3; do NOT create TODO comments in production code

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: All enums, domain contracts, value objects, migrations, and base models that every user story phase depends on. **No story phase can begin until this phase is complete.**

### Enums (all parallelizable)

- [X] T009 [P] Create `app/Modules/Risk/Domain/Enums/RuleType.php` — cases: `AmountLimit`, `VelocityCount`, `VelocityAmount`, `DeviceScore`, `IpScore`, `BeneficiaryScore`, `AuthFailureCount`
- [X] T010 [P] Create `app/Modules/Risk/Domain/Enums/RiskLevel.php` — cases: `Low`, `Medium`, `High`, `Critical`; add `fromScore(float $score): self` helper (0–40 Low, 41–65 Medium, 66–85 High, 86–100 Critical)
- [X] T011 [P] Create `app/Modules/Risk/Domain/Enums/RiskDecisionEnum.php` — cases: `Allow`, `Block`, `Review`
- [X] T012 [P] Create `app/Modules/Risk/Domain/Enums/AlertStatus.php` — cases: `Open`, `UnderInvestigation`, `Escalated`, `ResolvedTruePositive`, `ResolvedFalsePositive`, `Closed`; add `isTerminal(): bool` helper
- [X] T013 [P] Create `app/Modules/Risk/Domain/Enums/AlertType.php` — cases: `Velocity`, `Watchlist`, `Pattern`, `AuthFailure`, `ThresholdBreach`, `RapidMovement`
- [X] T014 [P] Create `app/Modules/Risk/Domain/Enums/HoldType.php` — cases: `RiskFreeze`, `ComplianceHold`, `AmlInvestigation`
- [X] T015 [P] Create `app/Modules/Risk/Domain/Enums/HoldStatus.php` — cases: `Active`, `Lifted`, `Expired`
- [X] T016 [P] Create `app/Modules/Risk/Domain/Enums/SarStatus.php` — cases: `Draft`, `Approved`, `Submitted`, `SubmissionFailed`

### Domain Contracts and Value Objects (all parallelizable)

- [X] T017 [P] Create `app/Modules/Risk/Domain/Contracts/RiskEvaluatorInterface.php` — method: `checkPreTransaction(TransactionRiskContext $ctx): RiskEvaluationResult`
- [X] T018 [P] Create `app/Modules/Risk/Domain/Contracts/WatchlistScreenerInterface.php` — methods: `screen(string $subjectType, string $subjectId, string $name, ?string $dob = null): array`, `screenRetrospectively(): void`
- [X] T019 [P] Create `app/Modules/Risk/Domain/Contracts/RiskMetricsInterface.php` — methods: `evaluationTotal(string $decision, string $riskLevel, string $txType): void`, `evaluationDuration(int $ms, string $decision): void`, `ruleTriggered(string $ruleName, string $ruleType): void`, `alertCreated(string $alertType, string $severity): void`, `alertResolved(string $decision, string $severity): void`, `accountFrozen(string $holdType): void`
- [X] T019a [P] Create `app/Modules/Risk/Domain/Contracts/FiaReportingInterface.php` — single method: `submit(SuspiciousActivityReport $sar): string` (returns FIA reference string on success; throws `\RuntimeException` on failure); create `app/Modules/Risk/Infrastructure/Adapters/FiaReportingStub.php` implementing it — stub body: `throw new \RuntimeException('FIA portal integration not yet implemented — deferred to E28/E1000')` with `submission_attempts` increment before throw so callers can record the attempt; FiaReportingInterface and FiaReportingStub are bound in T002
- [X] T020 [P] Create `app/Modules/Risk/Domain/ValueObjects/TransactionRiskContext.php` — readonly DTO: `idempotencyKey`, `transactionReference`, `transactionType`, `amount` (Money/BCMATH string), `currency`, `customerId`, `walletId?`, `agentId?`, `beneficiaryId?`, `deviceId?`, `ipAddress?`, `kycTier?`
- [X] T021 [P] Create `app/Modules/Risk/Domain/ValueObjects/RiskEvaluationResult.php` — readonly DTO: `evaluationId`, `decision` (RiskDecisionEnum), `riskLevel` (RiskLevel), `overallScore`, `rulesApplied` (array), `evaluationMs`

### Migrations (all parallelizable — different files)

- [X] T022 [P] Create migration `app/Modules/Risk/Infrastructure/Migrations/2026_09_26_000001_create_risk_rules_table.php` — see data-model.md RiskRule entity; include UNIQUE constraint on `(name)` and partial UNIQUE on `(name, subject_id) WHERE subject_id IS NOT NULL`; composite index `(rule_type, applies_to, is_active)`
- [X] T023 [P] Create migration `app/Modules/Risk/Infrastructure/Migrations/2026_09_26_000002_create_risk_evaluations_table.php` — see data-model.md RiskEvaluation entity; UNIQUE on `idempotency_key`; CHECK `overall_score BETWEEN 0 AND 100`; indexes `(customer_id, evaluated_at DESC)`, `(transaction_reference)`, `(risk_level, evaluated_at DESC)`
- [X] T024 [P] Create migration `app/Modules/Risk/Infrastructure/Migrations/2026_09_26_000003_create_risk_events_table.php` — see data-model.md RiskEvent entity; append-only (no FK cascade deletes); indexes `(subject_id, detected_at DESC)`, `(event_type, detected_at DESC)`
- [X] T025 [P] Create migration `app/Modules/Risk/Infrastructure/Migrations/2026_09_26_000004_create_risk_alerts_table.php` — see data-model.md RiskAlert entity; include `version INTEGER NOT NULL DEFAULT 1` for optimistic locking; indexes `(status, severity, sla_due_at)`, `(subject_id, status)`
- [X] T026 [P] Create migration `app/Modules/Risk/Infrastructure/Migrations/2026_09_26_000005_create_risk_alert_events_table.php` — composite PK `(alert_id, event_id)`; FKs to risk_alerts and risk_events
- [X] T027 [P] Create migration `app/Modules/Risk/Infrastructure/Migrations/2026_09_26_000006_create_risk_decisions_table.php` — see data-model.md RiskDecision entity; FK to risk_alerts
- [X] T028 [P] Create migration `app/Modules/Risk/Infrastructure/Migrations/2026_09_26_000007_create_watchlist_entries_table.php` — see data-model.md WatchlistEntry entity; GIN index on `normalized_name` using `gin_trgm_ops` for pg_trgm; index `(entry_type, is_active)`
- [X] T029 [P] Create migration `app/Modules/Risk/Infrastructure/Migrations/2026_09_26_000008_create_watchlist_matches_table.php` — see data-model.md WatchlistMatch entity; partial UNIQUE `(watchlist_entry_id, subject_type, subject_id) WHERE cleared_at IS NULL`
- [X] T030 [P] Create migration `app/Modules/Risk/Infrastructure/Migrations/2026_09_26_000009_create_account_holds_table.php` — see data-model.md AccountHold entity; indexes `(subject_id, status)`, `(status, expires_at) WHERE expires_at IS NOT NULL`
- [X] T031 [P] Create migration `app/Modules/Risk/Infrastructure/Migrations/2026_09_26_000010_create_suspicious_activity_reports_table.php` — see data-model.md SAR entity; CHECK `reporting_period_end >= reporting_period_start`, `amount_involved > 0`

### Eloquent Models (all parallelizable — different files)

- [X] T032 [P] Create `app/Modules/Risk/Domain/Models/RiskRule.php` — Eloquent model; casts: `rule_type` → RuleType, `action` → RiskDecisionEnum, `applies_to` → string; scope `active()` filtering `effective_from <= NOW()` and `(effective_until IS NULL OR effective_until > NOW())`
- [X] T033 [P] Create `app/Modules/Risk/Domain/Models/RiskEvaluation.php` — casts: `decision` → RiskDecisionEnum, `risk_level` → RiskLevel, `rules_applied` → array; override `save()` to prevent UPDATE after creation (append-only contract)
- [X] T034 [P] Create `app/Modules/Risk/Domain/Models/RiskEvent.php` — boot: override `updating` and `deleting` to `throw new \RuntimeException('RiskEvent is append-only')` (constitution P4: immutable audit log)
- [X] T035 [P] Create `app/Modules/Risk/Domain/Models/RiskAlert.php` — casts: `status` → AlertStatus, `severity` → RiskLevel, `alert_type` → AlertType; hasMany `decisions`, hasMany `events` via RiskAlertEvent; increment `version` on save via `saving` boot hook
- [X] T036 [P] Create `app/Modules/Risk/Domain/Models/RiskAlertEvent.php` — composite primary key `(alert_id, event_id)`; belongsTo RiskAlert; belongsTo RiskEvent
- [X] T037 [P] Create `app/Modules/Risk/Domain/Models/RiskDecision.php` — casts: `decision` → string; belongsTo RiskAlert
- [X] T038 [P] Create `app/Modules/Risk/Domain/Models/WatchlistEntry.php` — casts: `aliases` → array, `id_numbers` → array; scope `active()`; `normalizedName` mutator: lowercase + strip diacritics on set
- [X] T039 [P] Create `app/Modules/Risk/Domain/Models/WatchlistMatch.php` — casts: `match_method` → string; belongsTo WatchlistEntry; belongsTo RiskAlert (nullable)
- [X] T040 [P] Create `app/Modules/Risk/Domain/Models/AccountHold.php` — casts: `hold_type` → HoldType, `status` → HoldStatus; scope `active()` where `status = 'active'`
- [X] T041 [P] Create `app/Modules/Risk/Domain/Models/SuspiciousActivityReport.php` — casts: `status` → SarStatus, `suspected_offences` → array, `transaction_references` → array; belongsTo RiskAlert; belongsTo RiskDecision

### Shared Infrastructure Services

- [X] T042 Create `app/Modules/Risk/Application/Services/RiskMetricsService.php` implementing `RiskMetricsInterface` — emit StatsD/Prometheus counters and histograms; bind in RiskServiceProvider

**Checkpoint**: Phase 2 complete → all 10 tables exist, all models loadable, all enums and interfaces defined (including `FiaReportingInterface` + `FiaReportingStub` from T019a). Run `php artisan migrate` and verify no errors before proceeding.

---

## Phase 3: US-RISK-001 through US-RISK-004 — Core Synchronous Risk Evaluation (P0) 🎯 MVP

**Goal**: Deliver the core risk evaluation pipeline — the `RiskEvaluatorInterface` implementation that all transaction modules will call before posting.

**Independent Test (SC-1 from quickstart.md)**: Configure a per-transaction limit; call POST `/v1/risk/evaluate-transaction-risk` with an amount above the limit; receive `decision = "block"` in < 200ms.

- [X] T043 [US1] Create `app/Modules/Risk/Application/Commands/EvaluateTransactionRiskCommand.php` — readonly DTO wrapping TransactionRiskContext fields plus `idempotencyKey`
- [X] T044 [US1] Implement `app/Modules/Risk/Application/Services/RuleEngineService.php` — method `applyRules(TransactionRiskContext $ctx, array $rules): array` returns per-rule score contributions; loads active RiskRules from DB with `active()` scope; applies: amount-limit check (block if amount > threshold), velocity-count/amount check (delegates to VelocityAccumulatorService), device-score check, auth-failure-count check; aggregates into overall score; maps score to RiskDecisionEnum by reading `risk.block_score_threshold` and `risk.review_score_threshold` from `ConfigurationServiceInterface` — never hard-code numeric thresholds in domain logic (constitution P2/P3); `RiskPermissionSeeder` MUST seed both config keys with safe default values
- [X] T045 [US2] Implement `app/Modules/Risk/Application/Services/VelocityAccumulatorService.php` — use Redis ZADD + ZRANGEBYSCORE to maintain rolling-window sets keyed by `velocity:{customerId}:{txType}:{windowSeconds}`; methods: `getCount(string $key, int $windowSeconds): int`, `getAmount(string $key, int $windowSeconds): string`, `record(string $key, string $amount, int $windowSeconds): void`; TTL = window + 5 min buffer
- [X] T046 [US1] Implement `app/Modules/Risk/Application/Services/RiskEvaluatorService.php` implementing `RiskEvaluatorInterface` — method `checkPreTransaction(TransactionRiskContext $ctx): RiskEvaluationResult`: (1) check idempotency_key in risk_evaluations; if found, return existing result; (2) check AccountHold::active()->where('subject_id', $ctx->customerId)->exists() → if true, immediately return Block; (3) load active rules; (4) call RuleEngineService; (5) measure wall time; (6) persist RiskEvaluation; (7) emit metrics; (8) return RiskEvaluationResult; wrap in try/catch — on exception emit `risk.evaluation.unavailable` audit event (via AuditServiceInterface, not just a log) and return default Allow (constitution P9 — transaction reliability)
- [X] T047 [US1] Create `app/Modules/Risk/Infrastructure/Http/Requests/EvaluateTransactionRiskRequest.php` — validate: `idempotency_key` required string max:128; `transaction_reference` required string max:128; `transaction_type` required in enum list; `amount` required numeric string positive; `currency` in ['BWP']; `customer_id` required UUID
- [X] T048 [US1] Create `app/Modules/Risk/Infrastructure/Http/Resources/RiskEvaluationResource.php` — output: `evaluation_id`, `decision`, `risk_level`, `overall_score`, `rules_applied`, `evaluation_ms`, `evaluated_at`
- [X] T049 [US1] Implement `app/Modules/Risk/Infrastructure/Http/Controllers/RiskEvaluationController.php::evaluateTransactionRisk()` — dispatch EvaluateTransactionRiskCommand → RiskEvaluatorService; emit `risk.evaluation.{decision}` audit event (AuditServiceInterface); return RiskEvaluationResource; handle idempotency 409 on payload mismatch
- [X] T050 [US2] Add `RiskEvaluationController::evaluateTransactionVelocity()` + `VelocityRiskRequest` — delegates to VelocityAccumulatorService only; returns velocity-specific evaluation result
- [X] T051 [US3] Add `RiskEvaluationController::evaluateAmountThreshold()` + `AmountThresholdRequest` — queries applicable RiskRules (per_tx or velocity_amount by limit_type); returns threshold evaluation
- [X] T052 [US4] Add `RiskEvaluationController::evaluateDeviceRisk()` + `DeviceRiskRequest` — applies device-score rules; new device (`is_new_device=true`) gets elevated base score
- [X] T053 [US1] Register event listeners in `RiskServiceProvider` — `FundingTransactionCompleted` → `OnFundingTransactionCompleted`, `TransferCompleted` → `OnTransferCompleted`, `WithdrawalCompleted` → `OnWithdrawalCompleted`, `CommissionPaidEvent` → `OnCommissionPaid`, `ServicePaymentCompletedEvent` → `OnServicePaymentCompleted`, `QrPaymentCompletedEvent` → `OnQrPaymentCompleted`; each listener calls `VelocityAccumulatorService::record()`
- [X] T054 [US1] Create all 6 listener stubs: `app/Modules/Risk/Application/Listeners/On{FundingTransactionCompleted,TransferCompleted,WithdrawalCompleted,CommissionPaid,ServicePaymentCompleted,QrPaymentCompleted}.php` — each extracts `customerId`, `amount`, `transactionType` from event and calls VelocityAccumulatorService
- [X] T055 [US1] Write unit tests `tests/Unit/Modules/Risk/Services/RuleEngineServiceTest.php` — test: amount below limit → Allow; amount above limit → Block; velocity count exceeded → Review; score aggregation across multiple rules; default-allow on empty rules
- [X] T056 [US2] Write unit tests `tests/Unit/Modules/Risk/Services/VelocityAccumulatorServiceTest.php` — test: record + getCount in window; record + getAmount in window; expired entries excluded; Redis key TTL set correctly
- [X] T057 [US1] Write integration tests `tests/Feature/Modules/Risk/EvaluateTransactionRiskTest.php` — SC-1: block above per-tx limit; SC-2: idempotency same key returns same result; SC-2: 409 on payload mismatch; evaluation_ms < 200; audit event emitted; RiskEvaluation row persisted

**Checkpoint**: POST `/v1/risk/evaluate-transaction-risk` returns correct decisions. SC-1 and SC-2 from quickstart pass.

---

## Phase 4: US-RISK-005 through US-RISK-006 — IP and Beneficiary Risk Evaluation (P1)

**Goal**: Add IP-based risk scoring and beneficiary/watchlist screening to the evaluation pipeline.

**Independent Test (SC-4 from quickstart.md)**: Evaluate a beneficiary on the watchlist → decision is block/review; WatchlistMatch record created; RiskAlert with type=watchlist created.

- [X] T058 [P] [US5] Add `IpScore` rule evaluation to `RuleEngineService::applyRules()` — check ip_address against configured IP-score weight rules; elevated score for known VPN/Tor exit nodes (configurable list via RiskRule); add `evaluateIpRisk()` method
- [X] T059 [P] [US5] Create `app/Modules/Risk/Infrastructure/Http/Requests/IpRiskRequest.php` and add `RiskEvaluationController::evaluateIpRisk()` endpoint
- [X] T060 [US6] Implement `app/Modules/Risk/Application/Services/WatchlistScreenerService.php` implementing `WatchlistScreenerInterface` — `screen()`: (1) normalize input name (lowercase, strip diacritics); (2) exact match on `normalized_name`; (3) fuzzy match using `similarity(normalized_name, ?)` with configurable threshold (default 0.85); (4) alias match against JSONB `aliases` array; (5) on match: persist WatchlistMatch, create RiskAlert with type=watchlist severity=critical, emit `risk.watchlist.match` audit event; return array of WatchlistMatch
- [X] T061 [US6] Create `app/Modules/Risk/Infrastructure/Http/Requests/BeneficiaryRiskRequest.php` and add `RiskEvaluationController::evaluateBeneficiaryRisk()` — calls WatchlistScreenerService; integrates match score into overall evaluation; new beneficiary (`is_new_beneficiary=true`) adds baseline score increment
- [X] T062 [US6] Write unit tests `tests/Unit/Modules/Risk/Services/WatchlistScreenerServiceTest.php` — exact match, fuzzy match above threshold, fuzzy below threshold → no match, alias match, no-match → empty array
- [X] T063 [US6] Write integration tests `tests/Feature/Modules/Risk/BeneficiaryRiskTest.php` — SC-4: watchlist beneficiary → match created + alert created + decision=block; non-watchlist beneficiary → allow; new beneficiary flag increases score

**Checkpoint**: POST `/v1/risk/evaluate-beneficiary-risk` correctly screens against watchlist. SC-4 passes.

---

## Phase 5: US-RISK-007 through US-RISK-009 — Manual Transaction Decisions (P0)

**Goal**: Allow risk officers to manually allow, block, or escalate transactions that the engine flagged for review.

**Independent Test (SC-3 from quickstart.md)**: Evaluation returns review → call send-for-manual-review → RiskAlert created with status=open and sla_due_at set.

- [X] T064 [P] [US7] Create `app/Modules/Risk/Infrastructure/Http/Requests/ManualDecisionRequest.php` — validate: `evaluation_id` UUID required; `rationale` string min:10 max:2000
- [X] T065 [P] [US7] Add `RiskEvaluationController::allowTransaction()` — load RiskEvaluation; 404 if not found; 409 if RiskDecision already exists for this evaluation; create RiskDecision with `decision=clear`; emit `risk.evaluation.allow` audit event; return ManualDecisionResource
- [X] T066 [P] [US8] Add `RiskEvaluationController::blockTransaction()` — same pattern as allowTransaction; create RiskDecision with `decision=block`; emit `risk.evaluation.block` audit event
- [X] T067 [US9] Implement `app/Modules/Risk/Application/Services/AlertService.php::sendForManualReview()` — accepts RiskEvaluation; creates RiskAlert with `alert_type=ThresholdBreach`, `status=Open`; computes `sla_due_at` from configurable SLA per severity (default: critical=4h, high=24h, medium=72h, low=168h); links evaluation via RiskAlertEvent; emits `risk.alert.created` audit event
- [X] T068 [US9] Create `app/Modules/Risk/Infrastructure/Http/Requests/SendForReviewRequest.php` and add `RiskEvaluationController::sendTransactionForManualReview()` — calls AlertService; 409 if alert already open for this evaluation; return 201 RiskAlertResource
- [X] T069 [US7] Write unit tests `tests/Unit/Modules/Risk/Controllers/ManualDecisionTest.php` — allow: creates decision, 409 on duplicate; block: creates decision, 409 on duplicate; unknown evaluation: 404
- [X] T070 [US9] Write integration tests `tests/Feature/Modules/Risk/ManualReviewTest.php` — SC-3 scenario: velocity review evaluation → send-for-manual-review → alert created with correct SLA

**Checkpoint**: Officers can allow, block, or escalate a review-flagged transaction. SC-3 passes.

---

## Phase 6: US-RISK-010 through US-RISK-015 — Risk Rule Configuration (P0)

**Goal**: Allow risk officers to configure per-transaction, daily, monthly, customer-scoped, agent-scoped, and wallet-scoped limits.

**Independent Test (SC-10 from quickstart.md)**: Configure a customer-scoped limit lower than the global limit; evaluate a transaction between the two values; customer limit takes precedence → block.

- [X] T071 Create `app/Modules/Risk/Application/Commands/ConfigureRiskRuleCommand.php` — DTO: `appliesToType`, `appliesToId?`, `ruleType`, `thresholdAmount?`, `thresholdCount?`, `windowSeconds?`, `action`, `effectiveFrom?`, `effectiveUntil?`
- [X] T072 Add `RuleEngineService::configureRule(ConfigureRiskRuleCommand $cmd): RiskRule` — upsert logic: find existing active rule by `(name, subject_id)`; if found, deactivate old and create new (preserving audit history); emit `risk.rule.created` or `risk.rule.updated` audit event; invalidate Redis rule cache
- [X] T073 [P] [US10] Create `app/Modules/Risk/Infrastructure/Http/Requests/AmountLimitRuleRequest.php` and add `RiskRuleController::configurePerTransactionLimit()` — 201 on create, 200 on update; return RiskRuleResource
- [X] T074 [P] [US11] Create `app/Modules/Risk/Infrastructure/Http/Requests/VelocityAmountRuleRequest.php` (window_seconds=86400) and add `RiskRuleController::configureDailyLimit()`
- [X] T075 [P] [US12] Add `RiskRuleController::configureMonthlyLimit()` — uses same VelocityAmountRuleRequest with window_seconds=2592000
- [X] T076 [P] [US13] Create `app/Modules/Risk/Infrastructure/Http/Requests/SubjectLimitsRequest.php` and add `RiskRuleController::configureCustomerLimits()` — upserts multiple rules for customer subject; validates customer exists via cross-module check; 404 if customer not found
- [X] T077 [P] [US14] Add `RiskRuleController::configureAgentLimits()` — same as configureCustomerLimits but validates agent exists; 404 if agent not found
- [X] T078 [P] [US15] Add `RiskRuleController::configureWalletLimits()` — validates wallet exists; 404 if wallet not found
- [X] T079 [US10] Create `app/Modules/Risk/Infrastructure/Http/Controllers/RiskRuleController.php` with `listRules()` — filter by `rule_type`, `applies_to`, `subject_id`; return paginated RiskRuleResource collection
- [X] T080 [US10] Write unit tests `tests/Unit/Modules/Risk/Services/RuleEngineConfigureTest.php` — new rule created; existing rule deactivated on update; subject-scoped rule overrides global; list rules filtered correctly
- [X] T081 [US10] Write integration tests `tests/Feature/Modules/Risk/RuleConfigurationTest.php` — SC-10 scenario: global limit 1000, customer limit 500; transaction at 750 → block; different customer at 750 → allow

**Checkpoint**: Rules can be configured and immediately affect evaluations. SC-10 passes.

---

## Phase 7: US-RISK-016 through US-RISK-017 — Async Pattern Detection (P1)

**Goal**: Asynchronous jobs detect unusual transaction patterns and rapid fund movement, generating RiskAlerts for compliance review.

**Independent Test (SC-9 from quickstart.md)**: POST detect-unusual-transaction-pattern → 202; queue:work --once processes job; if customer has anomalous pattern, RiskAlert with type=pattern exists.

- [X] T082 [P] [US16] Implement `app/Modules/Risk/Application/Jobs/DetectUnusualPatternJob.php` — dispatched on `risk` queue; lookback window configurable (default 30 days); load customer's transaction history from velocity accumulator + ledger summary; compare against baseline (configurable: Z-score or percentile threshold); if anomaly detected: create RiskEvent with `event_type=pattern.unusual`; create RiskAlert with `alert_type=Pattern`; emit `risk.pattern_job.executed` metric; idempotent (check for existing open pattern alert before creating)
- [X] T083 [P] [US17] Implement `app/Modules/Risk/Application/Jobs/DetectRapidFundMovementJob.php` — 24-hour window; query inbound transactions (funding, transfers received) and outbound transactions (withdrawals, transfers sent) for subject; detect ratio where outbound_within_24h / inbound_within_48h > configurable threshold (default 0.8); create RiskEvent `pattern.rapid_movement` and RiskAlert `alert_type=RapidMovement` if triggered; idempotent
- [X] T084 [US16] Add `RiskEvaluationController::detectUnusualTransactionPattern()` + `PatternDetectionRequest` — validate `subject_type`, `subject_id`, optional `lookback_days`; dispatch `DetectUnusualPatternJob`; return 202 with `job_id` and `queued_at`
- [X] T085 [US17] Add `RiskEvaluationController::detectRapidFundMovement()` — same pattern; dispatch `DetectRapidFundMovementJob`; return 202
- [X] T086 [US16] Write unit tests `tests/Unit/Modules/Risk/Jobs/DetectUnusualPatternJobTest.php` — above threshold → event + alert created; below threshold → no alert; existing open alert → no duplicate; empty window → no processing
- [X] T087 [US16] Write feature test `tests/Feature/Modules/Risk/PatternDetectionTest.php` — SC-9 scenario: POST → 202; fake queue processes job; assert RiskEvent + RiskAlert created

**Checkpoint**: Pattern detection jobs dispatch correctly and create alerts when anomalies exceed thresholds. SC-9 passes.

---

## Phase 8: US-RISK-018 — Repeated Failed Authentication Detection (P0)

**Goal**: Synchronously detect when a customer or agent exceeds the configured authentication failure threshold and immediately create a high-severity alert.

**Independent Test (SC-5 from quickstart.md)**: 5 consecutive auth-failure calls for same subject/device → 5th call returns `alert_created=true`; RiskAlert with type=auth_failure severity=high exists.

- [X] T088 [US18] Add auth-failure accumulation to `RuleEngineService` — method `recordAuthFailure(string $subjectType, string $subjectId, string $deviceId): int`; use Redis INCR with TTL keyed by `auth_fail:{subjectType}:{subjectId}:{deviceId}:{window}`; load `AuthFailureCount` RiskRule for subject; return current count
- [X] T089 [US18] Add `AlertService::createAuthFailureAlert(string $subjectType, string $subjectId, int $failureCount): RiskAlert` — create RiskAlert with `alert_type=AuthFailure`, `severity=High`, `status=Open`; emit `risk.alert.created` audit event; return alert
- [X] T090 [US18] Create `app/Modules/Risk/Infrastructure/Http/Requests/AuthFailureRequest.php` and add `RiskEvaluationController::detectRepeatedFailedAuthentication()` — call `recordAuthFailure()`; if count >= threshold: call `createAuthFailureAlert()`; return `AuthFailureResponse` with `failure_count`, `threshold`, `alert_created`, `alert_id?`
- [X] T091 [US18] Write unit tests `tests/Unit/Modules/Risk/Services/AuthFailureDetectionTest.php` — 4 failures → no alert; 5 failures → alert created; 6th failure → no duplicate alert (existing open alert check); window expires → count resets
- [X] T092 [US18] Write integration tests `tests/Feature/Modules/Risk/AuthFailureDetectionTest.php` — SC-5 scenario: 5 calls → alert_created=true; verify alert in DB with correct severity

**Checkpoint**: Auth failure detection triggers correctly at threshold. SC-5 passes.

---

## Phase 9: US-RISK-019 through US-RISK-021 — Risk Alert Lifecycle (P0)

**Goal**: Full alert management — create, list, get, assign for investigation, and resolve with formal decision.

**Independent Test (SC-6 from quickstart.md)**: Create alert → investigate → resolve as false_positive → status=resolved_false_positive; RiskDecision record exists.

- [X] T093 [P] [US19] Add `AlertService::createAlert(CreateRiskAlertCommand $cmd): RiskAlert` — create RiskAlert with caller-supplied fields; link RiskEvents via RiskAlertEvent; compute `sla_due_at` from severity SLA config; emit `risk.alert.created` audit event; idempotent by `(alert_type, subject_id, correlation_id)` when correlation_id provided
- [X] T094 [P] [US19] Create `app/Modules/Risk/Application/Commands/CreateRiskAlertCommand.php` — DTO for CreateRiskAlertRequest fields
- [X] T095 [P] [US19] Create `app/Modules/Risk/Infrastructure/Http/Requests/CreateAlertRequest.php` and add `RiskAlertController::createRiskAlert()` — returns 201 AlertDetailResource; 409 on correlation conflict
- [X] T096 [P] [US19] Add `RiskAlertController::listAlerts()` — filters: status, severity, subject_id, assigned_to; paginate 20/page max 100; return AlertListResource
- [X] T097 [P] [US19] Add `RiskAlertController::getAlert(string $id)` — load alert with decisions and linked events; 404 if not found; return AlertDetailResource
- [X] T098 [US20] Add `AlertService::investigateAlert(InvestigateAlertCommand $cmd): RiskAlert` — load alert with pessimistic lock; check version matches $cmd->version → 409 if mismatch (optimistic lock); transition status to `UnderInvestigation`; set `assigned_to`; increment version; emit `risk.alert.assigned` audit event
- [X] T099 [US20] Create `app/Modules/Risk/Application/Commands/InvestigateAlertCommand.php` + `InvestigateAlertRequest` and add `RiskAlertController::investigateRiskAlert()` — return 200 AlertDetailResource; 409 on version mismatch
- [X] T100 [US21] Add `AlertService::resolveAlert(ResolveAlertCommand $cmd): RiskAlert` — load alert with pessimistic lock; check version → 409 on mismatch; validate terminal status not already set; require `checker_id` if decision is `freeze_account` or `file_sar`; create RiskDecision; set terminal status; set `resolved_at`; emit `risk.alert.resolved` audit event with rationale; **when `decision=file_sar`**: also create a `SuspiciousActivityReport` in `draft` status — set `filer_id=$cmd->decidedBy`, `narrative=$cmd->rationale`, `transaction_references` from linked RiskEvent transaction_references, `alert_id`, `decision_id`; do NOT yet submit to FIA (submission is a separate officer action via T119–T122)
- [X] T101 [US21] Create `app/Modules/Risk/Application/Commands/ResolveAlertCommand.php` + `ResolveAlertRequest` and add `RiskAlertController::resolveRiskAlert()` — return 200 AlertDetailResource
- [X] T102 [US20] Write unit tests `tests/Unit/Modules/Risk/Services/AlertServiceTest.php` — investigate: version match → success; version mismatch → 409; already terminal → 422; resolve: all terminal transitions; missing checker_id for freeze_account → 422; SLA computation per severity
- [X] T103 [US21] Write integration tests `tests/Feature/Modules/Risk/AlertLifecycleTest.php` — SC-6: create → investigate → resolve false_positive; SC-7: concurrent investigate with stale version → 409

**Checkpoint**: Full alert lifecycle functional. SC-6 and SC-7 pass.

---

## Phase 10: US-RISK-022 — Account Freeze (P0)

**Goal**: Risk officers can freeze an account (place an AccountHold), which immediately causes subsequent transaction evaluations to return `block`.

**Independent Test (SC-8 from quickstart.md)**: Freeze account → subsequent evaluate-transaction-risk returns block; AccountHold record exists with status=active.

- [X] T104 [US22] Implement `app/Modules/Risk/Application/Services/AccountHoldService.php::placeHold(FreezeAccountCommand $cmd): AccountHold` — validate no existing active hold for same subject+wallet; create AccountHold; require `checker_id` (maker-checker enforcement); emit `risk.account.frozen` audit event with `placed_by` and `checker_id` in metadata; cache active hold flag in Redis `hold_active:{subjectType}:{subjectId}` with TTL = `expires_at` or indefinite; return AccountHold
- [X] T105 [US22] Add `AccountHoldService::checkHold(string $subjectType, string $subjectId): bool` — check Redis cache first; fallback to DB `AccountHold::active()->where('subject_id', $subjectId)->exists()`; this method is called by `RiskEvaluatorService::checkPreTransaction()` (already wired in T046)
- [X] T106 [US22] Add `AccountHoldService::liftHold(string $holdId, string $liftedBy, string $liftReason): AccountHold` — set status=Lifted; clear Redis cache key; emit `risk.account.hold_lifted` audit event
- [X] T107 [US22] Create `app/Modules/Risk/Application/Commands/FreezeAccountCommand.php` + `app/Modules/Risk/Infrastructure/Http/Requests/FreezeAccountRequest.php` and add `RiskAlertController::freezeAccountDueToRisk()` — 201 AccountHoldResource; 409 if already frozen; 403 if missing `risk.freeze_account` permission
- [X] T108 [US22] Write unit tests `tests/Unit/Modules/Risk/Services/AccountHoldServiceTest.php` — place hold → subsequent checkHold returns true; lift hold → checkHold returns false; concurrent placeHold idempotent (second returns 409); missing checker_id → exception
- [X] T109 [US22] Write integration tests `tests/Feature/Modules/Risk/AccountFreezeTest.php` — SC-8 scenario: freeze account → evaluate-transaction-risk returns block; lift hold → evaluate returns allow; freeze without checker_id → 422

**Checkpoint**: Account freeze enforced in evaluation pipeline. SC-8 passes.

---

## Phase 10b: US-RISK-021 (cont.) — Suspicious Activity Report (SAR) Workflow

**Purpose**: After `decision=file_sar` creates a SAR draft (T100), compliance officers must be able to approve and submit it to Botswana FIA. This phase completes the US-RISK-021 AC ("persistence" and "traceability").

- [X] T119 [US21] Create `app/Modules/Risk/Infrastructure/Http/Controllers/SarController.php` with `show(string $id)` and `list()` methods — list filters: `status`, `subject_id`; show returns full SAR with narrative, suspected_offences, transaction_references; require `risk.file_sar` permission
- [X] T120 [US21] Add `SarController::approve(string $id)` — require `approver_id` (must differ from `filer_id`; maker-checker); set `status=approved`, `approver_id`, `approved_at`; emit `risk.sar.approved` audit event via AuditServiceInterface; return updated SAR resource; 409 if already approved; 422 if approver == filer
- [X] T121 [US21] Add `SarController::submit(string $id)` — require `status=approved`; attempt FIA portal submission (stub `FiaReportingInterface::submit(SAR $sar): string` returning FIA reference); on success: set `status=submitted`, `submitted_at`, `fia_reference`, increment `submission_attempts`; on failure: set `status=submission_failed`, `last_submission_error`; emit `risk.sar.submitted` or `risk.sar.submission_failed` audit event; return SAR resource
- [X] T122 [US21] Register SAR routes in `app/Modules/Risk/routes/risk.php`: `GET /v1/risk/sars`, `GET /v1/risk/sars/{id}`, `POST /v1/risk/sars/{id}/approve`, `POST /v1/risk/sars/{id}/submit`; write integration tests `tests/Feature/Modules/Risk/SarWorkflowTest.php` — resolve alert file_sar → SAR draft created; approve → status=approved; submit → status=submitted; submit without approval → 422; approver == filer → 422
- [X] T123 [US22] Add `RiskAlertController::liftAccountHold()` + `app/Modules/Risk/Infrastructure/Http/Requests/LiftHoldRequest.php` — fields: `hold_id` UUID required, `lift_reason` string min:10; require `risk.freeze_account` permission; call `AccountHoldService::liftHold()`; register route `POST /v1/risk/lift-account-hold`; emit `risk.account.hold_lifted` audit event; return AccountHoldResource; 404 if hold not found; 409 if status is already `lifted` or `expired`; add to integration tests in `AccountFreezeTest.php`: freeze → lift → subsequent evaluate-transaction-risk returns allow

---

## Phase 11: Polish & Cross-Cutting Concerns

**Purpose**: SLA enforcement, retrospective watchlist scanning, OpenAPI documentation, static analysis, and full quickstart validation.

- [X] T110 [P] Implement `app/Modules/Risk/Application/Jobs/EscalateOverdueAlertsJob.php` — query open/under_investigation alerts where `sla_due_at < NOW()`; for each: increment severity by one level (if configured); emit `risk.alert.sla_breached` metric; optionally notify assigned officer via Notification module (E17)
- [X] T111 [P] Register `EscalateOverdueAlertsJob` in `app/Console/Kernel.php` schedule — every 15 minutes: `$schedule->job(EscalateOverdueAlertsJob::class)->everyFifteenMinutes()->onQueue('risk')`
- [X] T112 [P] Implement `WatchlistScreenerService::screenRetrospectively()` — batch scan all active customers against updated watchlist entries; create WatchlistMatch records for new hits (where `cleared_at IS NULL`); create RiskAlerts for high-confidence matches; log `risk.watchlist.retrospective_scan` metric with match count
- [X] T113 Register `WatchlistScreenerService::screenRetrospectively()` as a daily scheduled command in `app/Console/Kernel.php`
- [X] T114 [P] Run PHPStan on `app/Modules/Risk/` at level 6: `./vendor/bin/phpstan analyse app/Modules/Risk --level=6`; fix all reported issues
- [X] T115 [P] Run quickstart.md SC-1 through SC-10 validation scenarios against the running Docker stack; document pass/fail results — 73/73 tests pass (2026-10-06)
- [X] T116 [P] Update `docs/openapi.yaml` (or generate from route annotations) to include all 22 Risk module endpoints from `contracts/risk-evaluation.yaml`, `contracts/risk-rules.yaml`, and `contracts/risk-detection-alerts.yaml`
- [X] T117 [P] Resolve F1 spec finding: add entity reconciliation note to `specs/018-fraud-risk-aml/spec.md` listing 6 additional entities introduced in data-model.md (RiskEvaluation, RiskAlertEvent, WatchlistEntry, WatchlistMatch, AccountHold, SuspiciousActivityReport)
- [X] T118 [P] Resolve B1 spec finding: add Event Mapping table to `specs/018-fraud-risk-aml/plan.md` bridging US_RISK_NNN_REQUESTED/SUCCEEDED/FAILED story events to concrete audit event keys
- [X] T124 [P] Create `app/Modules/Risk/Infrastructure/Http/Controllers/WatchlistController.php` with `store()`, `update()`, `deactivate()` — `store`: validate `full_name`, `entry_type`, `source` required; normalize name on save via `WatchlistEntry` mutator; `update`: update allowed fields, re-normalize; `deactivate`: set `is_active=false`; all methods require `risk.configure_rules` permission; register routes in `routes/risk.php`: `POST /v1/risk/watchlist`, `PUT /v1/risk/watchlist/{id}`, `DELETE /v1/risk/watchlist/{id}`; return `WatchlistEntryResource`
- [X] T125 [P] Create `database/seeders/WatchlistEntrySeeder.php` — seed ≥5 representative entries: (1) sanctions exact-match entry with name matching `$WATCHLIST_SUBJECT_ID` customer (used by SC-4 quickstart); (2) PEP entry; (3) alias-match entry with `aliases` JSON array; (4) fuzzy-match entry with intentional variant spelling; (5) internal-blocklist entry; guard seeder for `local` and `testing` environments only; register in `DatabaseSeeder::run()`

**Checkpoint**: PHPStan clean. All SC-1–SC-10 quickstart scenarios pass. OpenAPI docs up to date.

---

## Dependencies & Execution Order

### Phase Dependencies

| Phase | Depends On | Can Parallelise With |
|-------|-----------|----------------------|
| Phase 1 (Setup) | None | — |
| Phase 2 (Foundational) | Phase 1 complete | — |
| Phase 3 (US1–4, Core Eval) | Phase 2 complete | Phases 4–10 (if staffed) |
| Phase 4 (US5–6, IP+Beneficiary) | Phase 2 + Phase 3 (RuleEngineService) | Phases 5–10 |
| Phase 5 (US7–9, Manual Decisions) | Phase 2 + Phase 3 (RiskEvaluation model) | Phases 4, 6–10 |
| Phase 6 (US10–15, Config) | Phase 2 (RiskRule model) | Phases 4, 5, 7–10 |
| Phase 7 (US16–17, Patterns) | Phase 2 + Phase 3 (VelocityAccumulator) | Phases 4–6, 8–10 |
| Phase 8 (US18, Auth Failure) | Phase 2 + Phase 3 (RuleEngineService) | Phases 4–7, 9–10 |
| Phase 9 (US19–21, Alert Lifecycle) | Phase 2 + Phase 5 (AlertService) | Phases 4, 6–8, 10 |
| Phase 10 (US22, Account Freeze) | Phase 2 + Phase 9 (AlertService) | Phases 4–9 |
| Phase 11 (Polish) | All story phases complete | — |

### User Story Dependencies

- **US-RISK-001–004** (Phase 3): Foundation only — MVP deliverable
- **US-RISK-005–006** (Phase 4): Requires Phase 3 (RuleEngineService)
- **US-RISK-007–009** (Phase 5): Requires Phase 3 (RiskEvaluation model for manual decisions)
- **US-RISK-010–015** (Phase 6): Requires Phase 2 only (RiskRule model) — can start immediately after Phase 2
- **US-RISK-016–017** (Phase 7): Requires Phase 3 (VelocityAccumulatorService for history)
- **US-RISK-018** (Phase 8): Requires Phase 3 (RuleEngineService for threshold check)
- **US-RISK-019–021** (Phase 9): Requires Phase 5 (AlertService foundation)
- **US-RISK-022** (Phase 10): Requires Phase 9 (AlertService for alert linking)

### Within Each Phase

1. Service implementation before controller implementation
2. Command DTO before service consuming it
3. Request validation before controller method using it
4. Core service (`RiskEvaluatorService`) before controllers that call it

---

## Parallel Execution Examples

### Phase 2 Enums (all independent):
```
T009 RuleType  |  T010 RiskLevel  |  T011 RiskDecisionEnum
T012 AlertStatus  |  T013 AlertType  |  T014 HoldType  |  T015 HoldStatus  |  T016 SarStatus
```

### Phase 2 Migrations (all independent files):
```
T022 risk_rules  |  T023 risk_evaluations  |  T024 risk_events
T025 risk_alerts  |  T026 risk_alert_events + risk_decisions  |  T027–T031 remaining 5 tables
```

### Phase 3 (after T044 RuleEngineService + T045 VelocityAccumulatorService):
```
T047 Listeners  |  T048 Unit tests RuleEngine  |  T049 Unit tests Velocity
```

### Phase 6 Rule Config (after T071–T072 ConfigureRiskRuleCommand):
```
T073 perTxLimit  |  T074 dailyLimit  |  T075 monthlyLimit
T076 customerLimits  |  T077 agentLimits  |  T078 walletLimits
```

---

## Implementation Strategy

### MVP First (US-RISK-001–004 only)

1. Complete Phase 1 (Setup) — ~30 min
2. Complete Phase 2 (Foundational) — ~2 hours (parallelisable)
3. Complete Phase 3 (Core Evaluation US-RISK-001–004) — ~3 hours
4. **VALIDATE SC-1 and SC-2**: `POST /v1/risk/evaluate-transaction-risk` working with limit enforcement and idempotency
5. **MVP complete** — other transaction modules can now integrate with `RiskEvaluatorInterface`

### Incremental Delivery

After MVP:
- Phase 6 (Rule Configuration) — enables self-service limit management
- Phase 5 (Manual Decisions) — enables compliance officer workflow
- Phase 9 (Alert Lifecycle) — enables full case management
- Phase 4 (IP + Beneficiary / Watchlist) — adds screening capability
- Phase 7 (Pattern Detection) — adds AML pattern analysis
- Phase 8 (Auth Failure) — adds brute-force detection
- Phase 10 (Account Freeze) — completes enforcement capability
- Phase 11 (Polish) — production readiness

---

## Notes

- `[P]` = parallelizable (different files, no same-phase dependency)
- `[USN]` maps to US-RISK-00N from spec.md
- All amount comparisons use BCMATH (`bccomp`, `bcadd`) — never float arithmetic
- `RiskEvent` and `RiskEvaluation` are append-only; enforce this in model boot (constitution P4)
- Idempotency: every state-changing endpoint checks for existing record by idempotency key before inserting
- Optimistic locking on `RiskAlert.version` — always read version before writing; pass version in request
- Run `php artisan migrate` after completing all of Phase 2 before starting Phase 3
- PHPStan must be run and pass (level 6) before release
