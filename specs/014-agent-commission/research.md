# Research Notes — E14 Agent Commission
**Date**: 2026-09-23

---

## Decision 1: Module Namespace

**Decision**: `App\Modules\Commission\`

**Rationale**: Short, domain-scoped name consistent with existing modules (`AgentFloat`, `Wallet`, `Ledger`). "Commission" is the correct bounded-context name; "AgentCommission" would be overly verbose since all commissions in this system are agent commissions.

**Alternatives considered**: `AgentCommission` (rejected — redundant prefix), `Earnings` (rejected — ambiguous).

---

## Decision 2: Agent Tier Design Gap

**Finding**: E12 `AgentModel` (`app/Modules/Agent/Infrastructure/Models/AgentModel.php`) has no `agent_tier_id` or `tier` column. Only `status` (AgentStatus: active/suspended) and `agent_type` (AgentType: individual/business) exist. `AgentType` and `AgentStatus` enums do not include a tier concept.

**Decision**: E14 adds a nullable `agent_tier VARCHAR(50) NULL` column to `agent.agents` via a new migration in the Commission module (`platform` connection). `CommissionRule.agent_tier` is a nullable VARCHAR that is matched by value (string label), not by FK. No new lookup table is required.

**Rationale**: Avoids creating a separate `agent_tiers` lookup table in E14 (would require an ADR to expand E12 scope). String-label matching is consistent with the admin-configured, operations-driven nature of tier assignment described in the spec. The value space is controlled by admin via a new E14 endpoint (`PATCH /v1/agents/{agentId}/commission-tier`).

**Cross-module note**: The migration adding `agent_tier` to `agent.agents` uses the same cross-module schema extension pattern as E13 adding `wallet_type` to `wallet.wallets`. The column belongs to the `agent` schema but is managed by the Commission module.

**Alternatives considered**: Separate `agent.agent_tiers` lookup table (rejected — requires E12 scope change and ADR); `agent_type` reuse (rejected — `agent_type` is individual/business, not a commission tier).

---

## Decision 3: Commission Calculation Idempotency Key

**Decision**: The `idempotency_key` on `commission.commissions` is constructed as `{transaction_id}:{transaction_type}` (e.g., `uuid:cash_in`). A UNIQUE constraint on this column prevents duplicate commission records per transaction event, regardless of how many times the event listener fires.

**Rationale**: At-least-once event delivery requires consumer-side idempotency. The combination of `transaction_id + transaction_type` uniquely identifies a single qualifying event. This is cheaper than checking `ProcessedEventRepositoryInterface` for every calculation attempt — we use the DB UNIQUE constraint as the idempotency gate and catch `UniqueConstraintViolation` to return the existing record.

**Event-level idempotency**: The `ProcessedEventRepositoryInterface` is used at the LISTENER level to prevent the entire listener from running more than once per `(event_name, event_id)`. The DB constraint is an additional inner guard.

---

## Decision 4: Monthly Volume Aggregation

**Decision**: Monthly volume for tier evaluation is computed by aggregating from `commission.commissions` WHERE `agent_id = ? AND created_at >= start_of_current_month AND status != 'reversed'`. No separate counter/tally table is required at initial scale.

**Rationale**: At initial scale, the indexed query `(agent_id, created_at DESC)` on `commission.commissions` handles this efficiently. SC-E14-004 mandates the composite index. A denormalized tally would add synchronization complexity without measurable benefit until agent commission counts exceed hundreds of thousands per month.

**Alternatives considered**: Redis counter (rejected — adds operational complexity, cache invalidation risk); separate `monthly_volume` table (deferred — only if performance targets are missed post-launch).

---

## Decision 5: Commission Reversal Trigger

**Decision**: Commission reversal is triggered via an explicit admin API endpoint: `POST /v1/commissions/{commissionId}/reverse`. E13 does NOT currently emit a `FloatTransactionReversedEvent`; rather than requiring an E13 change, E14 exposes an admin-callable reversal endpoint. The endpoint is authorized with `commission.reverse` permission.

**Rationale**: Adding a reversal event to E13 would require a cross-module change outside E14's scope and without an approved spec change for E13. The admin endpoint approach is the minimal, spec-compliant implementation. FR-E14-014 mandates the compensating journal behavior regardless of trigger mechanism; the trigger mechanism is an implementation detail.

**Future path**: When E13 emits a `FloatTransactionReversedEvent`, E14 can add a listener without changing the core reversal logic.

---

## Decision 6: Journal Convention Alignment with E13

**Finding**: E13 plan.md registers `agent_float:{walletId}` as `accountType: 'asset'` (debit-normal). The spec defines the payout journal as `debit commission_payable:{agentId}` / `credit agent_float:{walletId}`. For a debit-normal asset, crediting it decreases the balance — however, the spec states the payout increases the agent's float. The spec's stated journals are internally consistent as mirror images (payout ↔ payout reversal), and FR-E14-010 explicitly defines `credit agent_float` as the payout mechanism.

**Decision**: Follow the spec's stated journals verbatim. Define `commission_payable:{agentId}` as `accountType: 'liability'` (credit-normal standard). Document the expected balance changes in the plan and validate via SC-E14-006 (total commission_expense debits = total commission_payable credits). Integration tests on the payout journal verify the actual float balance change is positive.

**Note**: If integration tests reveal the `credit agent_float` decreases the float balance, the fix is to swap the payout journal direction (to `debit agent_float / credit commission_expense_settlement`) — this is a test-discoverable issue, not a spec change.

---

## Decision 7: Payout Execution Strategy

**Decision**: Payout execution is synchronous within the `CommissionPayoutService::approveAndExecute()` call. The service:
1. Checks agent status via `AgentModel` (if suspended → set payout to `deferred`, emit `CommissionPayoutDeferred`, return)
2. Begins a platform-connection DB transaction
3. Resolves the agent's float wallet ID from E13 (`agent.float_wallets WHERE agent_id = ?`)
4. Posts the payout journal via `LedgerModuleContract::postEntry()`
5. Transitions all linked commissions to `paid` and payout to `completed`
6. Commits
7. Publishes `CommissionPaid` and `CommissionPayoutApproved` outbox events

**Idempotency**: `IdempotencyServiceInterface` gates the entire approval+execute call. For `failed` payout retry, the idempotency store returns the existing failed payout and allows re-execution.

**Note on failed retry**: When a `failed` payout is retried with the same `Idempotency-Key`, the idempotency service returns the existing `CommissionPayout` record. The service detects `status = failed` and re-attempts journal posting without creating a new payout record.

---

## Decision 8: Deferred Payout Auto-Resume

**Decision**: The `AgentReactivatedCommissionListener` listens to `AgentReactivatedEvent` (event name: `agent.reactivated`). On receipt, it queries for all `deferred` payouts for the reactivated agent and calls `CommissionPayoutService::executeDeferred()` for each. Uses `ProcessedEventRepositoryInterface` to prevent double-processing on event replay.

**Pattern**: Mirrors `AgentReactivatedFloatListener` in E13 (`app/Modules/AgentFloat/Infrastructure/Listeners/AgentReactivatedFloatListener.php`).

---

## Decision 9: Cross-Module Dependencies

| Dependency | How Consumed |
|-----------|--------------|
| E07 LedgerService | `LedgerModuleContract::postEntry()`, `registerAccount()`, `getBalance()` |
| E12 Agent Management | `agent.agents` table (agent status, agent_tier lookup); `AgentSuspendedEvent`, `AgentReactivatedEvent` |
| E13 Agent Float | `agent.float_wallets` (resolve walletId for payout); listens to `FloatCashIn.Completed`, `FloatCashOut.Completed` |
| E04 Permissions | Commission permissions seeded via migration, enforced via middleware |
| Platform Config | `ConfigurationServiceInterface` for `TBD-E14-001` (commission expense account reference) |

---

## Decision 10: No TBD Inversion

**TBD-E14-001** (commission expense ledger account): Read from `ConfigurationServiceInterface` at runtime with key `ledger.commission_expense_account.{currencyCode}`. Throws `CommissionExpenseAccountNotConfiguredException` if absent. **NOT hard-coded.**

**TBD-E14-002** (default commission rates): Not hard-coded. Seeded as `null`; admin must configure at least one active rule before any commission is calculated. A helper seed migration for development only (not production) may set example rates.

---

## Decision 11: Ledger Account Registration

| Account | Reference Pattern | `ownerType` | `accountType` |
|---------|-------------------|-------------|---------------|
| Commission expense (platform) | `commission_expense:{currencyCode}` — TBD-E14-001 | `system_commission` | `expense` |
| Commission payable (per agent) | `commission_payable:{agentId}` | `agent_commission` | `liability` |

Registration strategy:
- `commission_expense:{currency}` — registered once globally during module boot or first commission calculation; guarded by `entryExists()` check or `registerAccount()` idempotency.
- `commission_payable:{agentId}` — registered the first time a commission is calculated for a given agent; lazy registration inside a DB transaction.

---

## NEEDS CLARIFICATION Resolved

All `NEEDS CLARIFICATION` items from the spec have been resolved via `/speckit-clarify`. No open items remain.
