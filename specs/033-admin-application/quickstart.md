# Quickstart & Validation Scenarios — E33 Admin Application

## Prerequisites

- Laravel API running at `http://localhost:8000` (Docker: `wallet-ecosystem/docker-compose.yml`)
- Next.js admin app running at `http://localhost:3001`
- Redis running (dashboard cache)
- Seeded admin users with roles:
  - `admin@olekard.local` / `Admin123!` — role: **Admin** (all permissions)
  - `ops@olekard.local` / `Admin123!` — role: **Operations**
  - `finance@olekard.local` / `Admin123!` — role: **Finance**
  - `compliance@olekard.local` / `Admin123!` — role: **Compliance**
- At least 1 pending KYC submission, 1 active wallet, 1 completed `wallet_transfer` transaction, 1 pending agent

All tokens obtained via `POST /v1/admin/auth/login`.

---

## SC-1: Dashboard Metrics Load and Refresh

**Story**: US-ADMINAPP-001/002/003  
**Scenario**: All three dashboards return pre-cached data with a `cached_at` timestamp ≤ 5 minutes old.

```bash
# 1. Warm the cache manually (or wait for scheduler)
php artisan adminapp:refresh-dashboard-cache

# 2. Fetch operational dashboard as Admin
curl -X GET http://localhost:8000/v1/admin-app/dashboard/operational \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "X-Correlation-ID: $(uuidgen)"
# Expected: 200 + data.cached_at is within last 5 minutes

# 3. Verify Finance role can access financial dashboard
curl -X GET http://localhost:8000/v1/admin-app/dashboard/financial \
  -H "Authorization: Bearer $FINANCE_TOKEN" \
  -H "X-Correlation-ID: $(uuidgen)"
# Expected: 200

# 4. Verify Operations role is denied financial dashboard
curl -X GET http://localhost:8000/v1/admin-app/dashboard/financial \
  -H "Authorization: Bearer $OPS_TOKEN" \
  -H "X-Correlation-ID: $(uuidgen)"
# Expected: 403 ADMINAPP_AUTH_002

# 5. Verify dashboard UI at http://localhost:3001/admin-portal/dashboard shows "Last updated: X minutes ago"
```

---

## SC-2: User Search and Status Change

**Story**: US-ADMINAPP-004/005  
**Scenario**: Operations admin searches for a user and suspends their account.

```bash
# 1. Search users
curl "http://localhost:8000/v1/admin-app/users?q=Kgomotso&status=active" \
  -H "Authorization: Bearer $OPS_TOKEN" \
  -H "X-Correlation-ID: $(uuidgen)"
# Expected: 200 + at least 1 result with masked phone number

# 2. Suspend a user (requires Idempotency-Key)
IDEM="suspend-user-$(uuidgen)"
curl -X PATCH http://localhost:8000/v1/admin-app/users/$USER_ID/status \
  -H "Authorization: Bearer $OPS_TOKEN" \
  -H "X-Correlation-ID: $(uuidgen)" \
  -H "Idempotency-Key: $IDEM" \
  -H "Content-Type: application/json" \
  -d '{"action":"suspend","reason":"Test suspension"}'
# Expected: 200 + account_status: "suspended"

# 3. Replay with same Idempotency-Key — must return same 200 (not 409)
curl -X PATCH http://localhost:8000/v1/admin-app/users/$USER_ID/status \
  -H "Authorization: Bearer $OPS_TOKEN" \
  -H "Idempotency-Key: $IDEM" \
  -H "Content-Type: application/json" \
  -d '{"action":"suspend","reason":"Test suspension"}'
# Expected: 200 (idempotent replay)

# 4. Verify audit entry was created
curl "http://localhost:8000/v1/admin-app/audit?subject_id=$USER_ID&event_type=USER_SUSPENDED" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "X-Correlation-ID: $(uuidgen)"
# Expected: 200 + 1 audit event
```

---

## SC-3: KYC Approve/Reject — Maker-Checker Flow

**Story**: US-ADMINAPP-008/009  
**Scenario**: One compliance admin submits KYC approval (maker); a second approves via checker; domain executes.

```bash
# 1. List pending KYC
curl "http://localhost:8000/v1/admin-app/kyc?status=pending_review" \
  -H "Authorization: Bearer $COMPLIANCE_TOKEN" \
  -H "X-Correlation-ID: $(uuidgen)"
# Expected: 200 + at least 1 pending submission

# 2. Maker: submit KYC approval
IDEM_KYC="kyc-approve-$(uuidgen)"
curl -X POST http://localhost:8000/v1/admin-app/kyc/$KYC_ID/approve \
  -H "Authorization: Bearer $COMPLIANCE_TOKEN" \
  -H "X-Correlation-ID: $(uuidgen)" \
  -H "Idempotency-Key: $IDEM_KYC"
# Expected: 202 + approval_id + status: "pending_approval"

# 3. Checker: view pending approval at existing E28 endpoint
curl "http://localhost:8000/v1/admin/configuration/approvals/$APPROVAL_ID" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "X-Correlation-ID: $(uuidgen)"
# Expected: 200 + action_type: "adminapp.kyc.approve" + status: "pending_approval"

# 4. Checker: approve (different admin session)
curl -X POST http://localhost:8000/v1/admin/configuration/approvals/$APPROVAL_ID/approve \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "X-Correlation-ID: $(uuidgen)" \
  -H "Idempotency-Key: checker-approve-$(uuidgen)"
# Expected: 200 — action executed

# 5. Verify KYC submission is now "approved"
curl "http://localhost:8000/v1/admin-app/kyc/$KYC_ID" \
  -H "Authorization: Bearer $COMPLIANCE_TOKEN"
# Expected: status: "approved"

# 6. Verify audit trail
curl "http://localhost:8000/v1/admin-app/audit?subject_id=$KYC_ID&event_type=KYC_APPROVED" \
  -H "Authorization: Bearer $ADMIN_TOKEN"
# Expected: 1 audit entry
```

---

## SC-4: Wallet Freeze — Maker-Checker Flow

**Story**: US-ADMINAPP-011  
**Scenario**: Operations admin initiates wallet freeze; second admin approves; wallet becomes frozen.

```bash
# 1. Find an active wallet
curl "http://localhost:8000/v1/admin-app/wallets?status=active" \
  -H "Authorization: Bearer $OPS_TOKEN" \
  -H "X-Correlation-ID: $(uuidgen)"
# Expected: 200 + at least 1 result

# 2. Maker: submit freeze request
curl -X POST http://localhost:8000/v1/admin-app/wallets/$WALLET_ID/freeze \
  -H "Authorization: Bearer $OPS_TOKEN" \
  -H "X-Correlation-ID: $(uuidgen)" \
  -H "Idempotency-Key: freeze-$(uuidgen)" \
  -H "Content-Type: application/json" \
  -d '{"reason":"Investigation: suspected fraud"}'
# Expected: 202 + approval_id

# 3. Check wallet still active while pending
curl "http://localhost:8000/v1/admin-app/wallets/$WALLET_ID" \
  -H "Authorization: Bearer $OPS_TOKEN"
# Expected: status: "active" (not yet executed)

# 4. Checker approves via E28
curl -X POST http://localhost:8000/v1/admin/configuration/approvals/$APPROVAL_ID/approve \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "Idempotency-Key: checker-freeze-$(uuidgen)"
# Expected: 200

# 5. Wallet now frozen
curl "http://localhost:8000/v1/admin-app/wallets/$WALLET_ID" \
  -H "Authorization: Bearer $OPS_TOKEN"
# Expected: status: "frozen", frozen_by: "Admin User"
```

---

## SC-5: Transaction Reversal Eligibility

**Story**: US-ADMINAPP-015  
**Scenario**: Verify internal transfer is reversible; funding deposit is rejected.

```bash
# 1. Find a completed wallet_transfer
curl "http://localhost:8000/v1/admin-app/transactions?type=wallet_transfer&status=completed" \
  -H "Authorization: Bearer $OPS_TOKEN"
# Expected: reversal_eligible: true for matching transaction

# 2. Maker: submit reversal request for wallet_transfer
curl -X POST http://localhost:8000/v1/admin-app/transactions/$TXN_TRANSFER_ID/reverse \
  -H "Authorization: Bearer $OPS_TOKEN" \
  -H "X-Correlation-ID: $(uuidgen)" \
  -H "Idempotency-Key: reverse-transfer-$(uuidgen)" \
  -H "Content-Type: application/json" \
  -d '{"reason":"Customer dispute resolved"}'
# Expected: 202 + approval_id

# 3. Attempt to reverse a funding transaction — should fail immediately
curl "http://localhost:8000/v1/admin-app/transactions?type=funding&status=completed" \
  -H "Authorization: Bearer $OPS_TOKEN"
# Note reversal_eligible: false in response

curl -X POST http://localhost:8000/v1/admin-app/transactions/$TXN_FUNDING_ID/reverse \
  -H "Authorization: Bearer $OPS_TOKEN" \
  -H "X-Correlation-ID: $(uuidgen)" \
  -H "Idempotency-Key: reverse-funding-$(uuidgen)" \
  -H "Content-Type: application/json" \
  -d '{"reason":"Test"}'
# Expected: 422 ADMINAPP_015
```

---

## SC-6: Agent Approval — Maker-Checker Flow

**Story**: US-ADMINAPP-016  
**Scenario**: Operations admin approves a pending agent application.

```bash
# 1. List pending agents
curl "http://localhost:8000/v1/admin-app/agents?status=pending_approval" \
  -H "Authorization: Bearer $OPS_TOKEN"
# Expected: at least 1 pending agent

# 2. Maker: submit agent approval
curl -X POST http://localhost:8000/v1/admin-app/agents/$AGENT_ID/approve \
  -H "Authorization: Bearer $OPS_TOKEN" \
  -H "X-Correlation-ID: $(uuidgen)" \
  -H "Idempotency-Key: agent-approve-$(uuidgen)"
# Expected: 202

# 3. Checker approves via E28
curl -X POST http://localhost:8000/v1/admin/configuration/approvals/$APPROVAL_ID/approve \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "Idempotency-Key: checker-agent-$(uuidgen)"
# Expected: 200

# 4. Agent now active
curl "http://localhost:8000/v1/admin-app/agents/$AGENT_ID" \
  -H "Authorization: Bearer $OPS_TOKEN"
# Expected: agent_status: "active"
```

---

## SC-7: Audit Log Query with Date Range

**Story**: US-ADMINAPP-021  
**Scenario**: Compliance admin queries the audit log; 91-day range is rejected.

```bash
# 1. Valid range query
curl "http://localhost:8000/v1/admin-app/audit?from_date=2026-10-01T00:00:00Z&to_date=2026-10-05T23:59:59Z" \
  -H "Authorization: Bearer $COMPLIANCE_TOKEN" \
  -H "X-Correlation-ID: $(uuidgen)"
# Expected: 200 + results

# 2. 91-day range — should be rejected
curl "http://localhost:8000/v1/admin-app/audit?from_date=2026-07-01T00:00:00Z&to_date=2026-10-05T23:59:59Z" \
  -H "Authorization: Bearer $COMPLIANCE_TOKEN" \
  -H "X-Correlation-ID: $(uuidgen)"
# Expected: 400 ADMINAPP_AUDIT_001

# 3. Operations role denied audit access
curl "http://localhost:8000/v1/admin-app/audit" \
  -H "Authorization: Bearer $OPS_TOKEN"
# Expected: 403 ADMINAPP_AUTH_002
```

---

## SC-8: Report Request and Download

**Story**: US-ADMINAPP-022  
**Scenario**: Finance admin requests a transaction summary report and polls until ready.

```bash
# 1. Request report
curl -X POST http://localhost:8000/v1/admin-app/reports \
  -H "Authorization: Bearer $FINANCE_TOKEN" \
  -H "X-Correlation-ID: $(uuidgen)" \
  -H "Idempotency-Key: report-txn-$(uuidgen)" \
  -H "Content-Type: application/json" \
  -d '{"report_type":"transaction_summary","period_start":"2026-10-01","period_end":"2026-10-04","format":"xlsx"}'
# Expected: 202 + report_id + estimated_ready_in_seconds

# 2. Poll status
curl "http://localhost:8000/v1/admin-app/reports/$REPORT_ID" \
  -H "Authorization: Bearer $FINANCE_TOKEN"
# Expected: status: "generating" or "ready"

# 3. When ready: download_url present (signed URL, 1h TTL)
# Expected: status: "ready" + download_url

# 4. Idempotent replay returns same report (not a new generation)
curl -X POST http://localhost:8000/v1/admin-app/reports \
  -H "Authorization: Bearer $FINANCE_TOKEN" \
  -H "Idempotency-Key: report-txn-<same-key>" \
  -H "Content-Type: application/json" \
  -d '{"report_type":"transaction_summary","period_start":"2026-10-01","period_end":"2026-10-04","format":"xlsx"}'
# Expected: same report_id as first request
```

---

## SC-9: Role-Based Access Matrix Spot Checks

**Story**: All 22 stories  
**Scenario**: Verify role enforcement at representative endpoints.

| Admin Token | Endpoint | Expected |
|-------------|----------|----------|
| `finance` | `GET /v1/admin-app/dashboard/financial` | 200 |
| `finance` | `GET /v1/admin-app/dashboard/operational` | 403 |
| `ops` | `GET /v1/admin-app/kyc` | 403 |
| `compliance` | `GET /v1/admin-app/kyc` | 200 |
| `compliance` | `PATCH /v1/admin-app/wallets/{id}/freeze` | 403 |
| `ops` | `GET /v1/admin-app/reconciliation` | 403 |
| `finance` | `GET /v1/admin-app/reconciliation` | 200 |
| `ops` | `GET /v1/admin-app/audit` | 403 |
| `admin` | `GET /v1/admin-app/audit` | 200 |

All 403 responses must return `ADMINAPP_AUTH_002`.

---

## References

- Data models: `data-model.md`
- Endpoint details: `contracts/dashboards.md`, `contracts/user-wallet-transaction.md`, `contracts/agent-finance.md`, `contracts/compliance.md`
- Architecture decisions: `research.md`
- Maker-checker workflow: E28 `MakerCheckerWorkflow` — `/v1/admin/configuration/approvals/`
