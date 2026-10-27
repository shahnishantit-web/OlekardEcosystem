# OpenAPI Extension Contract: P02 — Payment Schema and API Contracts

**Date**: 2026-10-21

---

## Files Affected

| File | Action |
|------|--------|
| `wallet-ecosystem/services/api/openapi/v1/payment.yaml` | EXTEND — additive changes only; no existing paths/schemas removed |
| `wallet-ecosystem/services/api/openapi/v1/payment-events.yaml` | CREATE — new event schema document |

---

## payment.yaml Extensions

### 1. NextAction — Replace with Discriminated Union

**Current state**: Flat object with `type` enum (redirect, app_switch, sdk_init) and nullable shared fields.

**Target**: OpenAPI 3.1.0 discriminated union via `oneOf` + `discriminator`.

```yaml
# REPLACE the existing NextAction schema with:
NextAction:
  nullable: true
  oneOf:
    - $ref: '#/components/schemas/NextActionRedirect'
    - $ref: '#/components/schemas/NextActionOtpVerification'
    - $ref: '#/components/schemas/NextActionPollingRequired'
    - $ref: '#/components/schemas/NextActionWalletStepUp'
  discriminator:
    propertyName: type
    mapping:
      redirect: '#/components/schemas/NextActionRedirect'
      otp_verification: '#/components/schemas/NextActionOtpVerification'
      polling_required: '#/components/schemas/NextActionPollingRequired'
      wallet_step_up: '#/components/schemas/NextActionWalletStepUp'

NextActionRedirect:
  type: object
  required: [type, redirect_url, expires_at]
  properties:
    type: {type: string, enum: [redirect]}
    redirect_url: {type: string, format: uri}
    expires_at: {type: string, format: date-time}

NextActionOtpVerification:
  type: object
  required: [type, masked_destination, expires_at]
  properties:
    type: {type: string, enum: [otp_verification]}
    masked_destination: {type: string, example: "+267 7*** ***56"}
    expires_at: {type: string, format: date-time}

NextActionPollingRequired:
  type: object
  required: [type, poll_interval_seconds, max_attempts]
  properties:
    type: {type: string, enum: [polling_required]}
    poll_interval_seconds: {type: integer, minimum: 1, maximum: 60}
    max_attempts: {type: integer, minimum: 1, maximum: 30}

NextActionWalletStepUp:
  type: object
  required: [type, required_method]
  properties:
    type: {type: string, enum: [wallet_step_up]}
    required_method: {type: string, enum: [pin, biometric, otp]}
    step_up_threshold_minor: {type: integer, description: "Amount threshold that triggered step-up"}
```

**Rationale**: Discriminated unions prevent client code from guessing which fields are populated. Each type has only the fields relevant to it.

---

### 2. PaymentIntentResponse — Add New Fields

```yaml
# ADD to existing PaymentIntentResponse schema:
accounting_status:
  type: string
  enum: [pending_ledger, ledger_posted, ledger_failed]
fulfilment_status:
  type: string
  enum: [pending, fulfilled, fulfilment_failed]
fee_quote_minor:
  type: integer
  minimum: 0
  example: 0
  description: "Fee amount quoted at intent creation time (minor units). 0 for wallet payments. May differ from fee_minor on the executed attempt."
```

---

### 3. PaginatedMeta and PaginatedLinks Components (new)

```yaml
PaginatedMeta:
  type: object
  required: [current_page, per_page, total, last_page]
  properties:
    current_page: {type: integer, minimum: 1}
    per_page: {type: integer, minimum: 1, maximum: 100}
    total: {type: integer, minimum: 0}
    last_page: {type: integer, minimum: 1}

PaginatedLinks:
  type: object
  properties:
    first: {type: string, format: uri, nullable: true}
    last: {type: string, format: uri, nullable: true}
    prev: {type: string, format: uri, nullable: true}
    next: {type: string, format: uri, nullable: true}
```

---

### 4. ErrorCode Enum Component (new)

```yaml
ErrorCode:
  type: string
  description: "Machine-readable error code. Clients MUST switch on this value, not on message strings."
  enum:
    - INSUFFICIENT_FUNDS
    - STEP_UP_REQUIRED
    - PAYMENT_INTENT_NOT_FOUND
    - DUPLICATE_ATTEMPT
    - INVALID_STATE_TRANSITION
    - CAPABILITY_UNSUPPORTED
    - PROVIDER_CIRCUIT_OPEN
    - REFUND_LIMIT_EXCEEDED
    - IDEMPOTENCY_KEY_REQUIRED
    - UNAUTHENTICATED
    - ACCESS_DENIED
    - VALIDATION_ERROR
    - PRODUCTION_BLOCKED
    - PURPOSE_NOT_PAYABLE
    - UNKNOWN_PURPOSE_TYPE
```

Update `ErrorEnvelope.error.code` to `$ref: '#/components/schemas/ErrorCode'`.

---

### 5. Security Scope Annotations

Each existing endpoint path gets a `security` block:

```yaml
security:
  - BearerAuth: []
```

Admin endpoints additionally note `x-required-permission`:
```yaml
x-required-permission: payment.providers.manage  # or applicable permission slug
```

---

## payment-events.yaml (new file)

**File**: `wallet-ecosystem/services/api/openapi/v1/payment-events.yaml`

```yaml
openapi: "3.1.0"
info:
  title: OleKard Payment — Event Schemas
  version: "1.0.0"
  description: |
    Event payload schemas for payment domain events.
    All events use in-process Laravel event dispatch (P02).
    Outbox/broker integration: P09 scope.

components:
  schemas:
    PaymentEventEnvelope:
      type: object
      required: [event_id, event_type, schema_version, occurred_at, correlation_id, data]
      properties:
        event_id:
          type: string
          format: uuid
          description: UUID v7 unique event identifier
        event_type:
          type: string
          description: Stable dotted event name
        schema_version:
          type: integer
          description: Schema version integer (start at 1)
          minimum: 1
        occurred_at:
          type: string
          format: date-time
          description: UTC timestamp of the state change that triggered the event
        correlation_id:
          type: string
          format: uuid

    PaymentConfirmedEvent:
      allOf:
        - $ref: '#/components/schemas/PaymentEventEnvelope'
        - type: object
          properties:
            event_type:
              type: string
              enum: [payment.confirmed]
            schema_version:
              type: integer
              enum: [1]
            data:
              type: object
              required: [payment_intent_id, payer_actor_id, organisation_id,
                         amount_minor, currency_code, provider_code, fee_minor]
              properties:
                payment_intent_id: {type: string, format: uuid}
                payment_attempt_id: {type: string, format: uuid}
                payer_actor_id: {type: string, format: uuid}
                organisation_id: {type: string, format: uuid}
                purpose_type: {type: string}
                purpose_reference: {type: string, format: uuid}
                amount_minor: {type: integer, minimum: 1}
                currency_code: {type: string}
                provider_code: {type: string}
                fee_minor: {type: integer, minimum: 0}

    PaymentFailedEvent:
      allOf:
        - $ref: '#/components/schemas/PaymentEventEnvelope'
        - type: object
          properties:
            event_type:
              type: string
              enum: [payment.failed]
            schema_version:
              type: integer
              enum: [1]
            data:
              type: object
              required: [payment_intent_id, payer_actor_id, failure_reason]
              properties:
                payment_intent_id: {type: string, format: uuid}
                payment_attempt_id: {type: string, format: uuid, nullable: true}
                payer_actor_id: {type: string, format: uuid}
                organisation_id: {type: string, format: uuid}
                failure_reason: {type: string}
                provider_code: {type: string, nullable: true}

    RefundConfirmedEvent:
      allOf:
        - $ref: '#/components/schemas/PaymentEventEnvelope'
        - type: object
          properties:
            event_type:
              type: string
              enum: [payment.refund.confirmed]
            schema_version:
              type: integer
              enum: [1]
            data:
              type: object
              required: [payment_refund_id, payment_intent_id, refund_amount_minor, currency_code]
              properties:
                payment_refund_id: {type: string, format: uuid}
                payment_intent_id: {type: string, format: uuid}
                payment_attempt_id: {type: string, format: uuid}
                payer_actor_id: {type: string, format: uuid}
                organisation_id: {type: string, format: uuid}
                refund_amount_minor: {type: integer, minimum: 1}
                currency_code: {type: string}
                is_full_refund: {type: boolean}

    PaymentReversedEvent:
      allOf:
        - $ref: '#/components/schemas/PaymentEventEnvelope'
        - type: object
          properties:
            event_type:
              type: string
              enum: [payment.reversed]
            schema_version:
              type: integer
              enum: [1]
            data:
              type: object
              required: [payment_intent_id, reversal_reason]
              properties:
                payment_intent_id: {type: string, format: uuid}
                payment_attempt_id: {type: string, format: uuid, nullable: true}
                provider_reversal_reference: {type: string, nullable: true}
                reversal_reason: {type: string}
                reversed_amount_minor: {type: integer, nullable: true}
```

---

## Invariants for OpenAPI Extensions

- All extensions are additive — no path, parameter, or schema name removed
- All new schemas use `type: object` with `required` array for mandatory fields
- No `$ref` cycle introduced
- `discriminator.propertyName` matches the `type` property in each sub-schema (OpenAPI 3.1.0 requirement)
- Lint must pass with `npx @redocly/cli lint` at completion
