# Service Token API Contract — E01 Central Identity & Authentication

**Feature**: `001-central-identity-authentication`

E01 is the central issuer of service-identity JWT tokens. These tokens allow internal services to authenticate with other services without per-request E01 round-trips; verification uses the shared JWKS endpoint.

---

## Actor Types

| Type | Description |
|---|---|
| `USER` | Human user authenticated through the standard login flow |
| `SERVICE` | Internal service authenticating machine-to-machine |
| `SYSTEM` | Platform-managed automation (e.g., Horizon jobs, scheduled tasks) |

---

## Endpoint

### POST /v1/auth/service/token

Issue a service-identity JWT for an authenticated internal service.

**Authentication**: Service credential (mechanism TBD — stored in platform secret manager, not `platform.configuration_values`). Service credential type, format, and storage are defined when the first service-to-service integration is implemented; this contract defines what is issued.

**Idempotency**: Required (`X-Idempotency-Key`).

---

### Request

```json
{
  "service_id": "e09-payment-processor",
  "scopes": ["payment.write", "identity.read"],
  "audience": "api.olekard.com",
  "on_behalf_of": "user-uuid-or-null"
}
```

| Field | Type | Required | Notes |
|---|---|---|---|
| `service_id` | string | Yes | Stable identifier for the calling service |
| `scopes` | string[] | Yes | Requested capability scopes; must be a subset of authorized scopes for this service |
| `audience` | string | Yes | Target service/API; restricts where the token is accepted |
| `on_behalf_of` | uuid \| null | No | User UUID when service is acting on behalf of a user (delegation) |

**Scope catalogue**: TBD. Scope names and authorization mappings are defined per integration. Scope catalogue is not invented here.

---

### Response 200 OK

```json
{
  "success": true,
  "data": {
    "access_token": "eyJ...",
    "token_type": "Bearer",
    "expires_in": 900,
    "scopes": ["payment.write", "identity.read"]
  }
}
```

**Token lifetime**: TBD. Sourced from `platform.configuration_values` (key TBD). Not hard-coded. Service tokens are NOT indefinitely long-lived.

---

### Error Responses

| Code | HTTP | Meaning |
|---|---|---|
| `IDENTITY_002` | 401 | Invalid service credentials |
| `IDENTITY_010` | 403 | Requested scope not authorized for this service |
| `IDENTITY_001` | 400 | Validation failure |
| `IDENTITY_012` | 400 | Idempotency key reuse with differing payload |

---

## Service Token JWT Claims

```json
{
  "iss": "https://identity.olekard.com",
  "sub": "e09-payment-processor",
  "aud": ["api.olekard.com"],
  "iat": 1724234400,
  "exp": 1724235300,
  "nbf": 1724234400,
  "jti": "unique-token-id",
  "actor_type": "SERVICE",
  "service_id": "e09-payment-processor",
  "scopes": ["payment.write", "identity.read"],
  "on_behalf_of": "user-uuid-or-null",
  "kid": "key-id-for-jwks-lookup"
}
```

**Delegation**: When `on_behalf_of` is non-null, both the service identity (`service_id`) and the originating user identity (`on_behalf_of`) are preserved in the token. Receiving services can determine who initiated the operation and what service processed it.

---

## Verification by Receiving Services

Receiving services MUST:
1. Verify ES256 signature using the public key from JWKS (identified by `kid`).
2. Validate `iss` matches the E01 issuer URL.
3. Validate `aud` includes their own service identifier.
4. Validate `exp` has not elapsed.
5. Check `actor_type == "SERVICE"` to distinguish from user tokens.
6. Check `scopes` against the operation being authorized.
7. If acting on behalf of a user (`on_behalf_of` non-null), apply user-context authorization in addition to service scope check.

**No E01 round-trip** is required for token verification. All necessary claims are embedded.

---

## Security Constraints

- Service credentials stored in platform secret manager only; never in `platform.configuration_values`.
- Scope catalogue enforced at issuance; requesting unauthorized scopes is rejected with `IDENTITY_010`.
- Each service token must have a unique `jti` for auditability.
- Service tokens issued to one service MUST NOT be accepted by a service not in their `aud`.
- Audit record emitted on each service token issuance: `identity.service.token-issued`.
- Rotation interval for service credentials: TBD.
- Production secret-management provider: TBD.
