# Quickstart — E05 KYC & Identity Verification

Validation guide for proving E05 works end-to-end. Run these after migrations and seeding.

## Prerequisites

1. E01 (Identity) running: authentication and user creation working
2. E04 (Roles & Permissions) migrations applied: `kyc.case.*` permissions seeded
3. E05 migrations applied: `kyc.*` tables created, permissions and config seeded
4. An authenticated Customer token (obtained via E01 login)
5. An authenticated Admin token with `kyc.case.approve` and `kyc.case.review` permissions
6. A small JPEG image file for document upload (≤ 10MB)

---

## Scenario 1: Happy Path — Customer KYC Approved (Auto-Verify Disabled)

This proves the full lifecycle with manual approval.

**Step 1** — Start KYC case:
```bash
curl -X POST http://localhost:8080/v1/kyc/cases \
  -H "Authorization: Bearer {customer_token}" \
  -H "Idempotency-Key: kyc-test-001" \
  -H "Content-Type: application/json"
```
Expected: `201` with `case_id` and `status: initiated`.

**Step 2** — Capture personal information:
```bash
curl -X PUT http://localhost:8080/v1/kyc/cases/{case_id}/personal-information \
  -H "Authorization: Bearer {customer_token}" \
  -H "Content-Type: application/json" \
  -d '{
    "full_legal_name": "Jane Test",
    "date_of_birth": "1990-01-01",
    "nationality": "BW",
    "national_id_type": "national_id",
    "national_id_number": "1234567890123",
    "address": {
      "line_1": "123 Test Street",
      "city": "Gaborone",
      "postal_code": "0001",
      "country_code": "BW"
    }
  }'
```
Expected: `200` with `personal_information_provided: true`.

Verify: `SELECT national_id_hash FROM kyc.kyc_personal_info WHERE kyc_case_id = '{case_id}'` — should return a 64-char hex string. No `national_id_number` column should exist.

**Step 3** — Upload identity document:
```bash
curl -X POST http://localhost:8080/v1/kyc/cases/{case_id}/documents \
  -H "Authorization: Bearer {customer_token}" \
  -F "document_type=national_id" \
  -F "file=@/path/to/id-card.jpg" \
  -F "expires_on=2030-01-01"
```
Expected: `201` with `validation_status: valid`.

**Step 4** — Submit for verification:
```bash
curl -X POST http://localhost:8080/v1/kyc/cases/{case_id}/submit \
  -H "Authorization: Bearer {customer_token}" \
  -H "Idempotency-Key: kyc-submit-001"
```
Expected: `200` with `status: under_review`.

**Step 5** — Compliance Officer approves:
```bash
curl -X POST http://localhost:8080/v1/kyc/cases/{case_id}/approve \
  -H "Authorization: Bearer {admin_token}"
```
Expected: `200` with `status: approved`, `verified_at` set, `expires_at` set (or `null` if `kyc.validity_period_days` is TBD).

**Step 6** — Verify domain event emitted:
```sql
SELECT event_name, payload FROM platform.outbox_events
WHERE event_name = 'Kyc.Case.Approved'
ORDER BY created_at DESC LIMIT 1;
```
Expected: one row with `user_id` and `case_id` in payload.

**Step 7** — Verify audit trail:
```sql
SELECT action, actor_id, outcome FROM platform.audit_events
WHERE resource_id = '{case_id}'
ORDER BY occurred_at;
```
Expected: rows for `kyc.case.initiated`, `kyc.case.personal-information-captured`, `kyc.case.document-uploaded`, `kyc.case.submitted`, `kyc.case.approved`.

---

## Scenario 2: Duplicate Identity Detection

**Step 1**: Complete Scenario 1 (approve case for User A with national ID `1234567890123`).

**Step 2**: Start a new KYC case as a different user (User B).

**Step 3** — Try to capture personal info with the same national ID:
```bash
curl -X PUT http://localhost:8080/v1/kyc/cases/{case_b_id}/personal-information \
  -H "Authorization: Bearer {user_b_token}" \
  -H "Content-Type: application/json" \
  -d '{
    "full_legal_name": "Bob Duplicate",
    "date_of_birth": "1985-05-20",
    "nationality": "BW",
    "national_id_type": "national_id",
    "national_id_number": "1234567890123",
    "address": { "line_1": "456 Other St", "city": "Gaborone", "postal_code": "0001", "country_code": "BW" }
  }'
```
Expected: `409` with `DUPLICATE_IDENTITY_DETECTED`. No personal info record should exist for User B's case.

Verify: `SELECT COUNT(*) FROM kyc.kyc_personal_info WHERE kyc_case_id = '{case_b_id}'` → `0`.

---

## Scenario 3: Rejection and Resubmission

**Step 1**: Get a case to `under_review` (follow Scenario 1 Steps 1–4).

**Step 2** — Compliance Officer rejects:
```bash
curl -X POST http://localhost:8080/v1/kyc/cases/{case_id}/reject \
  -H "Authorization: Bearer {admin_token}" \
  -H "Content-Type: application/json" \
  -d '{"rejection_reason": "Document image is unreadable."}'
```
Expected: `200` with `status: rejected`.

**Step 3** — Customer checks status:
```bash
curl http://localhost:8080/v1/kyc/cases/my \
  -H "Authorization: Bearer {customer_token}"
```
Expected: `200` with `rejection_reason` populated.

**Step 4** — Customer resubmits:
```bash
curl -X POST http://localhost:8080/v1/kyc/cases/{case_id}/resubmit \
  -H "Authorization: Bearer {customer_token}" \
  -H "Idempotency-Key: kyc-resubmit-001"
```
Expected: `200` with `status: initiated`, `attempt_number: 2`.

Verify: previous documents soft-deleted: `SELECT COUNT(*) FROM kyc.kyc_documents WHERE kyc_case_id = '{case_id}' AND deleted_at IS NULL` → `0`.

---

## Scenario 4: Idempotency

**Step 1**: Start KYC with `Idempotency-Key: kyc-idem-test-001` — receive `201` with `case_id`.

**Step 2**: Repeat the same request with the same `Idempotency-Key`.

Expected: Same `201` response. `SELECT COUNT(*) FROM kyc.kyc_cases WHERE user_id = '{user_id}'` → `1` (not 2).

---

## Scenario 5: KYC Expiry Detection

**Step 1**: Approve a KYC case (Scenario 1 Steps 1–5).

**Step 2**: Manually backdate `expires_at`:
```sql
UPDATE kyc.kyc_cases SET expires_at = NOW() - INTERVAL '1 day' WHERE id = '{case_id}';
```

**Step 3** — Run expiry detection:
```bash
php artisan kyc:detect-expired
```

Expected output:
```
KYC expiry detection started.
Processed 1 cases. Expired: 1. Skipped: 0. Errors: 0.
```

**Step 4**: Verify:
```sql
SELECT status FROM kyc.kyc_cases WHERE id = '{case_id}';
```
Expected: `expired`.

**Step 5**: Verify domain event:
```sql
SELECT event_name FROM platform.outbox_events WHERE event_name = 'Kyc.Case.Expired';
```
Expected: one row.

---

## Scenario 6: Cross-User Access Blocked

**Step 1**: Obtain User A's `case_id`.

**Step 2** — Attempt to access User A's case with User B's token:
```bash
curl http://localhost:8080/v1/kyc/cases/{user_a_case_id} \
  -H "Authorization: Bearer {user_b_token}"
```
Expected: `404 Not Found` (not 403 — to avoid confirming case existence).

---

## Scenario 7: Document Validation Pre-Check (Stateless)

**Step 1** — Validate format:
```bash
curl -X POST http://localhost:8080/v1/kyc/documents/validate-format \
  -H "Authorization: Bearer {customer_token}" \
  -F "file=@/path/to/id-card.jpg"
```
Expected: `200` with `{ "valid": true }`.

**Step 2** — Validate expired document expiry:
```bash
curl -X POST http://localhost:8080/v1/kyc/documents/validate-expiry \
  -H "Authorization: Bearer {customer_token}" \
  -H "Content-Type: application/json" \
  -d '{"expires_on": "2020-01-01"}'
```
Expected: `200` with `{ "valid": false, "error": "DOCUMENT_EXPIRED" }`.

Verify: `SELECT COUNT(*) FROM kyc.kyc_documents` — count should NOT increase from stateless calls.
