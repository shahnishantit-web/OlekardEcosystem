# API Contract — QR Payments (Customer-Facing)

Base path: `/v1/qr-payments`  
Auth: Bearer token (customer role, `qr.pay` permission)  
Content-Type: `application/json`

---

## POST /v1/qr-payments/resolve

Decode and validate a scanned QR payload. Returns merchant details and payment information for the customer to review before confirming. No money moves.

**Permission**: `qr.pay`

**Request**

```json
{
  "payload": "eyJ0eXBlIjoiZHluYW1pYyIs..."
}
```

**Response 200 OK — Static QR**

```json
{
  "data": {
    "qr_code_id": "019270ab-...",
    "type": "static",
    "merchant": {
      "id": "01926fa2-...",
      "display_name": "Kgalagadi Café",
      "category": "food_and_beverage"
    },
    "currency": "BWP",
    "amount_required": true,
    "expires_at": null
  }
}
```

**Response 200 OK — Dynamic QR**

```json
{
  "data": {
    "qr_code_id": "019270ab-...",
    "type": "dynamic",
    "merchant": {
      "id": "01926fa2-...",
      "display_name": "Kgalagadi Café",
      "category": "food_and_beverage"
    },
    "currency": "BWP",
    "amount": "50.00",
    "amount_required": false,
    "expires_at": "2026-09-25T14:05:00Z"
  }
}
```

**Errors**

| Code | Status | Condition |
|------|--------|-----------|
| INVALID_QR_PAYLOAD | 422 | Signature invalid, payload malformed, or unrecognisable — audit-logged |
| QR_EXPIRED | 422 | Dynamic QR past its `expires_at` |
| QR_ALREADY_USED | 422 | Dynamic QR already used for a completed payment |
| QR_NOT_ACTIVE | 422 | QR is inactive |
| MERCHANT_NOT_ACTIVE | 422 | Merchant wallet is suspended or inactive |
| UNAUTHORIZED | 401 | Missing or invalid token |

---

## POST /v1/qr-payments/quote

Get a fee quote for a static QR payment amount. Call this after resolve, before confirm.

**Permission**: `qr.pay`

**Request**

```json
{
  "qr_code_id": "019270ab-...",
  "amount": 20.00,
  "currency": "BWP"
}
```

**Response 200 OK**

```json
{
  "data": {
    "amount": "20.00",
    "fee": "1.00",
    "total": "21.00",
    "currency": "BWP"
  }
}
```

**Errors**

| Code | Status | Condition |
|------|--------|-----------|
| AMOUNT_OUT_OF_RANGE | 422 | Amount below minimum or above maximum |
| NOT_FOUND | 404 | QR code not found or not active |
| CURRENCY_MISMATCH | 422 | Customer wallet currency ≠ QR currency |

---

## POST /v1/qr-payments

Confirm and execute a QR payment. This is the financial state-change endpoint.

**Permission**: `qr.pay`  
**Required header**: `Idempotency-Key: <uuid>`

**Request**

```json
{
  "qr_code_id": "019270ab-...",
  "amount": 20.00,
  "currency": "BWP",
  "step_up_token": "eyJhbGciOiJIUzI1NiJ9..."
}
```

| Field | Required | Notes |
|-------|----------|-------|
| qr_code_id | YES | Must be active (or reserved but re-attempting); resolved at scan time |
| amount | YES if static | Must match the quoted amount; omit for dynamic (amount is in QR) |
| currency | YES | Must match QR and wallet currency |
| step_up_token | Conditional | Required if payment amount ≥ `auth.step_up_threshold` |

**Response 201 Created — Completed**

```json
{
  "data": {
    "id": "01927100-...",
    "status": "completed",
    "qr_code_id": "019270ab-...",
    "amount": "20.00",
    "fee": "1.00",
    "total_debited": "21.00",
    "currency": "BWP",
    "merchant_display_name": "Kgalagadi Café",
    "receipt_ref": "QRPMT-20260925-A3F7",
    "created_at": "2026-09-25T14:02:30Z"
  }
}
```

**Errors**

| Code | Status | Condition |
|------|--------|-----------|
| UNAUTHORIZED | 401 | Missing or invalid token |
| FORBIDDEN | 403 | Wallet does not belong to authenticated customer |
| INSUFFICIENT_BALANCE | 422 | Customer wallet balance < principal + fee |
| STEP_UP_REQUIRED | 422 | Amount ≥ threshold; no or invalid step-up token provided |
| STEP_UP_TOKEN_EXPIRED | 422 | Step-up token provided but expired |
| QR_EXPIRED | 422 | Dynamic QR expired between resolve and confirm |
| QR_ALREADY_USED | 422 | Dynamic QR already used |
| QR_NOT_ACTIVE | 422 | QR is inactive |
| QR_RESERVED | 409 | QR reserved by another customer; reservation window not yet expired |
| MERCHANT_NOT_ACTIVE | 422 | Merchant wallet deactivated between scan and confirm |
| CURRENCY_MISMATCH | 422 | Customer or merchant wallet currency differs from QR currency |
| QR_PAYLOAD_MISMATCH | 422 | Confirmed amount differs from dynamic QR amount |

---

## GET /v1/qr-payments/{id}

Get a QR payment transaction by ID.

**Permission**: `qr.pay` (own transactions only)

**Response 200 OK**

```json
{
  "data": {
    "id": "01927100-...",
    "status": "completed",
    "qr_code_id": "019270ab-...",
    "amount": "20.00",
    "fee": "1.00",
    "total_debited": "21.00",
    "currency": "BWP",
    "merchant_display_name": "Kgalagadi Café",
    "receipt_ref": "QRPMT-20260925-A3F7",
    "created_at": "2026-09-25T14:02:30Z"
  }
}
```

**Errors**

| Code | Status | Condition |
|------|--------|-----------|
| NOT_FOUND | 404 | Transaction not found or belongs to different customer |

---

## GET /v1/qr-payments/{id}/receipt

Retrieve the immutable payment receipt for a completed QR payment.

**Permission**: `qr.pay` (own receipts only)

**Response 200 OK**

```json
{
  "data": {
    "receipt_ref": "QRPMT-20260925-A3F7",
    "merchant_display_name": "Kgalagadi Café",
    "merchant_ref": "INV-2026-001",
    "amount": "20.00",
    "fee": "1.00",
    "total_debited": "21.00",
    "currency": "BWP",
    "status": "completed",
    "issued_at": "2026-09-25T14:02:31Z"
  }
}
```

**Errors**

| Code | Status | Condition |
|------|--------|-----------|
| NOT_FOUND | 404 | Transaction not found or belongs to different customer |
| RECEIPT_NOT_AVAILABLE | 422 | Payment is pending or failed |
