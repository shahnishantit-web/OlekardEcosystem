# Quickstart Validation Guide: P19 — Receipts, Communication and Payment History APIs

**Date**: 2026-10-25
**Branch**: `066-payment-receipts-history`
**Prerequisites**: Docker Compose stack running with seeded test data (consumer, confirmed payment intents, a refund)

---

## Prerequisites

```bash
# Start the stack
docker compose up -d

# Run migrations (includes P19 migrations 000024–000026)
docker compose exec api php artisan migrate

# Seed test data (consumer + payment intents + refund)
docker compose exec api php artisan db:seed --class=PaymentHistorySeeder

# Obtain a test consumer JWT (consumer seeded by DevCustomerSeeder: +26771000001 / PIN 123456)
TOKEN=$(curl -s -X POST http://localhost:8000/v1/customer/auth/login \
  -H "Content-Type: application/json" \
  -d '{"phone":"+26771000001","pin":"123456"}' | jq -r '.data.access_token')
echo "TOKEN=$TOKEN"
```

---

## SC-001: Payment history retrieved within 500ms

```bash
curl -s -w "\n%{time_total}s\n" \
  -H "Authorization: Bearer $TOKEN" \
  http://localhost:8000/v1/payment/history
```

**Expected**:
- HTTP 200
- `data` array with records belonging only to the seeded consumer
- `meta.total` matches seeded count
- Response time < 0.5s

---

## SC-002: Receipt data reconciles to ledger

```bash
# Get first intentId from history
INTENT_ID=$(curl -s \
  -H "Authorization: Bearer $TOKEN" \
  http://localhost:8000/v1/payment/history | jq -r '.data[0].payment_reference')

# Retrieve the receipt
curl -s \
  -H "Authorization: Bearer $TOKEN" \
  http://localhost:8000/v1/payment/receipts/$INTENT_ID | jq .
```

**Expected**:
- HTTP 200
- `receipt_status = "confirmed"`
- `fee_amount_minor` matches the `fee_minor` column in `payment_attempts` (not the quoted fee from the intent)
- `total_charged_minor = original_amount_minor + fee_amount_minor`
- No `metadata`-derived fields in the response

---

## SC-003: Download tokens are single-use and expire

```bash
# Issue a download token
TOKEN_RESP=$(curl -s -X POST \
  -H "Authorization: Bearer $TOKEN" \
  http://localhost:8000/v1/payment/receipts/$INTENT_ID/download-token)
echo $TOKEN_RESP | jq .

DOWNLOAD_TOKEN=$(echo $TOKEN_RESP | jq -r '.token')
EXPIRES_AT=$(echo $TOKEN_RESP | jq -r '.expires_at')
echo "Token: $DOWNLOAD_TOKEN, Expires: $EXPIRES_AT"

# First redemption — should succeed
curl -s -o receipt_first.json -w "%{http_code}" \
  "http://localhost:8000/v1/payment/receipts/download/$DOWNLOAD_TOKEN"
echo  # → should print 200

# Second redemption — should return 410 (already used)
curl -s -w "%{http_code}" \
  "http://localhost:8000/v1/payment/receipts/download/$DOWNLOAD_TOKEN"
echo  # → should print 410
```

**Expected**:
- First redemption: 200; `used_at` is set in `receipt_download_tokens` table
- Second redemption: 410 Gone

**Test expiry (integration test only — manipulate DB directly)**:
```sql
UPDATE payment.receipt_download_tokens
  SET expires_at = now() - interval '1 second'
  WHERE token = '<new_token>';
```
Then redeem → expect 410 Gone.

---

## SC-004: Ownership check — another payer cannot access the receipt

```bash
# Obtain a second consumer's JWT (DevCustomerSeeder: +26771000002 / PIN 123456)
TOKEN_B=$(curl -s -X POST http://localhost:8000/v1/customer/auth/login \
  -H "Content-Type: application/json" \
  -d '{"phone":"+26771000002","pin":"123456"}' | jq -r '.data.access_token')

# Attempt to access consumer A's receipt as consumer B
curl -s -w "\n%{http_code}" \
  -H "Authorization: Bearer $TOKEN_B" \
  http://localhost:8000/v1/payment/receipts/$INTENT_ID
echo  # → should print 404 (ownership failure returns 404 to avoid information leakage)
```

**Expected**: 404 — intent ID is not revealed as belonging to another user

---

## SC-005: Notification deduplication — duplicate events produce at most one notification

```bash
# Dispatch the same payment.confirmed event twice
docker compose exec api php artisan tinker --execute="
  \$event = new App\Modules\Payment\Domain\Events\PaymentConfirmedEvent(
      paymentIntentId: 'PASTE_INTENT_ID',
      payerActorId: 'PASTE_PAYER_ACTOR_ID',
      amountMinor: 10000,
      feeMinor: 100,
      purposeType: 'funeral_assistance',
      purposeReference: 'REF-001',
      organisationId: 'ORG-001',
      correlationId: 'TEST-CORR-001'
  );
  event(\$event);
  event(\$event); // duplicate
"
```

**Check deduplication table**:
```sql
SELECT event_idempotency_key, status, COUNT(*)
FROM payment.receipt_notification_deliveries
WHERE event_type = 'payment.confirmed'
GROUP BY event_idempotency_key, status;
```

**Expected**:
- Exactly 1 row per `event_idempotency_key`
- `status = 'dispatched'` for the first; second event skipped (no new row inserted, or one row with `status = 'skipped'`)
- No duplicate notification in `notifications` table

---

## SC-006: Notification delivery failure does not alter payment status

```bash
# Temporarily disable the notification service (simulate failure)
docker compose exec api php artisan tinker --execute="
  // Force notification service to throw
  config(['app.force_notification_failure' => true]);
  \$event = new App\Modules\Payment\Domain\Events\PaymentConfirmedEvent(...);
  event(\$event);
"

# Check that payment intent status is unchanged
docker compose exec api php artisan tinker --execute="
  \$intent = App\Modules\Payment\Infrastructure\Models\PaymentIntentModel::find('INTENT_ID');
  echo \$intent->status->value;  // should still be 'confirmed'
"
```

**Expected**: Payment intent `status = confirmed` regardless of notification failure

---

## SC-007: Export formula injection safety

```bash
# Obtain an admin JWT
ADMIN_TOKEN=$(curl -s -X POST http://localhost:8000/v1/admin/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@olekard.local","password":"Admin123!"}' | jq -r '.data.access_token')

# Request an export
EXPORT_RESP=$(curl -s -X POST \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"scope_type":"organisation","organisation_id":"ORG-001"}' \
  http://localhost:8000/v1/admin/payment/exports)
echo $EXPORT_RESP | jq .

EXPORT_ID=$(echo $EXPORT_RESP | jq -r '.export_id')

# Poll until ready (job runs synchronously in testing environment)
until [ "$(curl -s -H "Authorization: Bearer $ADMIN_TOKEN" \
  http://localhost:8000/v1/admin/payment/exports/$EXPORT_ID | jq -r '.status')" = "ready" ]; do
  sleep 1
done

# Get download URL
EXPORT_DL_URL=$(curl -s \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  http://localhost:8000/v1/admin/payment/exports/$EXPORT_ID | jq -r '.download_url')

# Download the CSV
curl -s "$EXPORT_DL_URL" -o export.csv

# Validate no formula-injectable values (must not find unquoted =, +, -, @ at field start)
python3 -c "
import csv
with open('export.csv', newline='') as f:
    reader = csv.reader(f)
    for row in reader:
        for cell in row:
            v = cell.strip()
            if v and v[0] in '=+-@':
                print(f'UNSAFE: {cell}')
                exit(1)
print('All cells formula-safe')
"
```

**Expected**: `All cells formula-safe`

---

## SC-008: Export audit log within 5 seconds

After the download in SC-007:

```sql
SELECT created_at, actor_id, action, resource_id
FROM audit.audit_log_entries
WHERE action = 'payment.export.downloaded'
  AND resource_id = '<export_id>'
ORDER BY created_at DESC
LIMIT 1;
```

**Expected**: Row exists with `created_at` within 5 seconds of the download

---

## SC-009: Pending refund not shown as confirmed

```bash
# Seed a payment intent with a pending refund
docker compose exec api php artisan db:seed --class=PendingRefundSeeder

REFUND_INTENT_ID=$(# obtain from seeder output)

# Query history
curl -s \
  -H "Authorization: Bearer $TOKEN" \
  "http://localhost:8000/v1/payment/history" | \
  jq --arg id "$REFUND_INTENT_ID" '.data[] | select(.payment_reference == $id)'
```

**Expected**:
- `refund_status = "pending"` (not `"confirmed"`)
- `refund_amount_minor` set (the reserved amount)
- `payment_status = "refund_pending"` (not `"refunded"`)

---

## Purpose-Type Filter (US5)

```bash
# Filter by purpose_type
curl -s \
  -H "Authorization: Bearer $TOKEN" \
  "http://localhost:8000/v1/payment/history?purpose_type=funeral_assistance" | \
  jq '.data[] | .purpose_type'
```

**Expected**: All returned records have `purpose_type = "funeral_assistance"`. Records from other domains are absent.

---

## Domain Deep Link (US5)

```bash
# Check domain_deep_link field
curl -s \
  -H "Authorization: Bearer $TOKEN" \
  http://localhost:8000/v1/payment/history | \
  jq '.data[0].domain_deep_link'
```

**Expected**: String of format `"{purpose_type}://{purpose_reference}"` — NOT user-supplied input. Constructed server-side only.

---

## Validation Summary

| Scenario | What It Proves |
|----------|----------------|
| SC-001 | History responds < 500ms |
| SC-002 | Receipt reconciles to ledger (FR-009) |
| SC-003 | Token single-use + expiry returns 410 (FR-010, FR-011) |
| SC-004 | Ownership check blocks cross-payer access (FR-001, SC-004) |
| SC-005 | Deduplication prevents duplicate notifications (FR-014) |
| SC-006 | Notification failure does not change payment status (FR-015) |
| SC-007 | Formula injection safety on all CSV fields (FR-021) |
| SC-008 | Export access audited within 5s (FR-020) |
| SC-009 | Pending refunds correctly labelled (FR-006) |
| US5 filter | purpose_type filter returns correct subset (FR-007) |
| US5 deep link | domain_deep_link derived server-side (FR-026) |
