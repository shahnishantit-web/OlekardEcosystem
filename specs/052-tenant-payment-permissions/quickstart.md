# Quickstart: P03 — Tenant Isolation and Payment Permissions

**Date**: 2026-10-21

---

## Prerequisites

- Docker environment running: `cd wallet-ecosystem && docker compose up -d`
- Migrations run: `docker compose exec api php artisan migrate`
- Seeds run: `docker compose exec api php artisan db:seed --class=PaymentPermissionRoleSeeder`
- Dev admin user seeded: `admin@olekard.local` / `Admin123!`
- Dev agent user seeded: see `DevAgentSeeder` (+26771000002 / PIN 123456)

---

## Scenario 1: Permission Catalogue (SC-01)

Verify all 14 payment.* permissions exist after seeding.

```bash
docker compose exec api php artisan db:seed --class=PaymentPermissionRoleSeeder

# Verify permission count
docker compose exec api php artisan tinker --execute="
  \$count = DB::connection('identity')
    ->table('identity.permissions')
    ->where('slug', 'LIKE', 'payment.%')
    ->count();
  echo \"Payment permissions: \$count (expected: 14)\n\";
"
```

**Expected output**: `Payment permissions: 14 (expected: 14)`

Re-run seeder — count must remain 14 (idempotency):
```bash
docker compose exec api php artisan db:seed --class=PaymentPermissionRoleSeeder

docker compose exec api php artisan tinker --execute="
  echo DB::connection('identity')->table('identity.permissions')
    ->where('slug', 'LIKE', 'payment.%')->count();
"
```
**Expected**: 14 (not 28)

---

## Scenario 2: Cross-Tenant ID Substitution Rejection (SC-02)

Verify Org B cannot refund Org A's payment intent.

```bash
# Step 1: Create a payment intent as Org A customer
ORG_A_TOKEN=$(curl -s -X POST http://localhost:8000/api/v1/customer/auth/login \
  -H "Content-Type: application/json" \
  -d '{"phone":"+26771000001","pin":"123456"}' | jq -r '.data.access_token')

ORG_A_INTENT=$(curl -s -X POST http://localhost:8000/api/v1/payment/intents \
  -H "Authorization: Bearer $ORG_A_TOKEN" \
  -H "Idempotency-Key: sc2-intent-001" \
  -H "Content-Type: application/json" \
  -d '{
    "purpose_type": "test",
    "purpose_reference": "00000000-0000-7000-8000-000000000001",
    "organisation_id": "ORG_A_UUID",
    "currency_code": "BWP"
  }' | jq -r '.data.id')

echo "Org A intent: $ORG_A_INTENT"

# Step 2: Attempt refund as Org B customer (different organisation_id)
ORG_B_TOKEN=$(curl -s -X POST http://localhost:8000/api/v1/customer/auth/login \
  -H "Content-Type: application/json" \
  -d '{"phone":"+26771000003","pin":"123456"}' | jq -r '.data.access_token')

REFUND_STATUS=$(curl -s -o /dev/null -w "%{http_code}" \
  -X POST "http://localhost:8000/api/v1/payment/intents/$ORG_A_INTENT/refunds" \
  -H "Authorization: Bearer $ORG_B_TOKEN" \
  -H "Idempotency-Key: sc2-refund-001" \
  -H "Content-Type: application/json" \
  -d '{"amount_minor": 100}')

echo "Cross-tenant refund attempt status: $REFUND_STATUS (expected: 403)"
```

**Expected**: HTTP 403

---

## Scenario 3: Maker-Checker Self-Approval Prevention (SC-04)

Verify an operator cannot approve their own config change draft.

```bash
ADMIN_TOKEN=$(curl -s -X POST http://localhost:8000/api/v1/admin/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@olekard.local","password":"Admin123!"}' | jq -r '.data.access_token')

# List a provider to get its ID
PROVIDER_ID=$(curl -s http://localhost:8000/api/v1/admin/payment/providers \
  -H "Authorization: Bearer $ADMIN_TOKEN" | jq -r '.data[0].id')

# Step 1: Admin creates a config draft (payment.config.draft permission)
DRAFT_ID=$(curl -s -X POST \
  "http://localhost:8000/api/v1/admin/payment/providers/$PROVIDER_ID/config-changes" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "Idempotency-Key: sc3-draft-001" \
  -H "Content-Type: application/json" \
  -d '{
    "change_type": "high_risk_update",
    "proposed_changes": {"merchant_account_reference": "new-ref-001"},
    "notes": "Updating merchant reference"
  }' | jq -r '.data.id')

echo "Draft ID: $DRAFT_ID"

# Step 2: Same admin attempts to approve their own draft
SELF_APPROVE_STATUS=$(curl -s \
  -X POST "http://localhost:8000/api/v1/admin/payment/providers/$PROVIDER_ID/config-changes/$DRAFT_ID/approve" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "X-Step-Up-Token: test-step-up-token" \
  -H "Content-Type: application/json" \
  -d '{"notes": "Approving my own change"}')

echo "Self-approval status: $(echo $SELF_APPROVE_STATUS | jq -r '.error.code') (expected: SELF_APPROVAL_NOT_ALLOWED)"
```

**Expected**: error code `SELF_APPROVAL_NOT_ALLOWED`

---

## Scenario 4: Step-Up Enforcement on High-Value Refund Approval (SC-05)

Verify that approving a refund above `auth.step_up_threshold` without a step-up token returns STEP_UP_REQUIRED.

```bash
# Assume a refund in pending_approval state exists with amount_minor > 1000000 (BWP 10,000)
# Get a refund in pending_approval state
ADMIN_TOKEN=...  # from Scenario 3

REFUND_ID=$(curl -s "http://localhost:8000/api/v1/admin/payment/refunds?status=pending_approval" \
  -H "Authorization: Bearer $ADMIN_TOKEN" | jq -r '.data[0].id')

# Attempt approval WITHOUT step-up token
NO_STEP_UP_STATUS=$(curl -s -X POST \
  "http://localhost:8000/api/v1/admin/payment/refunds/$REFUND_ID/approve" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "Content-Type: application/json")

echo "Without step-up: $(echo $NO_STEP_UP_STATUS | jq -r '.error.code') (expected: STEP_UP_REQUIRED)"
```

**Expected**: error code `STEP_UP_REQUIRED`

---

## Scenario 5: Invalid Callback Provider Rejection (SC-02 negative)

Verify the callback endpoint rejects unknown providerCode.

```bash
STATUS=$(curl -s -o /dev/null -w "%{http_code}" \
  -X POST "http://localhost:8000/api/v1/payment/callbacks/nonexistent_provider" \
  -H "Content-Type: application/json" \
  -d '{"event":"payment.capture","reference":"test-ref"}')

echo "Unknown provider callback: $STATUS (expected: 404)"
```

**Expected**: HTTP 404

---

## Scenario 6: PHPStan Level 8 Clean (SC-06)

```bash
docker compose exec api ./vendor/bin/phpstan analyse \
  app/Modules/Payment \
  --level=8 \
  --no-progress \
  --memory-limit=512M
```

**Expected**: `[OK] No errors`

---

## Scenario 7: Unit Test Suite

```bash
docker compose exec api ./vendor/bin/phpunit \
  app/Modules/Payment/Tests/Unit/ \
  --no-coverage \
  --testdox
```

**Expected**: All tests pass including:
- `PaymentConfigDraftStatusTest` — all state transitions
- `SelfApprovalGuardTest` — drafter ≠ approver enforcement
- `StepUpThresholdTest` — threshold comparison logic
- `OrgScopeMiddlewareTest` — refund routes covered
