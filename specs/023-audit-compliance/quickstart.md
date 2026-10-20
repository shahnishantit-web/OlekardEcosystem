# Quickstart Validation Guide — E23 Audit & Compliance

This guide documents runnable end-to-end validation scenarios for E23. Refer to [data-model.md](data-model.md) for entity schemas and [contracts/openapi.yaml](contracts/openapi.yaml) for full API specification.

## Prerequisites

1. Docker environment running (`docker compose up -d` from `wallet-ecosystem/`)
2. Database seeded (`php artisan db:seed`)
3. An admin actor with `audit.read`, `compliance.read`, `compliance.report`, `compliance.config` permissions
4. Auth token obtained via E01 authentication endpoint
5. At least one customer user with KYC and transaction history

---

## SC-1: Audit Event Recording — Authentication

**Scenario**: Verify that a successful login produces an audit event.

```bash
# 1. Login as a customer (triggers US-AUDIT-001)
POST /v1/auth/login
{ "email": "customer@test.local", "password": "Test123!" }

# 2. Note the correlation_id from the response X-Correlation-ID header

# 3. Query audit events for the authentication category
GET /v1/audit/events?category=authentication&correlation_id={correlationId}
Authorization: Bearer {adminToken}

# Expected: 1 event with event_type=authentication.login.success,
# actor_type=customer, outcome=success
```

**Pass criteria**: Response contains an audit event with matching `correlation_id`, `event_category=authentication`, `outcome=success`, and a non-null `integrity_hash`.

---

## SC-2: Audit Immutability — Tamper Attempt

**Scenario**: Verify that an attempt to update an audit event is rejected and itself recorded.

```bash
# 1. Get an existing audit event ID
GET /v1/audit/events?per_page=1
Authorization: Bearer {adminToken}
# Note: {eventId} from response

# 2. Attempt to modify the audit event (should be blocked)
# This would be a direct DB update or a fabricated PATCH — the API has no PATCH endpoint.
# Test at the API layer: verify no PATCH/PUT/DELETE route exists for /v1/audit/events/{id}
# Expected HTTP response: 405 Method Not Allowed

# 3. Verify the integrity of the retrieved event
GET /v1/audit/events/{eventId}
# Expected: integrity_verified=true in response body
```

**Pass criteria**: No PATCH/PUT/DELETE route on `/v1/audit/events/{id}`; retrieved event has `integrity_verified=true`.

---

## SC-3: Compliance Status Query

**Scenario**: Verify that compliance status reflects the user's current KYC and AML state.

```bash
# 1. Query compliance status for a test user
GET /v1/compliance/status/{userId}
Authorization: Bearer {adminToken}

# Expected response:
# {
#   "data": {
#     "user_id": "{userId}",
#     "kyc_tier": 1,
#     "aml_risk_level": "low",
#     "active_restrictions": [],
#     "last_changed_at": "2026-..."
#   }
# }
```

**Pass criteria**: Response is ≤300ms; `kyc_tier` and `aml_risk_level` match the values in E05/E18 for this user.

---

## SC-4: Compliance History Query

**Scenario**: Verify that compliance history reflects all status changes in chronological order.

```bash
# 1. Trigger a KYC status change (via E05 admin endpoint)
POST /v1/kyc/admin/users/{userId}/tier
{ "tier": 2 }
Authorization: Bearer {adminToken}

# 2. Query compliance history
GET /v1/compliance/history/{userId}
Authorization: Bearer {adminToken}

# Expected: history contains entries for the initial kyc_tier (0→1 from seeding)
# AND the new entry (1→2), ordered newest-first.
```

**Pass criteria**: History response contains at least 2 entries; the most recent entry shows `field_changed=kyc_tier`, `before_value=1`, `after_value=2`; all entries have `triggering_audit_event_id`.

---

## SC-5: Compliance Report Generation

**Scenario**: Generate a compliance summary report and verify its contents.

```bash
# 1. Request report generation
POST /v1/compliance/reports
Authorization: Bearer {adminToken}
Idempotency-Key: {uuid}
{
  "report_type": "compliance_summary",
  "date_range_start": "2026-09-01",
  "date_range_end": "2026-09-28"
}

# Expected: 202 Accepted with status=pending or generating

# 2. Poll until status=completed (or immediate for short range)
GET /v1/compliance/reports/{reportId}
Authorization: Bearer {adminToken}

# Expected: status=completed, report_data contains audit_event_counts,
# kyc_status_distribution, and transaction_statistics
```

**Pass criteria**: Report reaches `status=completed`; `report_data` is non-null and contains at least one audit event count.

---

## SC-6: Idempotent Report Generation

**Scenario**: Submitting the same report request twice with the same idempotency key returns the same report.

```bash
# 1. Submit report request (same idempotency key as SC-5)
POST /v1/compliance/reports
Idempotency-Key: {same uuid from SC-5}
{ "report_type": "compliance_summary", "date_range_start": "2026-09-01", "date_range_end": "2026-09-28" }

# Expected: 200 OK (not 202), same report ID as SC-5
```

**Pass criteria**: HTTP 200; `id` matches SC-5's report ID; no duplicate report in the database.

---

## SC-7: SAR Generation

**Scenario**: Generate a Suspicious Activity Report for a flagged user.

```bash
# 1. Create a SAR
POST /v1/compliance/sars
Authorization: Bearer {adminToken}
Idempotency-Key: {uuid}
{
  "subject_user_id": "{userId}",
  "reason_code": "unusual_transaction_pattern",
  "reason_narrative": "User made 15 transactions in 2 minutes totalling BWP 50,000.",
  "flagged_transaction_ids": ["{txId1}", "{txId2}"]
}

# Expected: 201 Created
# {
#   "data": {
#     "report_number": "SAR-20260928-000001",
#     "status": "draft",
#     "subject_identity_snapshot": {...},
#     "flagged_transaction_ids": [...],
#     "aml_alert_history": [...],
#     "risk_score_timeline": [...]
#   }
# }
```

**Pass criteria**: `report_number` follows `SAR-{YYYYMMDD}-{6-digit seq}` format; `subject_identity_snapshot` is populated; `status=draft`.

---

## SC-8: Retention Policy Management

**Scenario**: Create and update a retention policy; verify the change is audited.

```bash
# 1. Create a retention policy
POST /v1/compliance/retention-policies
Authorization: Bearer {adminToken}
{
  "data_category": "audit.authentication",
  "minimum_retention_days": 730,
  "post_retention_action": "archive"
}
# Expected: 201 Created

# 2. Update the policy
PUT /v1/compliance/retention-policies/{policyId}
{
  "data_category": "audit.authentication",
  "minimum_retention_days": 365,
  "post_retention_action": "archive"
}
# Expected: 200 OK; enforcement_hold_until set to tomorrow (grace period)

# 3. Verify the policy change was audited
GET /v1/audit/events?category=config&entity_id={policyId}
# Expected: 2 events (created + updated), both with outcome=success
```

**Pass criteria**: Policy created successfully; update shortens the period; `enforcement_hold_until` is set; audit events recorded for both changes.

---

## SC-9: Authorization Enforcement

**Scenario**: Verify that actors without the required permission cannot access audit/compliance endpoints.

```bash
# Use a customer token (no audit.read permission)
GET /v1/audit/events
Authorization: Bearer {customerToken}

# Expected: 403 Forbidden
# { "error": { "code": "FORBIDDEN", "message": "..." } }
```

**Pass criteria**: HTTP 403; no audit event data in response body.
