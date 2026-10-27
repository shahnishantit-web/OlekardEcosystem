# Data Model: P03 — Tenant Isolation and Payment Permissions

**Date**: 2026-10-21

---

## New Entities

### PaymentConfigChangeDraft

Stores a pending high-risk provider configuration change submitted by a drafter, awaiting approval by a different actor.

**Table**: `payment.payment_config_change_drafts`

**Schema**: `payment.*`

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| `id` | UUID (v7) | PK | Stable unique identifier |
| `provider_id` | UUID | NOT NULL, FK → `payment.payment_providers.id` | Target provider |
| `change_type` | varchar(64) | NOT NULL, CHECK IN ('high_risk_update', 'credential_rotation') | Classification of change |
| `change_payload` | jsonb | NOT NULL | Proposed new values (MUST NOT contain raw credentials) |
| `status` | varchar(32) | NOT NULL, DEFAULT 'pending_approval', CHECK IN ('pending_approval','approved','rejected','withdrawn') | Draft lifecycle state |
| `drafted_by_actor_id` | UUID | NOT NULL | Actor who created the draft (server-resolved, never from request body) |
| `reviewed_by_actor_id` | UUID | NULLABLE | Actor who approved/rejected (NULL until reviewed) |
| `reviewed_at` | timestamptz | NULLABLE | When the review decision was made |
| `review_notes` | varchar(2000) | NULLABLE | Reviewer's notes or rejection reason |
| `idempotency_key` | varchar(255) | UNIQUE NOT NULL | Prevents duplicate draft submissions |
| `correlation_id` | UUID | NOT NULL | Request correlation for tracing |
| `created_at` | timestamptz | NOT NULL | |
| `updated_at` | timestamptz | NOT NULL | |

**CHECK constraints**:
- `chk_payment_config_drafts_status` — status IN ('pending_approval','approved','rejected','withdrawn')
- `chk_payment_config_drafts_change_type` — change_type IN ('high_risk_update','credential_rotation')
- `chk_payment_config_drafts_self_approval` — reviewed_by_actor_id IS NULL OR reviewed_by_actor_id != drafted_by_actor_id

**Indices**:
- `idx_payment_config_drafts_provider_status` — (provider_id, status) — list pending drafts per provider
- `idx_payment_config_drafts_idempotency` — UNIQUE (idempotency_key)
- `idx_payment_config_drafts_drafter` — (drafted_by_actor_id, status) — list own pending drafts

**State transitions**:
```
pending_approval → approved    (approved by actor ≠ drafter, with step-up)
pending_approval → rejected    (rejected by actor ≠ drafter)
pending_approval → withdrawn   (drafter withdraws before review)
approved         → [terminal]
rejected         → [terminal]
withdrawn        → [terminal]
```

**Security constraints**:
- `change_payload` MUST NOT contain raw API keys, shared secrets, or plaintext credentials. Only opaque references (e.g., vault key paths) are permitted.
- `drafted_by_actor_id` is set server-side from the authenticated session; it is never read from the request body.

---

## Modified Entities

### payment_refunds (additive columns)

Two approval workflow columns added. These support the refund approval gate (US4). All new columns are nullable to preserve backward compatibility with existing refund rows.

**Table**: `payment.payment_refunds`

**New columns**:

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| `approval_required` | boolean | NOT NULL DEFAULT false | Set true when amount_minor exceeds refund approval threshold at request time |
| `approved_by_actor_id` | UUID | NULLABLE | Actor who approved the refund (NULL if not yet approved or not required) |
| `approved_at` | timestamptz | NULLABLE | When the approval was recorded |
| `rejected_by_actor_id` | UUID | NULLABLE | Actor who rejected the refund (NULL if not rejected) |
| `rejected_at` | timestamptz | NULLABLE | When the rejection was recorded |
| `rejection_reason` | varchar(1000) | NULLABLE | Free-text reason for rejection |

**CHECK constraints**:
- `chk_payment_refunds_self_approval` — approved_by_actor_id IS NULL OR approved_by_actor_id != requested_by (note: `requested_by` is the existing column recording who initiated the refund)

**Updated state machine** (additive — existing transitions unchanged):

```
[existing: pending → pending_provider → ...]

pending_approval (new state) — refund created but awaiting approval
    ↓ approved (actor ≠ requested_by) → pending_provider (existing flow)
    ↓ rejected (actor ≠ requested_by) → rejected (terminal, new)
```

Note: `PaymentRefundStatus` enum must add `PendingApproval = 'pending_approval'` and `Rejected = 'rejected'` cases. The `isTerminal()` method must treat `Rejected` as terminal.

---

## Existing Entities — Permission Records (identity.*)

The `identity.permissions` table (managed by E04) receives 5 new permission rows via the updated `PaymentPermissionRoleSeeder`. No schema changes to the `identity.*` tables.

**New rows in `identity.permissions`**:

| slug | description | is_assignable_to_user_roles |
|------|-------------|----------------------------|
| `payment.config.draft` | Submit a high-risk provider configuration change for approval | true |
| `payment.payouts.view` | View payout records and history | true |
| `payment.payouts.manage` | Initiate and manage payout disbursements | true |
| `payment.callbacks.receive` | System-level: authorise the callback handler path | false |
| `payment.exports.view` | Export payment transaction data (audit/compliance) | true |

**Updated role → permission assignments** (in `identity.role_permissions`):

| Role | New permissions added |
|------|-----------------------|
| `admin` | payment.config.draft, payment.payouts.view, payment.payouts.manage, payment.exports.view |
| `admin-officer` | payment.exports.view |
| `agent` | payment.intents.read, payment.intents.manage, payment.refunds.request (NEW role assignment) |

---

## No Changes

The following entities are **not modified** by P03:
- `payment.payment_intents` — state machine, columns, and constraints are unchanged
- `payment.payment_attempts` — unchanged
- `payment.payment_providers` — no column changes; existing `is_enabled` flag is read but not modified
- `identity.roles` — no new roles created
- `identity.permissions` — structure unchanged; only new rows inserted via seeder
- Wallet, Ledger, Transaction modules — not touched
