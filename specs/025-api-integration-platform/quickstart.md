# Quickstart — E25 API & Integration Platform

Validation scenarios for proving the feature works end-to-end. Run after Docker environment is up.

## Prerequisites

```bash
# From wallet-ecosystem/
docker compose up -d
docker compose exec api php artisan migrate --path=database/migrations/integration
docker compose exec api php artisan db:seed --class=IntegrationSeeder
```

Seed creates:
- One admin user with `integrations.clients.write` + `integrations.read` permissions
- One mock SMS integration (provider_slug: `mock-sms`, provider_type: `sms`, status: `active`, `is_default: true`)
- One mock payment integration (provider_slug: `mock-payment`, provider_type: `payment`, status: `active`, `is_default: true`)

---

## SC-1: Create and authenticate an API client

```bash
# 1. Create client (requires admin session token)
curl -s -X POST http://localhost:8000/api/v1/integrations/clients \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"name":"Test Client","permissions":["funding.initiate"],"rate_limit_per_minute":30}' \
  | jq .

# Expected: 201 with data.api_key (full) and data.secret (shown once)
# Save: API_KEY=<data.api_key>  SECRET=<data.secret>

# 2. Authenticate a request using the new client credentials
CREDS=$(echo -n "$API_KEY:$SECRET" | base64)
curl -s -X GET http://localhost:8000/api/v1/funding/transactions \
  -H "Authorization: Bearer $CREDS" \
  | jq .status_code

# Expected: 200 (authenticated) — not 401
```

---

## SC-2: Rate limiting (per-client)

```bash
# Using the client from SC-1 (rate_limit_per_minute=30)
# Fire 31 requests in quick succession
for i in $(seq 1 31); do
  STATUS=$(curl -s -o /dev/null -w "%{http_code}" \
    -H "Authorization: Bearer $CREDS" \
    http://localhost:8000/api/v1/funding/transactions)
  echo "Request $i: $STATUS"
done

# Expected: first 30 → 200; request 31 → 429 with Retry-After header
```

---

## SC-3: Rate limiting (per-IP)

```bash
# This validates that the IP-level limit fires independently of client quota
# Requires the IP rate limit to be set low for testing (default 300/min — reduce in config)
docker compose exec api php artisan config:set integration.ip_rate_limit_per_minute 5

# Fire 6 requests with an unregistered credential (will be 401, but IP window still counts)
for i in $(seq 1 6); do
  STATUS=$(curl -s -o /dev/null -w "%{http_code}" \
    -H "Authorization: Bearer invalid" \
    http://localhost:8000/api/v1/integrations/clients)
  echo "Request $i: $STATUS"
done

# Expected: first 5 → 401 (wrong creds); request 6 → 429 (IP rate limit hit)
```

---

## SC-4: Secret rotation

```bash
# Rotate the secret for the client created in SC-1
CLIENT_ID=<id from SC-1>
curl -s -X POST http://localhost:8000/api/v1/integrations/clients/$CLIENT_ID/rotate-secret \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"reason":"Scheduled rotation test"}' \
  | jq .

# Expected: 200 with new data.secret

# Old credential should now fail
curl -s -o /dev/null -w "%{http_code}" \
  -H "Authorization: Bearer $CREDS" \
  http://localhost:8000/api/v1/funding/transactions
# Expected: 401
```

---

## SC-5: Receive a valid provider webhook

```bash
# Send a mock-payment webhook with a valid HMAC signature
PAYLOAD='{"event":"payment.success","reference":"TXN-001","amount":5000}'
SIGNATURE=$(echo -n "$PAYLOAD" | openssl dgst -sha256 -hmac "mock-secret" -binary | base64)

curl -s -X POST http://localhost:8000/api/v1/webhooks/mock-payment \
  -H "Content-Type: application/json" \
  -H "X-Signature: $SIGNATURE" \
  -d "$PAYLOAD" \
  | jq .

# Expected: 200 with data.status = "received" and data.webhook_event_id
```

---

## SC-6: Reject tampered webhook

```bash
PAYLOAD='{"event":"payment.success","reference":"TXN-001","amount":5000}'
BAD_SIGNATURE="sha256=invalidsignature"

curl -s -X POST http://localhost:8000/api/v1/webhooks/mock-payment \
  -H "Content-Type: application/json" \
  -H "X-Signature: $BAD_SIGNATURE" \
  -d "$PAYLOAD" \
  | jq .

# Expected: 401 with error.code = "INTEGRATION_WEBHOOK_001"
```

---

## SC-7: Webhook idempotency (duplicate delivery)

```bash
# Use the same payload as SC-5, re-deliver it
curl -s -X POST http://localhost:8000/api/v1/webhooks/mock-payment \
  -H "Content-Type: application/json" \
  -H "X-Signature: $SIGNATURE" \
  -d "$PAYLOAD" \
  | jq .data.status

# Expected: 200 (not 409) — idempotent re-acceptance
# Verify only one row exists in integration.webhook_events for this idempotency_key
docker compose exec api php artisan tinker --execute="
  echo DB::table('integration.webhook_events')
    ->where('idempotency_key', 'payment.success:TXN-001')
    ->count();"
# Expected: 1
```

---

## SC-8: Webhook retry on handler failure + dead-letter

```bash
# Register a failing handler for testing
docker compose exec api php artisan integration:test-fail-webhook $WEBHOOK_EVENT_ID

# Run the queue worker once
docker compose exec api php artisan queue:work --queue=webhooks --once

# Check status after retries exhausted (set max_retry_count=1 for test)
curl -s http://localhost:8000/api/v1/integrations/webhooks/$WEBHOOK_EVENT_ID \
  -H "Authorization: Bearer $ADMIN_TOKEN" | jq .data.status

# Expected: "dead_letter"
```

---

## SC-9: Register and list integrations

```bash
# Register a new SMS integration
curl -s -X POST http://localhost:8000/api/v1/integrations/providers \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "provider_type": "sms",
    "provider_slug": "test-sms-v2",
    "display_name": "Test SMS V2",
    "credentials_reference": "platform.integrations.test_sms_v2",
    "is_default": false
  }' | jq .data.id

# List all SMS integrations
curl -s "http://localhost:8000/api/v1/integrations/providers?provider_type=sms" \
  -H "Authorization: Bearer $ADMIN_TOKEN" | jq '[.data[].provider_slug]'

# Expected: ["mock-sms", "test-sms-v2"]
```

---

## SC-10: Re-queue a dead-letter webhook event

```bash
# From SC-8, the event is in dead_letter status
curl -s -X POST http://localhost:8000/api/v1/integrations/webhooks/$WEBHOOK_EVENT_ID/requeue \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  | jq .data.status

# Expected: "received" (retry_count reset to 0)
```

---

## PHPStan

```bash
docker compose exec api ./vendor/bin/phpstan analyse app/Modules/Integration --level=8 --configuration=phpstan.neon
# Expected: 0 errors
```

## Test suite

```bash
docker compose exec api php artisan test --filter=Integration
# Expected: all pass
```
