# Quickstart: P20 — Admin Provider Configuration and Policy Screens

## Prerequisites

- Docker stack running: `docker compose up -d` from `wallet-ecosystem/`
- Admin app running: `cd wallet-ecosystem/apps/admin && npm run dev`
- Backend migrations applied: `php artisan migrate` (including P20's `000027` migration)
- Seeded ADMIN user: `admin@olekard.local` / `Admin123!`
- At least one `payment_providers` row and one `payment_provider_accounts` row seeded (P05 seeder or manual insert)

---

## Scenario SC-001: Browse Provider Registry

**Goal**: Verify the Provider Registry list page loads and shows provider data.

```bash
# 1. Login as admin
curl -X POST http://localhost:8000/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@olekard.local","password":"Admin123!"}'
# Save token as $TOKEN

# 2. Verify API returns providers
curl -H "Authorization: Bearer $TOKEN" \
  http://localhost:8000/api/v1/admin/payment/providers
# Expected: 200 with data array
```

**UI validation**: Navigate to `http://localhost:3000/payments/providers` — list of providers renders with status badges and account counts. Filter by `status=suspended` shows only suspended entries. User without `payment.providers.read` sees access-denied page.

**Expected outcome**: Provider list renders; environment badge (`SANDBOX`/`PRODUCTION`) visible on each row.

---

## Scenario SC-002: View Provider Account Detail

**Goal**: Verify account detail screen loads with masked credential references.

```bash
# List accounts for provider {providerId}
curl -H "Authorization: Bearer $TOKEN" \
  "http://localhost:8000/api/v1/admin/payment/providers/{providerId}/accounts"
# Expected: 200 with accounts array; callback_secret_reference shows {key: "...", masked: "****"}

# Get single account
curl -H "Authorization: Bearer $TOKEN" \
  "http://localhost:8000/api/v1/admin/payment/providers/{providerId}/accounts/{accountId}"
# Expected: 200; callback_secret_reference.masked = "****"; callback_url = null
```

**UI validation**: Open account detail page. Credential reference field shows `••••••••` with "Change reference" button — raw secret value absent from DOM inspection. No `callback_url` input visible anywhere.

**Expected outcome**: Secret masked; no callback_url input; config_version displayed.

---

## Scenario SC-003: Edit Account Config (Non-High-Risk)

**Goal**: Verify non-high-risk field update applies immediately without draft.

```bash
# Update max_amount_minor (non-high-risk field)
curl -X PATCH \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  "http://localhost:8000/api/v1/admin/payment/providers/{providerId}/accounts/{accountId}" \
  -d '{"max_amount_minor": 5000000}'
# Expected: 200 with updated account; config_version incremented by 1
```

**UI validation**: Edit `max_amount_minor` in the config form, save. Toast shows "Changes saved". Account detail refreshes with new value. No approval dialog appears.

**Expected outcome**: `max_amount_minor` updated; `config_version` incremented; no draft created.

---

## Scenario SC-004: Maker-Checker for High-Risk Field

**Goal**: Verify editing `merchant_account_reference` triggers draft workflow.

```bash
# Attempt high-risk field update as operator A
curl -X PATCH \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  "http://localhost:8000/api/v1/admin/payment/providers/{providerId}/accounts/{accountId}" \
  -d '{"merchant_account_reference": "MERCHANT-NEW-REF-123"}'
# Expected: 202 with {requires_approval: true, draft_id: "..."}

# Check draft appears in list
curl -H "Authorization: Bearer $TOKEN" \
  "http://localhost:8000/api/v1/admin/payment/providers/{providerId}/config-changes?status=pending_approval"
# Expected: draft with change_diff showing merchant_account_reference change

# Try self-approval (should fail)
curl -X POST \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  "http://localhost:8000/api/v1/admin/payment/providers/{providerId}/config-changes/{draftId}/approve" \
  -d '{"notes": "Looks good"}'
# Expected: 403 SELF_APPROVAL_NOT_ALLOWED

# Approve as a different operator (with payment.config.approve_high_risk permission)
curl -X POST \
  -H "Authorization: Bearer $APPROVER_TOKEN" \
  -H "Content-Type: application/json" \
  "http://localhost:8000/api/v1/admin/payment/providers/{providerId}/config-changes/{draftId}/approve" \
  -d '{"notes": "Verified with bank confirmation"}'
# Expected: 200; draft status = approved; account merchant_account_reference updated
```

**UI validation**: As operator A, edit `merchant_account_reference` — approval-pending banner appears. Log in as operator B (approver), open approval queue, review diff, click Approve. Account shows updated reference with incremented `config_version`.

**Expected outcome**: 202 on high-risk edit; self-approval blocked with 403; approval updates live config.

---

## Scenario SC-005: Account Lifecycle — Suspend and Restore

**Goal**: Verify suspension blocks new option discovery while in-flight continues.

```bash
# Suspend account
curl -X POST \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  "http://localhost:8000/api/v1/admin/payment/providers/{providerId}/accounts/{accountId}/lifecycle" \
  -d '{"action": "suspend", "reason": "Provider reporting elevated failure rates"}'
# Expected: 200; account_lifecycle_status = "suspended"

# Verify account absent from option discovery
curl -H "Authorization: Bearer $CONSUMER_TOKEN" \
  "http://localhost:8000/api/v1/payment/options?purpose_type=wallet_topup&amount=1000&currency=BWP"
# Expected: suspended provider account absent from options list

# Restore
curl -X POST \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  "http://localhost:8000/api/v1/admin/payment/providers/{providerId}/accounts/{accountId}/lifecycle" \
  -d '{"action": "restore", "reason": "Provider issue resolved"}'
# Expected: 200; account_lifecycle_status = "active"
```

**UI validation**: Suspend button shows reason dialog. After suspend, status badge shows `suspended` (orange). Payment options API excludes account. Restore transitions back to `active`.

**Expected outcome**: Suspension within 1 request propagates to option discovery; restore reverts correctly.

---

## Scenario SC-006: Module Availability

**Goal**: Verify global module disable removes all options for that module's purpose types.

```bash
# Get module configs
curl -H "Authorization: Bearer $TOKEN" \
  http://localhost:8000/api/v1/admin/payment/config/modules
# Expected: list of modules with is_enabled and display_order

# Disable a module
curl -X PATCH \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  http://localhost:8000/api/v1/admin/payment/config/modules \
  -d '{"modules": [{"module_key": "bill_payment", "is_enabled": false}]}'
# Expected: 200 with updated module list

# Verify option discovery excludes that module
curl -H "Authorization: Bearer $CONSUMER_TOKEN" \
  "http://localhost:8000/api/v1/payment/options?purpose_type=bill_payment&amount=500&currency=BWP"
# Expected: empty options list (MODULE_NOT_ENABLED)
```

**Expected outcome**: Module disable reflected immediately in option eligibility pipeline.

---

## Scenario SC-007: Version History

**Goal**: Verify approved changes appear in version history timeline.

```bash
# Make two approved config changes, then view history
curl -H "Authorization: Bearer $TOKEN" \
  "http://localhost:8000/api/v1/admin/payment/providers/{providerId}/config-changes?status=approved"
# Expected: list of approved drafts in reverse chronological order with drafted_by, reviewed_by, created_at
```

**UI validation**: Version History tab shows timeline with three entries (two approved changes + activation). Diff view shows field-level changes. Denied drafts appear with denial reason.

**Expected outcome**: Timeline complete; denied entries present but not as "applied changes".

---

## Scenario SC-008: Adapter Allowlist Enforcement

**Goal**: Verify adapter identifier is restricted to allowlist values.

```bash
# Fetch adapter allowlist
curl -H "Authorization: Bearer $TOKEN" \
  http://localhost:8000/api/v1/admin/payment/providers/adapters
# Expected: {data: ["olekard_wallet", "mock_bank"]} (or whatever is registered)

# Attempt to create provider with non-allowlisted adapter
curl -X POST \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  http://localhost:8000/api/v1/admin/payment/providers \
  -d '{"code":"test","display_name":"Test","adapter_identifier":"fake_adapter","environment":"sandbox"}'
# Expected: 422 ADAPTER_NOT_IN_ALLOWLIST
```

**UI validation**: Adapter identifier dropdown is populated from the allowlist endpoint. Free text entry is not available. Submitting with an absent adapter shows validation error.

**Expected outcome**: 422 for non-allowlisted adapter; dropdown restricted to allowlist values.

---

## Scenario SC-009: Access Control

**Goal**: Verify server-side permission enforcement.

```bash
# Attempt provider list without payment.providers.read
curl -H "Authorization: Bearer $LIMITED_TOKEN" \
  http://localhost:8000/api/v1/admin/payment/providers
# Expected: 403

# Attempt lifecycle transition without payment.providers.manage
curl -X POST \
  -H "Authorization: Bearer $READ_ONLY_TOKEN" \
  -H "Content-Type: application/json" \
  "http://localhost:8000/api/v1/admin/payment/providers/{providerId}/accounts/{accountId}/lifecycle" \
  -d '{"action":"suspend","reason":"test"}'
# Expected: 403
```

**Expected outcome**: All permission checks enforced server-side regardless of UI state.

---

## Test Counts (target)

| Layer | Count | Note |
|-------|-------|------|
| Unit (PHPStan) | 0 errors | Backend + TypeScript |
| Feature (PHP) | ~15 | New backend endpoints; Docker-gated |
| Component (Vitest) | ~20 | Frontend component tests |
| Integration (UI flow) | SC-001 to SC-009 | 9 scenarios above |

---

## Links

- Data model: [data-model.md](data-model.md)
- API contracts: [contracts/provider-config-admin-api.yaml](contracts/provider-config-admin-api.yaml)
- Backend controllers: `wallet-ecosystem/services/api/app/Modules/Payment/Http/Controllers/Admin/`
- Admin screens: `wallet-ecosystem/apps/admin/app/(admin)/payments/providers/`
- Existing permissions: seeded by P03 migration `2026_10_21_000020_seed_payment_permissions.php`
