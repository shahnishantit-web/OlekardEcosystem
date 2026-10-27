# API Contracts: P05 — Provider Registry and Option Eligibility

## Existing Endpoint (enhanced)

### GET /v1/payment/options

**Auth**: Bearer token (any authenticated actor)

**Query parameters** (unchanged from P02):
- `purpose_type` (required)
- `purpose_reference` (required)
- `currency` (default `BWP`)
- `amount` (default `0`)
- `organisation_id`
- `country_code`
- `module_key`

**New query parameter (P05)**:
- `required_capability` — optional; when supplied, only accounts with that capability flag set to true are returned. Must be a value from `ProviderAccountCapability` enum.

**Enhanced response item** (adds P05 fields):
```json
{
  "provider_code": "olekard_wallet",
  "display_name": "OleKard Wallet",
  "display_description": "Pay from your OleKard wallet",
  "display_icon_key": "icon.payment.wallet",
  "display_badge": null,
  "is_wallet": true,
  "is_default": false,
  "sort_order": 10,
  "wallet_balance_minor": 125000,
  "has_sufficient_balance": true,
  "checkout_flow": "wallet_transfer",
  "eligible_instrument_types": ["wallet_transfer"],
  "capability_flags": {
    "supports_refund": true,
    "supports_webhook": true,
    "supports_step_up": true,
    "supports_qr": false,
    "supports_escrow": false
  }
}
```

**Error responses**: 401 (unauthenticated), 422 (missing required params)

---

## New Endpoints

### GET /v1/payment/providers/adapters

**Auth**: `payment.admin` permission required

**Response**:
```json
{
  "data": ["olekard_wallet", "mock_bank"]
}
```

**Error responses**: 401, 403

---

### GET /v1/payment/providers/eligibility-preview

**Auth**: `payment.admin` permission required

**Query parameters** (same set as /options, all optional):
- `purpose_type`, `purpose_reference`, `currency`, `amount`, `organisation_id`, `country_code`, `module_key`, `required_capability`

**Response**:
```json
{
  "data": [
    {
      "provider_code": "olekard_wallet",
      "account_id": "01932c10-...",
      "display_name": "OleKard Wallet",
      "config_version": 3,
      "is_eligible": true,
      "ineligibility_reason": null
    },
    {
      "provider_code": "mock_bank",
      "account_id": "01932c11-...",
      "display_name": "Development Mock Bank",
      "config_version": 1,
      "is_eligible": false,
      "ineligibility_reason": "PROVIDER_SUSPENDED"
    }
  ]
}
```

**Error responses**: 401, 403

---

## Draft Validation Error Shape

When `POST /v1/payment/providers/{id}/accounts/{accountId}/drafts` is called with invalid field values, the response shape is:

```json
{
  "message": "Draft validation failed",
  "error_code": "DRAFT_VALIDATION_FAILED",
  "errors": {
    "callback_secret_reference": "INVALID_SECRET_REFERENCE_FORMAT",
    "callback_url": "CALLBACK_URL_NOT_PERMITTED",
    "supported_currencies": "INVALID_CURRENCY_CODE: INVALID_CODE"
  }
}
```

HTTP 422.

---

## New Error Codes (additions to openapi/v1/payment.yaml ErrorCode enum)

| Code | Description |
|------|-------------|
| `ADAPTER_NOT_IN_ALLOWLIST` | Provider registration rejected — adapter not in installed allowlist |
| `DRAFT_VALIDATION_FAILED` | One or more fields in the change payload failed validation |
| `INVALID_SECRET_REFERENCE_FORMAT` | Secret reference must start with `vault://` or `secretsmanager://` |
| `CALLBACK_URL_NOT_PERMITTED` | callback_url storage is prohibited (E25 owns ingress) |
| `INVALID_CURRENCY_CODE` | Currency code not a recognised ISO 4217 code |
| `UNKNOWN_CAPABILITY_FLAG` | Capability flag key not in approved set |
| `UNKNOWN_INSTRUMENT_TYPE` | Payment method/instrument type not in approved set |
| `CONFIGURATION_VERSION_CONFLICT` | Concurrent draft approval conflict — account version changed since draft was created |
