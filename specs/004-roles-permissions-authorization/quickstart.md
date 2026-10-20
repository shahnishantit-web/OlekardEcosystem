# Quickstart — E04 Roles, Permissions & Authorization

**Purpose**: End-to-end validation scenarios to prove E04 works correctly after implementation.

**Prerequisites**:
- E01 test suite passing (authenticated sessions working)
- E03 test suite passing (admin device routes working)
- E04 migrations run: `php artisan migrate`
- Docker environment running: `docker-compose up -d`
- Admin account exists (see Scenario 0 below)

---

## Scenario 0 — Bootstrap First Admin (Artisan command)

```bash
php artisan identity:create-admin \
    --email=admin@olekard.local \
    --name="System Admin" \
    --password="Admin123!"
```

**Expected output**: `Admin user admin@olekard.local created successfully (ID: {uuid}).`

**Verify**: Log in via `POST /v1/auth/login` with the email/password. Confirm the JWT `actor_type = "ADMIN"`, `roles = ["admin"]`, and `permissions` contains all 15 seeded slugs (no `"*"`).

---

## Scenario 1 — JWT Contains Explicit Permissions (not wildcard)

1. Log in as admin: `POST /v1/auth/login`
2. Decode the JWT access token (base64 payload section)
3. Verify:
   - `actor_type` = `"ADMIN"`
   - `roles` = `["admin"]`
   - `permissions` is an array containing `"admin.user.create"`, `"admin.role.read"`, etc.
   - `permissions` does NOT contain `"*"`

---

## Scenario 2 — Customer Blocked from Admin Route

```bash
# Log in as a Customer (from an existing E01/E03 test account)
POST /v1/auth/login { ... }
# Extract the customer access_token

curl -X GET http://localhost:8000/v1/admin/roles \
  -H "Authorization: Bearer {customer_token}"
```

**Expected**: HTTP 403 `{ "error": { "code": "AUTHORIZATION_001" } }`

---

## Scenario 3 — Admin Views Roles

```bash
curl -X GET http://localhost:8000/v1/admin/roles \
  -H "Authorization: Bearer {admin_token}"
```

**Expected**: HTTP 200 with 3 roles: `customer`, `agent`, `admin`. The `admin` role has 15 permissions in the `permissions` array.

---

## Scenario 4 — Admin Views Permissions

```bash
curl -X GET http://localhost:8000/v1/admin/permissions \
  -H "Authorization: Bearer {admin_token}"
```

**Expected**: HTTP 200 with 15 permissions, all with `is_system_permission: true`.

---

## Scenario 5 — Admin Creates Another Admin User

```bash
curl -X POST http://localhost:8000/v1/admin/users \
  -H "Authorization: Bearer {admin_token}" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: $(uuidgen)" \
  -d '{ "email": "admin2@olekard.local", "display_name": "Second Admin", "password": "Admin456!" }'
```

**Expected**: HTTP 201 with the new admin's profile. Password absent from response. Audit record `identity.admin_user.created` written.

```bash
# Attempt with same Idempotency-Key
# Expected: same 201 response (idempotent replay)

# Attempt with same email but different Idempotency-Key
# Expected: HTTP 409 ADMIN_USER_EXISTS
```

---

## Scenario 6 — Admin Deactivation Revokes Sessions

```bash
# Log admin2 in to create a session
POST /v1/auth/login → admin2_token

# Deactivate admin2
curl -X PATCH http://localhost:8000/v1/admin/users/{admin2_id}/status \
  -H "Authorization: Bearer {admin_token}" \
  -H "Content-Type: application/json" \
  -d '{ "status": "inactive" }'
# Expected: HTTP 200 { "sessions_revoked": 1 }

# Attempt to use admin2's token
curl http://localhost:8000/v1/admin/roles \
  -H "Authorization: Bearer {admin2_token}"
# Expected: HTTP 401 (session revoked)
```

---

## Scenario 7 — Self-Deactivation Blocked

```bash
curl -X PATCH http://localhost:8000/v1/admin/users/{admin_self_id}/status \
  -H "Authorization: Bearer {admin_token}" \
  -H "Content-Type: application/json" \
  -d '{ "status": "inactive" }'
# Expected: HTTP 422 ADMIN_SELF_DEACTIVATE
```

---

## Scenario 8 — Role Creation and Permission Assignment

```bash
# Create a new role
curl -X POST http://localhost:8000/v1/admin/roles \
  -H "Authorization: Bearer {admin_token}" \
  -d '{ "slug": "auditor", "display_name": "Auditor" }'
# Expected: HTTP 201 with empty permissions array

# Assign a permission to the new role
PERM_ID=$(curl http://localhost:8000/v1/admin/permissions -H "Authorization: Bearer {admin_token}" \
  | jq -r '.data[] | select(.slug=="admin.user.read") | .id')

curl -X POST http://localhost:8000/v1/admin/roles/{auditor_role_id}/permissions \
  -H "Authorization: Bearer {admin_token}" \
  -d "{ \"permission_id\": \"$PERM_ID\" }"
# Expected: HTTP 200

# Assign the auditor role to admin2
curl -X POST http://localhost:8000/v1/admin/users/{admin2_id}/roles \
  -H "Authorization: Bearer {admin_token}" \
  -d '{ "role_id": "{auditor_role_id}" }'
# Expected: HTTP 200

# Log admin2 in; verify auditor role appears in JWT roles claim
```

---

## Scenario 9 — Seed Idempotency

```bash
# Run migrations again
php artisan migrate

# Verify role count is still 3
curl http://localhost:8000/v1/admin/roles -H "Authorization: Bearer {admin_token}" \
  | jq '.meta.total'
# Expected: 3

# Verify permission count is still 15
curl http://localhost:8000/v1/admin/permissions -H "Authorization: Bearer {admin_token}" \
  | jq '.meta.total'
# Expected: 15
```

---

## Scenario 10 — Duplicate Email on Role Slug

```bash
curl -X POST http://localhost:8000/v1/admin/roles \
  -H "Authorization: Bearer {admin_token}" \
  -d '{ "slug": "admin", "display_name": "Duplicate" }'
# Expected: HTTP 409 ROLE_SLUG_CONFLICT
```

---

## Scenario 11 — E01/E03 Admin Routes Now Use Permission Middleware

```bash
# Verify admin session revocation uses permission middleware (not inline check)
# Create a session for a customer user from E01 tests
# Admin revokes it — should use session.admin.revoke permission
curl -X POST http://localhost:8000/v1/admin/sessions/{session_id}/revoke \
  -H "Authorization: Bearer {admin_token}"
# Expected: HTTP 200

# A customer token attempting the same revocation
curl -X POST http://localhost:8000/v1/admin/sessions/{session_id}/revoke \
  -H "Authorization: Bearer {customer_token}"
# Expected: HTTP 403 AUTHORIZATION_001
```

---

## Verification Checklist

| Check | Pass Condition |
|-------|---------------|
| JWT `permissions` claim has no `"*"` | Decoded JWT payload |
| Seeded 3 roles | `GET /v1/admin/roles` total = 3 |
| Seeded 15 permissions | `GET /v1/admin/permissions` total = 15 |
| Admin has 15 permissions | `GET /v1/admin/roles/admin-role-id` permissions count = 15 |
| Customer receives 403 on admin routes | Scenarios 2, 11 |
| Deactivation revokes sessions | Scenario 6 |
| Self-deactivation rejected | Scenario 7 |
| Seed idempotent | Scenario 9 |
| Inline auth checks eliminated | Code review + Scenario 11 |
| Artisan command creates admin | Scenario 0 |
| Audit records written | Database assertion on `platform.audit_events` |
