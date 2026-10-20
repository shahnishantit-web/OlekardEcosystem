# Data Model — E14 Agent Commission
**Date**: 2026-09-23

All tables use the `platform` database connection. Schema: `commission.*` for new tables; `agent.*` for the cross-module extension column.

---

## Tables

### `commission.commission_rules`

Defines commission structures for qualifying transaction types.

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| `id` | `UUID` | PK | UUID v7 |
| `rule_type` | `VARCHAR(12)` | NOT NULL | `'flat'` \| `'percentage'` |
| `rate` | `INT` | NOT NULL, CHECK > 0 | Minor units (flat); basis points (percentage) |
| `applies_to` | `VARCHAR(8)` | NOT NULL | `'cash_in'` \| `'cash_out'` \| `'both'` |
| `agent_tier` | `VARCHAR(50)` | NULL | Matches `agent.agents.agent_tier`; NULL = global rule for all agents |
| `status` | `VARCHAR(12)` | NOT NULL, DEFAULT `'active'` | `'active'` \| `'superseded'` \| `'expired'` |
| `valid_from` | `DATE` | NOT NULL | Inclusive start date |
| `valid_to` | `DATE` | NULL | Inclusive end date; NULL = open-ended |
| `volume_threshold` | `BIGINT` | NULL | Minimum agent cumulative monthly volume (minor units) to activate this tier rule; NULL = no threshold (always applies when tier matches) |
| `created_by` | `UUID` | NOT NULL | Admin actor ID |
| `created_at` | `TIMESTAMPTZ` | NOT NULL, DEFAULT NOW() | |
| `updated_at` | `TIMESTAMPTZ` | NOT NULL, DEFAULT NOW() | |

**Indexes:**
```sql
CREATE INDEX idx_commission_rules_lookup
    ON commission.commission_rules (applies_to, status, valid_from, valid_to)
    WHERE status = 'active';

CREATE INDEX idx_commission_rules_tier_lookup
    ON commission.commission_rules (agent_tier, applies_to, status)
    WHERE status = 'active' AND agent_tier IS NOT NULL;
```

**Rule Selection Logic:**
1. Filter by `status = 'active'` AND `valid_from <= NOW()::DATE` AND (`valid_to IS NULL` OR `valid_to >= NOW()::DATE`)
2. Filter by `applies_to IN ('both', <transaction_type>)`
3. Filter by `(agent_tier = <agent.agent_tier>)` OR `(agent_tier IS NULL)` — tier-scoped rules take precedence over global (null) rules
4. Filter by `(volume_threshold IS NULL OR volume_threshold <= <agent_monthly_volume>)` — select the highest qualifying `volume_threshold` (agent-favorable); NULL threshold always qualifies
5. Among same-specificity candidates, select the most recently created (`created_at DESC LIMIT 1`)

---

### `commission.commissions`

Records individual commission entries earned per qualifying transaction.

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| `id` | `UUID` | PK | UUID v7 |
| `agent_id` | `UUID` | NOT NULL | FK → `agent.agents.id` |
| `transaction_id` | `UUID` | NOT NULL | E13 `float_transactions.id` reference |
| `transaction_type` | `VARCHAR(8)` | NOT NULL | `'cash_in'` \| `'cash_out'` |
| `amount` | `INT` | NOT NULL, CHECK > 0 | Minor units |
| `currency_code` | `VARCHAR(3)` | NOT NULL | ISO 4217 |
| `rule_id` | `UUID` | NOT NULL | FK → `commission.commission_rules.id` |
| `status` | `VARCHAR(10)` | NOT NULL, DEFAULT `'pending'` | `'pending'` \| `'approved'` \| `'paid'` \| `'reversed'` |
| `payout_id` | `UUID` | NULL | FK → `commission.commission_payouts.id`; set on approval |
| `journal_id` | `UUID` | NOT NULL | E07 journal ID from accrual posting |
| `reversal_journal_id` | `UUID` | NULL | E07 journal ID from reversal posting |
| `idempotency_key` | `VARCHAR(255)` | NOT NULL | `{transaction_id}:{transaction_type}` |
| `created_at` | `TIMESTAMPTZ` | NOT NULL, DEFAULT NOW() | |
| `updated_at` | `TIMESTAMPTZ` | NOT NULL, DEFAULT NOW() | |

**Indexes:**
```sql
CREATE UNIQUE INDEX idx_commissions_idempotency
    ON commission.commissions (idempotency_key);

CREATE INDEX idx_commissions_agent_status
    ON commission.commissions (agent_id, status);

CREATE INDEX idx_commissions_agent_created
    ON commission.commissions (agent_id, created_at DESC);  -- SC-E14-004

CREATE INDEX idx_commissions_payout
    ON commission.commissions (payout_id)
    WHERE payout_id IS NOT NULL;

CREATE INDEX idx_commissions_agent_month
    ON commission.commissions (agent_id, created_at DESC)
    WHERE status != 'reversed';  -- monthly volume aggregation
```

**State Transitions:**
```
pending → approved  (included in an approved payout batch)
approved → paid     (payout execution succeeds)
approved → pending  (payout cancelled)
pending  → reversed (reversal via admin endpoint, commission was not paid)
paid     → reversed (reversal via admin endpoint, commission was paid — triggers float wallet debit)
```

---

### `commission.commission_payouts`

Records admin-approved commission payout batches.

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| `id` | `UUID` | PK | UUID v7 |
| `agent_id` | `UUID` | NOT NULL | FK → `agent.agents.id` |
| `total_amount` | `INT` | NOT NULL, CHECK > 0 | Minor units — sum of linked commissions |
| `currency_code` | `VARCHAR(3)` | NOT NULL | ISO 4217 |
| `status` | `VARCHAR(12)` | NOT NULL, DEFAULT `'approved'` | See state machine |
| `approved_by` | `UUID` | NOT NULL | Admin actor ID |
| `approved_at` | `TIMESTAMPTZ` | NOT NULL, DEFAULT NOW() | |
| `executed_at` | `TIMESTAMPTZ` | NULL | Set when status → `completed` |
| `cancelled_by` | `UUID` | NULL | Set when status → `cancelled` |
| `cancelled_at` | `TIMESTAMPTZ` | NULL | Set when status → `cancelled` |
| `journal_id` | `UUID` | NULL | E07 payout journal ID; set on execution |
| `idempotency_key` | `VARCHAR(255)` | NOT NULL | From `Idempotency-Key` HTTP header |
| `created_at` | `TIMESTAMPTZ` | NOT NULL, DEFAULT NOW() | |
| `updated_at` | `TIMESTAMPTZ` | NOT NULL, DEFAULT NOW() | |

**Indexes:**
```sql
CREATE UNIQUE INDEX idx_commission_payouts_idempotency
    ON commission.commission_payouts (idempotency_key);

CREATE INDEX idx_commission_payouts_agent_status
    ON commission.commission_payouts (agent_id, status);

CREATE INDEX idx_commission_payouts_deferred
    ON commission.commission_payouts (agent_id, status)
    WHERE status = 'deferred';
```

**State Machine:**
```
approved → completed  (payout journal posted, float credited)
approved → deferred   (agent suspended at execution time)
approved → failed     (ledger failure during execution)
deferred → completed  (auto-resume on AgentReactivatedEvent)
deferred → cancelled  (admin cancels — reverts linked commissions to pending)
failed   → completed  (admin retries with same Idempotency-Key, succeeds)
```

---

### `commission.processed_events`

Stores consumer-side event processing records. Prevents duplicate processing on at-least-once event delivery. Module-local instance (each module creates its own per constitution convention).

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| `id` | `BIGSERIAL` | PK | |
| `event_name` | `VARCHAR(100)` | NOT NULL | e.g., `AgentFloat.CashIn.Completed` |
| `event_id` | `UUID` | NOT NULL | Event's correlation/message ID |
| `processed_at` | `TIMESTAMPTZ` | NOT NULL, DEFAULT NOW() | |

**Indexes:**
```sql
CREATE UNIQUE INDEX idx_commission_processed_events
    ON commission.processed_events (event_name, event_id);
```

---

### `agent.agents` — Cross-Module Extension Column

E14 adds the `agent_tier` column to the existing E12 table via a new migration in the Commission module.

```sql
ALTER TABLE agent.agents
    ADD COLUMN IF NOT EXISTS agent_tier VARCHAR(50) NULL;

CREATE INDEX idx_agents_commission_tier
    ON agent.agents (agent_tier)
    WHERE agent_tier IS NOT NULL;
```

**Notes:**
- Value is a free-form admin-assigned string label (e.g., `'standard'`, `'premium'`, `'gold'`)
- Managed by Commission module endpoint: `PATCH /v1/agents/{agentId}/commission-tier`
- Soft reference: no FK to a `commission_tiers` lookup table
- NULL means the agent has no explicit tier; global commission rules apply

---

## Entity Relationships

```
commission_rules ──────┐
                        │ (rule_id FK)
commissions ───────────┤──── commission_payouts
     │                  │         (payout_id FK)
     │ (agent_id FK)    │
     └──────────────────┘
           │
     agent.agents (id)
           │ (agent_tier → agent.agents.agent_tier label match)
           └───────────────── commission_rules (agent_tier)
```

---

## Ledger Account Registration

| Account | Reference | `accountType` | `ownerType` |
|---------|-----------|---------------|-------------|
| Platform commission expense | `commission_expense:{currencyCode}` | `expense` | `system_commission` |
| Agent commission payable | `commission_payable:{agentId}` | `liability` | `agent_commission` |

**Registration timing:**
- `commission_expense:{currency}` — registered lazily on first commission calculation, or via seeder in dev
- `commission_payable:{agentId}` — registered the first time a commission is calculated for a given agent (inside DB transaction in `CommissionCalculationService`)

**Journal patterns:**

Commission accrual (at calculation):
```yaml
transaction_reference: "commission_accrual:{commissionId}"
entry_type: "standard"
lines:
  - ledger_account_reference: "commission_expense:{currencyCode}"
    direction: debit
    amount: X
  - ledger_account_reference: "commission_payable:{agentId}"
    direction: credit
    amount: X
```

Commission payout (at execution):
```yaml
transaction_reference: "commission_payout:{payoutId}"
entry_type: "standard"
lines:
  - ledger_account_reference: "commission_payable:{agentId}"
    direction: debit
    amount: X
  - ledger_account_reference: "agent_float:{walletId}"
    direction: credit
    amount: X
```

Commission reversal — pending commission (at reversal):
```yaml
transaction_reference: "commission_reversal:{commissionId}"
entry_type: "standard"
lines:
  - ledger_account_reference: "commission_payable:{agentId}"
    direction: debit
    amount: X
  - ledger_account_reference: "commission_expense:{currencyCode}"
    direction: credit
    amount: X
```

Commission reversal — paid commission (two journals):
```yaml
# Journal 1: reverse accrual
transaction_reference: "commission_reversal_accrual:{commissionId}"
lines:
  - { ledger_account_reference: "commission_payable:{agentId}", direction: debit, amount: X }
  - { ledger_account_reference: "commission_expense:{currencyCode}", direction: credit, amount: X }

# Journal 2: clawback from agent float
transaction_reference: "commission_reversal_float:{commissionId}"
lines:
  - { ledger_account_reference: "agent_float:{walletId}", direction: debit, amount: X }
  - { ledger_account_reference: "commission_payable:{agentId}", direction: credit, amount: X }
```

---

## Migration Sequence

| Migration file | Connection | Purpose |
|----------------|------------|---------|
| `2026_09_30_014000_add_agent_tier_to_agents.php` | `platform` | Add `agent_tier` VARCHAR column to `agent.agents` |
| `2026_09_30_014100_create_commission_rules.php` | `platform` | Create `commission.commission_rules` table |
| `2026_09_30_014200_create_commissions.php` | `platform` | Create `commission.commissions` table |
| `2026_09_30_014300_create_commission_payouts.php` | `platform` | Create `commission.commission_payouts` table |
| `2026_09_30_014400_create_commission_processed_events.php` | `platform` | Create `commission.processed_events` table |
| `2026_09_30_014500_seed_commission_permissions.php` | `platform` | Seed E14 permissions into `auth.permissions` |
| `2026_09_30_014600_add_volume_threshold_to_commission_rules.php` | `platform` | Add `volume_threshold BIGINT NULL` to `commission.commission_rules` for tier-based volume promotion (US3) |

---

## Permissions to Seed

| Permission | Description |
|-----------|-------------|
| `commission.rules.create` | Admin: create commission rules |
| `commission.rules.view` | Admin: view commission rules |
| `commission.rules.update` | Admin: update/supersede commission rules |
| `commission.rules.delete` | Admin: deactivate commission rules |
| `commission.view_own` | Agent: view own commissions |
| `commission.view_any` | Admin: view any agent's commissions |
| `commission.payout.approve` | Admin: approve and execute commission payouts |
| `commission.payout.cancel` | Admin: cancel deferred payouts |
| `commission.reverse` | Admin: reverse an individual commission |
| `commission.tier.manage` | Admin: set agent commission tier |
| `commission.report.view` | Admin: view cross-agent commission report |
