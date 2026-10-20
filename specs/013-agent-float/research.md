# Research: E13 — Agent Float Management
**Date**: 2026-09-21

---

## Decision 1 — Float wallet storage: extend `wallet.wallets` with `wallet_type` discriminator

**Decision**: Reuse the existing `wallet.wallets` table. Add a `wallet_type VARCHAR(20) NOT NULL DEFAULT 'customer'` column with check constraint `('customer', 'agent_float')`. Drop the existing partial unique index `wallets_owner_id_active_unique (owner_id) WHERE status <> 'closed'` and replace it with `wallets_owner_id_wallettype_active_unique (owner_id, wallet_type) WHERE status <> 'closed'`. This allows a user who is an agent to hold both a personal customer wallet and an agent float wallet simultaneously.

**Rationale**: The spec assumption is validated — `wallet.wallets` is the right home. The current schema has no `wallet_type` discriminator and a partial unique index that would block dual wallet ownership. The new compound unique index is the minimal schema change needed. All other `wallet.wallets` guarantees (FK, status check, idempotency) are preserved.

**Alternatives considered**:
- Separate `agent.agent_float_wallets` table: rejected — duplicates the wallet lifecycle machinery; harder to reuse WalletStateMachine.
- Use `agent.agents.id` as `owner_id`: rejected — `wallets_owner_id_fk` references `identity.users(id)`; using agent entity ID would violate the FK. Agent's `user_id` (which is in `identity.users`) must be used.

---

## Decision 2 — Float wallet reference table: `agent.float_wallets`

**Decision**: Create `agent.float_wallets` (platform connection) as a lightweight reference table: `id`, `agent_id` (FK → `agent.agents.id`), `wallet_id` (cross-schema ref to `wallet.wallets.id`), `ledger_account_reference` (VARCHAR, the account ref registered with E07 LedgerService). Unique constraint on `agent_id` (one float wallet per agent).

**Rationale**: Provides a direct `agent_id → wallet_id → ledger_account_reference` lookup on a single connection (platform) without scanning `wallet.wallets`. Keeps the float wallet data model self-contained within the agent-float module. The `wallet_id` column is a soft cross-schema reference (no DB-level FK to `wallet.wallets` since they're on different connections), consistent with E12's cross-schema patterns.

**Alternatives considered**:
- Lookup by `owner_id + wallet_type` in `wallet.wallets`: valid but requires two cross-connection hops for every balance check.
- Add `float_wallet_id` to `agent.agents`: creates a forward dependency; agents table was created in E12 and modification requires a new migration with nullable column or a circular dependency.

---

## Decision 3 — Overdraft prevention: row-level lock on `agent.float_wallets`

**Decision**: In `FloatCashInService`, begin a platform-connection transaction, acquire `SELECT ... FOR UPDATE` on the `agent.float_wallets` row for the target agent, read the float balance via `LedgerModuleContract::getBalance(ledgerAccountRef)`, validate `balance >= amount`, then post the journal (all within the same platform transaction). The FOR UPDATE lock on `agent.float_wallets` serializes concurrent cash-in requests for the same agent.

**Rationale**: All three operations (lock, balance read, ledger post) happen on the same platform DB connection. The lock is acquired before the balance check, so two concurrent cash-ins cannot both observe a sufficiently large balance — only one proceeds. Balance is derived from the immutable ledger (no direct balance column to race against). This is the lightest correct serialization point.

**Alternatives considered**:
- Advisory lock (pg_try_advisory_lock): would work but more complex to wire up; no benefit over row lock here.
- Optimistic lock with version column: requires retry loop; overdraft on float is a hard stop not a soft conflict.

---

## Decision 4 — LedgerService posting patterns (confirmed from E08 codebase)

**Decision**: All float financial operations use `LedgerModuleContract::postEntry()` (never direct balance mutation). The float wallet's ledger account is debit-normal (asset). Journal directions:

| Operation | Agent Float Account | Counterparty Account |
|-----------|--------------------|--------------------|
| Float funding (admin → agent) | `debit` (balance ↑) | `credit` platform funding account |
| Cash-in (agent float → customer pocket) | `credit` (balance ↓) | `debit` customer pocket account |
| Cash-out (customer pocket → agent float) | `debit` (balance ↑) | `credit` customer pocket account |

Float wallet `ledger_account_reference` is registered via `LedgerModuleContract::registerAccount()` at wallet creation time with `accountType = 'asset'`, `ownerType = 'agent_float'`.

**Rationale**: Confirmed by reading `FundingCreditService.php` (E08) which uses the same pattern (floatRef → 'credit', pocketRef → 'debit' for customer funding). The platform float account reference is read from config at runtime (key `ledger.agent_float_funding_account.{currency}`). This is a TBD-E13-001 operational value — E13 reads it from `ConfigurationServiceInterface` and throws if missing.

---

## Decision 5 — Float transactions: new `agent.float_transactions` table

**Decision**: Create `agent.float_transactions` (platform connection). Columns: `id`, `agent_id`, `wallet_id`, `type` (funding|cash_in|cash_out), `amount`, `currency_code`, `direction` (debit|credit from agent's perspective), `related_party_id` (customer UUID or admin UUID), `related_party_type` (customer|admin), `reference`, `journal_id` (cross-ref to ledger journal), `idempotency_key`, `status`, `created_at`.

**Rationale**: `transaction.transaction_projections` only accepts types `['funding','transfer','withdrawal','refund']` (enforced by `tp_type_check`). Float-mediated cash-in/cash-out are agent operations, not customer-facing transaction projections. A dedicated table keeps the float domain self-contained and allows agent-specific query patterns (filter by agent, date range, type).

**Alternatives considered**:
- Extend `tp_type_check` to add `cash_in`/`cash_out`: pollutes the customer-facing transaction model; violates E11 module boundary.
- Reuse `transaction.transaction_projections` with `source_module = 'agent_float'`: same problem — type check constraint prevents it without an E11 migration.

---

## Decision 6 — Float limits: new `agent.float_limits` table (not `wallet.wallet_limits`)

**Decision**: Create `agent.float_limits` (platform connection) with columns: `id`, `agent_id` (FK to `agent.agents`, UNIQUE), `max_balance` (INT nullable is_tbd), `per_transaction_limit` (INT nullable is_tbd), `daily_volume_limit` (INT nullable is_tbd), `low_float_threshold` (INT nullable is_tbd), `currency_code`, `effective_from`, `created_by`, `updated_by`, `created_at`, `updated_at`. A new record is created for each agent; PATCH endpoint upserts it.

**Rationale**: `wallet.wallet_limits` check constraint only permits `['daily_transaction_amount','weekly_transaction_amount','monthly_transaction_amount','single_transaction_amount','max_balance']` — `low_float_threshold` is absent and cannot be added without an E06 migration. Float limits are an agent-domain concept (per-agent, set by admin). A single-row-per-agent limits table is simpler to reason about than four EAV rows in `wallet.wallet_limits`.

**TBD-E13-002**: Default limit values (initial max_balance, per_tx, daily, low_float_threshold) are operational decisions not resolved by this spec. All `is_tbd = true` initially; the service MUST treat `is_tbd = true` fields as "no limit enforced for this dimension" until an admin explicitly sets a value.

---

## Decision 7 — Module placement: new `App\Modules\AgentFloat\`

**Decision**: Create a new bounded module `App\Modules\AgentFloat\` (not extending `App\Modules\Agent\`) under `wallet-ecosystem/services/api/app/Modules/AgentFloat/`. Service provider: `AgentFloatServiceProvider`. Routes registered under `/v1/agents/{agentId}/...`.

**Rationale**: Agent float has distinct domain objects, lifecycle events, and financial flows. A dedicated module preserves extraction-readiness (Principle 7). `App\Modules\Agent\` is already 80+ files and adding float management would blur bounded context.

---

## Decision 8 — Agent lifecycle listener: react to E12 events via outbox

**Decision**: `App\Modules\AgentFloat\Infrastructure\Listeners\AgentSuspendedListener` and `AgentReactivatedListener` subscribe to `agent.suspended` / `agent.reactivated` outbox events respectively. On `agent.suspended`: locate the agent's `agent.float_wallets` record and update `wallet.wallets.status = 'suspended'` for the float wallet. On `agent.reactivated`: restore `wallet.wallets.status = 'active'`. Emit `FloatWalletStatusChangedEvent` to outbox after each change.

**Rationale**: FR-E13-021 requires automatic float wallet suspension when agent is suspended. The AgentSuspendedEvent / AgentReactivatedEvent already exist in E12 and carry `agentId`. Listening via outbox events preserves module independence (no direct module coupling).

---

## Decision 9 — TBD-E13-001: platform liquidity counterparty

**Decision**: Do NOT invent a value. The `FloatFundingService` reads the platform liquidity account reference from `ConfigurationServiceInterface::get('ledger.agent_float_funding_account.BWP')` (and per currency in general). If the config key is absent (TBD), the service throws `FloatFundingAccountNotConfiguredException`. This follows E08's `FloatAccountNotConfiguredException` pattern exactly.

**Rationale**: Principle 2 — TBD values must not be invented. Operational teams set this after going live. The service is fully implemented and testable using a seeded config value in dev/test.

---

## Decision 10 — Idempotency key namespacing

**Decision**: Float operation idempotency keys are namespaced as:
- Float wallet creation: `float_wallet_create:{agentId}:{idempotencyKey}`
- Float funding: `float_fund:{agentId}:{idempotencyKey}`
- Cash-in: `float_cash_in:{agentId}:{customerId}:{idempotencyKey}`
- Cash-out: `float_cash_out:{agentId}:{customerId}:{idempotencyKey}`

These are stored in `platform.idempotency_requests` via `IdempotencyServiceInterface`, consistent with E08/E09 patterns.
