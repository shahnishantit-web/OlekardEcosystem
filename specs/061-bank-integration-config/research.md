# Research: P12 — Dynamic Bank Integration Configuration and Certification

**Date**: 2026-10-24
**Status**: Complete — all decisions resolved; no NEEDS CLARIFICATION items.

---

## Decision 1: Schema Extension Strategy — Extend or Replace

**Decision**: Extend existing `payment_providers` and `payment_provider_accounts` tables with new columns and a lifecycle status column, plus add new tables for integration profiles, operation configs, configuration versions, certification evidence, and consumer options.

**Rationale**: The existing tables are already referenced by `PaymentAttemptModel`, `PaymentIntentModel`, and all P02–P11 infrastructure. Replacing them would require migrations across every dependent table and break existing test suites. Extending preserves backward compatibility and satisfies the spec requirement to avoid disrupting existing payment attempts.

**Alternatives considered**:
- Full replacement with separate "Bank" entity and FK to old provider table — rejected; would require dual-write period and complex backfill.
- Separate parallel tables with cross-join — rejected; adds query complexity and risks data drift.

---

## Decision 2: API Operation Configuration Storage Format

**Decision**: Store operation configuration as typed JSONB columns within `payment.api_operation_configs`. Each row represents one operation (initiate, query, cancel, refund, partial_refund, callback, reconciliation) for a given account configuration version. Field mappings are stored as typed arrays of `{source_field, target_field, transform_type, required}` objects with an enumerated, validated transform_type.

**Rationale**: JSONB allows flexible per-operation schemas while PostgreSQL's JSONB validation and Laravel's cast system provide sufficient type safety. The alternative (separate rows per field mapping) adds join complexity without additional safety given the validation layer.

**Alternatives considered**:
- EAV (entity-attribute-value) for mappings — rejected; overly complex and hard to validate.
- A separate `field_mappings` table — rejected; increases query depth without benefit over validated JSONB.
- PHP-serialized objects — rejected; not database-inspectable.

---

## Decision 3: SSRF Protection Implementation

**Decision**: Implement `SsrfGuard` as an application-layer service that validates base URLs at configuration-save time (not at request time). Validation rules: (a) scheme must be `https`; (b) resolved host must not be a private IPv4 range (10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16), loopback (127.0.0.0/8, ::1), link-local (169.254.0.0/16), or known cloud metadata IP (169.254.169.254, fd00:ec2::254); (c) port must be 443 or omitted; (d) host must match an explicit allowlist pattern configurable through Platform Configuration service.

**Rationale**: Save-time validation provides the strongest protection because it prevents malicious URLs from ever being stored. Runtime validation (checking at request dispatch) alone would allow URL storage followed by concurrent exploitation.

**Alternatives considered**:
- DNS rebinding protection at runtime only — insufficient; save-time check still needed.
- Network-layer egress control only — out of scope for P12 (deployment responsibility); application layer is always required.
- Regex-only validation — rejected; regexes can miss IPv6 variants; DNS resolution check is required for accurate range matching.

---

## Decision 4: Two-Person Approval Enforcement

**Decision**: The lifecycle transition to `Approved` requires a `submitted_by_actor_id` recorded at Evidence Review submission, and the approving call must supply an `actor_id` ≠ `submitted_by_actor_id`. The service enforces this programmatically; no UI workaround can bypass it. The check uses actor IDs from the authenticated session, not request body.

**Rationale**: FR-013 requires independent approval. Enforcing at the service layer (not controller) prevents bypass via direct API call. Actor ID is sourced from server-side session only.

**Alternatives considered**:
- Role-based two-person rule (approver must have a higher-tier role) — more complex to administer; actor ID inequality is a simpler, sufficient control.
- Approval token flow (email-based) — out of scope for admin-to-admin workflow; existing admin authentication is sufficient.

---

## Decision 5: Configuration Version Snapshot Strategy

**Decision**: When an Active account's configuration is submitted for activation, a `ConfigurationVersion` record is created as an immutable JSON snapshot of the complete operation configuration at that moment. Payment attempts record the `configuration_version_id` (UUID); the snapshot is never modified. Credential rotation records a new `CredentialRotationRecord` with the new secret reference key and the rotation timestamp; attempts lookup their credential context via the rotation log, not by re-reading the live secret reference.

**Rationale**: FR-023 requires attempt context to be immutable after commitment. FR-024 requires credential rotation not to break in-flight operations. Snapshots and rotation logs satisfy both without storing raw secrets anywhere.

**Alternatives considered**:
- Copying the full config into each attempt row — excessive storage; config can be large.
- Relying on updated_at timestamps for version tracking — not reliable under concurrent updates; explicit version IDs are required.

---

## Decision 6: Integration Profile Registry Scope

**Decision**: The Integration Profile registry (`payment.integration_profiles` table) is a read-only catalogue of reviewed adapter implementations. Entries are seeded via migration or admin-authenticated POST, not via public configuration. The registry documents: profile identifier, version, supported auth mechanisms (enumerated), supported payment capabilities (enumerated), required configuration schema (JSON Schema), and compatibility notes.

**Rationale**: The spec explicitly states (FR-007) that new authentication protocols require a new reviewed adapter. The registry documents what is reviewed and approved; it does not allow arbitrary protocol registration.

**Alternatives considered**:
- Purely code-driven (enum in PHP) — prevents admin visibility; makes it harder for non-engineers to see which profiles are available.
- Fully open registration (any admin can add a profile) — rejected; violates the security review requirement.

---

## Decision 7: Consumer Payment Options Response Enhancement

**Decision**: Extend the existing `PaymentOptionsController::index()` response to include: `display_label` (from ConsumerPaymentOption), `applicable_fees` array (from existing fee rule resolution), `restrictions` summary. The endpoint already exists at `/v1/payments/options`; P12 enriches the response shape. No new endpoint needed.

**Rationale**: `PaymentOptionsController` already exists and uses `ProviderRegistryService::discoverOptions()`. Adding display metadata requires joining to `ConsumerPaymentOption` records within the existing discovery flow.

**Alternatives considered**:
- Separate `/v1/payments/banks` endpoint — unnecessary duplication; the options endpoint already serves the discovery purpose.

---

## Decision 8: Credential Masking

**Decision**: `SecretReference` value objects store only the key name (e.g., `payments/fnbz/sandbox/api_key`). Serialisation to JSON always produces `{"key": "payments/fnbz/sandbox/api_key", "masked": "****"}`. The actual secret is fetched at adapter dispatch time from the platform secrets service and is never logged, returned, or cached in DB.

**Rationale**: FR-014 requirement. Using a value object ensures the masking logic is centralised and cannot be accidentally bypassed.

---

## Reused Predecessor Components

| Component | Package | Reuse Role in P12 |
|-----------|---------|-------------------|
| `PaymentProviderModel` | P02 | Extended with bank metadata columns and lifecycle_status |
| `PaymentProviderAccountModel` | P02 | Extended with account_lifecycle_status column |
| `PaymentConfigChangeDraftModel` | P03 | Reused as draft mechanism for operation config edits |
| `PaymentProviderAdminService` | P05 | Extended, not replaced; new BankAdminService delegates to it |
| `ProviderRegistryService` | P05 | Extended to join ConsumerPaymentOption for display metadata |
| `EligibilityPipeline` | P05 | No change; account lifecycle status = Suspended blocks selection |
| `AbstractBankPaymentAdapter` | P10 | Extended with `loadOperationConfig()` hook for DB-driven config |
| `PaymentOptionsController` | P05 | Response shape extended with ConsumerPaymentOption fields |
| Audit service (Platform) | E23 | Used for all lifecycle transitions and config mutations |
| E04 permissions | E04 | `payment.configuration` permission scope added to registry |
| Platform Secrets service | E26 | Secret references resolved at adapter dispatch time |
