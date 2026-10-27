# Quickstart Validation: P05 — Provider Registry and Option Eligibility

## Prerequisites

```bash
docker compose up -d
docker compose exec api php artisan migrate
docker compose exec api php artisan db:seed
```

Seeded providers: `olekard_wallet` (production, is_enabled=false), `mock_bank` (mock, is_enabled=false).

## SC-01: Eligibility Pipeline — Platform Stage

Enable olekard_wallet provider and its account, then verify a suspended provider is excluded.

```bash
# Enable provider + account, then call options
curl -s -H "Authorization: Bearer $TOKEN" \
  "http://localhost:8000/v1/payment/options?purpose_type=bill&purpose_reference=ref1&currency=BWP&amount=5000&organisation_id=$ORG_ID" \
  | jq '.data | length'
# Expected: 1 (olekard_wallet account)

# Suspend the provider
curl -s -X POST -H "Authorization: Bearer $ADMIN_TOKEN" \
  "http://localhost:8000/v1/payment/providers/$PROVIDER_ID/suspend"

# Re-query options
curl -s -H "Authorization: Bearer $TOKEN" \
  "http://localhost:8000/v1/payment/options?purpose_type=bill&purpose_reference=ref1&currency=BWP&amount=5000&organisation_id=$ORG_ID" \
  | jq '.data | length'
# Expected: 0 (suspended provider excluded)
```

## SC-02: Adapter Allowlist

```bash
# Attempt to register provider with unknown adapter
curl -s -X POST -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"code":"rogue","display_name":"Rogue","adapter_identifier":"rogue_gateway","environment":"sandbox"}' \
  "http://localhost:8000/v1/payment/providers" \
  | jq '.error_code'
# Expected: "ADAPTER_NOT_IN_ALLOWLIST"

# Register with allowed adapter
curl -s -X POST -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"code":"olekard2","display_name":"Test","adapter_identifier":"olekard_wallet","environment":"sandbox"}' \
  "http://localhost:8000/v1/payment/providers" \
  | jq '.id'
# Expected: a UUID
```

## SC-03: Config Versioning

```bash
# Read current config_version (expected: 1)
curl -s -H "Authorization: Bearer $ADMIN_TOKEN" \
  "http://localhost:8000/v1/payment/providers/$PROVIDER_ID/accounts/$ACCOUNT_ID" \
  | jq '.config_version'
# Expected: 1

# Update a non-sensitive field
curl -s -X PATCH -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"display_description":"Updated description"}' \
  "http://localhost:8000/v1/payment/providers/$PROVIDER_ID/accounts/$ACCOUNT_ID"

# Read again
curl -s -H "Authorization: Bearer $ADMIN_TOKEN" \
  "http://localhost:8000/v1/payment/providers/$PROVIDER_ID/accounts/$ACCOUNT_ID" \
  | jq '.config_version'
# Expected: 2
```

## SC-04: Attempt Version Snapshot

```bash
# Create intent and initiate attempt (account at version 2)
ATTEMPT_ID=$(curl -s -X POST ... | jq -r '.attempt_id')

# Read attempt — should show provider_account_version=2
curl -s -H "Authorization: Bearer $ADMIN_TOKEN" \
  "http://localhost:8000/v1/payment/attempts/$ATTEMPT_ID" \
  | jq '.provider_account_version'
# Expected: 2

# Update account config (version becomes 3)
curl -s -X PATCH ... -d '{"display_description":"New desc"}' ...

# Suspend provider
curl -s -X POST ... "$URL/suspend"

# Resolve the attempt (should succeed despite suspension)
curl -s -X POST -H "Authorization: Bearer $ADMIN_TOKEN" \
  "http://localhost:8000/v1/payment/attempts/$ATTEMPT_ID/fail" \
  | jq '.status'
# Expected: "failed" (not blocked by suspension)
```

## SC-05: Eligibility Preview

```bash
curl -s -H "Authorization: Bearer $ADMIN_TOKEN" \
  "http://localhost:8000/v1/payment/providers/eligibility-preview?currency=BWP&amount=5000&organisation_id=$ORG_ID&purpose_type=bill" \
  | jq '.data[] | {provider_code, is_eligible, ineligibility_reason}'
# Expected: mix of true/false with specific reasons
```

## SC-06: Draft Validation

```bash
# Submit draft with invalid secret reference
curl -s -X POST -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: test-idem-01" \
  -d '{"change_type":"account_update","change_payload":{"callback_secret_reference":"plaintext-password"}}' \
  "http://localhost:8000/v1/payment/providers/$PROVIDER_ID/accounts/$ACCOUNT_ID/drafts" \
  | jq '{error_code, errors}'
# Expected: error_code=DRAFT_VALIDATION_FAILED, errors.callback_secret_reference=INVALID_SECRET_REFERENCE_FORMAT
```
