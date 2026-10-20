# Implementation Plan — E18 Fraud, Risk & AML

## Constitution Check

- [x] Approved Laravel/Next.js/Flutter stack respected — backend is Laravel/PHP 8.4+; no stack change
- [x] No TBD value invented — all thresholds configurable via RiskRule; regulatory jurisdiction confirmed as Botswana FIA
- [x] Authorization remains server-side — all endpoints require `bearerAuth`; permissions seeded via E04 infrastructure
- [x] Financial integrity preserved — AccountHold does not mutate ledger; evaluation records are immutable after creation
- [x] Security/audit requirements incorporated — `risk.*` audit events on evaluation, hold, alert lifecycle transitions
- [x] UX/design-system requirements incorporated — service-layer module; admin UI consumption deferred to E28/admin epic
- [x] QA strategy incorporated — 10 quickstart scenarios; unit, integration, and concurrency tests specified in tasks

## Technical Context

- **Backend:** Laravel / PHP 8.4+
- **Module path:** `App\Modules\Risk\`
- **Database:** PostgreSQL — `risk` schema; 10 tables (see data-model.md)
- **Queue/cache:** Redis + Laravel Horizon — async detection jobs on `risk` queue
- **API:** REST/OpenAPI — 3 contract files in `contracts/`
- **Testing:** PHPUnit/Pest — unit for evaluator, integration for API, concurrency for idempotency
- **Observability:** structured logs with `correlation_id`; StatsD/Prometheus metrics; AuditServiceInterface
- **Dependencies (consumed):**

  | Module | Contract | Used For |
  |--------|----------|----------|
  | E03 Device & Session | Request headers | `device_id`, `ip_address` in evaluation context |
  | E04 Permissions | Permission seeder infrastructure | `risk.*` permission set |
  | E05 KYC | `KycModuleContract::getKycTierForUser()` | KYC tier snapshot at evaluation time |
  | E06 Wallet | `WalletModuleContract::getWalletSummary()` | Wallet balance context for limit checks |
  | E08 Funding | `FundingTransactionCompleted` event | Velocity accumulation listener |
  | E09 Transfer | `TransferCompleted` event | Rapid movement detection listener |
  | E10 Withdrawal | `WithdrawalCompleted` event | Withdrawal velocity listener |
  | E12 Agent | `AgentModuleContract::getAgentProfile()` | Agent tier for limit thresholds |
  | E14 Commission | `CommissionPaidEvent` event | Commission velocity listener |
  | E15 Bill Payments | `ServicePaymentCompletedEvent` event | Bill payment velocity listener |
  | E16 QR Payments | `QrPaymentCompletedEvent` event | QR payment velocity and pattern listener |
  | E28 Config | `ConfigurationServiceInterface` | Risk threshold overrides |

- **Integration contract (published by this module):**
  - `RiskEvaluatorInterface::checkPreTransaction(TransactionRiskContext): RiskEvaluationResult`
  - Called synchronously by E08–E16 before posting transactions
  - Must return within 200ms p99

## Module Tree

```
App\Modules\Risk\
├── Domain\
│   ├── Contracts\
│   │   ├── RiskEvaluatorInterface.php          ← checkPreTransaction()
│   │   ├── WatchlistScreenerInterface.php      ← screen(), screenRetrospectively()
│   │   ├── FiaReportingInterface.php           ← stub: submit(SAR): string; real FIA portal integration deferred to E28/E1000
│   │   └── RiskMetricsInterface.php
│   ├── Enums\
│   │   ├── RuleType.php
│   │   ├── RiskLevel.php
│   │   ├── RiskDecisionEnum.php
│   │   ├── AlertStatus.php
│   │   ├── AlertType.php
│   │   ├── HoldType.php
│   │   ├── HoldStatus.php
│   │   └── SarStatus.php
│   ├── Models\
│   │   ├── RiskRule.php
│   │   ├── RiskEvaluation.php
│   │   ├── RiskEvent.php
│   │   ├── RiskAlert.php
│   │   ├── RiskAlertEvent.php
│   │   ├── RiskDecision.php
│   │   ├── WatchlistEntry.php
│   │   ├── WatchlistMatch.php
│   │   ├── AccountHold.php
│   │   └── SuspiciousActivityReport.php
│   └── ValueObjects\
│       ├── TransactionRiskContext.php
│       └── RiskEvaluationResult.php
├── Application\
│   ├── Commands\
│   │   ├── EvaluateTransactionRiskCommand.php
│   │   ├── ConfigureRiskRuleCommand.php
│   │   ├── CreateRiskAlertCommand.php
│   │   ├── InvestigateAlertCommand.php
│   │   ├── ResolveAlertCommand.php
│   │   └── FreezeAccountCommand.php
│   ├── Services\
│   │   ├── RiskEvaluatorService.php            ← orchestrates sync evaluation
│   │   ├── RuleEngineService.php               ← applies active rules to context
│   │   ├── VelocityAccumulatorService.php      ← Redis-backed rolling window sums
│   │   ├── WatchlistScreenerService.php        ← fuzzy-match against watchlist_entries
│   │   ├── AlertService.php                    ← alert CRUD + lifecycle transitions
│   │   ├── AccountHoldService.php              ← hold placement + enforcement check
│   │   └── RiskMetricsService.php
│   ├── Jobs\
│   │   ├── DetectUnusualPatternJob.php         ← async, 30-day lookback
│   │   ├── DetectRapidFundMovementJob.php      ← async, 24-hour window
│   │   └── EscalateOverdueAlertsJob.php        ← scheduled SLA enforcement
│   └── Listeners\
│       ├── OnFundingTransactionCompleted.php
│       ├── OnTransferCompleted.php
│       ├── OnWithdrawalCompleted.php
│       ├── OnCommissionPaid.php
│       ├── OnServicePaymentCompleted.php
│       └── OnQrPaymentCompleted.php
├── Infrastructure\
│   ├── Http\
│   │   ├── Controllers\
│   │   │   ├── RiskEvaluationController.php
│   │   │   ├── RiskRuleController.php
│   │   │   ├── RiskAlertController.php
│   │   │   ├── SarController.php               ← SAR approve/submit workflow (Phase 10b)
│   │   │   └── WatchlistController.php         ← store/update/deactivate watchlist entries (T124)
│   │   ├── Requests\
│   │   │   └── [one FormRequest per endpoint]
│   │   └── Resources\
│   │       └── [one ApiResource per response schema]
│   ├── Adapters\
│   │   └── FiaReportingStub.php                ← stub for FIA portal; deferred to E28/E1000
│   ├── Persistence\
│   │   └── Migrations\
│   │       ├── 2026_09_26_000001_create_risk_rules_table.php
│   │       ├── 2026_09_26_000002_create_risk_evaluations_table.php
│   │       ├── 2026_09_26_000003_create_risk_events_table.php
│   │       ├── 2026_09_26_000004_create_risk_alerts_table.php
│   │       ├── 2026_09_26_000005_create_risk_alert_events_table.php
│   │       ├── 2026_09_26_000006_create_risk_decisions_table.php
│   │       ├── 2026_09_26_000007_create_watchlist_entries_table.php
│   │       ├── 2026_09_26_000008_create_watchlist_matches_table.php
│   │       ├── 2026_09_26_000009_create_account_holds_table.php
│   │       └── 2026_09_26_000010_create_suspicious_activity_reports_table.php
│   └── Providers\
│       └── RiskServiceProvider.php
└── routes\
    └── risk.php
```

## Phase 0 — Research

Complete. See `research.md`. All 5 unknowns resolved:
1. **Sync/async model:** Hybrid — sync for limits/watchlist/auth-failure; async for pattern detection
2. **Jurisdiction:** Botswana FIA (Financial Intelligence Act, 2009)
3. **Latency:** <200ms p99 sync; <5 minutes async
4. **Cross-epic dependencies:** Listed in Technical Context table above
5. **Edge cases:** Evaluation unavailable → default-allow + alert; optimistic locking on alert updates

## Phase 1 — Design

Complete. Artifacts:
- `data-model.md` — 10 entities with full column specs
- `contracts/risk-evaluation.yaml` — US-RISK-001 through US-RISK-009
- `contracts/risk-rules.yaml` — US-RISK-010 through US-RISK-015
- `contracts/risk-detection-alerts.yaml` — US-RISK-016 through US-RISK-022
- `quickstart.md` — SC-1 through SC-10

## Implementation Approach

1. Confirm consumed contracts (KycModuleContract, AgentModuleContract, etc.) via interface inspection in prior modules.
2. Scaffold `RiskServiceProvider`, route file, and module folder structure.
3. Run migrations to create the `risk` schema tables.
4. Implement `RiskEvaluatorInterface` and `RuleEngineService` (sync evaluation core).
5. Implement `VelocityAccumulatorService` backed by Redis sorted sets with TTL.
6. Implement `WatchlistScreenerService` using PostgreSQL `pg_trgm` similarity.
7. Implement alert lifecycle services (`AlertService`, `AccountHoldService`).
8. Implement async detection jobs (`DetectUnusualPatternJob`, `DetectRapidFundMovementJob`).
9. Register event listeners for E08–E16 events.
10. Implement HTTP layer: controllers, form requests, resources.
11. Register `EscalateOverdueAlertsJob` as a scheduled command.
12. Write tests (unit + integration + concurrency for idempotency).
13. Emit OpenAPI from route annotations or generate spec stub.

## Data / Financial Controls

- `RiskEvent` is **append-only** — no UPDATE or DELETE after insert (enforced in `RiskEvent::booted()`)
- `AccountHold` does **not** post any ledger entry — hold enforcement is a pre-check in the wallet/transaction pipeline
- `RiskEvaluation` UNIQUE on `idempotency_key` — concurrent duplicate submissions return the existing record
- `RiskAlert` uses optimistic locking (`version` column) — 409 on stale writes; caller must re-fetch and retry
- `SuspiciousActivityReport` requires approver_id before `status = submitted` (maker-checker)
- `RiskDecision` requires `checker_id` for `freeze_account` and `file_sar` decisions
- `RuleType.VelocityCount` is used internally by auth-failure detection (T088–T090); there is no dedicated HTTP endpoint to configure count-based velocity thresholds in E18 scope — the threshold is read from the `AuthFailureCount` RiskRule seeded by `RiskPermissionSeeder`. Count-based rule configuration for other contexts is deferred to E28.

## Audit Events

| Event Key | Trigger | Actor Type |
|-----------|---------|------------|
| `risk.evaluation.allow` | EvaluatorService returns `allow` | system |
| `risk.evaluation.block` | EvaluatorService returns `block` | system |
| `risk.evaluation.review` | EvaluatorService returns `review` | system |
| `risk.evaluation.unavailable` | Evaluation service fault — default-allow applied | system |
| `risk.watchlist.match` | WatchlistScreenerService detects a match | system |
| `risk.alert.created` | AlertService creates alert (auto or manual) | system or officer |
| `risk.alert.assigned` | InvestigateAlertCommand transitions to `under_investigation` | officer |
| `risk.alert.resolved` | ResolveAlertCommand sets terminal status | officer |
| `risk.account.frozen` | AccountHoldService places hold | officer (maker + checker) |
| `risk.account.hold_lifted` | AccountHoldService lifts hold | officer |
| `risk.rule.created` | RiskRule created | officer |
| `risk.rule.updated` | RiskRule updated | officer |
| `risk.rule.deactivated` | RiskRule soft-disabled | officer |
| `risk.sar.approved` | SAR approved by second officer (maker-checker) | officer |
| `risk.sar.submitted` | SAR submitted to FIA | officer |
| `risk.sar.submission_failed` | SAR FIA submission failed; draft preserved for retry | system |

## Story Event → Audit Key Mapping (B1)

The spec stories use canonical `US_RISK_NNN_REQUESTED / SUCCEEDED / FAILED` event notation. The table below maps each story event to the concrete `risk.*` audit event key emitted by the implementation.

| Story Event | Concrete Audit Key | Notes |
|-------------|-------------------|-------|
| US_RISK_001_SUCCEEDED | `risk.evaluation.allow` / `risk.evaluation.block` / `risk.evaluation.review` | One of three keys depending on decision |
| US_RISK_001_FAILED | `risk.evaluation.unavailable` | Default-allow applied on service fault |
| US_RISK_002_SUCCEEDED | `risk.evaluation.allow` / `risk.evaluation.block` / `risk.evaluation.review` | Velocity sub-evaluation |
| US_RISK_003_SUCCEEDED | `risk.evaluation.allow` / `risk.evaluation.block` / `risk.evaluation.review` | Amount threshold sub-evaluation |
| US_RISK_004_SUCCEEDED | `risk.evaluation.allow` / `risk.evaluation.review` | Device risk sub-evaluation |
| US_RISK_005_SUCCEEDED | `risk.evaluation.allow` / `risk.evaluation.review` | IP risk sub-evaluation |
| US_RISK_006_SUCCEEDED | `risk.evaluation.allow` / `risk.evaluation.review` | Beneficiary risk sub-evaluation |
| US_RISK_007_SUCCEEDED | `risk.evaluation.allow` | Manual allow decision |
| US_RISK_008_SUCCEEDED | `risk.evaluation.block` | Manual block decision |
| US_RISK_009_SUCCEEDED | `risk.alert.created` | Alert created for manual review |
| US_RISK_010_SUCCEEDED | `risk.rule.created` / `risk.rule.updated` | Per-transaction limit rule upsert |
| US_RISK_011_SUCCEEDED | `risk.rule.created` / `risk.rule.updated` | Daily limit rule upsert |
| US_RISK_012_SUCCEEDED | `risk.rule.created` / `risk.rule.updated` | Monthly limit rule upsert |
| US_RISK_013_SUCCEEDED | `risk.rule.created` / `risk.rule.updated` | Customer limit override |
| US_RISK_014_SUCCEEDED | `risk.rule.created` / `risk.rule.updated` | Agent limit override |
| US_RISK_015_SUCCEEDED | `risk.rule.created` / `risk.rule.updated` | Wallet limit override |
| US_RISK_016_SUCCEEDED | `risk.alert.created` | Unusual pattern confirmed, alert raised |
| US_RISK_017_SUCCEEDED | `risk.alert.created` | Rapid fund movement confirmed, alert raised |
| US_RISK_018_SUCCEEDED | `risk.alert.created` | Auth failure threshold exceeded, alert raised |
| US_RISK_019_SUCCEEDED | `risk.alert.created` | Manual alert created |
| US_RISK_020_SUCCEEDED | `risk.alert.assigned` | Alert moved to under_investigation |
| US_RISK_021_SUCCEEDED | `risk.alert.resolved` | Alert resolved with formal decision |
| US_RISK_021_SUCCEEDED (file_sar) | `risk.sar.approved` / `risk.sar.submitted` / `risk.sar.submission_failed` | SAR sub-lifecycle after file_sar decision |
| US_RISK_022_SUCCEEDED | `risk.account.frozen` | Account hold placed |
| US_RISK_022_SUCCEEDED (lift) | `risk.account.hold_lifted` | Account hold lifted |

All `_REQUESTED` story events are recorded implicitly via standard Laravel request logging; no separate audit record is emitted unless the evaluation succeeds or fails. `AUDIT_EVENT_RECORDED` in the spec refers to the AuditServiceInterface call that persists the above `risk.*` keys.

## Observability

**Metrics** (StatsD/Prometheus, no `.count` suffix):

| Metric | Labels | Description |
|--------|--------|-------------|
| `risk.evaluation.total` | `decision`, `risk_level`, `transaction_type` | Total evaluation count |
| `risk.evaluation.duration_ms` | `decision` | Histogram of evaluation wall time |
| `risk.rule.triggered` | `rule_name`, `rule_type` | Per-rule trigger count |
| `risk.alert.created` | `alert_type`, `severity` | New alerts |
| `risk.alert.resolved` | `decision`, `severity` | Resolved alerts |
| `risk.account.frozen` | `hold_type` | Freeze events |
| `risk.watchlist.screened` | `match_method` | Screening calls |
| `risk.pattern_job.executed` | `job_type`, `result` | Async detection job outcomes |

**Logging:** All evaluation requests logged at INFO level with `correlation_id`, `customer_id`, `decision`, `evaluation_ms`. Blocked or review decisions logged at WARNING.

**Tracing:** `correlation_id` propagated from incoming request → evaluation record → audit events → async jobs (via job payload).

**SLA monitoring:** `EscalateOverdueAlertsJob` runs every 15 minutes; emits `risk.alert.sla_breached` metric for any open alert past `sla_due_at`.
