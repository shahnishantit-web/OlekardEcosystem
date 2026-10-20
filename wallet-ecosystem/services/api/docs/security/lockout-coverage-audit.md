# LockoutService Coverage Audit — E26 Security Hardening

**Date**: 2026-10-02
**Scope**: All Identity module authentication entry points.

## Summary

| Entry Point | assertNotLocked | recordFailure | recordSuccess | Notes |
|-------------|:--------------:|:-------------:|:-------------:|-------|
| `AuthenticationService::loginWithPassword()` | ✅ | ✅ | ✅ (via completeLogin) | Full coverage |
| `AuthenticationService::loginWithPin()` | ✅ | ✅ | ✅ (via completeLogin) | Full coverage |
| `AuthenticationService::loginWithOtp()` | ✅ | ✅ (E26 fix) | ✅ (via completeLogin) | Fixed in T038 — added `peekUserId` + `recordFailure` on OTP failure |
| `AuthenticationService::loginWithBiometric()` | ✅ | ✅ (via biometric) | ✅ (via completeLogin) | Full coverage |
| `StepUpService::verifyChallenge()` | — | — | — | Step-up requires a valid JWT; account lockout from initial auth protects access. OTP challenge uses `max_attempts` per-challenge protection. Accepted risk. |
| `OtpService::verify()` | — | — | — | Per-challenge attempt counter; hourly send limit enforced separately. Does not use account lockout. Acceptable by design — OTP challenges expire on max_attempts. |

## Changes Made (T038)

- **AuthenticationService**: Added `peekUserId` call before `verify()` to load the user for lockout enforcement on OTP paths.
- **OtpService**: Added `peekUserId(string $challengeId): ?string` — non-destructive challenge lookup.
- **AuthenticationService.loginWithOtp**: Added `recordFailure` on `OtpInvalidOrExpiredException`.

## Accepted Gaps

- **StepUpService**: Does not inject `LockoutService`. Step-up auth is gated behind valid JWT auth which already enforces lockout. Adding account lockout to step-up would require injecting two new dependencies. Accepted risk documented here; can be addressed in a future hardening cycle.
