# Quickstart Validation — E29 Multi-Currency Wallet

## Prerequisites

- Docker environment running (`docker compose up -d`)
- Database migrations applied (`php artisan migrate`)
- Seeded currencies: BWP (primary), USD, ZAR, EUR at minimum
- Seeded FX rates: BWP→USD, USD→BWP, BWP→ZAR, ZAR→BWP
- Admin seeded: `admin@olekard.local` / `Admin123!` with `multi_currency.configure_rate` permission
- Customer seeded with primary BWP wallet (E06 prerequisite)
- `CUSTOMER_TOKEN` and `ADMIN_TOKEN` available from authentication

---

## SC-01 — Create a currency wallet (US-MCW-001)

```bash
# Create a USD wallet for the customer
curl -s -X POST /v1/multi-currency/wallets \
  -H "Authorization: Bearer $CUSTOMER_TOKEN" \
  -H "Idempotency-Key: $(uuidgen)" \
  -H "Content-Type: application/json" \
  -d '{"currency_code": "USD"}' | jq .
```

**Expected:** `201` response, `data.currency_code = "USD"`, `data.balance = "0.00"`

```bash
# Attempt duplicate — same idempotency key returns existing wallet (idempotent)
# Attempt duplicate — different idempotency key returns 409 conflict
```

---

## SC-02 — View currency balances (US-MCW-003)

```bash
curl -s /v1/multi-currency/wallets \
  -H "Authorization: Bearer $CUSTOMER_TOKEN" | jq .
```

**Expected:** `200` response, array includes the USD wallet with balance.

---

## SC-03 — List supported currencies (US-MCW-002)

```bash
curl -s /v1/multi-currency/currencies \
  -H "Authorization: Bearer $CUSTOMER_TOKEN" | jq .
```

**Expected:** `200`, list contains only `is_active=true` currencies.

---

## SC-04 — FX fee preview (US-MCW-006)

```bash
curl -s "/v1/multi-currency/fx-fee?from_currency=BWP&to_currency=USD&amount=1000" \
  -H "Authorization: Bearer $CUSTOMER_TOKEN" | jq .
```

**Expected:** `200`, `data.fee_amount` > 0, `data.to_amount_estimate` reflects rate × (amount - fee).

---

## SC-05 — FX quote-then-confirm conversion (US-MCW-007 + US-MCW-008)

```bash
# Phase 1: Request quote
QUOTE=$(curl -s -X POST /v1/multi-currency/fx-quotes \
  -H "Authorization: Bearer $CUSTOMER_TOKEN" \
  -H "Idempotency-Key: $(uuidgen)" \
  -H "Content-Type: application/json" \
  -d '{"from_currency_code":"BWP","to_currency_code":"USD","from_amount":"500.00"}')

echo $QUOTE | jq .
QUOTE_ID=$(echo $QUOTE | jq -r '.data.id')
```

**Expected:** `201`, `data.status = "quoted"`, `data.expires_at` ~60 s in future, `data.to_amount` populated.

```bash
# Phase 2: Confirm conversion (within 60s)
IDEM=$(uuidgen)
curl -s -X POST /v1/multi-currency/fx-quotes/$QUOTE_ID/confirm \
  -H "Authorization: Bearer $CUSTOMER_TOKEN" \
  -H "Idempotency-Key: $IDEM" \
  -H "Content-Type: application/json" \
  -d "{\"idempotency_key\":\"$IDEM\"}" | jq .
```

**Expected:** `200`, `data.status = "completed"`, `data.from_amount = "500.00"`, `data.to_amount` matches quote, BWP wallet balance decreased, USD wallet balance increased.

```bash
# Confirm again with same idempotency key — expect 409 with existing FxTransaction
```

---

## SC-06 — Expired quote rejected (US-MCW-007 edge case)

Request a quote, wait > 60 seconds, then attempt confirm.

**Expected:** `422` with code `fx_quote_expired`.

---

## SC-07 — Same-currency transfer (US-MCW-004)

Requires two USD wallets (only possible if seeded; one-per-currency rule means the second must be in a different currency — this scenario tests the rejection path).

```bash
# Attempt transfer to a wallet of different currency — expect 422
curl -s -X POST /v1/multi-currency/wallets/$SOURCE_ID/transfers \
  -H "Authorization: Bearer $CUSTOMER_TOKEN" \
  -H "Idempotency-Key: $(uuidgen)" \
  -H "Content-Type: application/json" \
  -d "{\"to_wallet_id\":\"$DIFFERENT_CURRENCY_WALLET_ID\",\"amount\":\"100.00\"}" | jq .
```

**Expected:** `422`, `error.code = "currency_mismatch"`.

---

## SC-08 — FX transaction history (US-MCW-009)

```bash
curl -s "/v1/multi-currency/fx-transactions" \
  -H "Authorization: Bearer $CUSTOMER_TOKEN" | jq .
```

**Expected:** `200`, contains the completed FxTransaction from SC-05, with `status = "completed"`.

---

## SC-09 — Admin: Submit FX rate change (US-MCW-005)

```bash
curl -s -X POST /v1/admin/multi-currency/fx-rates \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "Idempotency-Key: $(uuidgen)" \
  -H "Content-Type: application/json" \
  -d '{
    "from_currency_code": "BWP",
    "to_currency_code": "USD",
    "rate": "0.07350000",
    "spread": "0.015",
    "effective_from": "2026-10-03T00:00:00Z"
  }' | jq .
```

**Expected:** `201`, rate change in `pending_approval` state; requires a second admin to approve via E28 flow.

---

## SC-10 — Unauthorized access rejected

```bash
# Without auth header — expect 401
curl -s /v1/multi-currency/wallets | jq .

# Customer token on admin endpoint — expect 403
curl -s /v1/admin/multi-currency/fx-rates \
  -H "Authorization: Bearer $CUSTOMER_TOKEN" | jq .
```

---

## Ledger Verification (US-MCW-008)

After SC-05 completes, verify double-entry balance in E07 ledger:

```sql
-- Verify FX suspense account nets to zero
SELECT SUM(CASE WHEN entry_type='debit' THEN amount ELSE -amount END) AS net
FROM wallet.ledger_entries
WHERE account_id = (SELECT id FROM wallet.accounts WHERE code = 'multi_currency.fx_suspense')
  AND created_at > NOW() - INTERVAL '5 minutes';
-- Expected: 0.00
```

---

## Contracts reference

See [`contracts/openapi.yaml`](contracts/openapi.yaml) for full request/response schemas.

## Data model reference

See [`data-model.md`](data-model.md) for entity definitions and relationships.
