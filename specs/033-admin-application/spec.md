# E33 — Admin Application

## Intent
This feature specification decomposes Epic E33 from the approved 538-story backlog into an independently executable Spec-Kit feature.

## Scope
Implement only the stories listed in this document. Preserve their IDs and business intent.

## Platform alignment
- Backend API: Laravel/PHP 8.4+
- Admin/Web: Next.js + TypeScript where UI applies
- Mobile: Flutter where UI applies
- DB: PostgreSQL
- Cache/Queue: Redis + Horizon
- API: REST/OpenAPI
- Design: shared token-driven design system

## Decision handling
- Do not invent TBD values.
- Configurable policies must be resolved through the centralized Admin configuration capability.
- Financial stories must use ledger/idempotency/concurrency controls where applicable.
- Provider selection remains TBD.
- The `/v1/admin-app/` routes are thin aggregation controllers. They orchestrate calls to existing domain services from E01–E32 and must not duplicate domain logic. No business rules that already exist in a prior epic may be re-implemented here.

## Clarifications

### Session 2026-10-05

- Q: Does the maker-checker approval workflow apply to all 22 stories, or only to specific state-changing operations? → A: Maker-checker applies only to state-changing operations: Approve KYC (008), Reject KYC (009), Freeze wallet (011), Unfreeze wallet (012), Reverse transaction (015), Approve agent (016). All other stories use `admin.read / admin.write as applicable` without maker-checker.
- Q: Do all four admin roles have access to every story, or does each role have a restricted subset? → A: Roles are differentiated. Admin has access to all 22 stories. Role-to-story access matrix:

| Story | Admin | Operations | Finance | Compliance |
|-------|-------|------------|---------|------------|
| 001 View operational dashboard | ✓ | ✓ | ✓ | ✓ |
| 002 View financial dashboard | ✓ | | ✓ | |
| 003 View risk dashboard | ✓ | | | ✓ |
| 004 Search users | ✓ | ✓ | | |
| 005 Manage users | ✓ | ✓ | | |
| 006 Manage roles | ✓ | | | |
| 007 Review KYC | ✓ | | | ✓ |
| 008 Approve KYC | ✓ | | | ✓ |
| 009 Reject KYC | ✓ | | | ✓ |
| 010 Search wallets | ✓ | ✓ | | |
| 011 Freeze wallet | ✓ | ✓ | | |
| 012 Unfreeze wallet | ✓ | ✓ | | |
| 013 Search transactions | ✓ | ✓ | ✓ | |
| 014 Investigate transactions | ✓ | ✓ | | |
| 015 Reverse transaction | ✓ | ✓ | | |
| 016 Approve agent | ✓ | ✓ | | |
| 017 Manage agent | ✓ | ✓ | | |
| 018 Manage float | ✓ | | ✓ | |
| 019 Reconciliation | ✓ | | ✓ | |
| 020 Settlement | ✓ | | ✓ | |
| 021 Audit | ✓ | | | ✓ |
| 022 Reports | ✓ | | ✓ | ✓ |

- Q: For the three dashboard stories (001/002/003), should data be real-time or near-real-time? → A: Near-real-time. Metrics are pre-aggregated and automatically refreshed at a maximum interval of 5 minutes. Each dashboard must display a "last updated" timestamp. No WebSocket or server-sent events required.
- Q: Should the `/v1/admin-app/` routes be new aggregation controllers or duplicate domain endpoints? → A: Aggregation layer. E33 adds thin admin-application controllers that call existing domain services from E01–E32. No domain logic from prior epics is re-implemented.
- Q: For US-ADMINAPP-015 Reverse transaction, which transaction types are eligible for reversal? → A: Internal transactions only — wallet-to-wallet transfers (E09) and agent cash-in/out (E08/E10). Bank-settled funding deposits and withdrawals are excluded; those require external bank reversal processes outside this system.

## Epic Stories

### US-ADMINAPP-001 — View operational dashboard (P0)
**Actor:** Authorized Admin / Operations / Finance / Compliance user

**Acceptance Criteria:** View operational dashboard must satisfy the source formal specification, including validation, authorization, persistence, traceability/audit and applicable financial integrity/idempotency requirements. Dashboard metrics are pre-aggregated and automatically refreshed at a maximum staleness of 5 minutes. A "last updated" timestamp is prominently displayed. No real-time push or WebSocket required.

**API:** `GET /v1/admin-app/dashboard/operational`

**DB Entities:** User, Wallet, Transaction, AdminAction

**Events:** US_ADMINAPP_001_REQUESTED; US_ADMINAPP_001_SUCCEEDED; US_ADMINAPP_001_FAILED (where applicable); AUDIT_EVENT_RECORDED

**Permissions:** admin.read / admin.write as applicable

**UI Screens:** Admin Portal: relevant management screen, detail/drawer, confirmation, error state

**QA Scenarios:** Happy-path test with valid data. | Required-field and invalid-format validation test. | Unauthorized-role/access test. | Boundary/limit test where applicable. | Failure/timeout/provider-error test where applicable. | Duplicate/idempotency test where applicable. | Audit/event verification. | Regression test for dependent modules.

**Definition of Done:** Criteria + API/DB/events/security/QA implemented and passing

### US-ADMINAPP-002 — View financial dashboard (P0)
**Actor:** Admin, Finance

**Acceptance Criteria:** View financial dashboard must satisfy the source formal specification, including validation, authorization, persistence, traceability/audit and applicable financial integrity/idempotency requirements. Dashboard metrics are pre-aggregated and automatically refreshed at a maximum staleness of 5 minutes. A "last updated" timestamp is prominently displayed. No real-time push or WebSocket required.

**API:** `GET /v1/admin-app/dashboard/financial`

**DB Entities:** User, Wallet, Transaction, AdminAction

**Events:** US_ADMINAPP_002_REQUESTED; US_ADMINAPP_002_SUCCEEDED; US_ADMINAPP_002_FAILED (where applicable); AUDIT_EVENT_RECORDED

**Permissions:** admin.read / admin.write as applicable

**UI Screens:** Admin Portal: relevant management screen, detail/drawer, confirmation, error state

**QA Scenarios:** Happy-path test with valid data. | Required-field and invalid-format validation test. | Unauthorized-role/access test. | Boundary/limit test where applicable. | Failure/timeout/provider-error test where applicable. | Duplicate/idempotency test where applicable. | Audit/event verification. | Regression test for dependent modules.

**Definition of Done:** Criteria + API/DB/events/security/QA implemented and passing

### US-ADMINAPP-003 — View risk dashboard (P0)
**Actor:** Admin, Compliance

**Acceptance Criteria:** View risk dashboard must satisfy the source formal specification, including validation, authorization, persistence, traceability/audit and applicable financial integrity/idempotency requirements. Dashboard metrics are pre-aggregated and automatically refreshed at a maximum staleness of 5 minutes. A "last updated" timestamp is prominently displayed. No real-time push or WebSocket required.

**API:** `GET /v1/admin-app/dashboard/risk`

**DB Entities:** User, Wallet, Transaction, AdminAction

**Events:** US_ADMINAPP_003_REQUESTED; US_ADMINAPP_003_SUCCEEDED; US_ADMINAPP_003_FAILED (where applicable); AUDIT_EVENT_RECORDED

**Permissions:** admin.read / admin.write as applicable

**UI Screens:** Admin Portal: relevant management screen, detail/drawer, confirmation, error state

**QA Scenarios:** Happy-path test with valid data. | Required-field and invalid-format validation test. | Unauthorized-role/access test. | Boundary/limit test where applicable. | Failure/timeout/provider-error test where applicable. | Duplicate/idempotency test where applicable. | Audit/event verification. | Regression test for dependent modules.

**Definition of Done:** Criteria + API/DB/events/security/QA implemented and passing

### US-ADMINAPP-004 — Search users (P0)
**Actor:** Admin, Operations

**Acceptance Criteria:** Search users must satisfy the source formal specification, including validation, authorization, persistence, traceability/audit and applicable financial integrity/idempotency requirements.

**API:** `GET /v1/admin-app/users`, `GET /v1/admin-app/users/{user_id}`

**DB Entities:** User, Wallet, Transaction, AdminAction

**Events:** US_ADMINAPP_004_REQUESTED; US_ADMINAPP_004_SUCCEEDED; US_ADMINAPP_004_FAILED (where applicable); AUDIT_EVENT_RECORDED

**Permissions:** admin.read / admin.write as applicable

**UI Screens:** Admin Portal: relevant management screen, detail/drawer, confirmation, error state

**QA Scenarios:** Happy-path test with valid data. | Required-field and invalid-format validation test. | Unauthorized-role/access test. | Boundary/limit test where applicable. | Failure/timeout/provider-error test where applicable. | Duplicate/idempotency test where applicable. | Audit/event verification. | Regression test for dependent modules.

**Definition of Done:** Criteria + API/DB/events/security/QA implemented and passing

### US-ADMINAPP-005 — Manage users (P0)
**Actor:** Admin, Operations

**Acceptance Criteria:** Manage users must satisfy the source formal specification, including validation, authorization, persistence, traceability/audit and applicable financial integrity/idempotency requirements.

**API:** `PATCH /v1/admin-app/users/{user_id}/status`

**DB Entities:** User, Wallet, Transaction, AdminAction

**Events:** US_ADMINAPP_005_REQUESTED; US_ADMINAPP_005_SUCCEEDED; US_ADMINAPP_005_FAILED (where applicable); AUDIT_EVENT_RECORDED

**Permissions:** admin.read / admin.write as applicable

**UI Screens:** Admin Portal: relevant management screen, detail/drawer, confirmation, error state

**QA Scenarios:** Happy-path test with valid data. | Required-field and invalid-format validation test. | Unauthorized-role/access test. | Boundary/limit test where applicable. | Failure/timeout/provider-error test where applicable. | Duplicate/idempotency test where applicable. | Audit/event verification. | Regression test for dependent modules.

**Definition of Done:** Criteria + API/DB/events/security/QA implemented and passing

### US-ADMINAPP-006 — Manage roles (P0)
**Actor:** Admin

**Acceptance Criteria:** Manage roles must satisfy the source formal specification, including validation, authorization, persistence, traceability/audit and applicable financial integrity/idempotency requirements.

**API:** `GET /v1/admin-app/roles`, `PATCH /v1/admin-app/roles/{role_id}/permissions`

**DB Entities:** User, Wallet, Transaction, AdminAction

**Events:** US_ADMINAPP_006_REQUESTED; US_ADMINAPP_006_SUCCEEDED; US_ADMINAPP_006_FAILED (where applicable); AUDIT_EVENT_RECORDED

**Permissions:** admin.read / admin.write as applicable

**UI Screens:** Admin Portal: relevant management screen, detail/drawer, confirmation, error state

**QA Scenarios:** Happy-path test with valid data. | Required-field and invalid-format validation test. | Unauthorized-role/access test. | Boundary/limit test where applicable. | Failure/timeout/provider-error test where applicable. | Duplicate/idempotency test where applicable. | Audit/event verification. | Regression test for dependent modules.

**Definition of Done:** Criteria + API/DB/events/security/QA implemented and passing

### US-ADMINAPP-007 — Review KYC (P0)
**Actor:** Admin, Compliance

**Acceptance Criteria:** Review KYC must satisfy the source formal specification, including validation, authorization, persistence, traceability/audit and applicable financial integrity/idempotency requirements.

**API:** `GET /v1/admin-app/kyc`, `GET /v1/admin-app/kyc/{kyc_submission_id}`

**DB Entities:** User, Wallet, Transaction, AdminAction

**Events:** US_ADMINAPP_007_REQUESTED; US_ADMINAPP_007_SUCCEEDED; US_ADMINAPP_007_FAILED (where applicable); AUDIT_EVENT_RECORDED

**Permissions:** admin.read / admin.write as applicable

**UI Screens:** Admin Portal: relevant management screen, detail/drawer, confirmation, error state

**QA Scenarios:** Happy-path test with valid data. | Required-field and invalid-format validation test. | Unauthorized-role/access test. | Boundary/limit test where applicable. | Failure/timeout/provider-error test where applicable. | Duplicate/idempotency test where applicable. | Audit/event verification. | Regression test for dependent modules.

**Definition of Done:** Criteria + API/DB/events/security/QA implemented and passing

### US-ADMINAPP-008 — Approve KYC (P0)
**Actor:** Admin, Compliance

**Acceptance Criteria:** Approve KYC must satisfy the source formal specification, including validation, authorization, persistence, traceability/audit and applicable financial integrity/idempotency requirements.

**API:** `POST /v1/admin-app/kyc/{kyc_submission_id}/approve`

**DB Entities:** User, Wallet, Transaction, AdminAction

**Events:** US_ADMINAPP_008_REQUESTED; US_ADMINAPP_008_SUCCEEDED; US_ADMINAPP_008_FAILED (where applicable); AUDIT_EVENT_RECORDED

**Permissions:** admin.write; maker-checker required before execution

**UI Screens:** Admin Portal: relevant management screen, detail/drawer, confirmation, error state

**QA Scenarios:** Happy-path test with valid data. | Required-field and invalid-format validation test. | Unauthorized-role/access test. | Boundary/limit test where applicable. | Failure/timeout/provider-error test where applicable. | Duplicate/idempotency test where applicable. | Audit/event verification. | Regression test for dependent modules.

**Definition of Done:** Criteria + API/DB/events/security/QA implemented and passing

### US-ADMINAPP-009 — Reject KYC (P0)
**Actor:** Admin, Compliance

**Acceptance Criteria:** Reject KYC must satisfy the source formal specification, including validation, authorization, persistence, traceability/audit and applicable financial integrity/idempotency requirements.

**API:** `POST /v1/admin-app/kyc/{kyc_submission_id}/reject`

**DB Entities:** User, Wallet, Transaction, AdminAction

**Events:** US_ADMINAPP_009_REQUESTED; US_ADMINAPP_009_SUCCEEDED; US_ADMINAPP_009_FAILED (where applicable); AUDIT_EVENT_RECORDED

**Permissions:** admin.write; maker-checker required before execution

**UI Screens:** Admin Portal: relevant management screen, detail/drawer, confirmation, error state

**QA Scenarios:** Happy-path test with valid data. | Required-field and invalid-format validation test. | Unauthorized-role/access test. | Boundary/limit test where applicable. | Failure/timeout/provider-error test where applicable. | Duplicate/idempotency test where applicable. | Audit/event verification. | Regression test for dependent modules.

**Definition of Done:** Criteria + API/DB/events/security/QA implemented and passing

### US-ADMINAPP-010 — Search wallets (P0)
**Actor:** Admin, Operations

**Acceptance Criteria:** Search wallets must satisfy the source formal specification, including validation, authorization, persistence, traceability/audit and applicable financial integrity/idempotency requirements.

**API:** `GET /v1/admin-app/wallets`, `GET /v1/admin-app/wallets/{wallet_id}`

**DB Entities:** User, Wallet, Transaction, AdminAction

**Events:** US_ADMINAPP_010_REQUESTED; US_ADMINAPP_010_SUCCEEDED; US_ADMINAPP_010_FAILED (where applicable); AUDIT_EVENT_RECORDED

**Permissions:** admin.read / admin.write as applicable

**UI Screens:** Admin Portal: relevant management screen, detail/drawer, confirmation, error state

**QA Scenarios:** Happy-path test with valid data. | Required-field and invalid-format validation test. | Unauthorized-role/access test. | Boundary/limit test where applicable. | Failure/timeout/provider-error test where applicable. | Duplicate/idempotency test where applicable. | Audit/event verification. | Regression test for dependent modules.

**Definition of Done:** Criteria + API/DB/events/security/QA implemented and passing

### US-ADMINAPP-011 — Freeze wallet (P0)
**Actor:** Admin, Operations

**Acceptance Criteria:** Freeze wallet must satisfy the source formal specification, including validation, authorization, persistence, traceability/audit and applicable financial integrity/idempotency requirements.

**API:** `POST /v1/admin-app/wallets/{wallet_id}/freeze`

**DB Entities:** User, Wallet, Transaction, AdminAction

**Events:** US_ADMINAPP_011_REQUESTED; US_ADMINAPP_011_SUCCEEDED; US_ADMINAPP_011_FAILED (where applicable); AUDIT_EVENT_RECORDED

**Permissions:** admin.write; maker-checker required before execution

**UI Screens:** Admin Portal: relevant management screen, detail/drawer, confirmation, error state

**QA Scenarios:** Happy-path test with valid data. | Required-field and invalid-format validation test. | Unauthorized-role/access test. | Boundary/limit test where applicable. | Failure/timeout/provider-error test where applicable. | Duplicate/idempotency test where applicable. | Audit/event verification. | Regression test for dependent modules.

**Definition of Done:** Criteria + API/DB/events/security/QA implemented and passing

### US-ADMINAPP-012 — Unfreeze wallet (P0)
**Actor:** Admin, Operations

**Acceptance Criteria:** Unfreeze wallet must satisfy the source formal specification, including validation, authorization, persistence, traceability/audit and applicable financial integrity/idempotency requirements.

**API:** `POST /v1/admin-app/wallets/{wallet_id}/unfreeze`

**DB Entities:** User, Wallet, Transaction, AdminAction

**Events:** US_ADMINAPP_012_REQUESTED; US_ADMINAPP_012_SUCCEEDED; US_ADMINAPP_012_FAILED (where applicable); AUDIT_EVENT_RECORDED

**Permissions:** admin.write; maker-checker required before execution

**UI Screens:** Admin Portal: relevant management screen, detail/drawer, confirmation, error state

**QA Scenarios:** Happy-path test with valid data. | Required-field and invalid-format validation test. | Unauthorized-role/access test. | Boundary/limit test where applicable. | Failure/timeout/provider-error test where applicable. | Duplicate/idempotency test where applicable. | Audit/event verification. | Regression test for dependent modules.

**Definition of Done:** Criteria + API/DB/events/security/QA implemented and passing

### US-ADMINAPP-013 — Search transactions (P0)
**Actor:** Admin, Operations, Finance

**Acceptance Criteria:** Search transactions must satisfy the source formal specification, including validation, authorization, persistence, traceability/audit and applicable financial integrity/idempotency requirements.

**API:** `GET /v1/admin-app/transactions`, `GET /v1/admin-app/transactions/{transaction_id}`

**DB Entities:** User, Wallet, Transaction, AdminAction

**Events:** US_ADMINAPP_013_REQUESTED; US_ADMINAPP_013_SUCCEEDED; US_ADMINAPP_013_FAILED (where applicable); AUDIT_EVENT_RECORDED

**Permissions:** admin.read / admin.write as applicable

**UI Screens:** Admin Portal: relevant management screen, detail/drawer, confirmation, error state

**QA Scenarios:** Happy-path test with valid data. | Required-field and invalid-format validation test. | Unauthorized-role/access test. | Boundary/limit test where applicable. | Failure/timeout/provider-error test where applicable. | Duplicate/idempotency test where applicable. | Audit/event verification. | Regression test for dependent modules.

**Definition of Done:** Criteria + API/DB/events/security/QA implemented and passing

### US-ADMINAPP-014 — Investigate transactions (P0)
**Actor:** Admin, Operations

**Acceptance Criteria:** Investigate transactions must satisfy the source formal specification, including validation, authorization, persistence, traceability/audit and applicable financial integrity/idempotency requirements.

**API:** `GET /v1/admin-app/transactions/{transaction_id}` (with ledger entries + fraud assessment)

**DB Entities:** User, Wallet, Transaction, AdminAction

**Events:** US_ADMINAPP_014_REQUESTED; US_ADMINAPP_014_SUCCEEDED; US_ADMINAPP_014_FAILED (where applicable); AUDIT_EVENT_RECORDED

**Permissions:** admin.read / admin.write as applicable

**UI Screens:** Admin Portal: relevant management screen, detail/drawer, confirmation, error state

**QA Scenarios:** Happy-path test with valid data. | Required-field and invalid-format validation test. | Unauthorized-role/access test. | Boundary/limit test where applicable. | Failure/timeout/provider-error test where applicable. | Duplicate/idempotency test where applicable. | Audit/event verification. | Regression test for dependent modules.

**Definition of Done:** Criteria + API/DB/events/security/QA implemented and passing

### US-ADMINAPP-015 — Reverse transaction (P0)
**Actor:** Admin, Operations

**Acceptance Criteria:** Reverse transaction must satisfy the source formal specification, including validation, authorization, persistence, traceability/audit and applicable financial integrity/idempotency requirements. Reversal is limited to internal transactions: wallet-to-wallet transfers and agent cash-in/out only. Bank-settled funding deposits and withdrawals are not eligible for admin-initiated reversal (those require external bank reversal processes). The reversal is executed via the existing domain reversal APIs from E09/E08/E10; no new reversal domain logic is implemented here.

**API:** `POST /v1/admin-app/transactions/{transaction_id}/reverse`

**DB Entities:** User, Wallet, Transaction, AdminAction

**Events:** US_ADMINAPP_015_REQUESTED; US_ADMINAPP_015_SUCCEEDED; US_ADMINAPP_015_FAILED (where applicable); AUDIT_EVENT_RECORDED

**Permissions:** admin.write; maker-checker required before execution

**UI Screens:** Admin Portal: relevant management screen, detail/drawer, confirmation, error state

**QA Scenarios:** Happy-path test with valid data. | Required-field and invalid-format validation test. | Unauthorized-role/access test. | Boundary/limit test where applicable. | Failure/timeout/provider-error test where applicable. | Duplicate/idempotency test where applicable. | Audit/event verification. | Regression test for dependent modules.

**Definition of Done:** Criteria + API/DB/events/security/QA implemented and passing

### US-ADMINAPP-016 — Approve agent (P0)
**Actor:** Admin, Operations

**Acceptance Criteria:** Approve agent must satisfy the source formal specification, including validation, authorization, persistence, traceability/audit and applicable financial integrity/idempotency requirements.

**API:** `POST /v1/admin-app/agents/{agent_id}/approve`

**DB Entities:** User, Wallet, Transaction, AdminAction

**Events:** US_ADMINAPP_016_REQUESTED; US_ADMINAPP_016_SUCCEEDED; US_ADMINAPP_016_FAILED (where applicable); AUDIT_EVENT_RECORDED

**Permissions:** admin.write; maker-checker required before execution

**UI Screens:** Admin Portal: relevant management screen, detail/drawer, confirmation, error state

**QA Scenarios:** Happy-path test with valid data. | Required-field and invalid-format validation test. | Unauthorized-role/access test. | Boundary/limit test where applicable. | Failure/timeout/provider-error test where applicable. | Duplicate/idempotency test where applicable. | Audit/event verification. | Regression test for dependent modules.

**Definition of Done:** Criteria + API/DB/events/security/QA implemented and passing

### US-ADMINAPP-017 — Manage agent (P0)
**Actor:** Admin, Operations

**Acceptance Criteria:** Manage agent must satisfy the source formal specification, including validation, authorization, persistence, traceability/audit and applicable financial integrity/idempotency requirements.

**API:** `GET /v1/admin-app/agents`, `GET /v1/admin-app/agents/{agent_id}`, `PATCH /v1/admin-app/agents/{agent_id}/status`

**DB Entities:** User, Wallet, Transaction, AdminAction

**Events:** US_ADMINAPP_017_REQUESTED; US_ADMINAPP_017_SUCCEEDED; US_ADMINAPP_017_FAILED (where applicable); AUDIT_EVENT_RECORDED

**Permissions:** admin.read / admin.write as applicable

**UI Screens:** Admin Portal: relevant management screen, detail/drawer, confirmation, error state

**QA Scenarios:** Happy-path test with valid data. | Required-field and invalid-format validation test. | Unauthorized-role/access test. | Boundary/limit test where applicable. | Failure/timeout/provider-error test where applicable. | Duplicate/idempotency test where applicable. | Audit/event verification. | Regression test for dependent modules.

**Definition of Done:** Criteria + API/DB/events/security/QA implemented and passing

### US-ADMINAPP-018 — Manage float (P0)
**Actor:** Admin, Finance

**Acceptance Criteria:** Manage float must satisfy the source formal specification, including validation, authorization, persistence, traceability/audit and applicable financial integrity/idempotency requirements.

**API:** `GET /v1/admin-app/float`, `POST /v1/admin-app/float/{agent_id}/adjust`

**DB Entities:** User, Wallet, Transaction, AdminAction

**Events:** US_ADMINAPP_018_REQUESTED; US_ADMINAPP_018_SUCCEEDED; US_ADMINAPP_018_FAILED (where applicable); AUDIT_EVENT_RECORDED

**Permissions:** admin.read / admin.write as applicable

**UI Screens:** Admin Portal: relevant management screen, detail/drawer, confirmation, error state

**QA Scenarios:** Happy-path test with valid data. | Required-field and invalid-format validation test. | Unauthorized-role/access test. | Boundary/limit test where applicable. | Failure/timeout/provider-error test where applicable. | Duplicate/idempotency test where applicable. | Audit/event verification. | Regression test for dependent modules.

**Definition of Done:** Criteria + API/DB/events/security/QA implemented and passing

### US-ADMINAPP-019 — Reconciliation (P0)
**Actor:** Admin, Finance

**Acceptance Criteria:** Reconciliation must satisfy the source formal specification, including validation, authorization, persistence, traceability/audit and applicable financial integrity/idempotency requirements.

**API:** `GET /v1/admin-app/reconciliation`, `GET /v1/admin-app/reconciliation/{batch_id}`

**DB Entities:** User, Wallet, Transaction, AdminAction

**Events:** US_ADMINAPP_019_REQUESTED; US_ADMINAPP_019_SUCCEEDED; US_ADMINAPP_019_FAILED (where applicable); AUDIT_EVENT_RECORDED

**Permissions:** admin.read / admin.write as applicable

**UI Screens:** Admin Portal: relevant management screen, detail/drawer, confirmation, error state

**QA Scenarios:** Happy-path test with valid data. | Required-field and invalid-format validation test. | Unauthorized-role/access test. | Boundary/limit test where applicable. | Failure/timeout/provider-error test where applicable. | Duplicate/idempotency test where applicable. | Audit/event verification. | Regression test for dependent modules.

**Definition of Done:** Criteria + API/DB/events/security/QA implemented and passing

### US-ADMINAPP-020 — Settlement (P0)
**Actor:** Admin, Finance

**Acceptance Criteria:** Settlement must satisfy the source formal specification, including validation, authorization, persistence, traceability/audit and applicable financial integrity/idempotency requirements.

**API:** `GET /v1/admin-app/settlement`, `GET /v1/admin-app/settlement/{settlement_id}`

**DB Entities:** User, Wallet, Transaction, AdminAction

**Events:** US_ADMINAPP_020_REQUESTED; US_ADMINAPP_020_SUCCEEDED; US_ADMINAPP_020_FAILED (where applicable); AUDIT_EVENT_RECORDED

**Permissions:** admin.read / admin.write as applicable

**UI Screens:** Admin Portal: relevant management screen, detail/drawer, confirmation, error state

**QA Scenarios:** Happy-path test with valid data. | Required-field and invalid-format validation test. | Unauthorized-role/access test. | Boundary/limit test where applicable. | Failure/timeout/provider-error test where applicable. | Duplicate/idempotency test where applicable. | Audit/event verification. | Regression test for dependent modules.

**Definition of Done:** Criteria + API/DB/events/security/QA implemented and passing

### US-ADMINAPP-021 — Audit (P0)
**Actor:** Admin, Compliance

**Acceptance Criteria:** Audit must satisfy the source formal specification, including validation, authorization, persistence, traceability/audit and applicable financial integrity/idempotency requirements.

**API:** `GET /v1/admin-app/audit`

**DB Entities:** User, Wallet, Transaction, AdminAction

**Events:** US_ADMINAPP_021_REQUESTED; US_ADMINAPP_021_SUCCEEDED; US_ADMINAPP_021_FAILED (where applicable); AUDIT_EVENT_RECORDED

**Permissions:** admin.read / admin.write as applicable

**UI Screens:** Admin Portal: relevant management screen, detail/drawer, confirmation, error state

**QA Scenarios:** Happy-path test with valid data. | Required-field and invalid-format validation test. | Unauthorized-role/access test. | Boundary/limit test where applicable. | Failure/timeout/provider-error test where applicable. | Duplicate/idempotency test where applicable. | Audit/event verification. | Regression test for dependent modules.

**Definition of Done:** Criteria + API/DB/events/security/QA implemented and passing

### US-ADMINAPP-022 — Reports (P0)
**Actor:** Admin, Finance, Compliance

**Acceptance Criteria:** Reports must satisfy the source formal specification, including validation, authorization, persistence, traceability/audit and applicable financial integrity/idempotency requirements.

**API:** `GET /v1/admin-app/reports`, `POST /v1/admin-app/reports`, `GET /v1/admin-app/reports/{report_id}`

**DB Entities:** User, Wallet, Transaction, AdminAction

**Events:** US_ADMINAPP_022_REQUESTED; US_ADMINAPP_022_SUCCEEDED; US_ADMINAPP_022_FAILED (where applicable); AUDIT_EVENT_RECORDED

**Permissions:** admin.read / admin.write as applicable

**UI Screens:** Admin Portal: relevant management screen, detail/drawer, confirmation, error state

**QA Scenarios:** Happy-path test with valid data. | Required-field and invalid-format validation test. | Unauthorized-role/access test. | Boundary/limit test where applicable. | Failure/timeout/provider-error test where applicable. | Duplicate/idempotency test where applicable. | Audit/event verification. | Regression test for dependent modules.

**Definition of Done:** Criteria + API/DB/events/security/QA implemented and passing


## Epic-level Definition of Done
- All stories in this epic meet their story-specific Definition of Done.
- Cross-story dependencies are tested.
- API contracts and migrations are reviewed.
- Security and authorization checks pass.
- UI states and accessibility requirements pass where applicable.
- No unresolved Critical/High defect blocks completion.
