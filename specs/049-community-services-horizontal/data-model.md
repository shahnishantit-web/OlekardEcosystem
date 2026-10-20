# Data Model: E49 — Community Services Platform Horizontal Foundation

**Schema namespace**: `community_services`
**ID type**: UUID v7 (time-sortable) for all primary keys
**Timestamp type**: `timestamptz` (UTC); `business_timezone` stored separately where display requires it
**Amount type**: `bigint` (integer minor units) + `char(3)` currency code; no `decimal`/`float` for financial values

---

## Module: Organisation (HZ02)

### `community_services.organisations`

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | uuid | PK | UUID v7 |
| `external_ref` | varchar(64) | unique, not null | Opaque public reference |
| `name` | varchar(255) | not null | |
| `type` | varchar(50) | not null | `charity`, `event_organiser`, `community_group`, `cooperative` |
| `registration_number` | varchar(100) | nullable | |
| `operating_countries` | jsonb | not null, default `[]` | ISO 3166-1 alpha-2 array |
| `status` | varchar(30) | not null, default `draft` | See states below |
| `verified_at` | timestamptz | nullable | |
| `verified_by` | uuid | nullable, FK users | |
| `payout_destination_id` | uuid | nullable, FK payout_destinations | |
| `payout_destination_verified_at` | timestamptz | nullable | |
| `suspension_reason` | text | nullable | |
| `suspension_expires_at` | timestamptz | nullable | |
| `metadata` | jsonb | not null, default `{}` | |
| `created_by` | uuid | not null, FK users | |
| `created_at` | timestamptz | not null | |
| `updated_at` | timestamptz | not null | |
| `deleted_at` | timestamptz | nullable | Soft delete for archives |

**States**: `draft` → `submitted` → `under_review` ⇄ `changes_requested` → `approved` → `suspended` → `archived`; `rejected` and `cancelled` are terminal.

**Indexes**: `status`, `type`, `operating_countries` (GIN), `created_by`

---

### `community_services.organisation_evidence`

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | uuid | PK | |
| `organisation_id` | uuid | not null, FK organisations | |
| `type` | varchar(50) | not null | `registration`, `identity`, `address`, `financial_permit` |
| `file_key` | varchar(500) | not null | Storage path, not public URL |
| `filename` | varchar(255) | not null | |
| `mime_type` | varchar(100) | not null | |
| `size_bytes` | bigint | not null | |
| `scan_status` | varchar(20) | not null, default `pending` | `pending`, `clean`, `infected`, `error` |
| `uploaded_by` | uuid | not null, FK users | |
| `uploaded_at` | timestamptz | not null | |
| `expires_at` | timestamptz | nullable | For time-limited evidence |

**Access**: Served via expiring signed URL; direct file key never exposed to client.

---

### `community_services.payout_destinations`

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | uuid | PK | |
| `organisation_id` | uuid | not null, FK organisations | |
| `type` | varchar(30) | not null | `bank_account`, `mobile_money`, `wallet` |
| `provider` | varchar(50) | not null | |
| `masked_reference` | varchar(100) | not null | Last 4 digits / account suffix |
| `encrypted_reference` | text | not null | Encrypted full reference, key-managed |
| `status` | varchar(20) | not null, default `pending_verification` | `pending_verification`, `verified`, `deprecated` |
| `verified_at` | timestamptz | nullable | |
| `verified_by` | uuid | nullable, FK users | |
| `created_at` | timestamptz | not null | |
| `updated_at` | timestamptz | not null | |

---

### `community_services.organisation_status_history`

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | uuid | PK | |
| `organisation_id` | uuid | not null, FK organisations | |
| `from_status` | varchar(30) | not null | |
| `to_status` | varchar(30) | not null | |
| `reason` | text | nullable | |
| `actor_id` | uuid | not null, FK users | |
| `occurred_at` | timestamptz | not null | |

---

## Module: Membership (HZ03)

### `community_services.staff_memberships`

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | uuid | PK | Membership ID, distinct from person ID |
| `person_id` | uuid | not null, FK users | |
| `organisation_id` | uuid | not null, FK organisations | |
| `role` | varchar(50) | not null | `organiser_admin`, `community_representative`, `finance_approver`, `gate_operator`, `sponsor_partner`, `support_officer` |
| `scoped_to_event_id` | uuid | nullable | Gate operator or sponsor: restrict to one event |
| `scoped_to_community_id` | uuid | nullable | Community rep: restrict to one community |
| `status` | varchar(20) | not null, default `active` | `active`, `suspended`, `revoked` |
| `effective_from` | timestamptz | not null | |
| `effective_to` | timestamptz | nullable | Time-bound roles |
| `invited_by` | uuid | not null, FK users | |
| `created_at` | timestamptz | not null | |
| `updated_at` | timestamptz | not null | |

**Unique constraint**: `(person_id, organisation_id, role)` — prevents duplicate active memberships.

---

### `community_services.staff_invitations`

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | uuid | PK | |
| `organisation_id` | uuid | not null, FK organisations | |
| `intended_email` | varchar(320) | not null | Bound to recipient |
| `role` | varchar(50) | not null | |
| `token_hash` | char(64) | not null, unique | SHA-256 of one-time token |
| `status` | varchar(20) | not null, default `pending` | `pending`, `accepted`, `expired`, `revoked` |
| `expires_at` | timestamptz | not null | |
| `accepted_at` | timestamptz | nullable | |
| `accepted_by` | uuid | nullable, FK users | |
| `invited_by` | uuid | not null, FK users | |
| `created_at` | timestamptz | not null | |

---

### `community_services.consumer_memberships`

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | uuid | PK | |
| `person_id` | uuid | not null, FK users | |
| `community_id` | uuid | not null, FK communities | |
| `status` | varchar(20) | not null, default `pending` | `pending`, `active`, `suspended`, `departed` |
| `joined_at` | timestamptz | nullable | |
| `departed_at` | timestamptz | nullable | |
| `created_at` | timestamptz | not null | |
| `updated_at` | timestamptz | not null | |

---

## Module: Consent (HZ04)

### `community_services.consent_notices`

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | uuid | PK | |
| `organisation_id` | uuid | nullable, FK organisations | null = platform-level notice |
| `purpose` | varchar(50) | not null | `operational`, `marketing`, `sponsor_sharing`, `data_retention` |
| `version` | varchar(20) | not null | semver e.g. `1.2.0` |
| `content_hash` | char(64) | not null | SHA-256 of notice body |
| `locale` | char(5) | not null, default `en` | BCP-47 |
| `effective_from` | timestamptz | not null | |
| `superseded_at` | timestamptz | nullable | |
| `created_by` | uuid | not null, FK users | |
| `created_at` | timestamptz | not null | |

---

### `community_services.consent_records`

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | uuid | PK | |
| `person_id` | uuid | not null, FK users | |
| `notice_id` | uuid | not null, FK consent_notices | |
| `purpose` | varchar(50) | not null | Denormalised for fast filter |
| `action` | varchar(20) | not null | `granted`, `withdrawn` |
| `capture_source` | varchar(50) | not null | `app_onboarding`, `settings`, `admin_import` |
| `actor_id` | uuid | not null, FK users | May differ from person_id (admin action) |
| `occurred_at` | timestamptz | not null | |
| `ip_address` | varchar(45) | nullable | IPv4/IPv6, for audit |

**Append-only** — no updates or deletes.

---

### `community_services.data_rights_requests`

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | uuid | PK | |
| `person_id` | uuid | not null, FK users | |
| `type` | varchar(20) | not null | `access`, `correction`, `deletion`, `portability` |
| `status` | varchar(20) | not null, default `received` | `received`, `in_progress`, `completed`, `rejected` |
| `reason` | text | nullable | For rejections |
| `completed_at` | timestamptz | nullable | |
| `handled_by` | uuid | nullable, FK users | |
| `created_at` | timestamptz | not null | |
| `updated_at` | timestamptz | not null | |

---

## Module: Service Catalogue (HZ05)

### `community_services.service_entitlements`

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | uuid | PK | |
| `organisation_id` | uuid | not null, FK organisations | |
| `service_type` | varchar(30) | not null | `funeral_assistance`, `events_ticketing`, `raffles_fundraising` |
| `jurisdiction` | char(2) | not null | ISO 3166-1 alpha-2 |
| `enabled` | boolean | not null, default false | |
| `activation_gate_passed` | boolean | not null, default false | Raffle: requires compliance review |
| `gate_evidence_id` | uuid | nullable, FK organisation_evidence | |
| `effective_from` | timestamptz | nullable | |
| `effective_to` | timestamptz | nullable | |
| `enabled_by` | uuid | nullable, FK users | |
| `created_at` | timestamptz | not null | |
| `updated_at` | timestamptz | not null | |

**Unique constraint**: `(organisation_id, service_type, jurisdiction)`

---

### `community_services.catalogue_categories`

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | uuid | PK | |
| `organisation_id` | uuid | not null, FK organisations | |
| `service_type` | varchar(30) | not null | |
| `name` | varchar(100) | not null | |
| `slug` | varchar(100) | not null | |
| `description` | text | nullable | |
| `visible` | boolean | not null, default true | |
| `sort_order` | smallint | not null, default 0 | |
| `created_at` | timestamptz | not null | |
| `updated_at` | timestamptz | not null | |

---

## Module: Order / Payment Orchestration (HZ06)

### `community_services.orders`

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | uuid | PK | UUID v7 |
| `external_ref` | varchar(64) | not null, unique | Opaque consumer-facing reference |
| `organisation_id` | uuid | not null, FK organisations | |
| `consumer_id` | uuid | not null, FK users | |
| `purpose_type` | varchar(30) | not null | `event_ticket`, `funeral_contribution`, `raffle_entry`, `donation` |
| `purpose_id` | uuid | not null | ID of the event/collection/campaign |
| `currency` | char(3) | not null | ISO 4217 |
| `subtotal` | bigint | not null | Minor units |
| `fee_total` | bigint | not null, default 0 | Minor units |
| `grand_total` | bigint | not null | `subtotal + fee_total` |
| `status` | varchar(30) | not null, default `pending` | See states below |
| `idempotency_key` | varchar(128) | not null | |
| `request_hash` | char(64) | not null | SHA-256 of canonical payload |
| `hold_expires_at` | timestamptz | nullable | Inventory hold expiry |
| `confirmed_at` | timestamptz | nullable | |
| `cancelled_at` | timestamptz | nullable | |
| `metadata` | jsonb | not null, default `{}` | |
| `created_at` | timestamptz | not null | |
| `updated_at` | timestamptz | not null | |

**Order states**: `pending` → `hold` → `processing` → `confirmed` | `failed` | `cancelled` | `refunded` (partial: `partially_refunded`)

**Indexes**: `(consumer_id, status)`, `(organisation_id, status)`, `idempotency_key` (unique), `(purpose_type, purpose_id)`

---

### `community_services.order_lines`

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | uuid | PK | |
| `order_id` | uuid | not null, FK orders | |
| `item_ref` | varchar(100) | not null | e.g. ticket category ID |
| `description` | varchar(255) | not null | |
| `quantity` | smallint | not null | |
| `unit_price` | bigint | not null | Minor units |
| `line_total` | bigint | not null | `quantity * unit_price` |
| `currency` | char(3) | not null | |
| `created_at` | timestamptz | not null | |

---

### `community_services.payment_attempts`

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | uuid | PK | UUID v7 |
| `order_id` | uuid | not null, FK orders | |
| `connector_id` | uuid | nullable, FK partner_connectors | |
| `provider_ref` | varchar(255) | nullable | Provider transaction ID |
| `amount` | bigint | not null | Minor units |
| `currency` | char(3) | not null | |
| `status` | varchar(30) | not null, default `initiated` | `initiated`, `pending`, `captured`, `failed`, `cancelled` |
| `failure_code` | varchar(50) | nullable | |
| `failure_message` | varchar(500) | nullable | |
| `initiated_at` | timestamptz | not null | |
| `terminal_at` | timestamptz | nullable | |
| `metadata` | jsonb | not null, default `{}` | Provider-specific safe metadata |

**No raw card data stored** — provider_ref is a token/reference only.

---

### `community_services.idempotency_records`

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | uuid | PK | |
| `actor_id` | uuid | not null | |
| `operation_type` | varchar(50) | not null | `create_order`, `confirm_payment`, `initiate_refund`, etc. |
| `idempotency_key` | varchar(128) | not null | |
| `request_hash` | char(64) | not null | SHA-256 of canonical request body |
| `response_payload` | jsonb | nullable | Stored on first success for replay |
| `status` | varchar(20) | not null | `processing`, `completed`, `failed` |
| `created_at` | timestamptz | not null | |
| `expires_at` | timestamptz | not null | TTL for garbage collection |

**Unique constraint**: `(actor_id, operation_type, idempotency_key)`

---

## Module: Receipts (HZ07)

### `community_services.receipts`

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | uuid | PK | UUID v7 |
| `external_ref` | varchar(64) | not null, unique | Consumer-facing immutable reference |
| `order_id` | uuid | not null, FK orders | |
| `organisation_id` | uuid | not null, FK organisations | |
| `consumer_id` | uuid | not null, FK users | |
| `purpose_type` | varchar(30) | not null | |
| `purpose_description` | varchar(255) | not null | Human-readable at time of issue |
| `currency` | char(3) | not null | |
| `amount` | bigint | not null | Grand total in minor units |
| `provider_ref` | varchar(255) | nullable | Masked external reference |
| `ledger_posting_id` | uuid | not null | FK to wallet ledger posting |
| `issued_at` | timestamptz | not null | |

**Immutable after creation** — no update operations on this table.

---

## Module: Refunds / Disputes (HZ08)

### `community_services.refund_requests`

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | uuid | PK | UUID v7 |
| `external_ref` | varchar(64) | not null, unique | |
| `order_id` | uuid | not null, FK orders | |
| `organisation_id` | uuid | not null, FK organisations | |
| `requested_by` | uuid | not null, FK users | |
| `approved_by` | uuid | nullable, FK users | Must differ from requested_by |
| `amount` | bigint | not null | Minor units |
| `currency` | char(3) | not null | |
| `reason` | text | not null | |
| `status` | varchar(30) | not null, default `pending_review` | `pending_review`, `approved`, `processing`, `completed`, `failed`, `rejected` |
| `provider_ref` | varchar(255) | nullable | |
| `ledger_posting_id` | uuid | nullable | Compensating entry ID |
| `rejection_reason` | text | nullable | |
| `completed_at` | timestamptz | nullable | |
| `created_at` | timestamptz | not null | |
| `updated_at` | timestamptz | not null | |

**Self-approval prevention**: Application layer enforces `approved_by != requested_by`; database check constraint as backup.

---

### `community_services.dispute_holds`

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | uuid | PK | UUID v7 |
| `order_id` | uuid | not null, FK orders | |
| `organisation_id` | uuid | not null, FK organisations | |
| `amount` | bigint | not null | Minor units |
| `currency` | char(3) | not null | |
| `reason` | text | not null | |
| `raised_by` | uuid | not null, FK users | |
| `resolved_by` | uuid | nullable, FK users | |
| `status` | varchar(20) | not null, default `active` | `active`, `resolved`, `released` |
| `resolution_notes` | text | nullable | |
| `created_at` | timestamptz | not null | |
| `resolved_at` | timestamptz | nullable | |

---

### `community_services.refund_policies`

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | uuid | PK | UUID v7 |
| `organisation_id` | uuid | not null, FK organisations | |
| `service_type` | varchar(30) | not null | |
| `version` | varchar(20) | not null | |
| `terms_summary` | text | not null | Consumer-visible before payment |
| `max_refund_window_hours` | int | not null | |
| `requires_approval_above` | bigint | nullable | Minor units threshold |
| `effective_from` | timestamptz | not null | |
| `effective_to` | timestamptz | nullable | |
| `created_by` | uuid | not null, FK users | |
| `created_at` | timestamptz | not null | |

---

## Module: Partner Connectors (HZ12)

### `community_services.partner_connectors`

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | uuid | PK | UUID v7 |
| `partner_key` | varchar(50) | not null, unique | e.g. `btc`, `stripe_test` |
| `name` | varchar(100) | not null | Display name |
| `adapter_class` | varchar(255) | not null | FQCN of adapter implementing PartnerAdapterContract |
| `adapter_version` | varchar(20) | not null | Semver |
| `capability_manifest` | jsonb | not null | Supported capabilities list |
| `organisation_mapping` | jsonb | not null, default `{}` | External org IDs |
| `credential_vault_path` | varchar(500) | not null | Vault key reference — no actual secrets |
| `webhook_secret_vault_path` | varchar(500) | nullable | |
| `sandbox_mode` | boolean | not null, default true | |
| `health_status` | varchar(20) | not null, default `unknown` | `healthy`, `degraded`, `down`, `unknown` |
| `last_health_check_at` | timestamptz | nullable | |
| `enabled` | boolean | not null, default false | |
| `created_by` | uuid | not null, FK users | |
| `created_at` | timestamptz | not null | |
| `updated_at` | timestamptz | not null | |

---

### `community_services.webhook_receipts`

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | uuid | PK | UUID v7 |
| `connector_id` | uuid | not null, FK partner_connectors | |
| `provider_event_id` | varchar(255) | not null | |
| `raw_payload_hash` | char(64) | not null | SHA-256 of raw body |
| `received_at` | timestamptz | not null | |
| `signature_valid` | boolean | not null | |
| `processing_status` | varchar(20) | not null, default `received` | `received`, `processing`, `completed`, `failed`, `quarantined` |
| `processed_at` | timestamptz | nullable | |
| `error_message` | text | nullable | |

**Unique constraint**: `(connector_id, provider_event_id)` — deduplication anchor.

---

## Module: Approvals / Policy Versioning (HZ13)

### `community_services.approval_requests`

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | uuid | PK | UUID v7 |
| `external_ref` | varchar(64) | not null, unique | |
| `organisation_id` | uuid | not null, FK organisations | |
| `type` | varchar(50) | not null | `payout`, `refund`, `destination_change`, `emergency_suspension`, `beneficiary_change` |
| `subject_entity_type` | varchar(50) | not null | |
| `subject_entity_id` | uuid | not null | |
| `submitted_by` | uuid | not null, FK users | |
| `assigned_to` | uuid | nullable, FK users | |
| `status` | varchar(30) | not null, default `pending_review` | `pending_review`, `approved`, `rejected`, `expired`, `cancelled` |
| `approved_by` | uuid | nullable, FK users | MUST differ from submitted_by |
| `rejected_by` | uuid | nullable, FK users | |
| `decision_reason` | text | nullable | |
| `policy_version_id` | uuid | nullable, FK policy_versions | Active version at submission time |
| `expires_at` | timestamptz | nullable | |
| `decided_at` | timestamptz | nullable | |
| `created_at` | timestamptz | not null | |
| `updated_at` | timestamptz | not null | |

**Check constraint**: `approved_by != submitted_by` (DB-level backup to application enforcement).

**Serialisation**: Pessimistic row lock during approval execution to prevent concurrent double-approval.

---

### `community_services.policy_versions`

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | uuid | PK | UUID v7 |
| `organisation_id` | uuid | nullable, FK organisations | null = platform-level policy |
| `policy_type` | varchar(50) | not null | `contribution_amount`, `refund_policy`, `capacity_rule`, `fee_schedule`, `approval_threshold` |
| `version` | varchar(20) | not null | Semver |
| `content_hash` | char(64) | not null | SHA-256 of canonical policy JSON |
| `content` | jsonb | not null | Policy details |
| `effective_from` | timestamptz | not null | |
| `effective_to` | timestamptz | nullable | |
| `authored_by` | uuid | not null, FK users | |
| `approval_request_id` | uuid | nullable, FK approval_requests | Required for high-risk policy changes |
| `created_at` | timestamptz | not null | |

---

## Module: Domain Events Outbox

### `community_services.domain_events`

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | uuid | PK | UUID v7 |
| `event_type` | varchar(100) | not null | e.g. `Payment.Confirmed` |
| `schema_version` | varchar(10) | not null | e.g. `1.0` |
| `aggregate_type` | varchar(50) | not null | |
| `aggregate_id` | uuid | not null | |
| `organisation_id` | uuid | not null | Scope |
| `correlation_id` | uuid | not null | |
| `causation_id` | uuid | nullable | |
| `payload` | jsonb | not null | Event payload (no PII beyond reference IDs) |
| `occurred_at` | timestamptz | not null | Business time |
| `dispatched_at` | timestamptz | nullable | Set when successfully dispatched |
| `status` | varchar(20) | not null, default `pending` | `pending`, `dispatched`, `failed` |
| `attempt_count` | smallint | not null, default 0 | |
| `last_error` | text | nullable | |
| `created_at` | timestamptz | not null | |

**Indexes**: `(status, created_at)` for outbox worker polling; `(aggregate_type, aggregate_id)` for event history queries.

---

## Module: Notifications (HZ09) — Extension

No new tables. Extends `notification_jobs` (E17) by:
- Adding `source_module = 'community_services'` filter column
- Using existing `deduplication_key` field for community event deduplication

---

## Module: Shared — Purchase Policy Version Link

### `community_services.purchase_policy_links`

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | uuid | PK | |
| `order_id` | uuid | not null, FK orders | |
| `policy_type` | varchar(50) | not null | |
| `policy_version_id` | uuid | not null, FK policy_versions | Version active at purchase time |
| `accepted_at` | timestamptz | not null | |

**Immutable after creation** — captures the exact policy version the consumer accepted.

---

## State Machines Summary

### Order Status

```
pending → hold (inventory reserved)
hold → processing (payment initiated)
processing → confirmed (payment captured + entitlement issued)
processing → failed (payment failed / timeout)
hold → cancelled (hold expired, consumer cancelled)
confirmed → partially_refunded (partial refund approved)
confirmed → refunded (full refund approved)
```

### Refund Request Status

```
pending_review → approved (Finance Approver, ≠ requester)
pending_review → rejected (Finance Approver, with reason)
approved → processing (provider refund initiated)
processing → completed (provider confirms)
processing → failed (provider fails)
```

### Organisation Status

```
draft → submitted
submitted → under_review
under_review → changes_requested
changes_requested → submitted (re-submission)
under_review → approved
approved → suspended (with reason + expiry/follow-up)
suspended → approved (reinstatement)
any → archived (after reconciliation)
any → rejected (terminal)
any → cancelled (terminal)
```

### Approval Request Status

```
pending_review → approved (approver ≠ submitter, serialised)
pending_review → rejected (approver, with reason)
approved → [subject entity transitions]
pending_review → expired (expires_at reached)
any → cancelled (submitter or admin)
```

---

## Indexes and Performance

| Table | Index | Purpose |
|---|---|---|
| `orders` | `(organisation_id, status, created_at)` | Admin order list, scoped |
| `orders` | `(consumer_id, status)` | Consumer order history |
| `orders` | `idempotency_key` (unique) | Idempotency lookup |
| `payment_attempts` | `(order_id, status)` | Attempt history |
| `receipts` | `(consumer_id, issued_at)` | Consumer receipt list |
| `refund_requests` | `(organisation_id, status)` | Admin refund queue |
| `domain_events` | `(status, created_at)` | Outbox worker polling |
| `webhook_receipts` | `(connector_id, provider_event_id)` unique | Deduplication |
| `approval_requests` | `(organisation_id, status)` | Approval inbox |
| `consent_records` | `(person_id, purpose)` | Consent lookup |
| `staff_memberships` | `(person_id, organisation_id, status)` | Auth scope resolution |
