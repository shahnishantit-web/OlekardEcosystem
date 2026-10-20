# API Contract — QR Code Management (Merchant-Facing)

Base path: `/v1/qr-codes`  
Auth: Bearer token (merchant role, `qr.generate` / `qr.manage` permissions)  
Content-Type: `application/json`

---

## POST /v1/qr-codes

Generate a new QR code (static or dynamic).

**Permission**: `qr.generate`

**Request**

```json
{
  "type": "static | dynamic",
  "label": "Counter 1",
  "amount": 50.00,
  "currency": "BWP",
  "merchant_ref": "INV-2026-001",
  "expiry_seconds": 300
}
```

| Field | Required | Notes |
|-------|----------|-------|
| type | YES | `static` or `dynamic` |
| label | NO | Max 100 chars; merchant display label; not included in signed payload |
| amount | YES if dynamic | DECIMAL ≥ 0.01; omit for static |
| currency | NO | Defaults to `BWP`; must match merchant wallet currency |
| merchant_ref | NO | Max 255 chars; included in signed payload for dynamic |
| expiry_seconds | NO | Dynamic only; defaults to `qr.dynamic_expiry_seconds` config value |

**Response 201 Created**

```json
{
  "data": {
    "id": "019270ab-...",
    "type": "dynamic",
    "label": "Counter 1",
    "amount": "50.00",
    "currency": "BWP",
    "merchant_ref": "INV-2026-001",
    "payload": "eyJ0eXBlIjoiZHluYW1pYyIs...",
    "status": "active",
    "expires_at": "2026-09-25T14:05:00Z",
    "created_at": "2026-09-25T14:00:00Z"
  }
}
```

**Errors**

| Code | Status | Condition |
|------|--------|-----------|
| FORBIDDEN | 403 | Actor does not have `qr.generate` permission |
| VALIDATION_ERROR | 422 | `amount` missing for dynamic; `amount` present for static; `label` too long; unsupported currency |
| MERCHANT_NOT_ACTIVE | 422 | Authenticated merchant's wallet is not active |

---

## GET /v1/qr-codes

List the authenticated merchant's QR codes.

**Permission**: `qr.manage`

**Query parameters**

| Param | Type | Notes |
|-------|------|-------|
| status | string | Filter by status: `active`, `inactive`, `used`, `expired` |
| type | string | Filter by type: `static`, `dynamic` |
| per_page | int | Default 25, max 100 |
| cursor | string | Pagination cursor |

**Response 200 OK**

```json
{
  "data": [
    {
      "id": "019270ab-...",
      "type": "static",
      "label": "Counter 1",
      "amount": null,
      "currency": "BWP",
      "status": "active",
      "expires_at": null,
      "created_at": "2026-09-25T13:00:00Z"
    }
  ],
  "meta": {
    "next_cursor": "...",
    "per_page": 25
  }
}
```

---

## GET /v1/qr-codes/{id}

Get a single QR code by ID.

**Permission**: `qr.manage` (own QR codes only)

**Response 200 OK**: Full QR code object (same shape as POST response).

**Errors**

| Code | Status | Condition |
|------|--------|-----------|
| NOT_FOUND | 404 | QR not found or belongs to different merchant |

---

## DELETE /v1/qr-codes/{id}

Deactivate a QR code (transitions `active` → `inactive`).

**Permission**: `qr.manage` (own QR codes only)

**Response 200 OK**

```json
{
  "data": {
    "id": "019270ab-...",
    "status": "inactive",
    "updated_at": "2026-09-25T14:10:00Z"
  }
}
```

**Errors**

| Code | Status | Condition |
|------|--------|-----------|
| NOT_FOUND | 404 | QR not found or belongs to different merchant |
| QR_NOT_ACTIVE | 422 | QR is already inactive, used, or expired |
