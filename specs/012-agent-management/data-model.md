# Data Model — E12 Agent Management

**DB Connection**: `platform`  
**Schema**: `agent`  
**UUID strategy**: UUID v7 (time-ordered) for all primary keys

---

## Entity Relationship Overview

```
identity.users (E01)
    │
    ├──< agent.agent_applications (application_id is the bridge)
    │         │
    │         └──< agent.agent_documents
    │
    └──< agent.agents (created on approval only)
              │
              ├──< agent.agent_status_history
              │
              └──< agent.agent_customer_relationships >── identity.users (customer)
```

---

## Table: `agent.agent_applications`

Represents a multi-step agent onboarding application. One per applicant attempt.

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| `id` | `uuid` | PK, default `gen_random_uuid()` | UUID v7 |
| `user_id` | `uuid` | NOT NULL, FK → `identity.users.id` | Applicant's identity account |
| `agent_type` | `varchar(20)` | NOT NULL, CHECK IN ('individual','business') | |
| `status` | `varchar(30)` | NOT NULL, default 'draft' | See state machine below |
| `personal_info` | `jsonb` | nullable | First/last name, national ID, DOB, phone, address |
| `business_info` | `jsonb` | nullable | Trading name, reg number, tax number, address |
| `kyc_provider_reference` | `varchar(255)` | nullable | Reference ID from KYC provider |
| `kyc_result` | `varchar(30)` | nullable | CHECK IN ('passed','failed','pending') |
| `kyc_failure_reason` | `text` | nullable | Reason from KYC provider if failed |
| `rejection_reason` | `text` | nullable | Required when status = 'rejected' |
| `last_updated_by` | `uuid` | nullable | Actor ID of last admin action (maker-checker) |
| `submitted_at` | `timestamptz` | nullable | When applicant submitted for review |
| `kyc_initiated_at` | `timestamptz` | nullable | |
| `approved_at` | `timestamptz` | nullable | |
| `rejected_at` | `timestamptz` | nullable | |
| `correlation_id` | `uuid` | NOT NULL | Set at creation |
| `idempotency_key` | `varchar(255)` | nullable | From creation request |
| `created_at` | `timestamptz` | NOT NULL, default now() | |
| `updated_at` | `timestamptz` | NOT NULL, default now() | |

**Indexes**:
- `UNIQUE (user_id)` — one active application per user (partial: `WHERE status NOT IN ('rejected')`)
- `INDEX (status)` — admin list/filter
- `INDEX (user_id, status)` — ownership checks

**State Machine** (FR-E12-013):
```
draft ──> in_progress ──> submitted ──> kyc_in_progress ──> kyc_passed ──┐
                                                          └──> kyc_failed  ├──> under_review ──> approved
                                                                            │                └──> rejected
                                                                            └──> (auto_approve=true) approved
```

**Edit-lock states**: Application MUST NOT be modified when `status IN ('submitted','kyc_in_progress','kyc_passed','kyc_failed','under_review','approved','rejected')` — FR-E12-006.

---

## Table: `agent.agent_documents`

One row per uploaded document per application.

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| `id` | `uuid` | PK | UUID v7 |
| `application_id` | `uuid` | NOT NULL, FK → `agent.agent_applications.id` | |
| `document_type` | `varchar(40)` | NOT NULL, CHECK IN ('national_id','proof_of_address','business_registration') | |
| `storage_reference` | `text` | nullable | Null when `status = 'quarantined'` |
| `mime_type` | `varchar(100)` | NOT NULL | |
| `size_bytes` | `bigint` | NOT NULL, CHECK > 0 | |
| `status` | `varchar(30)` | NOT NULL, default 'pending_review' | See enum below |
| `rejection_reason` | `varchar(100)` | nullable | e.g. `VIRUS_SCAN_FAILED`, `UNSUPPORTED_FORMAT` |
| `uploaded_at` | `timestamptz` | NOT NULL, default now() | |

**Document Status Enum**: `pending_review` | `accepted` | `rejected` | `quarantined`

**Note**: A `quarantined` document has no `storage_reference` — the file was never persisted to storage.

**Indexes**:
- `INDEX (application_id, document_type)` — supersede lookup
- `INDEX (application_id, status)` — completeness checks

**Supersede rule**: When a new document of the same `document_type` is uploaded for the same `application_id`, the previous document's status is set to `rejected` (with reason `SUPERSEDED`). FR-E12 AC-4 for US-AGENT-004.

---

## Table: `agent.agents`

The active agent profile. Created ONLY upon admin approval of an AgentApplication. Never created before.

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| `id` | `uuid` | PK | UUID v7 |
| `application_id` | `uuid` | NOT NULL, UNIQUE, FK → `agent.agent_applications.id` | One agent per application |
| `user_id` | `uuid` | NOT NULL, UNIQUE, FK → `identity.users.id` | One agent record per user |
| `agent_type` | `varchar(20)` | NOT NULL | Copied from application |
| `status` | `varchar(20)` | NOT NULL, default 'active', CHECK IN ('active','suspended') | |
| `approved_by` | `uuid` | NOT NULL | Actor ID of approving admin |
| `approved_at` | `timestamptz` | NOT NULL | |
| `suspended_by` | `uuid` | nullable | Actor ID of suspending admin |
| `suspension_reason` | `text` | nullable | Required when suspended |
| `suspended_at` | `timestamptz` | nullable | |
| `reactivated_by` | `uuid` | nullable | Actor ID of reactivating admin |
| `reactivated_at` | `timestamptz` | nullable | |
| `created_at` | `timestamptz` | NOT NULL, default now() | |
| `updated_at` | `timestamptz` | NOT NULL, default now() | |

**Agent Status State Machine**:
```
active ──> suspended ──> active
```

**Indexes**:
- `UNIQUE (application_id)` — CC-E12-001 concurrency guard (prevents duplicate agent creation)
- `UNIQUE (user_id)` — one agent per identity user
- `INDEX (status)` — admin filtering

---

## Table: `agent.agent_customer_relationships`

Links agents to the customers they have registered or serve.

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| `id` | `uuid` | PK | UUID v7 |
| `agent_id` | `uuid` | NOT NULL, FK → `agent.agents.id` | |
| `customer_id` | `uuid` | NOT NULL, FK → `identity.users.id` | Customer's identity user ID |
| `registered_by` | `uuid` | NOT NULL | Agent ID who registered this customer |
| `registered_at` | `timestamptz` | NOT NULL, default now() | |

**Indexes**:
- `UNIQUE (agent_id, customer_id)` — one relationship per pair (FR-E12-017)
- `INDEX (customer_id)` — customer lookup
- `INDEX (agent_id)` — agent's customer list

**Authorization invariant**: Any request to access `customer_id` data must confirm `EXISTS (SELECT 1 FROM agent.agent_customer_relationships WHERE agent_id = ? AND customer_id = ?)`. Return `403 UNAUTHORIZED_CUSTOMER_ACCESS` if not found — never `404`.

---

## Table: `agent.agent_status_history`

Immutable audit log of agent status transitions. Rows MUST NOT be updated or deleted.

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| `id` | `uuid` | PK | UUID v7 |
| `agent_id` | `uuid` | NOT NULL, FK → `agent.agents.id` | |
| `previous_status` | `varchar(20)` | nullable | Null on initial creation event |
| `new_status` | `varchar(20)` | NOT NULL | |
| `actor_id` | `uuid` | NOT NULL | Admin or system actor |
| `actor_type` | `varchar(20)` | NOT NULL, CHECK IN ('admin','system') | |
| `reason` | `text` | nullable | Mandatory for suspend/reject |
| `occurred_at` | `timestamptz` | NOT NULL, default now() | |

**Immutability**: Override `performUpdate()` and `performDeleteOnModel()` to throw `AgentStatusHistoryImmutableException` — same pattern as E11's `TransactionStatusHistoryModel`.

**Indexes**:
- `INDEX (agent_id, occurred_at DESC)` — timeline lookup

---

## Permissions (seed in `identity.permissions`)

| Slug | Display Name | Used by |
|------|-------------|---------|
| `agent.application.create` | Create Agent Application | US-AGENT-001 |
| `agent.application.read` | View Own Agent Application | US-AGENT-002/003/004 |
| `agent.application.read.all` | View All Agent Applications (Admin) | admin list |
| `agent.approve` | Approve Agent Application | US-AGENT-006 |
| `agent.reject` | Reject Agent Application | US-AGENT-007 |
| `agent.suspend` | Suspend Agent | US-AGENT-008 |
| `agent.reactivate` | Reactivate Agent | US-AGENT-009 |
| `agent.customer.search` | Search Agent Customers | US-AGENT-010 |
| `agent.customer.register` | Register Customer via Agent | US-AGENT-011 |
| `agent.customer.kyc.initiate` | Initiate Customer KYC via Agent | US-AGENT-012 |
| `agent.customer.wallet.read` | View Customer Wallet (Agent) | US-AGENT-013 |
| `agent.customer.transactions.read` | View Customer Transactions (Agent) | US-AGENT-014 |

---

## Role (seed in `identity.roles`)

| Slug | Display Name | Permissions granted |
|------|-------------|---------------------|
| `agent` | Agent | All `agent.customer.*` permissions; `agent.application.create`; `agent.application.read` |

**Note**: The `agent` role is granted to the agent's `user_id` upon approval (FR-E12-011). Admin-only permissions (`agent.approve`, `agent.reject`, `agent.suspend`, `agent.reactivate`) are assigned to the `admin` role — not to `agent`.

---

## Configuration Defaults (seed in `platform.configuration_values`)

| Key | Value | Type |
|-----|-------|------|
| `agent.approval.require_maker_checker` | `true` | boolean |
| `agent.approval.auto_approve` | `false` | boolean |
| `agent.kyc.provider` | `stub` | string |
| `agent.document.max_size_bytes` | `10485760` | integer |
| `agent.document.allowed_formats` | `application/pdf,image/jpeg,image/png` | string |
| `agent.document.virus_scan.provider` | `null` | string |
| `agent.customer.search.min_length` | `3` | integer |

---

## Domain Events

All events are published via `EventPublisherInterface` to `platform.outbox_events`.

| Event name | Trigger | Key payload fields |
|------------|---------|-------------------|
| `agent.application.created` | POST /agent-applications | application_id, user_id, agent_type, correlation_id |
| `agent.application.personal_info_updated` | PATCH .../personal-information | application_id, actor_id, correlation_id |
| `agent.application.business_info_updated` | PATCH .../business-information | application_id, actor_id, correlation_id |
| `agent.application.document_uploaded` | POST .../documents (clean scan) | application_id, document_id, document_type, actor_id |
| `agent.application.document_rejected` | POST .../documents (virus scan fail) | application_id, document_id, rejection_reason (`VIRUS_SCAN_FAILED`), actor_type (`system`) |
| `agent.application.kyc_initiated` | POST .../kyc | application_id, kyc_provider_reference, actor_id |
| `agent.approved` | POST .../approve | application_id, agent_id, user_id, approved_by, correlation_id |
| `agent.rejected` | POST .../reject | application_id, rejection_reason, rejected_by, correlation_id |
| `agent.suspended` | POST /agents/{id}/suspend | agent_id, user_id, suspended_by, suspension_reason, correlation_id |
| `agent.reactivated` | POST /agents/{id}/reactivate | agent_id, user_id, reactivated_by, correlation_id |
| `agent.customer.registered` | POST /agents/{id}/customers | agent_id, customer_id, registered_by, correlation_id |
| `agent.customer.kyc_initiated` | POST .../kyc | agent_id, customer_id, actor_id |
| `security.unauthorized_access_attempt` | Any IDOR attempt | agent_id, target_customer_id, endpoint, actor_id, correlation_id |
