# Quickstart Validation Guide — E02 User & Profile Management

**Feature**: `002-user-profile-management` | **Date**: 2026-08-27

This guide provides runnable validation scenarios that prove E02 works end-to-end. All scenarios assume:
- E999 Platform Foundation is running
- E01 Central Identity & Authentication is running
- A registered, authenticated test user exists (obtain a Bearer token from E01's `/v1/auth/login`)
- An admin user exists with required permissions

See [profile-api.md](./profile-api.md) for full request/response schemas.

---

## Prerequisites

```bash
# 1. Set environment
export API_BASE="http://localhost:8000/v1"
export TOKEN="<bearer-token-from-E01-login>"
export ADMIN_TOKEN="<admin-bearer-token>"
export USER_ID="<uuid-of-test-user>"
export IDEMPOTENCY_KEY="$(uuidgen)"
export CORRELATION_ID="$(uuidgen)"

# 2. Verify E999 platform services are up
curl -s $API_BASE/platform/health | jq .

# 3. Verify E01 auth is up
curl -s $API_BASE/auth/health | jq .
```

---

## Scenario 1: Profile Created After Registration

**Goal**: Verify that `Identity.User.Registered` triggers profile creation.

```bash
# Register via E01 (adapt to your E01 registration endpoint)
curl -s -X POST $API_BASE/auth/register \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: $(uuidgen)" \
  -d '{"mobile_number": "+26777000001"}' | jq .

# After OTP verification, a profile should exist:
curl -s $API_BASE/users/profile \
  -H "Authorization: Bearer $TOKEN" \
  -H "X-Correlation-ID: $CORRELATION_ID" | jq .

# ASSERT:
# - success: true
# - data.account_status = "active"
# - data.first_name = null (profile created with nullable personal fields)
# - data.email or data.mobile_number populated (fetched from E01 at creation)
```

**Verify audit**: Check `platform.audit_events` for `profile.user.profile-created` action.

**Verify event**: Check `platform.outbox_events` for `Profile.User.ProfileCreated` event.

---

## Scenario 2: Personal Information Update

**Goal**: Update personal info; verify idempotency and audit.

```bash
IDEMPOTENCY_KEY=$(uuidgen)

curl -s -X PUT $API_BASE/users/profile/personal \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: $IDEMPOTENCY_KEY" \
  -H "X-Correlation-ID: $CORRELATION_ID" \
  -d '{
    "first_name": "Thabo",
    "last_name": "Mokoena",
    "date_of_birth": "1990-05-15",
    "preferred_language": "en"
  }' | jq .

# ASSERT: success: true, data.first_name = "Thabo"

# Idempotency: same request, same key → same response, no duplicate write
curl -s -X PUT $API_BASE/users/profile/personal \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: $IDEMPOTENCY_KEY" \
  -H "X-Correlation-ID: $CORRELATION_ID" \
  -d '{
    "first_name": "Thabo",
    "last_name": "Mokoena",
    "date_of_birth": "1990-05-15",
    "preferred_language": "en"
  }' | jq .

# ASSERT: same 200 response; check DB — only one write occurred
```

**Security check**: Include a protected field:

```bash
curl -s -X PUT $API_BASE/users/profile/personal \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: $(uuidgen)" \
  -d '{"account_status": "closed"}' | jq .

# ASSERT: HTTP 400, error.code = "FIELD_NOT_PERMITTED"
```

---

## Scenario 3: Mobile Number Change Flow

**Goal**: Full OTP contact change flow including security alert.

```bash
# 3a. Initiate mobile change
CHANGE_KEY=$(uuidgen)
curl -s -X POST $API_BASE/users/profile/mobile/change \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: $CHANGE_KEY" \
  -d '{"new_mobile": "+26777999888"}' | jq .

# ASSERT: success: true, data.challenge_id present
# ASSERT (E17 stub): OTP "sent" to +26777999888; security alert "sent" to old mobile
# ASSERT: platform.outbox_events contains Profile.User.MobileChangeInitiated AND Profile.User.ContactSecurityAlertSent

export CHALLENGE_ID="<challenge_id from response>"

# 3b. Verify OTP (use OTP from E01 dev log/stub)
VERIFY_KEY=$(uuidgen)
curl -s -X POST $API_BASE/users/profile/mobile/verify \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: $VERIFY_KEY" \
  -d "{\"challenge_id\": \"$CHALLENGE_ID\", \"otp\": \"123456\"}" | jq .

# ASSERT: success: true, data.mobile_number masked value present
# ASSERT: profile.user_profiles.mobile_number = "+26777999888"
# ASSERT: identity.users.mobile_number = "+26777999888" (via E01 updateUserContact call)

# 3c. Old mobile still shown during pending phase (before verify)
# Re-run GET /v1/users/profile between step 3a and 3b to confirm old value
```

---

## Scenario 4: Account Suspension, Reactivation and Closure Lifecycle

**Goal**: Verify status machine transitions and audit trail.

```bash
# Suspend (admin)
curl -s -X POST $API_BASE/admin/users/$USER_ID/suspend \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: $(uuidgen)" \
  -d '{"reason": "Compliance investigation"}' | jq .
# ASSERT: data.account_status = "suspended"

# Reactivate (admin)
curl -s -X POST $API_BASE/admin/users/$USER_ID/reactivate \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "Idempotency-Key: $(uuidgen)" | jq .
# ASSERT: data.account_status = "active"

# View full status history
curl -s $API_BASE/admin/users/$USER_ID/profile/status \
  -H "Authorization: Bearer $ADMIN_TOKEN" | jq .
# ASSERT: history contains two entries (suspended → active)

# User self-service closure request
curl -s -X POST $API_BASE/users/profile/closure/request \
  -H "Authorization: Bearer $TOKEN" \
  -H "Idempotency-Key: $(uuidgen)" | jq .
# If no obligations: data.account_status = "pending_closure"
# If obligations: HTTP 409, error.code = "CLOSURE_BLOCKED_BY_OBLIGATIONS"

# Cancel closure (user self-service)
curl -s -X DELETE $API_BASE/users/profile/closure/request \
  -H "Authorization: Bearer $TOKEN" \
  -H "Idempotency-Key: $(uuidgen)" | jq .
# ASSERT: data.account_status = "active"
```

---

## Scenario 5: IDOR Protection

**Goal**: Confirm that a user cannot access another user's profile.

```bash
OTHER_USER_ID="<some-other-user-uuid>"

# Attempt to access another user's profile via admin endpoint without permission
curl -s $API_BASE/admin/users/$OTHER_USER_ID/profile \
  -H "Authorization: Bearer $TOKEN" | jq .
# ASSERT: HTTP 403, error.code = "FORBIDDEN"

# Attempt to access own profile using wrong user_id (non-admin path)
# The non-admin GET /v1/users/profile ignores path params entirely — no disclosure possible
```

---

## Scenario 6: Financial Obligation Gate

**Goal**: Verify closure is blocked when stub is active.

```bash
# With StubFinancialObligationService active, ALL closure requests are blocked:
curl -s -X POST $API_BASE/users/profile/closure/request \
  -H "Authorization: Bearer $TOKEN" \
  -H "Idempotency-Key: $(uuidgen)" | jq .
# ASSERT: HTTP 409
# ASSERT: error.code = "CLOSURE_BLOCKED_BY_OBLIGATIONS"
# ASSERT: error.details.blocked_by = ["obligations_check_unavailable"]
```

---

## Database Validation Queries

```sql
-- Verify profile record created
SELECT id, user_id, account_status, email, mobile_number, first_name
FROM profile.user_profiles
WHERE user_id = '<your-test-user-uuid>';

-- Verify status history
SELECT from_status, to_status, reason, actor_type, created_at
FROM profile.account_status_history
WHERE user_id = '<your-test-user-uuid>'
ORDER BY created_at;

-- Verify audit events
SELECT action, actor_id, before_state, after_state, created_at
FROM platform.audit_events
WHERE metadata->>'module' = 'profile'
ORDER BY created_at DESC LIMIT 20;

-- Verify outbox events
SELECT event_name, status, payload, created_at
FROM platform.outbox_events
WHERE event_name LIKE 'Profile.%'
ORDER BY created_at DESC LIMIT 20;
```

---

## Performance Validation

```bash
# GET /v1/users/profile — P95 ≤ 200 ms
# Run via K6 script: tests/performance/profile/view-profile.js

# PUT /v1/users/profile/personal — P95 ≤ 300 ms
# Run via K6 script: tests/performance/profile/update-personal.js
```
