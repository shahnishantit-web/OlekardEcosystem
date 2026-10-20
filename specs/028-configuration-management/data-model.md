# Data Model — E28 Configuration Management

## Existing Tables (modified by E28)

### platform.configurations *(migration: add domain column)*

Existing table — stores the catalog of known configuration keys.

| Column | Type | Notes |
|--------|------|-------|
| id | UUID v7 | PK |
| config_key | VARCHAR(255) | UNIQUE NOT NULL — e.g. `financial_policy.max_single_transfer` |
| config_value | TEXT | Raw default / seed value |
| config_type | VARCHAR(50) | `string`, `integer`, `decimal`, `boolean`, `json` |
| is_tbd | BOOLEAN | True if value not yet resolved |
| description | TEXT | Human-readable description |
| **domain** | **VARCHAR(50)** | **NEW — `financial_policy`, `operational_policy`, `geographic`** |
| created_at | TIMESTAMPTZ | |
| updated_at | TIMESTAMPTZ | |

**E28 migration adds**: `domain VARCHAR(50) NOT NULL DEFAULT 'operational_policy'` (backfill then remove default).

---

### platform.configuration_values *(existing, unchanged)*

Read model used by `ConfigurationService::get()`. Written to by E28's approval flow (draft record). This table is NOT modified by E28 migrations — only new rows are inserted.

| Column | Type | Notes |
|--------|------|-------|
| id | UUID v7 | PK |
| config_key | VARCHAR(255) | |
| config_value | TEXT | |
| value_type | VARCHAR(50) | |
| status | VARCHAR(30) | `draft`, `active`, `archived` |
| version | INTEGER | UNIQUE per config_key |
| is_tbd | BOOLEAN | |
| effective_from | TIMESTAMPTZ | nullable |
| effective_until | TIMESTAMPTZ | nullable |
| created_at | TIMESTAMPTZ | |
| updated_at | TIMESTAMPTZ | |

---

### platform.configuration_audit *(existing, unchanged — immutable, REVOKE UPDATE DELETE)*

---

## New Tables (created by E28)

### platform.configuration_change_requests

Mutable workflow entity — tracks the pending change through maker-checker approval.

| Column | Type | Nullable | Notes |
|--------|------|----------|-------|
| id | UUID v7 | NO | PK |
| config_key | VARCHAR(255) | NO | FK → platform.configurations.config_key |
| domain | VARCHAR(50) | NO | `financial_policy`, `operational_policy`, `geographic` |
| proposed_value | JSONB | NO | New value being requested |
| previous_value | JSONB | YES | Active value at time of submission (snapshot) |
| maker_id | UUID | NO | Actor who submitted the request |
| status | VARCHAR(30) | NO | `pending_approval`, `approved`, `rejected`, `withdrawn`, `failed` |
| rejection_reason | TEXT | YES | Required when status is `rejected` or `withdrawn` |
| idempotency_key | VARCHAR(255) | NO | UNIQUE — caller-supplied idempotency token |
| correlation_id | UUID | YES | Request-scoped correlation ID |
| submitted_at | TIMESTAMPTZ | NO | Defaults to NOW() |
| resolved_at | TIMESTAMPTZ | YES | Set when status transitions to terminal state |
| created_at | TIMESTAMPTZ | NO | |
| updated_at | TIMESTAMPTZ | NO | |

**Indexes**:
- `UNIQUE (config_key) WHERE status = 'pending_approval'` — enforces FR-007 (one pending per key)
- `INDEX (status, submitted_at DESC)` — supports escalation check query
- `INDEX (maker_id)` — supports history-by-maker query
- `UNIQUE (idempotency_key)` — idempotency backstop

**State Transitions**:
```
[submitted] → pending_approval
pending_approval → approved     (checker approves; maker ≠ checker)
pending_approval → rejected     (checker rejects)
pending_approval → withdrawn    (maker withdraws)
approved → failed               (activation-time validation fails)
```

---

### platform.configuration_approvals

Immutable decision record — one row per checker action.

| Column | Type | Nullable | Notes |
|--------|------|----------|-------|
| id | UUID v7 | NO | PK |
| change_request_id | UUID | NO | FK → platform.configuration_change_requests.id |
| checker_id | UUID | NO | Actor who made the decision |
| decision | VARCHAR(20) | NO | `approved`, `rejected` |
| reason | TEXT | YES | Required for `rejected`; optional for `approved` |
| decided_at | TIMESTAMPTZ | NO | |
| correlation_id | UUID | YES | |
| created_at | TIMESTAMPTZ | NO | |

**Indexes**:
- `INDEX (change_request_id)` — lookup approvals by request
- `INDEX (checker_id)` — audit queries by checker

No UPDATE or DELETE permissions granted (append-only).

---

### platform.configuration_versions

Immutable state-transition log — one row per state change on any change request. Also records the final activation event (when draft → active in configuration_values).

| Column | Type | Nullable | Notes |
|--------|------|----------|-------|
| id | UUID v7 | NO | PK |
| change_request_id | UUID | YES | FK → platform.configuration_change_requests.id |
| approval_id | UUID | YES | FK → platform.configuration_approvals.id (null for non-approval transitions) |
| config_key | VARCHAR(255) | NO | Denormalized for independent queryability |
| domain | VARCHAR(50) | NO | Denormalized |
| previous_value | JSONB | YES | null for first-ever change to a key |
| new_value | JSONB | YES | null for `withdrawn` / `rejected` (value never applied) |
| maker_id | UUID | YES | Identity of submitter |
| checker_id | UUID | YES | Identity of checker (null if no checker yet, or withdrawn) |
| event_type | VARCHAR(30) | NO | `submitted`, `approved`, `rejected`, `withdrawn`, `activated`, `failed` |
| decision_reason | TEXT | YES | For `rejected`, `withdrawn`, `failed` |
| effective_at | TIMESTAMPTZ | YES | Set for `activated` event |
| created_at | TIMESTAMPTZ | NO | |

**Indexes**:
- `INDEX (config_key, created_at DESC)` — version history query (FR-017)
- `INDEX (domain, created_at DESC)` — domain-filtered history
- `INDEX (maker_id)` — history by maker
- `INDEX (checker_id)` — history by checker
- `INDEX (event_type, created_at DESC)` — audit queries by event type

**DB-level immutability** (migration SQL):
```sql
REVOKE UPDATE, DELETE ON platform.configuration_versions FROM PUBLIC;
```
Same treatment already applied to `platform.configuration_audit`.

---

## Enums (PHP Backed String Enums)

### ConfigurationDomain
```php
enum ConfigurationDomain: string {
    case FinancialPolicy   = 'financial_policy';
    case OperationalPolicy = 'operational_policy';
    case Geographic        = 'geographic';
}
```

### ChangeRequestStatus
```php
enum ChangeRequestStatus: string {
    case PendingApproval = 'pending_approval';
    case Approved        = 'approved';
    case Rejected        = 'rejected';
    case Withdrawn       = 'withdrawn';
    case Failed          = 'failed';
}
```

### VersionEventType
```php
enum VersionEventType: string {
    case Submitted = 'submitted';
    case Approved  = 'approved';
    case Rejected  = 'rejected';
    case Withdrawn = 'withdrawn';
    case Activated = 'activated';
    case Failed    = 'failed';
}
```

---

## Entity Relationships

```
platform.configurations (1) ─── (N) platform.configuration_values
platform.configurations (1) ─── (N) platform.configuration_change_requests
platform.configuration_change_requests (1) ─── (0..1) platform.configuration_approvals
platform.configuration_change_requests (1) ─── (N) platform.configuration_versions
platform.configuration_approvals (1) ─── (N) platform.configuration_versions
```

---

## Domain Validator Interfaces

```php
interface ConfigurationDomainValidatorInterface
{
    public function validate(string $key, mixed $value): void; // throws ValidationException
    public function supportsDomain(ConfigurationDomain $domain): bool;
}
```

Three implementations:
- `FinancialPolicyConfigValidator` — validates `financial_policy.*` keys (positive decimals for limits/fees, valid currency codes for commissions)
- `OperationalPolicyConfigValidator` — validates `operational_policy.*` keys (KYC rule structs, risk scoring weights, template placeholder checks)
- `GeographicConfigValidator` — validates `geographic.*` keys (ISO 4217 currency codes, ISO 3166-1 country codes, duplicate detection)

---

## Key Configuration Namespaces

| Domain | Key Prefix Examples |
|--------|---------------------|
| financial_policy | `financial_policy.max_single_transfer`, `financial_policy.daily_limit`, `financial_policy.fee_rate`, `financial_policy.commission_rate` |
| operational_policy | `operational_policy.kyc_tier1_limit`, `operational_policy.risk_score_threshold`, `operational_policy.notification_template.otp` |
| geographic | `geographic.supported_currencies`, `geographic.supported_countries` |
