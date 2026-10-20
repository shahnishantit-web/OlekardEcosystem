# API Contract — E34 QA & Validation

**Base path:** `/v1/qa`
**Auth:** Bearer token (JWT). All endpoints require permission `qa.execute`.
**Idempotency:** All `POST` trigger endpoints require `Idempotency-Key` header (UUID v4/v7). Duplicate key returns the existing `TestRun` (HTTP 200) rather than creating a new one.
**Correlation:** `X-Correlation-Id` header forwarded through all requests and stored on `TestRun`.

---

## Standard Error Envelope

```json
{
  "error": {
    "code": "QA_ERR_CODE",
    "message": "Human-readable message",
    "correlation_id": "uuid"
  }
}
```

| Code | Status | Meaning |
|------|--------|---------|
| `QA_UNAUTHORIZED` | 401 | Missing/invalid token |
| `QA_FORBIDDEN` | 403 | Actor lacks `qa.execute` |
| `QA_IDEM_REQUIRED` | 422 | `Idempotency-Key` header absent on POST |
| `QA_CATEGORY_RUNNING` | 409 | A run for this category is already `running` |
| `QA_RUN_NOT_FOUND` | 404 | `TestRun` id not found |

---

## Trigger Endpoints (18 endpoints)

All 18 trigger endpoints share the same request/response shape. Only the URL differs.

### POST /v1/qa/{category-slug}

Category slug → endpoint mapping:

| Story | Slug |
|-------|------|
| US-QA-001 | `unit-test-authentication` |
| US-QA-002 | `integration-test-authentication` |
| US-QA-003 | `api-authorization-testing` |
| US-QA-004 | `wallet-balance-testing` |
| US-QA-005 | `ledger-balance-testing` |
| US-QA-006 | `transfer-testing` |
| US-QA-007 | `duplicate-transaction-testing` |
| US-QA-008 | `callback-testing` |
| US-QA-009 | `payment-failure-testing` |
| US-QA-010 | `reconciliation-testing` |
| US-QA-011 | `kyc-testing` |
| US-QA-012 | `agent-testing` |
| US-QA-013 | `security-testing` |
| US-QA-014 | `performance-testing` |
| US-QA-015 | `load-testing` |
| US-QA-016 | `disaster-recovery-testing` |
| US-QA-017 | `uat` |
| US-QA-018 | `regression-testing` |

**Request headers:**
```
Authorization: Bearer {token}
Idempotency-Key: {uuid}
X-Correlation-Id: {uuid}   (optional; generated server-side if absent)
Content-Type: application/json
```

**Request body:** empty `{}` (no required fields; optional `options` key reserved for future use)

**Response 202 Accepted:**
```json
{
  "data": {
    "run_id": "01932abc-...",
    "category": "unit_auth",
    "status": "pending",
    "correlation_id": "uuid",
    "created_at": "2026-10-06T09:00:00Z"
  }
}
```

**Response 200 OK** (idempotent duplicate):
```json
{
  "data": {
    "run_id": "01932abc-...",
    "category": "unit_auth",
    "status": "completed",
    "correlation_id": "uuid",
    "created_at": "2026-10-06T09:00:00Z"
  }
}
```

**Emitted events (after job completes):**
- `US_QA_{NNN}_REQUESTED` — on run creation
- `US_QA_{NNN}_SUCCEEDED` — when all results = `passed`
- `US_QA_{NNN}_FAILED` — when any result = `failed` or `error`
- `AUDIT_EVENT_RECORDED` — always

---

## GET /v1/qa/runs

List test runs (most recent first, paginated).

**Query params:**
| Param | Type | Description |
|-------|------|-------------|
| `category` | string | Filter by `QaCategory` value |
| `status` | string | Filter by `TestRunStatus` |
| `page` | integer | Default 1 |
| `per_page` | integer | Default 20, max 100 |

**Response 200:**
```json
{
  "data": [
    {
      "id": "01932abc-...",
      "category": "unit_auth",
      "status": "completed",
      "triggered_by": "uuid",
      "passed": 8,
      "failed": 0,
      "skipped": 0,
      "total": 8,
      "started_at": "2026-10-06T09:00:05Z",
      "completed_at": "2026-10-06T09:00:12Z",
      "created_at": "2026-10-06T09:00:00Z"
    }
  ],
  "meta": {
    "current_page": 1,
    "per_page": 20,
    "total": 42
  }
}
```

---

## GET /v1/qa/runs/{id}

Get a single test run with all `TestResult` records.

**Response 200:**
```json
{
  "data": {
    "id": "01932abc-...",
    "category": "unit_auth",
    "status": "completed",
    "triggered_by": "uuid",
    "correlation_id": "uuid",
    "started_at": "2026-10-06T09:00:05Z",
    "completed_at": "2026-10-06T09:00:12Z",
    "created_at": "2026-10-06T09:00:00Z",
    "results": [
      {
        "id": "uuid",
        "test_case": {
          "id": "uuid",
          "code": "qa.unit_auth.happy_path",
          "name": "Unit auth happy path",
          "scenario_type": "happy_path"
        },
        "outcome": "passed",
        "duration_ms": 45,
        "error_message": null,
        "evidence": null
      },
      {
        "id": "uuid",
        "test_case": { "...": "..." },
        "outcome": "failed",
        "duration_ms": 120,
        "error_message": "Expected 200, got 401 on POST /v1/auth/login",
        "evidence": {
          "request": { "...": "..." },
          "response": { "status": 401, "body": { "...": "..." } }
        }
      }
    ]
  }
}
```

**Response 404:** `QA_RUN_NOT_FOUND`

---

## GET /v1/qa/dashboard

Aggregate view: latest run status per category.

**Response 200:**
```json
{
  "data": {
    "summary": {
      "total_categories": 18,
      "all_passed": 14,
      "has_failures": 3,
      "pending_or_running": 1
    },
    "categories": [
      {
        "category": "unit_auth",
        "story": "US-QA-001",
        "label": "Unit test authentication",
        "latest_run_id": "uuid",
        "latest_status": "completed",
        "passed": 8,
        "failed": 0,
        "total": 8,
        "last_run_at": "2026-10-06T09:00:12Z"
      },
      {
        "category": "wallet_balance",
        "story": "US-QA-004",
        "label": "Wallet balance testing",
        "latest_run_id": null,
        "latest_status": null,
        "passed": null,
        "failed": null,
        "total": null,
        "last_run_at": null
      }
    ]
  }
}
```

---

## OpenAPI Spec

The authoritative OpenAPI document will be written to:
`wallet-ecosystem/services/api/openapi/v1/qa.yaml`

All 21 endpoints (18 trigger + list + detail + dashboard) will be documented there. Refer to that file after implementation for schema details.
