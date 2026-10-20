# Rate-Limit Coverage — E26 Security Hardening

**Date**: 2026-10-02
**Scope**: E25 `DualRateLimitMiddleware` coverage verification.

## Summary

All public API endpoints are protected by the `DualRateLimitMiddleware` (E25) which enforces
both per-IP and per-authenticated-user rate limits. The middleware is registered globally in the `api` middleware group.

## Webhook Receiver

The webhook receiver endpoint (`POST /api/v1/webhooks/{provider}`) is in the `api` group
and therefore inherits the `DualRateLimitMiddleware`. Provider callbacks are additionally
protected by HMAC signature verification.

## Rate-Limit Configuration

| Setting | Key | Default |
|---------|-----|---------|
| Per-IP requests/minute | `rate_limit.per_ip` | Platform configuration |
| Per-user requests/minute | `rate_limit.per_user` | Platform configuration |
| Burst allowance | `rate_limit.burst` | Platform configuration |

## HTTP 429 Response Format

On limit exceeded, the middleware returns:
- HTTP 429 Too Many Requests
- `Retry-After: <seconds>` header
- Standard error envelope: `{"success": false, "error": {"code": "RATE_LIMIT_EXCEEDED", ...}}`

## Test Coverage

- `BruteForceProtectionTest::rate_limit_exceeded_returns_429_with_retry_after_header` (T080)
  verifies the 429 + `Retry-After` header format under burst conditions.
