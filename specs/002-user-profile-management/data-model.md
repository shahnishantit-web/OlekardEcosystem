# Data Model — E02 User & Profile Management

**Feature**: `002-user-profile-management` | **Date**: 2026-08-27 | **Schema**: `profile`

E02 owns the `profile` PostgreSQL schema exclusively. All tables are created by E02 migrations on the `profile` named database connection. No cross-schema JOINs are permitted. Cross-module references use UUID value copies, not foreign key constraints.

---

## Entity Relationship Overview

```
profile.user_profiles (1)
    └── (N) profile.account_status_history  [append-only; one row per transition]
    └── (0..1) profile.data_exports         [one per active export request]
```

No foreign keys reference tables outside the `profile` schema. `user_id` on all tables is a UUID value copy of `identity.users.id` — validated at the application layer, not with a DB constraint.

---

## Table: `profile.user_profiles`

One record per registered user. Created by the `CreateProfileOnUserRegisteredListener` when `Identity.User.Registered` is consumed.

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | `uuid` | PK NOT NULL DEFAULT `gen_random_uuid()` | UUID v7 |
| `user_id` | `uuid` | NOT NULL UNIQUE | Value copy of `identity.users.id`; UNIQUE constraint prevents duplicate profiles |
| `first_name` | `varchar(100)` | NULL | Nullable at creation; user fills in after registration |
| `last_name` | `varchar(100)` | NULL | Nullable at creation |
| `date_of_birth` | `date` | NULL | Validated against `profile.minimum_user_age_years` when provided |
| `gender` | `varchar(20)` | NULL | Allowed: `male`, `female`, `non_binary`, `prefer_not_to_say` |
| `address_street` | `varchar(255)` | NULL | |
| `address_city` | `varchar(100)` | NULL | |
| `address_region` | `varchar(100)` | NULL | |
| `address_postal_code` | `varchar(20)` | NULL | |
| `address_country` | `char(2)` | NULL | ISO 3166-1 alpha-2; validated against known codes |
| `preferred_language` | `char(5)` | NULL | BCP 47 tag (e.g., `en`, `tn-BW`) |
| `email` | `varchar(255)` | NULL | Populated at creation via E01 service call; updated by contact-change flow only |
| `mobile_number` | `varchar(20)` | NULL | E.164 format; populated at creation via E01 service call |
| `profile_photo_key` | `varchar(500)` | NULL | Internal object storage key; NEVER returned in API responses or events |
| `account_status` | `varchar(30)` | NOT NULL DEFAULT `'active'` | Enum: `active`, `suspended`, `pending_closure`, `closed` |
| `pending_email` | `varchar(255)` | NULL | New email awaiting OTP verification; cleared on verify or expiry |
| `pending_email_challenge_id` | `uuid` | NULL | E01 OTP challenge ID for pending email change |
| `pending_mobile` | `varchar(20)` | NULL | New mobile awaiting OTP verification; E.164 |
| `pending_mobile_challenge_id` | `uuid` | NULL | E01 OTP challenge ID for pending mobile change |
| `created_at` | `timestamptz` | NOT NULL DEFAULT `now()` | |
| `updated_at` | `timestamptz` | NOT NULL DEFAULT `now()` | Auto-updated via Eloquent `timestamps()` |

**Indexes**:

| Index | Type | Columns | Purpose |
|---|---|---|---|
| PK | PRIMARY | `id` | Record lookup |
| `ux_profile_user_profiles_user_id` | UNIQUE | `user_id` | Prevents duplicate profiles; fast lookup by user_id |
| `ix_profile_user_profiles_account_status` | B-tree | `account_status` | Admin queries by status |
| `ix_profile_user_profiles_email` | B-tree | `email` | Contact uniqueness check on change initiation |
| `ix_profile_user_profiles_mobile` | B-tree | `mobile_number` | Contact uniqueness check on change initiation |

**Account Status State Machine**:

```
active
    → suspended          (admin: account.suspend)
    → pending_closure    (user: self-service request or admin if applicable)

suspended
    → active             (admin: account.reactivate)

pending_closure
    → active             (user: cancel; admin: account.reactivate)
    → closed             (admin: account.close — after final obligation check)

closed
    → [terminal; no further transitions]
```

**Note**: `identity.users.status` (E01) tracks lock/suspension at the identity layer independently. E02's `account_status` is the profile-domain lifecycle state. These are complementary, not redundant.

---

## Table: `profile.account_status_history`

Append-only audit trail of all account status transitions. Never updated or deleted.

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | `uuid` | PK NOT NULL DEFAULT `gen_random_uuid()` | UUID v7 |
| `user_id` | `uuid` | NOT NULL | Value copy of `identity.users.id` |
| `from_status` | `varchar(30)` | NULL | NULL for initial activation at profile creation |
| `to_status` | `varchar(30)` | NOT NULL | New status after transition |
| `reason` | `text` | NULL | Mandatory for suspension; optional for other transitions |
| `actor_id` | `uuid` | NOT NULL | User UUID, admin UUID, or system zero-UUID |
| `actor_type` | `varchar(20)` | NOT NULL | `USER`, `ADMIN`, `SYSTEM` |
| `closure_obligations` | `jsonb` | NULL | Populated on `CLOSURE_BLOCKED` records; lists obligation categories |
| `correlation_id` | `uuid` | NOT NULL | Propagated from `X-Correlation-ID` request header |
| `created_at` | `timestamptz` | NOT NULL DEFAULT `now()` | No `updated_at`; append-only |

**Indexes**:

| Index | Type | Columns | Purpose |
|---|---|---|---|
| PK | PRIMARY | `id` | |
| `ix_profile_status_history_user_id` | B-tree | `user_id` | Status history queries per user |
| `ix_profile_status_history_user_created` | B-tree | `(user_id, created_at DESC)` | Ordered history queries |

**Constraints**:
- No `updated_at` column — this table is INSERT-only; the application layer enforces this
- `from_status` NULL is valid only for the first record per user (initial `active` state on registration)

---

## Table: `profile.data_exports`

Tracks personal data export requests. One record per export request.

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | `uuid` | PK NOT NULL DEFAULT `gen_random_uuid()` | UUID v7 |
| `user_id` | `uuid` | NOT NULL | Value copy of `identity.users.id` |
| `status` | `varchar(20)` | NOT NULL DEFAULT `'processing'` | `processing`, `ready`, `downloaded`, `expired`, `failed` |
| `download_key` | `varchar(500)` | NULL | Internal object storage key for the export file; NEVER returned directly |
| `expires_at` | `timestamptz` | NULL | When the download link expires; set when export is ready |
| `requested_at` | `timestamptz` | NOT NULL DEFAULT `now()` | |
| `completed_at` | `timestamptz` | NULL | Set when export package is ready |
| `correlation_id` | `uuid` | NOT NULL | From `X-Correlation-ID` |
| `created_at` | `timestamptz` | NOT NULL DEFAULT `now()` | |
| `updated_at` | `timestamptz` | NOT NULL DEFAULT `now()` | |

**Indexes**:

| Index | Type | Columns | Purpose |
|---|---|---|---|
| PK | PRIMARY | `id` | |
| `ix_profile_data_exports_user_id_status` | B-tree | `(user_id, status)` | Check for in-progress export before new request |
| `ix_profile_data_exports_status` | B-tree | `status` | Cleanup job queries |

---

## Cross-Module References

| Column | Source Module | Referenced Value | Validation |
|---|---|---|---|
| `profile.user_profiles.user_id` | E01 `identity.users.id` | UUID value copy | Application-layer: verified to exist when `Identity.User.Registered` is consumed |
| `profile.account_status_history.user_id` | E01 `identity.users.id` | UUID value copy | Derived from authenticated token or parent profile record |
| `profile.data_exports.user_id` | E01 `identity.users.id` | UUID value copy | Derived from authenticated token |
| `profile.user_profiles.pending_email_challenge_id` | E01 `identity.otp_challenges.id` | UUID value copy | Value provided by E01 when OTP challenge is issued |
| `profile.user_profiles.pending_mobile_challenge_id` | E01 `identity.otp_challenges.id` | UUID value copy | Value provided by E01 when OTP challenge is issued |

No `FOREIGN KEY` constraints cross schema boundaries (per ADR-003).

---

## Eloquent Model Notes

All three Eloquent models use:
- `protected $connection = 'profile'` (named connection; ADR-002)
- `protected $table` fully qualified within the `profile` schema
- UUID primary keys (`$incrementing = false`, `$keyType = 'string'`)
- `HasUuids` trait or manual UUID v7 generation in `creating` hook
- `timestamps()` for `user_profiles` and `data_exports`; `AccountStatusHistoryModel` explicitly disables `updated_at`

`AccountStatusHistoryModel` uses `$timestamps = false` with a manual `created_at` cast — enforces append-only semantics at the model layer (no `update()` calls permitted).
