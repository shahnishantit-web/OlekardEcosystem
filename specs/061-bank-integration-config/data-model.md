# Data Model: P12 — Dynamic Bank Integration Configuration and Certification

**Date**: 2026-10-24
**Schema**: `payment` (PostgreSQL)

All new tables use UUID v7 primary keys (`$table->uuid('id')->primary()`), `created_at`/`updated_at` timestamps with timezone, and are in the `payment` schema.

---

## Existing Tables Extended

### `payment.payment_providers` — Bank (extended)

Existing table. P12 adds columns via migration:

| Column | Type | Change |
|--------|------|--------|
| `bank_lifecycle_status` | `varchar(32)` NOT NULL DEFAULT `'draft'` | **NEW** |
| `supported_countries` | `jsonb` NOT NULL DEFAULT `'[]'` | **NEW** |
| `organisation_ownership` | `uuid` NULL | **NEW** — NULL = platform-owned |
| `description` | `text` NULL | **NEW** |
| `logo_reference` | `varchar(500)` NULL | **NEW** |
| `metadata_version` | `integer` NOT NULL DEFAULT `1` | **NEW** |

**`bank_lifecycle_status` constraint**: `('draft', 'active', 'suspended', 'retired')`

**Existing columns retained unchanged**: `id`, `code`, `display_name`, `adapter_identifier`, `adapter_version`, `environment`, `is_enabled`, `is_suspended`, `sort_order`, `is_default`, `created_at`, `updated_at`

### `payment.payment_provider_accounts` — Bank Integration Account (extended)

Existing table. P12 adds columns via migration:

| Column | Type | Change |
|--------|------|--------|
| `account_lifecycle_status` | `varchar(32)` NOT NULL DEFAULT `'draft'` | **NEW** |
| `submitted_by_actor_id` | `uuid` NULL | **NEW** — actor who submitted for approval |
| `approved_by_actor_id` | `uuid` NULL | **NEW** — actor who approved (must differ from submitted_by) |
| `approved_at` | `timestamptz` NULL | **NEW** |
| `activated_at` | `timestamptz` NULL | **NEW** |
| `suspended_at` | `timestamptz` NULL | **NEW** |
| `retired_at` | `timestamptz` NULL | **NEW** |
| `rejection_reason` | `text` NULL | **NEW** |
| `active_config_version_id` | `uuid` NULL | **NEW** — FK to configuration_versions |
| `display_label` | `varchar(255)` NULL | **NEW** |
| `display_sort_order` | `smallint` NOT NULL DEFAULT `0` | **NEW** |

**`account_lifecycle_status` constraint**: `('draft','validated','sandbox_testing','evidence_review','approved','active','suspended','retired')`

---

## New Tables

### `payment.integration_profiles` — Integration Profile Registry

Read-only catalogue of reviewed adapter implementations.

| Column | Type | Notes |
|--------|------|-------|
| `id` | `uuid` PK | |
| `profile_identifier` | `varchar(128)` UNIQUE NOT NULL | e.g. `http-redirect-v1`, `mock-bank-v1` |
| `version` | `varchar(32)` NOT NULL | semver string |
| `display_name` | `varchar(255)` NOT NULL | Human label |
| `description` | `text` NULL | |
| `supported_auth_mechanisms` | `jsonb` NOT NULL | enum array: `bearer_token`, `api_key`, `hmac_signed`, `oauth2_client_credentials` |
| `supported_capabilities` | `jsonb` NOT NULL | enum array: `initiate`, `query`, `cancel`, `full_refund`, `partial_refund`, `callback`, `reconciliation` |
| `config_schema` | `jsonb` NOT NULL | JSON Schema for operation config validation |
| `is_active` | `boolean` NOT NULL DEFAULT `true` | |
| `notes` | `text` NULL | |
| `created_at` | `timestamptz` | |
| `updated_at` | `timestamptz` | |

**Index**: `profile_identifier, version` (unique)

---

### `payment.api_operation_configs` — API Operation Configuration

One row per supported operation per account configuration version.

| Column | Type | Notes |
|--------|------|-------|
| `id` | `uuid` PK | |
| `account_id` | `uuid` NOT NULL | FK → `payment_provider_accounts.id` |
| `config_version_id` | `uuid` NULL | FK → `configuration_versions.id`; NULL for drafts |
| `operation_type` | `varchar(32)` NOT NULL | `initiate`, `query`, `cancel`, `full_refund`, `partial_refund`, `callback`, `reconciliation` |
| `base_url` | `varchar(500)` NOT NULL | HTTPS-only; validated by SsrfGuard at save |
| `endpoint_path` | `varchar(500)` NOT NULL | |
| `http_method` | `varchar(10)` NOT NULL | `GET`, `POST`, `PUT`, `PATCH` |
| `content_type` | `varchar(64)` NOT NULL DEFAULT `'application/json'` | |
| `non_secret_headers` | `jsonb` NOT NULL DEFAULT `'{}'` | `{header_name: value}` — no secrets |
| `auth_profile` | `varchar(64)` NOT NULL | references `integration_profiles.supported_auth_mechanisms` enum value |
| `secret_key_references` | `jsonb` NOT NULL DEFAULT `'{}'` | `{role: "secret_manager_key_name"}` — key names only, never raw values |
| `request_field_mappings` | `jsonb` NOT NULL DEFAULT `'[]'` | typed mapping rules array |
| `response_field_mappings` | `jsonb` NOT NULL DEFAULT `'[]'` | typed mapping rules array |
| `provider_txn_ref_mapping` | `varchar(255)` NULL | dot-path in response JSON |
| `idempotency_ref_mapping` | `varchar(255)` NULL | field name to use as idempotency key |
| `amount_format` | `varchar(64)` NOT NULL DEFAULT `'minor_integer'` | `minor_integer`, `decimal_string`, `decimal_number` |
| `currency_format` | `varchar(32)` NOT NULL DEFAULT `'iso4217_upper'` | |
| `success_status_values` | `jsonb` NOT NULL DEFAULT `'[]'` | exact provider status strings → success |
| `pending_status_values` | `jsonb` NOT NULL DEFAULT `'[]'` | → pending/unresolved |
| `failure_status_values` | `jsonb` NOT NULL DEFAULT `'[]'` | → failure |
| `timeout_ms` | `integer` NOT NULL DEFAULT `30000` | max 120000 |
| `max_retries` | `smallint` NOT NULL DEFAULT `2` | |
| `retry_backoff_ms` | `integer` NOT NULL DEFAULT `1000` | |
| `callback_verification_profile` | `varchar(64)` NULL | only relevant for `callback` operation |
| `api_version` | `varchar(32)` NULL | |
| `is_payout` | `boolean` NOT NULL DEFAULT `false` | must be false; payout operations rejected by constraint |
| `created_at` | `timestamptz` | |
| `updated_at` | `timestamptz` | |

**Constraints**:
- `is_payout` CHECK `is_payout = false` (payout excluded from payment operation config)
- `operation_type` CHECK enumerated values
- `http_method` CHECK enumerated values
- `base_url` must pass SsrfGuard validation at application layer before insert

**Index**: `(account_id, operation_type, config_version_id)` unique (non-null config_version_id) to prevent duplicate operations per version

**Field mapping rule schema** (each element of `request_field_mappings` / `response_field_mappings`):
```json
{
  "source_field": "string",
  "target_field": "string",
  "transform_type": "literal|json_path|constant|amount_minor|amount_decimal|currency_code|uuid_v7|timestamp_iso8601|boolean_string",
  "required": true
}
```

---

### `payment.configuration_versions` — Immutable Configuration Snapshot

One row per activation event. Snapshot includes all operation configs at activation time.

| Column | Type | Notes |
|--------|------|-------|
| `id` | `uuid` PK | |
| `account_id` | `uuid` NOT NULL | FK → `payment_provider_accounts.id` |
| `version_number` | `integer` NOT NULL | auto-incrementing per account |
| `snapshot` | `jsonb` NOT NULL | complete operation config at activation time |
| `activated_at` | `timestamptz` NOT NULL | |
| `activated_by_actor_id` | `uuid` NOT NULL | |
| `superseded_at` | `timestamptz` NULL | set when a newer version activates |
| `created_at` | `timestamptz` | |

**Index**: `(account_id, version_number)` unique

---

### `payment.certification_evidence` — Certification Evidence

Evidence records linked to a configuration version.

| Column | Type | Notes |
|--------|------|-------|
| `id` | `uuid` PK | |
| `account_id` | `uuid` NOT NULL | FK → `payment_provider_accounts.id` |
| `config_version_id` | `uuid` NULL | FK → `configuration_versions.id`; NULL for draft evidence |
| `evidence_type` | `varchar(64)` NOT NULL | `test_run`, `documentation`, `merchant_ownership`, `sandbox_access`, `refund_evidence`, `reconciliation_evidence`, `other` |
| `title` | `varchar(255)` NOT NULL | |
| `notes` | `text` NULL | |
| `file_reference` | `varchar(500)` NULL | storage key (no public URL) |
| `test_run_result` | `jsonb` NULL | structured result for `test_run` evidence type |
| `submitted_by_actor_id` | `uuid` NOT NULL | |
| `created_at` | `timestamptz` | |

**Index**: `(account_id, evidence_type)`

---

### `payment.consumer_payment_options` — Consumer Display Records

Display configuration for mobile payment options discovery.

| Column | Type | Notes |
|--------|------|-------|
| `id` | `uuid` PK | |
| `account_id` | `uuid` NOT NULL | FK → `payment_provider_accounts.id` |
| `display_label` | `varchar(255)` NOT NULL | |
| `payment_method` | `varchar(64)` NOT NULL | `bank_redirect`, `bank_checkout`, `direct_debit`, `card` |
| `display_order` | `smallint` NOT NULL DEFAULT `0` | |
| `supported_purpose_types` | `jsonb` NOT NULL DEFAULT `'[]'` | |
| `country_restrictions` | `jsonb` NOT NULL DEFAULT `'[]'` | |
| `is_active` | `boolean` NOT NULL DEFAULT `false` | toggled when account lifecycle = active |
| `created_at` | `timestamptz` | |
| `updated_at` | `timestamptz` | |

**Index**: `(account_id, is_active)`, `(payment_method, is_active)`

---

### `payment.credential_rotation_log` — Credential Rotation History

Tracks credential reference rotations for in-flight attempt resolution.

| Column | Type | Notes |
|--------|------|-------|
| `id` | `uuid` PK | |
| `account_id` | `uuid` NOT NULL | |
| `secret_role` | `varchar(128)` NOT NULL | e.g. `api_key`, `callback_secret` |
| `previous_key_name` | `varchar(500)` NOT NULL | key name in secrets manager (not value) |
| `new_key_name` | `varchar(500)` NOT NULL | |
| `rotated_by_actor_id` | `uuid` NOT NULL | |
| `rotation_reason` | `text` NULL | |
| `effective_from` | `timestamptz` NOT NULL | |
| `created_at` | `timestamptz` | |

---

## Entity Relationships

```
payment_providers (Bank)
  ├── has many → payment_provider_accounts (BankIntegrationAccount)
  │     ├── has many → api_operation_configs (ApiOperationConfig)
  │     ├── has many → configuration_versions (ConfigurationVersion)
  │     │     └── api_operation_configs.config_version_id → configuration_versions.id
  │     ├── has many → certification_evidence (CertificationEvidence)
  │     ├── has one  → consumer_payment_options (ConsumerPaymentOption)
  │     └── has many → credential_rotation_log (CredentialRotationLog)
  └── references → integration_profiles (via adapter_identifier + adapter_version)

payment_attempts
  └── references → configuration_versions.id (immutable after commitment)
```

---

## State Machines

### Bank Lifecycle (`bank_lifecycle_status`)

```
draft → active → suspended → retired
              ↘ retired (direct from active)
```

Transitions:
- `draft` → `active`: admin activation; metadata complete check
- `active` → `suspended`: admin suspend; all accounts get new-transaction block
- `active` → `retired`: only if all accounts are retired
- `suspended` → `active`: admin reactivate
- `suspended` → `retired`: only if obligations resolved

### Account Configuration Lifecycle (`account_lifecycle_status`)

```
draft
  ↓ (submit for validation)
validated
  ↓ (initiate sandbox testing)
sandbox_testing
  ↓ (submit evidence)
evidence_review
  ↓ (second approver approves)    ← [rejected → draft]
approved
  ↓ (admin activates)
active ←→ suspended
  ↓
retired
```

Key guards:
- `evidence_review → approved`: requires `approved_by_actor_id ≠ submitted_by_actor_id`
- `approved → active`: creates `ConfigurationVersion` snapshot; sets `active_config_version_id`
- `active → retired`: blocks if outstanding payment obligations exist without approved resolution
- Any edit to Active config: creates new Draft row; does not modify active
