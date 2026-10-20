# API Contract — KYC Review (Compliance Officer / Admin)

**Feature**: E05 — KYC & Identity Verification
**Base path**: `/v1/kyc`
**Auth**: `identity.auth` + `identity.require-permission:{kyc.case.*}` middleware

---

## Error Codes

| Code | HTTP | Condition |
|------|------|-----------|
| `AUTHORIZATION_001` | 403 | Actor lacks required permission |
| `AUTHORIZATION_002` | 403 | Actor type not permitted |
| `KYC_CASE_NOT_FOUND` | 404 | Case does not exist |
| `INVALID_CASE_STATUS` | 422 | Transition not valid from current status |
| `REJECTION_REASON_REQUIRED` | 400 | `rejection_reason` is empty or absent |
| `INFO_REQUEST_NOTE_REQUIRED` | 400 | `info_request_note` is empty or absent |

---

## `GET /v1/kyc/cases`

List KYC cases, with optional status filter. Paginated.

**Required permission**: `kyc.case.review`

**Query parameters**:

| Param | Type | Default | Description |
|-------|------|---------|-------------|
| `status` | string | none | Filter by status: `initiated` / `under_review` / `approved` / `rejected` / `needs_info` / `expired` |
| `user_id` | UUID | none | Filter by user |
| `page` | integer | 1 | Pagination cursor |
| `per_page` | integer | 20 | Max 100 |

**Response** `200 OK`:
```json
{
  "success": true,
  "data": [
    {
      "case_id": "01966b4c-...",
      "user_id": "01966aaa-...",
      "status": "under_review",
      "attempt_number": 1,
      "submitted_at": "2026-09-17T10:05:00Z",
      "personal_info_summary": {
        "full_legal_name": "Jane Doe",
        "nationality": "BW",
        "national_id_type": "national_id"
      },
      "documents_count": 2
    }
  ],
  "meta": {
    "current_page": 1,
    "per_page": 20,
    "total": 47
  }
}
```

---

## `GET /v1/kyc/cases/{id}`

Retrieve full case details including personal information and document list.

**Required permission**: `kyc.case.review` (Admin/Compliance Officer) or `kyc.case.read` (own case only)

**Response** `200 OK`:
```json
{
  "success": true,
  "data": {
    "case_id": "01966b4c-...",
    "user_id": "01966aaa-...",
    "status": "under_review",
    "attempt_number": 1,
    "submitted_at": "2026-09-17T10:05:00Z",
    "reviewed_at": null,
    "reviewer_id": null,
    "rejection_reason": null,
    "info_request_note": null,
    "verified_at": null,
    "expires_at": null,
    "personal_information": {
      "full_legal_name": "Jane Doe",
      "date_of_birth": "1990-03-15",
      "nationality": "BW",
      "national_id_type": "national_id",
      "address": {
        "line_1": "123 Main Street",
        "city": "Gaborone",
        "country_code": "BW"
      }
    },
    "documents": [
      {
        "document_id": "01966b4d-...",
        "document_type": "national_id",
        "mime_type": "image/jpeg",
        "file_size_bytes": 204800,
        "expires_on": "2030-01-01",
        "validation_status": "valid",
        "created_at": "2026-09-17T10:01:00Z"
      }
    ],
    "verification_results": [
      {
        "result_id": "01966b4e-...",
        "provider_name": "null-provider",
        "outcome": "manual_review_required",
        "confidence_score": null,
        "created_at": "2026-09-17T10:05:30Z"
      }
    ]
  }
}
```

**Note**: `national_id_hash` is NOT returned in any response. The API returns only `national_id_type`.

---

## `POST /v1/kyc/cases/{id}/approve`

Approve a KYC case. Sets `status = approved`, `verified_at`, `expires_at`, emits domain event.

**Required permission**: `kyc.case.approve`

**Request body**: none

**Response** `200 OK`:
```json
{
  "success": true,
  "data": {
    "case_id": "01966b4c-...",
    "status": "approved",
    "verified_at": "2026-09-17T10:10:00Z",
    "expires_at": "2027-09-17T10:10:00Z"
  }
}
```

**Note**: `expires_at` is calculated as `verified_at + kyc.validity_period_days`. If `kyc.validity_period_days` is TBD, the field is `null` and a log warning is emitted.

`422 Unprocessable` — `INVALID_CASE_STATUS` (not `under_review`).

`403 Forbidden` — `AUTHORIZATION_001` (actor lacks `kyc.case.approve`).

---

## `POST /v1/kyc/cases/{id}/reject`

Reject a KYC case with a mandatory reason.

**Required permission**: `kyc.case.review`

**Request body** (`application/json`):
```json
{
  "rejection_reason": "The submitted passport has expired. Please upload a valid document."
}
```

**Response** `200 OK`:
```json
{
  "success": true,
  "data": {
    "case_id": "01966b4c-...",
    "status": "rejected",
    "rejection_reason": "The submitted passport has expired. Please upload a valid document."
  }
}
```

`400 Bad Request` — `REJECTION_REASON_REQUIRED` if field is empty or absent.

---

## `POST /v1/kyc/cases/{id}/request-info`

Request additional information from the customer.

**Required permission**: `kyc.case.review`

**Request body** (`application/json`):
```json
{
  "info_request_note": "Please upload a utility bill dated within the last 3 months as proof of address."
}
```

**Response** `200 OK`:
```json
{
  "success": true,
  "data": {
    "case_id": "01966b4c-...",
    "status": "needs_info",
    "info_request_note": "Please upload a utility bill dated within the last 3 months as proof of address."
  }
}
```

`400 Bad Request` — `INFO_REQUEST_NOTE_REQUIRED` if field is empty or absent.
