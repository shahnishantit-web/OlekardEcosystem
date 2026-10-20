# API Contracts — E31 Customer Application

All endpoints served by `wallet-ecosystem/services/api`.
Base URL: `{API_BASE_URL}/api/v1`
Auth header: `Authorization: Bearer {access_token}` (except unauthenticated endpoints)
Idempotency header: `Idempotency-Key: {uuid-v4}` (all financial POSTs)
Correlation header: `X-Correlation-Id: {uuid-v4}` (all requests, auto-injected by `CorrelationIdInterceptor`)

---

## Authentication (US-CUST-001 to US-CUST-004)

### Register — Step 1: Request OTP
```
POST /v1/customer/auth/register/initiate
Content-Type: application/json

{
  "phone": "+254712345678",
  "first_name": "Jane",
  "last_name": "Doe"
}

200 OK
{
  "otp_reference": "otp_abc123",
  "expires_in_seconds": 300
}

422 Unprocessable Entity
{
  "message": "Validation error",
  "errors": { "phone": ["Phone already registered"] }
}
```

### Register — Step 2: Verify OTP
```
POST /v1/customer/auth/register/verify
Content-Type: application/json

{
  "otp_reference": "otp_abc123",
  "otp_code": "123456"
}

200 OK
{
  "registration_token": "reg_token_xyz",
  "expires_in_seconds": 900
}
```

### Register — Step 3: Complete (set PIN)
```
POST /v1/customer/auth/register/complete
Content-Type: application/json
Authorization: Bearer {registration_token}

{
  "pin_hash": "$2b$10$...",   // bcrypt hash of PIN
  "email": "jane@example.com" // optional
}

201 Created
{
  "access_token": "eyJ...",
  "refresh_token": "eyJ...",
  "expires_in": 900,
  "customer": { "id": "...", "phone": "+254712345678", "first_name": "Jane", ... }
}
```

### Login — Step 1: Initiate (OTP or PIN)
```
POST /v1/customer/auth/login
Content-Type: application/json

{
  "phone": "+254712345678",
  "method": "pin" | "otp"
}

200 OK
{
  "challenge_token": "chal_xyz",
  "method": "pin" | "otp",
  "expires_in_seconds": 300
}
```

### Login — Step 2: Complete (PIN)

The `pin` field contains the entered PIN for verification against the central
identity service's stored hash. Production transport uses HTTPS. Never log or
persist the entered PIN. A newly salted client bcrypt hash cannot be compared
with the server's independently salted hash and must not be sent as a credential.
The historical `pin_hash` alias accepts numeric PINs only for compatibility.

```
POST /v1/customer/auth/login/verify
Content-Type: application/json

{
  "challenge_token": "chal_xyz",
  "pin": "123456"
}

200 OK
{
  "access_token": "eyJ...",
  "refresh_token": "eyJ...",
  "expires_in": 900,
  "customer": { ... }
}

401 Unauthorized
{ "message": "Invalid PIN", "attempts_remaining": 2 }
```

### Refresh Token
```
POST /v1/customer/auth/refresh
Content-Type: application/json

{ "refresh_token": "eyJ..." }

200 OK
{ "access_token": "eyJ...", "expires_in": 900 }

401 Unauthorized
{ "message": "Refresh token expired" }
```

### Logout
```
POST /v1/customer/auth/logout
Authorization: Bearer {access_token}

204 No Content
```

### Reset PIN — Initiate
```
POST /v1/customer/auth/reset-pin/initiate
Content-Type: application/json

{ "phone": "+254712345678" }

200 OK
{ "otp_reference": "otp_reset_abc", "expires_in_seconds": 300 }
```

### Reset PIN — Complete
```
POST /v1/customer/auth/reset-pin/complete
Content-Type: application/json

{
  "otp_reference": "otp_reset_abc",
  "otp_code": "654321",
  "new_pin_hash": "$2b$10$..."
}

200 OK
{ "message": "PIN updated successfully" }
```

### Step-Up Authentication Challenge (Principle 5)

When a protected endpoint determines the current session requires elevation (e.g., high-value transaction), it returns:

```
403 Forbidden
{
  "error": "step_up_required",
  "message": "This action requires additional verification"
}
```

The `AuthInterceptor` intercepts this response, navigates to the PIN overlay, collects the PIN hash, and calls:

```
POST /v1/customer/auth/step-up
Authorization: Bearer {access_token}
Content-Type: application/json

{ "pin_hash": "$2b$10$..." }

200 OK
{ "step_up_token": "su_eyJ...", "expires_in_seconds": 60 }

401 Unauthorized
{ "message": "Invalid PIN", "attempts_remaining": 2 }
```

The interceptor then retries the original request with the additional header:

```
X-StepUp-Token: su_eyJ...
```

---

## Wallet (US-CUST-006)

### Get My Wallet
```
GET /v1/customer/wallets/me

200 OK
{
  "id": "01920...",
  "wallet_number": "OLK-001-23456",
  "currency_code": "KES",
  "balance_minor_units": 150000,
  "status": "active",
  "updated_at": "2026-10-01T10:00:00Z"
}
```

### Get Wallet Limits (FR-021)
```
GET /v1/customer/wallets/me/limits

200 OK
{
  "min_fund_minor_units": 100,
  "max_fund_minor_units": 1000000,
  "max_daily_transfer_minor_units": 500000,
  "min_withdrawal_minor_units": 500,
  "daily_used_minor_units": 75000
}
```

---

## Transactions (US-CUST-007, US-CUST-008)

### List Transactions
```
GET /v1/customer/transactions?page=1&per_page=20&type=transfer&status=completed

200 OK
{
  "data": [
    {
      "id": "...",
      "reference_number": "TXN-20261001-ABCDE",
      "type": "transfer",
      "status": "completed",
      "amount_minor_units": 50000,
      "fee_minor_units": 50,
      "currency_code": "KES",
      "counterparty_name": "John Smith",
      "counterparty_wallet_number": "OLK-002-11111",
      "description": "Rent",
      "created_at": "2026-10-01T09:00:00Z",
      "completed_at": "2026-10-01T09:00:05Z"
    }
  ],
  "meta": { "current_page": 1, "per_page": 20, "total": 45, "last_page": 3 }
}
```

### Get Transaction Detail
```
GET /v1/customer/transactions/{id}

200 OK
{ ... same shape as list item ... }
```

---

## Funding / Add Money (US-CUST-011)

### Initiate Funding
```
POST /v1/customer/funding
Idempotency-Key: {uuid-v4}
Content-Type: application/json

{
  "method": "bank_transfer",
  "amount_minor_units": 100000,
  "currency_code": "KES"
}

202 Accepted
{
  "transaction_id": "...",
  "reference_number": "FND-20261001-AAAAA",
  "status": "awaiting_payment",
  "redirect_url": "https://bank.example.com/pay?ref=...",
  "created_at": "..."
}
```

---

## Transfer / Send Money (US-CUST-012)

### Look Up Recipient Wallet
```
GET /v1/customer/wallets/lookup?wallet_number=OLK-002-11111

200 OK
{ "wallet_number": "OLK-002-11111", "owner_name": "John Smith", "currency_code": "KES" }

404 Not Found
{ "message": "Wallet not found" }
```

### Preview Transfer (fee calculation)
```
POST /v1/customer/transfers/preview
Content-Type: application/json

{
  "recipient_wallet_number": "OLK-002-11111",
  "amount_minor_units": 50000,
  "currency_code": "KES"
}

200 OK
{
  "amount_minor_units": 50000,
  "fee_minor_units": 50,
  "net_debit_minor_units": 50050,
  "currency_code": "KES",
  "recipient_name": "John Smith"
}
```

### Submit Transfer
```
POST /v1/customer/transfers
Idempotency-Key: {uuid-v4}
Content-Type: application/json

{
  "recipient_wallet_number": "OLK-002-11111",
  "amount_minor_units": 50000,
  "currency_code": "KES",
  "note": "Rent"
}

201 Created
{
  "transaction_id": "...",
  "reference_number": "TXN-20261001-BBBBB",
  "status": "completed",
  "amount_minor_units": 50000,
  "fee_minor_units": 50,
  "currency_code": "KES",
  "recipient_name": "John Smith",
  "created_at": "..."
}
```

---

## Withdrawal (US-CUST-013)

### Preview Withdrawal
```
POST /v1/customer/withdrawals/preview
Content-Type: application/json

{
  "agent_wallet_number": "AGT-003-55555",
  "amount_minor_units": 20000,
  "currency_code": "KES"
}

200 OK
{
  "amount_minor_units": 20000,
  "fee_minor_units": 30,
  "net_debit_minor_units": 20030,
  "currency_code": "KES",
  "agent_name": "M-Pesa Agent - Westlands"
}
```

### Submit Withdrawal
```
POST /v1/customer/withdrawals
Idempotency-Key: {uuid-v4}
Content-Type: application/json

{
  "agent_wallet_number": "AGT-003-55555",
  "amount_minor_units": 20000,
  "currency_code": "KES"
}

202 Accepted
{
  "transaction_id": "...",
  "reference_number": "WDR-20261001-CCCCC",
  "status": "ready_for_pickup",
  "pickup_code": "789012",
  "amount_minor_units": 20000,
  "fee_minor_units": 30,
  "currency_code": "KES",
  "agent_name": "M-Pesa Agent - Westlands",
  "created_at": "..."
}
```

---

## Service Payments (US-CUST-014 to US-CUST-017)

### List Service Categories
```
GET /v1/customer/services/categories

200 OK
{
  "data": [
    { "id": "...", "name": "Airtime", "icon_url": "...", "description": "...", "provider_count": 4 },
    { "id": "...", "name": "Utilities", "icon_url": "...", "description": "...", "provider_count": 2 }
  ]
}
```

### List Providers in Category
```
GET /v1/customer/services/categories/{categoryId}/providers

200 OK
{
  "data": [
    {
      "id": "...",
      "name": "Safaricom Airtime",
      "logo_url": "...",
      "required_fields": [
        { "key": "phone_number", "label": "Phone Number", "type": "phone", "required": true }
      ],
      "fee_minor_units": 0,
      "currency_code": "KES",
      "is_active": true
    }
  ]
}
```

### Preview Service Purchase
```
POST /v1/customer/payments/service/preview
Content-Type: application/json

{
  "provider_id": "...",
  "amount_minor_units": 10000,
  "field_values": { "phone_number": "+254712345678" }
}

200 OK
{
  "amount_minor_units": 10000,
  "fee_minor_units": 0,
  "net_debit_minor_units": 10000,
  "currency_code": "KES",
  "provider_name": "Safaricom Airtime",
  "summary": "KES 100 airtime to +254712345678"
}
```

### Submit Service Purchase
```
POST /v1/customer/payments/service
Idempotency-Key: {uuid-v4}
Content-Type: application/json

{
  "provider_id": "...",
  "amount_minor_units": 10000,
  "field_values": { "phone_number": "+254712345678" }
}

201 Created
{
  "id": "...",
  "reference_number": "SVC-20261001-DDDDD",
  "status": "completed",
  "provider_name": "Safaricom Airtime",
  "amount_minor_units": 10000,
  "fee_minor_units": 0,
  "currency_code": "KES",
  "created_at": "..."
}
```

### Service Payment History
```
GET /v1/customer/payments/service?page=1&per_page=20

200 OK
{
  "data": [ { ... } ],
  "meta": { "current_page": 1, "total": 12, ... }
}
```

---

## Notifications (US-CUST-010)

### List Notifications
```
GET /v1/customer/notifications?page=1&per_page=20&unread=true

200 OK
{
  "data": [
    {
      "id": "...",
      "type": "transaction",
      "title": "Money Received",
      "body": "You received KES 500 from John Smith",
      "is_read": false,
      "metadata": { "transaction_id": "..." },
      "created_at": "..."
    }
  ],
  "meta": { ... },
  "unread_count": 3
}
```

### Mark Notification(s) as Read
```
PATCH /v1/customer/notifications/read
Content-Type: application/json

{ "ids": ["...", "..."] }       // or omit ids to mark all read

204 No Content
```

### Register FCM Token
```
POST /v1/customer/devices/{deviceId}/push-token
Content-Type: application/json

{ "push_token": "fcm_token_here", "platform": "android" | "ios" }

204 No Content
```

---

## Profile (US-CUST-018)

### Get Profile
```
GET /v1/customer/profile

200 OK
{
  "id": "...",
  "phone": "+254712345678",
  "email": "jane@example.com",
  "first_name": "Jane",
  "last_name": "Doe",
  "avatar_url": null,
  "kyc_status": "approved",
  "locale": "en-KE",
  "notifications": { "push": true, "sms": true, "email": false }
}
```

### Update Profile
```
PATCH /v1/customer/profile
Content-Type: application/json

{
  "email": "new@example.com",
  "notifications": { "email": true }
}

200 OK
{ ... full profile ... }
```

---

## Security Settings (US-CUST-019)

### Change PIN
```
POST /v1/customer/auth/change-pin
Content-Type: application/json

{
  "current_pin_hash": "$2b$10$...",
  "new_pin_hash": "$2b$10$..."
}

204 No Content

401 Unauthorized
{ "message": "Current PIN is incorrect" }
```

---

## Devices (US-CUST-005) — reuses existing E03 endpoints

### List Devices
```
GET /v1/customer/devices

200 OK
{
  "data": [
    {
      "id": "...",
      "device_name": "Jane's iPhone 15",
      "platform": "ios",
      "status": "active",
      "trusted_at": "...",
      "last_seen_at": "...",
      "is_current": true
    }
  ]
}
```

### Revoke Device
```
DELETE /v1/customer/devices/{deviceId}

204 No Content
```

---

## Support (US-CUST-021)

### List Tickets
```
GET /v1/customer/support/tickets?page=1&per_page=20

200 OK
{ "data": [ { "id": "...", "reference_number": "...", "subject": "...", "status": "open", ... } ], "meta": { ... } }
```

### Create Ticket
```
POST /v1/customer/support/tickets
Content-Type: multipart/form-data

subject: "Payment not received"
category: "payment"
body: "I sent KES 1000 but the recipient says..."
attachments[]: <file>

201 Created
{ "id": "...", "reference_number": "TKT-20261001-EEEEE", "status": "open", ... }
```

### Get Ticket with Messages
```
GET /v1/customer/support/tickets/{id}

200 OK
{
  "id": "...",
  "reference_number": "...",
  "subject": "...",
  "category": "payment",
  "status": "in_progress",
  "messages": [
    { "id": "...", "sender": "customer", "body": "...", "attachment_urls": [], "sent_at": "..." },
    { "id": "...", "sender": "support", "body": "...", "sent_at": "..." }
  ],
  "created_at": "...",
  "updated_at": "..."
}
```

### Reply to Ticket
```
POST /v1/customer/support/tickets/{id}/messages
Content-Type: multipart/form-data

body: "Thank you for the update..."
attachments[]: <file>

201 Created
{ "id": "...", "sender": "customer", "body": "...", "sent_at": "..." }
```

---

## Disputes (US-CUST-022, US-CUST-023)

### Raise Dispute
```
POST /v1/customer/support/disputes
Content-Type: application/json

{
  "transaction_id": "...",
  "reason": "unauthorized_transaction",
  "description": "I did not authorize this transfer"
}

201 Created
{
  "id": "...",
  "reference_number": "DSP-20261001-FFFFF",
  "status": "submitted",
  "transaction_id": "...",
  "transaction_reference": "TXN-20261001-BBBBB",
  "reason": "unauthorized_transaction",
  "created_at": "..."
}
```

### List Disputes
```
GET /v1/customer/support/disputes

200 OK
{ "data": [ { ... } ], "meta": { ... } }
```

### Get Dispute Detail
```
GET /v1/customer/support/disputes/{id}

200 OK
{
  "id": "...",
  "reference_number": "...",
  "transaction_id": "...",
  "reason": "...",
  "status": "under_review",
  "resolution_notes": null,
  "created_at": "...",
  "updated_at": "..."
}
```

---

## Common Error Shapes

```json
// 401 Unauthorized
{ "message": "Unauthenticated" }

// 403 Forbidden
{ "message": "This action is unauthorized" }

// 422 Unprocessable Entity
{
  "message": "Validation error",
  "errors": {
    "amount_minor_units": ["Must be at least 100 (KES 1.00)"]
  }
}

// 409 Conflict (duplicate idempotency key — return original result)
{ "idempotent_replayed": true, "data": { ... original response ... } }

// 429 Too Many Requests
{ "message": "Too many requests", "retry_after": 60 }

// 500 Internal Server Error
{ "message": "An unexpected error occurred", "trace_id": "..." }
```

---

## Idempotency Key Generation (Client Pattern)

```dart
import 'package:uuid/uuid.dart';

final idempotencyKey = const Uuid().v4();
// Passed as header: 'Idempotency-Key': idempotencyKey
// Stored in-memory for the duration of the submission attempt
// On 409 response: parse original result from { idempotent_replayed: true, data: {...} }
```
