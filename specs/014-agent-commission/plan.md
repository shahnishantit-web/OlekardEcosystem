# Implementation Plan — E14 Agent Commission
**Date**: 2026-09-23

---

## Constitution Check

- [x] Approved Laravel/Next.js/Flutter stack respected — PHP 8.4+, PostgreSQL, Redis, Laravel Horizon; new `Commission` module follows established module conventions; no deviations
- [x] No TBD value invented — `commission_expense:{currency}` reference read from `ConfigurationServiceInterface` at runtime; throws `CommissionExpenseAccountNotConfiguredException` if absent (TBD-E14-001). Default commission rates are not hard-coded; admin must configure rules before any commission is calculated (TBD-E14-002)
- [x] Authorization remains server-side — all 11 commission permissions seeded and enforced via Laravel Gate/Spatie middleware; agents cannot access admin endpoints; cross-agent reads blocked
- [x] Financial integrity preserved — all balance changes via `LedgerModuleContract::postEntry()`; no direct column mutation; idempotency on payout approval; row-level lock on `commission_payouts` during execution; compensating journals for reversals
- [x] Security/audit requirements incorporated — all financial mutations (payout, reversal, rule changes) emit audit records via `AuditServiceInterface`; idempotency via `IdempotencyServiceInterface`; outbox events via `EventPublisherInterface`; event consumers use `ProcessedEventRepositoryInterface`
- [x] UX/design-system requirements incorporated — N/A for this epic (backend API + domain layer only; Flutter/Next.js screens are future epics)
- [x] QA strategy incorporated — unit tests (rule selection, calculation service, state machine), feature tests (API contracts), concurrency tests (duplicate commission idempotency, duplicate payout idempotency, deferred resume replay); see `quickstart.md`

---

## Technical Context

- **Backend**: `App\Modules\Commission\` (new bounded module), Laravel / PHP 8.4+
- **Database**: PostgreSQL
  - `platform` connection: `commission.commission_rules`, `commission.commissions`, `commission.commission_payouts`, `commission.processed_events`
  - `platform` connection (cross-module extension): `agent.agents.agent_tier` column
- **Queue/cache**: Redis + Laravel Horizon (outbox event processing; listener jobs)
- **API**: REST/OpenAPI — see `contracts/api.yaml`
- **Testing**: PHPUnit/Pest — unit, feature, concurrency

---

## Dependencies

| Dependency | Direction | How consumed |
|-----------|-----------|--------------|
| E07 LedgerService | E14 depends on E07 | `LedgerModuleContract::postEntry()`, `getBalance()`, `registerAccount()` |
| E12 Agent Management | E14 depends on E12 | `agent.agents` table (agent status + `agent_tier`); listens to `agent.suspended` / `agent.reactivated` events |
| E13 Agent Float | E14 depends on E13 | `agent.float_wallets` (resolve `walletId` for payout journal); listens to `AgentFloat.CashIn.Completed` / `AgentFloat.CashOut.Completed` |
| E04 Permissions | E14 depends on E04 | Commission permissions seeded via migration; enforced via middleware |
| Platform Config | E14 depends on Platform | `ConfigurationServiceInterface` for `ledger.commission_expense_account.{currency}` |
| Platform IdempotencyService | E14 depends on Platform | `IdempotencyServiceInterface` — HTTP-level payout idempotency |
| Platform EventPublisher | E14 depends on Platform | `EventPublisherInterface` — outbox event publishing |
| Platform AuditService | E14 depends on Platform | `AuditServiceInterface` — financial and admin action logging |

---

## Module Structure

```
app/Modules/Commission/
├── Application/
│   └── Services/
│       ├── CommissionRuleService.php         (US1 — rule CRUD)
│       ├── CommissionCalculationService.php  (US2, US3 — calculate commission per event)
│       ├── CommissionPayoutService.php        (US7, US8 — approve+execute, deferred resume)
│       ├── CommissionReversalService.php      (US4 — compensating journal, reversal flow)
│       └── CommissionReportService.php        (US5, US6, US9 — paginated queries)
├── Domain/
│   ├── Enums/
│   │   ├── CommissionRuleType.php            (flat | percentage)
│   │   ├── CommissionAppliesTo.php           (cash_in | cash_out | both)
│   │   ├── CommissionRuleStatus.php          (active | superseded | expired)
│   │   ├── CommissionStatus.php              (pending | approved | paid | reversed)
│   │   └── CommissionPayoutStatus.php        (approved | completed | deferred | failed | cancelled)
│   ├── Events/
│   │   ├── CommissionRuleCreatedEvent.php
│   │   ├── CommissionCalculatedEvent.php
│   │   ├── CommissionPaidEvent.php
│   │   ├── CommissionReversedEvent.php
│   │   ├── CommissionPayoutApprovedEvent.php
│   │   ├── CommissionPayoutDeferredEvent.php
│   │   ├── CommissionPayoutFailedEvent.php
│   │   └── CommissionPayoutCancelledEvent.php
│   └── Exceptions/
│       ├── CommissionExpenseAccountNotConfiguredException.php
│       ├── CommissionRuleNotFoundException.php
│       ├── CommissionNotFoundException.php
│       ├── CommissionPayoutNotFoundException.php
│       ├── CommissionAlreadyProcessedException.php
│       ├── CommissionAlreadyReversedException.php
│       └── PayoutCancellationNotAllowedException.php
└── Infrastructure/
    ├── Http/
    │   ├── Controllers/
    │   │   ├── CommissionRuleController.php          (CRUD rules)
    │   │   ├── AgentCommissionController.php          (agent-scoped list, pending, report)
    │   │   ├── CommissionPayoutController.php         (approve, get, cancel)
    │   │   ├── CommissionReversalController.php       (reverse)
    │   │   ├── AgentCommissionTierController.php      (PATCH commission-tier)
    │   │   └── CommissionReportController.php         (admin cross-agent report)
    │   ├── Requests/
    │   │   ├── CreateCommissionRuleRequest.php
    │   │   ├── UpdateCommissionRuleRequest.php
    │   │   ├── ApproveCommissionPayoutRequest.php
    │   │   ├── ReverseCommissionRequest.php
    │   │   ├── SetAgentTierRequest.php
    │   │   └── CommissionReportRequest.php
    │   └── Resources/
    │       ├── CommissionRuleResource.php
    │       ├── CommissionResource.php
    │       ├── CommissionPayoutResource.php
    │       └── PendingCommissionsSummaryResource.php
    ├── Listeners/
    │   ├── FloatCashInCommissionListener.php   (listens: AgentFloat.CashIn.Completed)
    │   ├── FloatCashOutCommissionListener.php  (listens: AgentFloat.CashOut.Completed)
    │   └── AgentReactivatedCommissionListener.php (listens: agent.reactivated)
    ├── Migrations/
    │   ├── 2026_09_30_014000_add_agent_tier_to_agents.php      (platform — cross-module extension)
    │   ├── 2026_09_30_014100_create_commission_rules.php       (platform)
    │   ├── 2026_09_30_014200_create_commissions.php            (platform)
    │   ├── 2026_09_30_014300_create_commission_payouts.php     (platform)
    │   ├── 2026_09_30_014400_create_commission_processed_events.php (platform)
    │   └── 2026_09_30_014500_seed_commission_permissions.php   (platform)
    ├── Models/
    │   ├── CommissionRuleModel.php            (commission.commission_rules)
    │   ├── CommissionModel.php                (commission.commissions)
    │   └── CommissionPayoutModel.php          (commission.commission_payouts)
    ├── Repositories/
    │   └── CommissionProcessedEventRepository.php  (implements ProcessedEventRepositoryInterface)
    └── Providers/
        └── CommissionServiceProvider.php
```

---

## Ledger Account Naming

| Account | `ledger_account_reference` pattern | `ownerType` | `accountType` |
|---------|-------------------------------------|-------------|---------------|
| Platform commission expense (TBD-E14-001) | `commission_expense:{currencyCode}` — read from `ConfigurationServiceInterface` | `system_commission` | `expense` |
| Agent commission payable | `commission_payable:{agentId}` | `agent_commission` | `liability` |
| Agent float wallet | `agent_float:{walletId}` — reference resolved from E13 `agent.float_wallets` | (registered by E13) | (registered by E13) |

---

## Journal Posting Patterns

All amounts in minor units. See `data-model.md` for full journal structures.

**Commission accrual** (at calculation time):
```
debit:  commission_expense:{currencyCode}    amount: X
credit: commission_payable:{agentId}         amount: X
transaction_reference: "commission_accrual:{commissionId}"
```

**Commission payout** (at execution time):
```
debit:  commission_payable:{agentId}         amount: X
credit: agent_float:{walletId}               amount: X
transaction_reference: "commission_payout:{payoutId}"
```

**Commission reversal — pending** (restores accrual):
```
debit:  commission_payable:{agentId}         amount: X
credit: commission_expense:{currencyCode}    amount: X
transaction_reference: "commission_reversal:{commissionId}"
```

**Commission reversal — paid** (restores accrual + claws back float):
```
Journal 1:
  debit:  commission_payable:{agentId}       amount: X
  credit: commission_expense:{currencyCode}  amount: X
  transaction_reference: "commission_reversal_accrual:{commissionId}"

Journal 2:
  debit:  agent_float:{walletId}             amount: X
  credit: commission_payable:{agentId}       amount: X
  transaction_reference: "commission_reversal_float:{commissionId}"
```

---

## Commission Calculation Flow

`CommissionCalculationService.calculate(agentId, transactionId, transactionType, amount, currencyCode, walletId)`:

1. Check `ProcessedEventRepositoryInterface::hasBeenProcessed(eventName, eventId)` — return if duplicate
2. Check agent status via `AgentModel` — if suspended, return (no commission for suspended agents)
3. Resolve commission expense account from `ConfigurationServiceInterface` — throw `CommissionExpenseAccountNotConfiguredException` if absent
4. Look up applicable commission rule (tier-scoped first, then global; most-recently-created wins among same-specificity active rules matching date range and `applies_to`)
5. If no rule found — skip silently (no commission)
6. Calculate amount: flat → `rule.rate`; percentage → `floor(amount * rule.rate / 10000)`
7. Compute `idempotency_key = "{transactionId}:{transactionType}"`
8. `BEGIN` platform-connection DB transaction
9. Lazy-register `commission_payable:{agentId}` via `LedgerModuleContract::registerAccount()` if not yet registered
10. `LedgerModuleContract::postEntry()` — post accrual journal; capture `journalId`
11. INSERT into `commission.commissions` — catch `UniqueConstraintViolation` on `idempotency_key` and return existing record (inner idempotency guard)
12. `COMMIT`
13. `ProcessedEventRepositoryInterface::markAsProcessed(eventName, eventId)`
14. Publish `CommissionCalculatedEvent` via `EventPublisherInterface`

---

## Payout Execution Flow

`CommissionPayoutService.approveAndExecute(adminId, agentId, commissionIds, idempotencyKey)`:

1. `IdempotencyServiceInterface::check(idempotencyKey)` — if existing payout found:
   - If `failed` → allow re-execution (continue below with existing payout record)
   - Otherwise → return cached response
2. Validate all `commissionIds` have `status = 'pending'` — throw `CommissionAlreadyProcessedException` if not
3. `BEGIN` platform-connection DB transaction
4. Resolve agent status via `AgentModel`
5. If agent suspended:
   - Create `CommissionPayout(status: deferred)` + set `commission.status = 'approved'`
   - `COMMIT`
   - Publish `CommissionPayoutDeferredEvent`
   - Return
6. Resolve float wallet ID from `agent.float_wallets WHERE agent_id = ?`
7. Calculate `totalAmount = sum(commission.amount for commissionIds)`
8. Create `CommissionPayout(status: approved)` if new; update existing `failed` record
9. Try `LedgerModuleContract::postEntry(payout journal)`:
   - On success → set `payout.journal_id`, `payout.executed_at`, `payout.status = 'completed'`; set all commissions `status = 'paid'`, `payout_id = payout.id`
   - On failure → set `payout.status = 'failed'`; commissions remain `pending`; `COMMIT`; publish `CommissionPayoutFailedEvent`; return failed payout
10. `COMMIT`
11. Publish `CommissionPayoutApprovedEvent` and `CommissionPaidEvent` via `EventPublisherInterface`
12. Audit log via `AuditServiceInterface`

---

## Deferred Payout Auto-Resume

`AgentReactivatedCommissionListener.handle(AgentReactivatedEvent)`:
1. Check `ProcessedEventRepositoryInterface::hasBeenProcessed(event_name, event_id)` — skip if duplicate
2. Query `commission_payouts WHERE agent_id = ? AND status = 'deferred'`
3. For each deferred payout:
   - Call `CommissionPayoutService.executeDeferred(payoutId)` (same execution flow as steps 6–11 above, without the agent-status check)
4. `ProcessedEventRepositoryInterface::markAsProcessed(event_name, event_id)`

---

## API Routes

```php
// CommissionServiceProvider::boot()
Route::prefix('v1')->middleware(['identity.auth'])->group(function () {
    // Commission rules (admin only)
    Route::post  ('commission-rules',                     [CommissionRuleController::class, 'store']);
    Route::get   ('commission-rules',                     [CommissionRuleController::class, 'index']);
    Route::get   ('commission-rules/{ruleId}',            [CommissionRuleController::class, 'show']);
    Route::patch ('commission-rules/{ruleId}',            [CommissionRuleController::class, 'update']);
    Route::delete('commission-rules/{ruleId}',            [CommissionRuleController::class, 'destroy']);

    // Agent commission views (agent + admin)
    Route::get   ('agents/{agentId}/commissions',         [AgentCommissionController::class, 'index']);
    Route::get   ('agents/{agentId}/commissions/pending', [AgentCommissionController::class, 'pending']);

    // Agent commission tier (admin only)
    Route::patch ('agents/{agentId}/commission-tier',     [AgentCommissionTierController::class, 'update']);

    // Commission reversal (admin only)
    Route::post  ('commissions/{commissionId}/reverse',   [CommissionReversalController::class, 'reverse']);

    // Commission payouts (admin only)
    Route::post  ('commission-payouts',                   [CommissionPayoutController::class, 'store']);
    Route::get   ('commission-payouts/{payoutId}',        [CommissionPayoutController::class, 'show']);
    Route::delete('commission-payouts/{payoutId}',        [CommissionPayoutController::class, 'cancel']);

    // Admin cross-agent report
    Route::get   ('commissions/report',                   [CommissionReportController::class, 'index']);
});
```

---

## Event/Listener Registration

```php
// In CommissionServiceProvider
protected array $listen = [
    \App\Modules\AgentFloat\Domain\Events\FloatCashInEvent::class   => [FloatCashInCommissionListener::class],
    \App\Modules\AgentFloat\Domain\Events\FloatCashOutEvent::class  => [FloatCashOutCommissionListener::class],
    \App\Modules\Agent\Domain\Events\AgentReactivatedEvent::class   => [AgentReactivatedCommissionListener::class],
];
```

Event names consumed:
- `AgentFloat.CashIn.Completed` → triggers commission calculation for cash-in
- `AgentFloat.CashOut.Completed` → triggers commission calculation for cash-out
- `agent.reactivated` → triggers deferred payout execution

---

## Idempotency Key Namespacing

| Operation | Namespace |
|-----------|-----------|
| Commission calculation (inner DB guard) | `{transactionId}:{transactionType}` (UNIQUE constraint on `commissions.idempotency_key`) |
| Commission payout approval | HTTP `Idempotency-Key` header; stored in `commission_payouts.idempotency_key` |
| Commission reversal | HTTP `Idempotency-Key` header; stored via `IdempotencyServiceInterface` |
| Rule creation | HTTP `Idempotency-Key` header; stored via `IdempotencyServiceInterface` |

---

## Implementation Approach

1. Migrations — `agent_tier` cross-module column + 5 new tables + permissions
2. Domain layer — enums, events, exceptions
3. Infrastructure models — `CommissionRuleModel`, `CommissionModel`, `CommissionPayoutModel`
4. `CommissionProcessedEventRepository` — implements `ProcessedEventRepositoryInterface`
5. Application services — calculation → payout → reversal → report → rule CRUD
6. Controllers, requests, API resources
7. Event listeners for E13 float events + E12 agent reactivation
8. Service provider + route registration + listener registration
9. Unit tests (rule selection logic, calculation logic, state machine, idempotency guards)
10. Feature tests (API contracts — all 14 endpoints)
11. Concurrency tests (duplicate commission, duplicate payout, deferred replay)
12. OpenAPI annotations on controllers

---

## Data / Financial Controls

- Immutable ledger entries (via `LedgerModuleContract` — no direct journal edits)
- `commission.commissions.idempotency_key` UNIQUE — prevents duplicate commission records
- `commission.commission_payouts.idempotency_key` UNIQUE — prevents duplicate payout approvals
- `commission.processed_events.(event_name, event_id)` UNIQUE — consumer-side idempotency for listener replay
- `commission_expense:{currency}` account reference read from config, never hard-coded (TBD-E14-001)
- Row-level guard on `CommissionPayoutService.approveAndExecute()` via idempotency check before any DB writes
- Commission status validated before payout inclusion — no `approved/paid/reversed` commissions in new batch
- Reversal compensating journals posted atomically within DB transaction; commission transitions in same transaction
- All `commission.commissions` records must have non-null `journal_id`, `transaction_id`, `rule_id` (enforced by NOT NULL constraints)

---

## Observability

- Structured logs on all service operations (rule creation, commission calculation, payout execution, reversal, deferred resume)
- Metrics: `commission.calculated`, `commission.payout.completed`, `commission.payout.deferred`, `commission.payout.failed`, `commission.reversed`
- Audit records for: rule creation/update/deactivation, payout approval, payout cancellation, commission reversal, agent tier change
- Correlation IDs propagated from HTTP request header and event payload through all service calls
- `CommissionLedgerPostFailed` event emitted when commission expense account is not configured — allows alerting

---

## Complexity Tracking

No architecture deviations. Commission module follows established patterns from E08, E12, E13. Agent tier cross-module extension follows E13's `wallet_type` precedent. Any future change to the approved stack requires an ADR.

**Key research findings** (see `research.md`):
- Agent tier gap resolved: E14 adds `agent_tier VARCHAR(50) NULL` to `agent.agents` via migration
- Journal patterns follow spec verbatim (FR-E14-006, FR-E14-010, FR-E14-014)
- Monthly volume aggregation from `commission.commissions` — no separate counter table at initial scale
- Commission reversal is admin-triggered (endpoint), not event-driven from E13

---

## Phase 0 Output

- `research.md` — all NEEDS CLARIFICATION resolved; agent tier gap resolved; journal convention aligned

## Phase 1 Output

- `data-model.md` — full schema for 4 new tables + 1 cross-module migration; ledger account definitions; journal patterns
- `contracts/api.yaml` — OpenAPI 3.1 spec for all 13 endpoints
- `quickstart.md` — 9 end-to-end validation scenarios; concurrency tests; performance targets
