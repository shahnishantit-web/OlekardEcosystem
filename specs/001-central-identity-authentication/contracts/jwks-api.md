# JWKS API Contract — E01 Central Identity & Authentication

**Feature**: `001-central-identity-authentication`

---

## Endpoint

### GET /v1/.well-known/jwks.json

Return the JSON Web Key Set containing E01's current public key(s). This is the single source of truth for JWT signature verification across all downstream services.

**Authentication**: None required. Public endpoint.

**Rate limiting**: Standard platform rate limiting applies; this endpoint should be designed for high read throughput since all downstream services cache from it.

---

## Response: 200 OK

```json
{
  "keys": [
    {
      "kty": "EC",
      "crv": "P-256",
      "use": "sig",
      "alg": "ES256",
      "kid": "2026-primary-abc123",
      "x": "base64url-encoded-x-coordinate",
      "y": "base64url-encoded-y-coordinate"
    }
  ]
}
```

During key rotation, the `keys` array contains both the new (signing) key and the old (verification-only) key:

```json
{
  "keys": [
    {
      "kty": "EC",
      "crv": "P-256",
      "use": "sig",
      "alg": "ES256",
      "kid": "2026-primary-abc123",
      "x": "...",
      "y": "..."
    },
    {
      "kty": "EC",
      "crv": "P-256",
      "use": "sig",
      "alg": "ES256",
      "kid": "2026-retired-def456",
      "x": "...",
      "y": "..."
    }
  ]
}
```

**Never** include private key material (`d` parameter) in the JWKS response.

---

## Response Headers

| Header | Value | Notes |
|---|---|---|
| `Content-Type` | `application/json` | |
| `Cache-Control` | `public, max-age=300` | 5-minute HTTP cache (configurable) |
| `X-Correlation-ID` | echoed from request | |

---

## `kid` Format

The `kid` value is computed as the first 12 hex characters of the SHA-256 hash of the public key's DER-encoded SubjectPublicKeyInfo. This produces a stable, deterministic identifier that does not change across application restarts.

Example derivation:
```
kid = hex(sha256(der_encode(public_key)))[0:12]
```

The `kid` value embedded in issued JWT headers MUST match a `kid` in the JWKS response at the time of token verification.

---

## Key Rotation Protocol

1. Generate new EC P-256 key pair; store new private key as `IDENTITY_JWT_PRIVATE_KEY` secret.
2. Store old public key as `IDENTITY_JWT_OLD_PUBLIC_KEY` secret (for JWKS inclusion during rotation window).
3. Deploy: JWKS endpoint now serves both old and new public keys.
4. New access tokens are signed with the new private key (`kid` updated).
5. Existing valid tokens signed with the old key remain verifiable via the old `kid` in JWKS.
6. Once all old tokens expire (max access token TTL from config), remove `IDENTITY_JWT_OLD_PUBLIC_KEY`.
7. JWKS endpoint reverts to single key.

**Minimum rotation window**: old key MUST remain in JWKS until all tokens signed with it expire (at minimum the configured access token TTL after rotation).

---

## Downstream Verification

Downstream services (E04, E09, E23, etc.) MUST:

1. Fetch JWKS at startup and cache locally with the HTTP `max-age` TTL.
2. On cache miss for a `kid` seen in an incoming token: re-fetch JWKS once before rejecting.
3. Never call E01 for per-request token validation (no introspection endpoint).
4. Trust only the `iss` claim matching the configured E01 issuer URL.
5. Validate `exp`, `nbf`, `aud` on every token.

---

## Redis Cache

The JWKS JSON is cached in Redis under `identity:jwks` with a TTL matching the HTTP `max-age`. The cache is invalidated immediately after key rotation (deploy event or manual flush). Downstream services read from the HTTP endpoint, not Redis directly.

---

## Security Constraints

- Private key (`d` parameter) MUST NEVER appear in any response.
- Private keys stored in platform secret manager; never in `platform.configuration_values`.
- JWKS endpoint served over HTTPS only in all non-local environments.
- No authentication required; tampering prevented by TLS + infrastructure controls.
- Log all JWKS requests at `DEBUG` level only; avoid high-volume `INFO` logging of JWKS hits.
