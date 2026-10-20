# API Contracts — E02 User & Profile Management

**Feature**: `002-user-profile-management` | **Date**: 2026-08-27
**Base path**: `/v1` | **Envelope**: Platform Foundation `ApiResponse`

All responses use the standard Platform Foundation envelope (aligned to E01 established pattern; `meta` field is not included — T185):
```json
{
  "success": true|false,
  "data": { ... } | null,
  "error": null | { "code": "ERROR_CODE", "message": "Human message", "trace_id": "uuid" }
}
```

The `X-Correlation-ID` response header carries the correlation ID on every response.

All authenticated endpoints require `Authorization: Bearer {token}`. All state-changing endpoints require `Idempotency-Key: {uuid}` and `X-Correlation-ID: {uuid}` headers.

---

## Customer / Agent Self-Service Endpoints

### GET /v1/users/profile

**Intent**: Return the authenticated user's own profile.  
**Auth**: Bearer token; `user_id` extracted from JWT — no path parameter.  
**Idempotent**: Yes (read-only).

**Response 200**:
```json
{
  "success": true,
  "data": {
    "user_id": "uuid",
    "first_name": "string | null",
    "last_name": "string | null",
    "date_of_birth": "YYYY-MM-DD | null",
    "gender": "male|female|non_binary|prefer_not_to_say | null",
    "address": {
      "street": "string | null",
      "city": "string | null",
      "region": "string | null",
      "postal_code": "string | null",
      "country": "XX | null"
    },
    "preferred_language": "string | null",
    "email": "string | null",
    "mobile_number": "string | null",
    "profile_photo_url": "https://signed-url... | null",
    "account_status": "active|suspended|pending_closure|closed",
    "created_at": "ISO-8601",
    "updated_at": "ISO-8601"
  }
}
```

**Note**: `profile_photo_url` is a signed URL (TTL from `profile.photo.signed_url_ttl_seconds`). `profile_photo_key` is never included.

**Error responses**:
| Code | HTTP | Error Code | Condition |
|---|---|---|---|
| 401 | 401 | `UNAUTHORIZED` | Missing or invalid token |
| 404 | 404 | `PROFILE_NOT_FOUND` | Profile not yet created (E01 event lag) |

---

### PUT /v1/users/profile/personal

**Intent**: Update the authenticated user's personal information fields.  
**Auth**: Bearer token.  
**Idempotent**: Yes (with `Idempotency-Key`).

**Request body** (all fields optional; send only fields to update):
```json
{
  "first_name": "string (max 100)",
  "last_name": "string (max 100)",
  "date_of_birth": "YYYY-MM-DD",
  "gender": "male|female|non_binary|prefer_not_to_say",
  "address": {
    "street": "string (max 255)",
    "city": "string (max 100)",
    "region": "string (max 100)",
    "postal_code": "string (max 20)",
    "country": "XX (ISO 3166-1 alpha-2)"
  },
  "preferred_language": "string (BCP 47, max 5)"
}
```

**Forbidden fields**: `id`, `user_id`, `email`, `mobile_number`, `account_status`, `created_at`, `updated_at`, `profile_photo_key`, `pending_*`. If included, return `FIELD_NOT_PERMITTED`.

**Response 200**: Same shape as `GET /v1/users/profile`.

**Error responses**:
| HTTP | Error Code | Condition |
|---|---|---|
| 400 | `VALIDATION_ERROR` | Invalid field format (bad date, invalid country, etc.) |
| 400 | `FIELD_NOT_PERMITTED` | Protected field included in request body |
| 422 | `MINIMUM_AGE_VIOLATION` | Date of birth results in age below configured minimum |
| 409 | `IDEMPOTENCY_KEY_MISMATCH` | Same idempotency key, different payload |

---

### POST /v1/users/profile/photo

**Intent**: Upload a new profile photo.  
**Auth**: Bearer token.  
**Content-Type**: `multipart/form-data`  
**Idempotent**: No.

**Request form fields**:
- `photo`: binary file upload

**Validation** (all limits from `platform.configuration_values`):
- `Content-Type` of file part must be in `profile.photo.accepted_mime_types`
- File size ≤ `profile.photo.max_size_bytes`
- Dimensions ≥ `profile.photo.min_width_px` × `profile.photo.min_height_px`

**Response 200**:
```json
{ "success": true, "data": { "profile_photo_url": "https://signed-url..." } }
```

**Error responses**:
| HTTP | Error Code | Condition |
|---|---|---|
| 413 | `FILE_TOO_LARGE` | File exceeds configured maximum size |
| 415 | `UNSUPPORTED_MEDIA_TYPE` | MIME type not in accepted list |
| 422 | `IMAGE_DIMENSIONS_TOO_SMALL` | Image below minimum dimensions |

---

### POST /v1/users/profile/email/change

**Intent**: Initiate an email address change; sends OTP to new email AND security alert to old email.  
**Auth**: Bearer token.  
**Idempotent**: Yes.

**Request**:
```json
{ "new_email": "user@example.com" }
```

**Response 200**:
```json
{ "success": true, "data": { "challenge_id": "uuid" } }
```

**Note**: Response is identical whether `new_email` is already in use or not (no disclosure).

**Error responses**:
| HTTP | Error Code | Condition |
|---|---|---|
| 400 | `INVALID_EMAIL_FORMAT` | `new_email` fails email format validation |
| 400 | `SAME_CONTACT_VALUE` | `new_email` matches current email |
| 409 | `CONTACT_CHANGE_IN_PROGRESS` | Pending email change already exists |
| 429 | `RATE_LIMIT_EXCEEDED` | Contact change rate limit exceeded |

---

### POST /v1/users/profile/email/verify

**Intent**: Complete email change by verifying the OTP.  
**Auth**: Bearer token.  
**Idempotent**: Yes.

**Request**:
```json
{ "challenge_id": "uuid", "otp": "string" }
```

**Response 200**:
```json
{ "success": true, "data": { "email": "j*****@example.com" } }
```

**Error responses**:
| HTTP | Error Code | Condition |
|---|---|---|
| 400 | `OTP_INVALID` | OTP code does not match |
| 400 | `OTP_EXPIRED` | Challenge has expired |
| 404 | `CHALLENGE_NOT_FOUND` | `challenge_id` not found |
| 409 | `CHALLENGE_ALREADY_COMPLETED` | Challenge already successfully verified |
| 500 | `INTERNAL_ERROR` | Atomic update to E01 failed; challenge remains active; client should retry |

---

### POST /v1/users/profile/mobile/change

**Intent**: Initiate a mobile number change; sends OTP to new mobile AND security alert to old mobile.  
**Auth**: Bearer token.  
**Idempotent**: Yes.

**Request**:
```json
{ "new_mobile": "+26777123456" }
```

**Response 200**: `{ "success": true, "data": { "challenge_id": "uuid" } }`

**Error responses**: Same as `/email/change` substituting `INVALID_MOBILE_FORMAT`.

---

### POST /v1/users/profile/mobile/verify

**Intent**: Complete mobile number change by verifying the OTP.  
**Auth**: Bearer token.  
**Idempotent**: Yes.

Same structure as `/email/verify` substituting mobile values.

---

### GET /v1/users/profile/status

**Intent**: Return current account status and transition history.  
**Auth**: Bearer token.

**Response 200**:
```json
{
  "success": true,
  "data": {
    "account_status": "active|suspended|pending_closure|closed",
    "history": [
      {
        "from_status": "string | null",
        "to_status": "string",
        "reason": "string | null",
        "actor_type": "USER|ADMIN|SYSTEM",
        "created_at": "ISO-8601"
      }
    ]
  }
}
```

**Note**: `actor_id` is NOT returned to the user — only `actor_type`. Admin path includes `actor_id`.

---

### POST /v1/users/profile/closure/request

**Intent**: Self-service account closure request.  
**Auth**: Bearer token.  
**Idempotent**: Yes.

**Request body**: Empty or `{}`.

**Response 200** (no obligations):
```json
{ "success": true, "data": { "account_status": "pending_closure" } }
```

**Response 409** (obligations exist):
```json
{
  "success": false,
  "error": {
    "code": "CLOSURE_BLOCKED_BY_OBLIGATIONS",
    "message": "Account closure is blocked by unresolved obligations.",
    "details": { "blocked_by": ["wallet_balance", "pending_transactions"] }
  }
}
```

---

### DELETE /v1/users/profile/closure/request

**Intent**: Cancel a pending account closure; restores `active` status.  
**Auth**: Bearer token.  
**Idempotent**: Yes.

**Request body**: Optional `{ "reason": "string" }`.

**Response 200**:
```json
{ "success": true, "data": { "account_status": "active" } }
```

**Error responses**:
| HTTP | Error Code | Condition |
|---|---|---|
| 400 | `INVALID_STATUS_TRANSITION` | Account is not in `pending_closure` status |

---

### POST /v1/users/profile/data-export

**Intent**: Request a personal data export package.  
**Auth**: Bearer token.  
**Idempotent**: Yes.

**Response 200**:
```json
{ "success": true, "data": { "export_id": "uuid", "status": "processing" } }
```

**Error responses**:
| HTTP | Error Code | Condition |
|---|---|---|
| 409 | `EXPORT_IN_PROGRESS` | Active export already processing for this user |

---

### GET /v1/users/profile/data-export/{export_id}

**Intent**: Poll export status and retrieve download URL when ready.  
**Auth**: Bearer token.

**Response 200**:
```json
{
  "success": true,
  "data": {
    "export_id": "uuid",
    "status": "processing|ready|downloaded|expired|failed",
    "download_url": "https://signed-url... | null",
    "expires_at": "ISO-8601 | null"
  }
}
```

---

## Admin Endpoints

### GET /v1/admin/users/{user_id}/profile

**Auth**: Admin Bearer + `profile.read` permission.

**Response**: Same shape as `GET /v1/users/profile` plus `actor_id` in audit fields.

**Error responses**: `403 FORBIDDEN` (missing permission), `404 PROFILE_NOT_FOUND`.

---

### PUT /v1/admin/users/{user_id}/profile/personal

**Auth**: Admin Bearer + `profile.update` permission.

**Request/Response**: Same as `/v1/users/profile/personal`.

**Error responses**: Same plus `403 FORBIDDEN`.

---

### GET /v1/admin/users/{user_id}/profile/status

**Auth**: Admin Bearer + `profile.read` permission.

**Response**: Same as `/v1/users/profile/status` plus `actor_id` in each history entry.

---

### POST /v1/admin/users/{user_id}/suspend

**Auth**: Admin Bearer + `account.suspend` permission.  
**Idempotent**: Yes.

**Request**:
```json
{ "reason": "string (required)" }
```

**Response 200**:
```json
{ "success": true, "data": { "account_status": "suspended" } }
```

**Error responses**:
| HTTP | Error Code | Condition |
|---|---|---|
| 400 | `INVALID_STATUS_TRANSITION` | Account not in `active` status |
| 400 | `VALIDATION_ERROR` | `reason` missing or empty |
| 403 | `FORBIDDEN` | Missing `account.suspend` permission |

---

### POST /v1/admin/users/{user_id}/reactivate

**Auth**: Admin Bearer + `account.reactivate` permission.  
**Idempotent**: Yes.

**Request body**: Optional `{ "reason": "string" }`.

**Response 200**:
```json
{ "success": true, "data": { "account_status": "active" } }
```

**Error responses**: `400 INVALID_STATUS_TRANSITION` (not in `suspended`), `403 FORBIDDEN`.

---

### POST /v1/admin/users/{user_id}/cancel-closure

**Auth**: Admin Bearer + `account.reactivate` permission.  
**Idempotent**: Yes.

**Request body**: Optional `{ "reason": "string" }`.

**Response 200**:
```json
{ "success": true, "data": { "account_status": "active" } }
```

**Error responses**: `400 INVALID_STATUS_TRANSITION` (not in `pending_closure`), `403 FORBIDDEN`.

---

### POST /v1/admin/users/{user_id}/close

**Auth**: Admin Bearer + `account.close` permission.  
**Idempotent**: Yes.

**Request body**: Optional `{ "reason": "string" }`.

**Response 200**:
```json
{ "success": true, "data": { "account_status": "closed" } }
```

**Error responses**:
| HTTP | Error Code | Condition |
|---|---|---|
| 400 | `INVALID_STATUS_TRANSITION` | Account not in `pending_closure` |
| 403 | `FORBIDDEN` | Missing `account.close` permission |
| 409 | `CLOSURE_BLOCKED_BY_OBLIGATIONS` | Financial obligations found in final check |

---

## Common Error Codes

| Error Code | HTTP | Meaning |
|---|---|---|
| `UNAUTHORIZED` | 401 | Missing/invalid/expired Bearer token |
| `FORBIDDEN` | 403 | Valid token but insufficient permission or IDOR mismatch |
| `PROFILE_NOT_FOUND` | 404 | Profile record not yet created |
| `VALIDATION_ERROR` | 400 | Request body fails validation |
| `FIELD_NOT_PERMITTED` | 400 | Protected field in request body |
| `MINIMUM_AGE_VIOLATION` | 422 | Age below configured minimum |
| `SAME_CONTACT_VALUE` | 400 | New contact value matches current |
| `CONTACT_CHANGE_IN_PROGRESS` | 409 | Pending change already exists for this contact type |
| `OTP_INVALID` | 400 | OTP code does not match |
| `OTP_EXPIRED` | 400 | OTP challenge has expired |
| `CHALLENGE_NOT_FOUND` | 404 | challenge_id not found |
| `CHALLENGE_ALREADY_COMPLETED` | 409 | Challenge already verified |
| `INVALID_STATUS_TRANSITION` | 400 | State machine rejects the requested transition |
| `CLOSURE_BLOCKED_BY_OBLIGATIONS` | 409 | Financial obligations prevent closure |
| `EXPORT_IN_PROGRESS` | 409 | Active export already processing |
| `FILE_TOO_LARGE` | 413 | Photo exceeds configured maximum size |
| `UNSUPPORTED_MEDIA_TYPE` | 415 | Photo MIME type not accepted |
| `IMAGE_DIMENSIONS_TOO_SMALL` | 422 | Photo below minimum dimensions |
| `RATE_LIMIT_EXCEEDED` | 429 | Rate limit for this endpoint exceeded |
| `IDEMPOTENCY_KEY_MISMATCH` | 409 | Same key, different payload hash |
| `INTERNAL_ERROR` | 500 | Unexpected server error; safe to retry |
