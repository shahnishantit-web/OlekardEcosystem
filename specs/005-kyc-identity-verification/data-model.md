# Data Model — E05 KYC & Identity Verification

**Feature**: `005-kyc-identity-verification` | **Schema**: `kyc` (PostgreSQL) | **Connection**: `kyc`

---

## Overview

E05 creates four new tables in the `kyc` schema, adds `PiiMasker::hashNationalId()` to the Platform module, seeds 6 new permissions in `identity.permissions`, assigns them to the `admin` role, and seeds 6 `platform.configuration_values` entries.

| Change | Type | Target |
|--------|------|--------|
| Create | NEW TABLE | `kyc.kyc_cases` |
| Create | NEW TABLE | `kyc.kyc_personal_info` |
| Create | NEW TABLE | `kyc.kyc_documents` |
| Create | NEW TABLE | `kyc.kyc_verification_results` |
| Seed | DATA | `identity.permissions` (6 new rows) |
| Seed | DATA | `identity.role_permissions` (6 rows — admin role) |
| Seed | DATA | `platform.configuration_values` (6 rows) |
| Modify | PLATFORM MODULE | `PiiMasker::hashNationalId()` added |

---

## Entity 1: `kyc.kyc_cases`

Central aggregate root for the KYC lifecycle.

### Column Inventory

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| `id` | UUID | PK | UUID v7; generated at creation |
| `user_id` | UUID | NOT NULL, FK → `identity.users.id` | One user: one open case at a time |
| `status` | VARCHAR(30) | NOT NULL | Enum: `initiated` / `documents_submitted` / `under_review` / `approved` / `rejected` / `needs_info` / `expired` |
| `attempt_number` | INTEGER | NOT NULL, DEFAULT 1 | Incremented on resubmit and reverification |
| `submitted_at` | TIMESTAMPTZ | NULL | Set when case transitions to `under_review` |
| `reviewed_at` | TIMESTAMPTZ | NULL | Set when reviewer acts (approve/reject/request-info) |
| `reviewer_id` | UUID | NULL, FK → `identity.users.id` | Admin who performed the last review action |
| `rejection_reason` | TEXT | NULL | Set on reject; cleared on resubmit |
| `info_request_note` | TEXT | NULL | Set on request-info; cleared on resubmit |
| `verified_at` | TIMESTAMPTZ | NULL | Set on approval |
| `expires_at` | TIMESTAMPTZ | NULL | Set on approval; checked by expiry job |
| `idempotency_key` | VARCHAR(255) | NULL, UNIQUE | Stores `Idempotency-Key` from start-kyc request |
| `created_at` | TIMESTAMPTZ | NOT NULL, DEFAULT `now()` | |
| `updated_at` | TIMESTAMPTZ | NOT NULL, DEFAULT `now()` | |

### Indexes

```sql
-- Open case check (BR-E05-001): "does this user have an open case?"
CREATE INDEX kyc_cases_user_id_status_idx ON kyc.kyc_cases (user_id, status);

-- Expiry job: fetch all approved cases past expires_at
CREATE INDEX kyc_cases_status_expires_at_idx ON kyc.kyc_cases (status, expires_at)
    WHERE status = 'approved';

-- Idempotency replay
CREATE UNIQUE INDEX kyc_cases_idempotency_key_unique ON kyc.kyc_cases (idempotency_key)
    WHERE idempotency_key IS NOT NULL;
```

### Eloquent Model

**File**: `app/Modules/Kyc/Infrastructure/Models/KycCaseModel.php`
**Connection**: `kyc`
**Table**: `kyc.kyc_cases`

### State Machine Transitions (KycStateMachine)

| From | To | Actor | Method |
|---|---|---|---|
| `initiated` | `documents_submitted` | System (first document upload) | `afterDocumentUploaded()` |
| `initiated`/`documents_submitted` | `under_review` | Customer/Agent | `submit()` |
| `under_review` | `approved` | System or Compliance Officer | `approve()` |
| `under_review` | `rejected` | Compliance Officer | `reject()` |
| `under_review` | `needs_info` | Compliance Officer | `requestInfo()` |
| `rejected`/`needs_info` | `initiated` | Customer/Agent | `resubmit()` |
| `approved` | `expired` | System (scheduler) | `expire()` |
| `approved`/`expired` | `initiated` | Admin/Compliance Officer | `triggerReverification()` |

---

## Entity 2: `kyc.kyc_personal_info`

One-to-one with `kyc_cases`. Stores personal details with national ID as HMAC-SHA256 hash.

### Column Inventory

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| `id` | UUID | PK | UUID v7 |
| `kyc_case_id` | UUID | NOT NULL, UNIQUE, FK → `kyc.kyc_cases.id` | One personal info record per case |
| `full_legal_name` | VARCHAR(255) | NOT NULL | As appears on identity document |
| `date_of_birth` | DATE | NOT NULL | ISO 8601 date |
| `nationality` | CHAR(2) | NOT NULL | ISO 3166-1 alpha-2 country code |
| `national_id_type` | VARCHAR(30) | NOT NULL | Enum: `national_id` / `passport` / `drivers_licence` |
| `national_id_hash` | VARCHAR(64) | NOT NULL | `hash_hmac('sha256', $id, app.key)` hex string — never plaintext |
| `address_line_1` | VARCHAR(255) | NOT NULL | |
| `address_line_2` | VARCHAR(255) | NULL | |
| `city` | VARCHAR(100) | NOT NULL | |
| `state_province` | VARCHAR(100) | NULL | |
| `postal_code` | VARCHAR(20) | NOT NULL | |
| `country_code` | CHAR(2) | NOT NULL | ISO 3166-1 alpha-2 |
| `created_at` | TIMESTAMPTZ | NOT NULL | |
| `updated_at` | TIMESTAMPTZ | NOT NULL | |

### Indexes

```sql
-- Duplicate detection: find any approved case with this national ID hash
CREATE INDEX kyc_personal_info_national_id_hash_idx ON kyc.kyc_personal_info (national_id_hash);

-- FK from case
CREATE UNIQUE INDEX kyc_personal_info_case_id_unique ON kyc.kyc_personal_info (kyc_case_id);
```

### Security Note

`national_id_hash` stores ONLY `PiiMasker::hashNationalId($plaintext)`. No plaintext column exists or is ever written. The plaintext national ID received from the API MUST be hashed before calling `DB::insert()`. This is enforced in `KycCaseService::capturePersonalInformation()`.

### Eloquent Model

**File**: `app/Modules/Kyc/Infrastructure/Models/KycPersonalInfoModel.php`
**Connection**: `kyc`
**Table**: `kyc.kyc_personal_info`

---

## Entity 3: `kyc.kyc_documents`

One case has many documents. Soft-delete for supersession.

### Column Inventory

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| `id` | UUID | PK | UUID v7 |
| `kyc_case_id` | UUID | NOT NULL, FK → `kyc.kyc_cases.id` | |
| `document_type` | VARCHAR(30) | NOT NULL | Enum: `national_id` / `passport` / `drivers_licence` / `utility_bill` / `selfie` / `other` |
| `file_reference` | VARCHAR(500) | NOT NULL | Opaque storage key from `KycDocumentStorageContract`; never a file path |
| `mime_type` | VARCHAR(100) | NOT NULL | Validated server-side |
| `file_size_bytes` | INTEGER | NOT NULL | Validated against `kyc.max_document_size_bytes` |
| `expires_on` | DATE | NULL | Validated if present: must be in future |
| `validation_status` | VARCHAR(20) | NOT NULL | Enum: `pending` / `valid` / `invalid` |
| `validation_error` | VARCHAR(255) | NULL | Set when `validation_status = invalid` |
| `deleted_at` | TIMESTAMPTZ | NULL | Soft delete — set when a new doc of same `document_type` replaces this one |
| `created_at` | TIMESTAMPTZ | NOT NULL | |

### Indexes

```sql
-- Find active documents for a case by type
CREATE INDEX kyc_documents_case_type_active_idx
    ON kyc.kyc_documents (kyc_case_id, document_type)
    WHERE deleted_at IS NULL;
```

### Supersession Logic

When a new document is uploaded for a `document_type` that already has an active record on the case, `KycDocumentService::upload()` sets `deleted_at = now()` on the old record before inserting the new one. Both are in the same database transaction.

### Eloquent Model

**File**: `app/Modules/Kyc/Infrastructure/Models/KycDocumentModel.php`
**Connection**: `kyc`
**Table**: `kyc.kyc_documents`

---

## Entity 4: `kyc.kyc_verification_results`

Immutable record of each automated verification call. One case can have multiple results (one per `attempt_number` and any retries).

### Column Inventory

| Column | Type | Constraints | Notes |
|--------|------|-------------|-------|
| `id` | UUID | PK | UUID v7 |
| `kyc_case_id` | UUID | NOT NULL, FK → `kyc.kyc_cases.id` | |
| `provider_name` | VARCHAR(100) | NOT NULL | From `kyc.provider` config at time of call |
| `provider_reference` | VARCHAR(255) | NULL | Provider's transaction/reference ID |
| `outcome` | VARCHAR(30) | NOT NULL | Enum: `pass` / `fail` / `manual_review_required` / `error` |
| `confidence_score` | DECIMAL(5,4) | NULL | 0.0000–1.0000; from provider if available |
| `raw_response_hash` | VARCHAR(64) | NOT NULL | `hash_hmac('sha256', $serializedResponse, app.key)` — raw response is NEVER stored |
| `created_at` | TIMESTAMPTZ | NOT NULL | |

### Indexes

```sql
-- Latest result for a case
CREATE INDEX kyc_verification_results_case_created_idx
    ON kyc.kyc_verification_results (kyc_case_id, created_at DESC);
```

### Security Note

The provider's raw JSON response MUST be serialized, hashed with `hash_hmac('sha256', $json, config('app.key'))`, and the hash stored in `raw_response_hash`. The raw JSON is then discarded — it is not stored in the database, not logged, and not returned in any API response.

### Eloquent Model

**File**: `app/Modules/Kyc/Infrastructure/Models/KycVerificationResultModel.php`
**Connection**: `kyc`
**Table**: `kyc.kyc_verification_results`

---

## Seeded Data

### New Permissions (`identity.permissions`)

Seeded by migration using `insertOrIgnore` by `slug`:

| slug | description |
|------|-------------|
| `kyc.case.create` | Initiate a KYC case |
| `kyc.case.update` | Capture personal info; upload documents; submit; resubmit |
| `kyc.case.read` | View own KYC case status and details |
| `kyc.case.review` | Perform manual review; reject; request info; view all cases |
| `kyc.case.approve` | Approve a KYC case |
| `kyc.case.manage` | Trigger reverification; admin overrides |

### Role-Permission Assignments (`identity.role_permissions`)

All 6 permissions assigned to the `admin` role (same pattern as E04).

### Configuration Values (`platform.configuration_values`)

| Key | Default | `is_tbd` | Notes |
|-----|---------|----------|-------|
| `kyc.validity_period_days` | `null` | true | TBD-E05-002 |
| `kyc.max_document_size_bytes` | `null` | true | TBD-E05-002 |
| `kyc.accepted_mime_types` | `null` | true | TBD-E05-002 |
| `kyc.provider` | `null` | true | TBD-E05-001 |
| `kyc.auto_verify_enabled` | `false` | false | Safe default while TBD-E05-001 unresolved |
| `kyc.provider_max_retries` | `3` | false | |

---

## Migration Order

| Order | File | Action |
|-------|------|--------|
| 1 | `2026_09_17_001000_create_kyc_kyc_cases.php` | Create `kyc.kyc_cases` |
| 2 | `2026_09_17_001100_create_kyc_kyc_personal_info.php` | Create `kyc.kyc_personal_info` (FK → cases) |
| 3 | `2026_09_17_001200_create_kyc_kyc_documents.php` | Create `kyc.kyc_documents` (FK → cases) |
| 4 | `2026_09_17_001300_create_kyc_kyc_verification_results.php` | Create `kyc.kyc_verification_results` (FK → cases) |
| 5 | `2026_09_17_001400_seed_kyc_permissions.php` | Seed permissions + role assignments in `identity` schema |
| 6 | `2026_09_17_001500_seed_kyc_configuration_values.php` | Seed config entries in `platform` schema |

---

## Cross-Module Dependencies

| E05 Table | References | Notes |
|-----------|------------|-------|
| `kyc.kyc_cases.user_id` | `identity.users.id` | FK constraint; no cascade delete |
| `kyc.kyc_cases.reviewer_id` | `identity.users.id` | FK constraint; nullable |
| `kyc.kyc_personal_info.kyc_case_id` | `kyc.kyc_cases.id` | ON DELETE CASCADE |
| `kyc.kyc_documents.kyc_case_id` | `kyc.kyc_cases.id` | ON DELETE CASCADE |
| `kyc.kyc_verification_results.kyc_case_id` | `kyc.kyc_cases.id` | ON DELETE CASCADE |
| Permission seed | `identity.permissions` / `identity.role_permissions` | `insertOrIgnore` by slug |
| Config seed | `platform.configuration_values` | `insertOrIgnore` by key |
