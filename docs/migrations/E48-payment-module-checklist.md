# Migration Checklist: E48 Shared Payment Integration

**Feature**: E48 Shared Payment Integration and Orchestration
**Migration date**: (to be filled on deployment)
**Engineer**: (to be filled)
**Approver**: (to be filled)

---

## Pre-Migration Prerequisites

- [ ] PHPStan level 8 passes with 0 errors across `app/Modules/Payment`
- [ ] All unit and feature tests pass in CI
- [ ] OpenAPI `payment.yaml` validated (`swagger-cli validate`)
- [ ] No live bank adapter enabled (only `olekard_wallet` and `mock_bank` dev adapter registered)
- [ ] DB backup taken for `payment.*` schema

---

## Step 1: Apply Migrations

Run the Payment module migrations in order:

```bash
cd wallet-ecosystem/services/api
php artisan migrate --path=app/Modules/Payment/Infrastructure/Migrations
```

**Expected tables created**:
- `payment.payment_providers`
- `payment.payment_provider_accounts`
- `payment.payment_intents`
- `payment.payment_attempts`
- `payment.payment_refunds`
- `payment.payment_callbacks`
- `payment.reconciliation_exceptions`
- `payment.bank_provider_documentation_gaps`

**Verify**:
```bash
psql -c "\dt payment.*"
```

Expected: 8 tables listed.

---

## Step 2: Seed Payment Providers (Sandbox Only)

OleKard Wallet is enabled by default for all environments. Mock Bank is seeded as dev-only.

```bash
php artisan db:seed --class=PaymentProviderSeeder
```

**Verify**:
```sql
SELECT code, display_name, is_enabled, environment FROM payment.payment_providers;
```

Expected output:
```
olekard_wallet | OleKard Wallet       | true  | production
mock_bank      | Dev Mock Bank        | false | sandbox
```

OleKard Wallet is disabled by default at migration time — enable after integration verification (Step 6).

---

## Step 3: Seed Payment Permissions

```bash
php artisan db:seed --class=PaymentPermissionRoleSeeder
```

**Verify**:
```sql
SELECT slug FROM identity.permissions WHERE slug LIKE 'payment.%' ORDER BY slug;
```

Expected: 9 rows (payment.intents.read, payment.intents.manage, payment.refunds.request,
payment.refunds.approve, payment.providers.read, payment.providers.manage,
payment.config.approve_high_risk, payment.reconciliation.view, payment.reconciliation.manage)

---

## Step 4: Assign Permissions to Roles

The seeder in Step 3 assigns permissions to existing roles. Verify assignments:

```sql
SELECT r.slug AS role, p.slug AS permission
FROM identity.role_permissions rp
JOIN identity.roles r ON r.id = rp.role_id
JOIN identity.permissions p ON p.id = rp.permission_id
WHERE p.slug LIKE 'payment.%'
ORDER BY r.slug, p.slug;
```

Expected role assignments:
- `admin`: all 9 permissions
- `admin-officer`: 4 read/request permissions
- `customer-support`: `payment.intents.read`

---

## Step 5: Configure Circuit Breaker Thresholds

Set via `ConfigurationService` (admin UI or direct DB):

```sql
INSERT INTO platform.configuration_entries (key, value, description) VALUES
  ('payment.circuit_breaker_threshold',    '5',  'Consecutive failures before circuit opens'),
  ('payment.circuit_breaker_reset_minutes', '10', 'Minutes before open circuit resets'),
  ('payment.intent_ttl_minutes',            '30', 'Payment intent expiry window in minutes'),
  ('payment.attempt_timeout_minutes',       '15', 'Payment attempt timeout in minutes'),
  ('payment.refund_approval_threshold_minor', '50000', 'Refund amount requiring approval (minor units)')
ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value;
```

---

## Step 6: Enable OleKard Wallet Provider

After verifying:
- `WalletModuleContract` integration tests pass
- Hold creation/release round-trip confirmed in staging

Enable via admin API:

```bash
curl -X PUT /api/v1/admin/payment/providers/{olekard_wallet_provider_id} \
  -H "Authorization: Bearer {admin_token}" \
  -d '{"is_enabled": true}'
```

---

## Post-Migration Verification

- [ ] `GET /api/v1/payment/options?purpose_type=test&...` returns options list
- [ ] `POST /api/v1/payment/intents` creates intent successfully
- [ ] `POST /api/v1/payment/callbacks/mock_bank` returns `202` (non-prod only)
- [ ] Admin UI `/admin/payment/providers` lists providers
- [ ] Audit log shows `payment.intent.created` event
- [ ] Circuit breaker Redis keys absent (clean state)
- [ ] No reconciliation exceptions in `payment.reconciliation_exceptions`

---

## Rollback

If migration fails after Step 1:

```bash
php artisan migrate:rollback --path=app/Modules/Payment/Infrastructure/Migrations
```

If rollback fails (e.g., data already written): restore from pre-migration backup.

The Payment module is additive — no existing module contracts were changed. Rollback does not affect E08-E16 modules.
