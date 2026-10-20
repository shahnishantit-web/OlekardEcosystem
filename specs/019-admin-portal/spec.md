# E19 — Admin Portal

## Intent
This feature specification decomposes Epic E19 from the approved 538-story backlog into an independently executable Spec-Kit feature.

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
- No external provider integration is required for E19 scope; the Admin Portal consumes internal APIs from E01–E18 modules only.

## Epic Stories

### US-ADMIN-001 — View dashboard (P0)
**Actor:** Authorized operations/admin user or system

**Acceptance Criteria:** View dashboard must satisfy the source formal specification, including validation, authorization, persistence, traceability/audit and applicable financial integrity/idempotency requirements.

**API:** `/v1/admin/view-dashboard`

**DB Entities:** User, Wallet, Transaction, AdminAction

**Events:** US_ADMIN_001_REQUESTED; US_ADMIN_001_SUCCEEDED; US_ADMIN_001_FAILED (where applicable); AUDIT_EVENT_RECORDED

**Permissions:** admin.read / admin.write as applicable; maker-checker for financial changes

**UI Screens:** Admin Portal: dashboard/list/detail/action/confirmation screens as applicable

**QA Scenarios:** Happy-path test with valid data. | Required-field and invalid-format validation test. | Unauthorized-role/access test. | Boundary/limit test where applicable. | Failure/timeout/provider-error test where applicable. | Duplicate/idempotency test where applicable. | Audit/event verification. | Regression test for dependent modules.

**Definition of Done:** Criteria + API/DB/events/security/QA implemented and passing

### US-ADMIN-002 — View total users (P0)
**Actor:** Authorized operations/admin user or system

**Acceptance Criteria:** View total users must satisfy the source formal specification, including validation, authorization, persistence, traceability/audit and applicable financial integrity/idempotency requirements.

**API:** `/v1/admin/view-total-users`

**DB Entities:** User, Wallet, Transaction, AdminAction

**Events:** US_ADMIN_002_REQUESTED; US_ADMIN_002_SUCCEEDED; US_ADMIN_002_FAILED (where applicable); AUDIT_EVENT_RECORDED

**Permissions:** admin.read / admin.write as applicable; maker-checker for financial changes

**UI Screens:** Admin Portal: dashboard/list/detail/action/confirmation screens as applicable

**QA Scenarios:** Happy-path test with valid data. | Required-field and invalid-format validation test. | Unauthorized-role/access test. | Boundary/limit test where applicable. | Failure/timeout/provider-error test where applicable. | Duplicate/idempotency test where applicable. | Audit/event verification. | Regression test for dependent modules.

**Definition of Done:** Criteria + API/DB/events/security/QA implemented and passing

### US-ADMIN-003 — View wallet statistics (P0)
**Actor:** Authorized operations/admin user or system

**Acceptance Criteria:** View wallet statistics must satisfy the source formal specification, including validation, authorization, persistence, traceability/audit and applicable financial integrity/idempotency requirements.

**API:** `/v1/admin/view-wallet-statistics`

**DB Entities:** User, Wallet, Transaction, AdminAction

**Events:** US_ADMIN_003_REQUESTED; US_ADMIN_003_SUCCEEDED; US_ADMIN_003_FAILED (where applicable); AUDIT_EVENT_RECORDED

**Permissions:** admin.read / admin.write as applicable; maker-checker for financial changes

**UI Screens:** Admin Portal: dashboard/list/detail/action/confirmation screens as applicable

**QA Scenarios:** Happy-path test with valid data. | Required-field and invalid-format validation test. | Unauthorized-role/access test. | Boundary/limit test where applicable. | Failure/timeout/provider-error test where applicable. | Duplicate/idempotency test where applicable. | Audit/event verification. | Regression test for dependent modules.

**Definition of Done:** Criteria + API/DB/events/security/QA implemented and passing

### US-ADMIN-004 — View transaction statistics (P0)
**Actor:** Authorized operations/admin user or system

**Acceptance Criteria:** View transaction statistics must satisfy the source formal specification, including validation, authorization, persistence, traceability/audit and applicable financial integrity/idempotency requirements.

**API:** `/v1/admin/view-transaction-statistics`

**DB Entities:** User, Wallet, Transaction, AdminAction

**Events:** US_ADMIN_004_REQUESTED; US_ADMIN_004_SUCCEEDED; US_ADMIN_004_FAILED (where applicable); AUDIT_EVENT_RECORDED

**Permissions:** admin.read / admin.write as applicable; maker-checker for financial changes

**UI Screens:** Admin Portal: dashboard/list/detail/action/confirmation screens as applicable

**QA Scenarios:** Happy-path test with valid data. | Required-field and invalid-format validation test. | Unauthorized-role/access test. | Boundary/limit test where applicable. | Failure/timeout/provider-error test where applicable. | Duplicate/idempotency test where applicable. | Audit/event verification. | Regression test for dependent modules.

**Definition of Done:** Criteria + API/DB/events/security/QA implemented and passing

### US-ADMIN-005 — View transaction failures (P0)
**Actor:** Authorized operations/admin user or system

**Acceptance Criteria:** View transaction failures must satisfy the source formal specification, including validation, authorization, persistence, traceability/audit and applicable financial integrity/idempotency requirements.

**API:** `/v1/admin/view-transaction-failures`

**DB Entities:** User, Wallet, Transaction, AdminAction

**Events:** US_ADMIN_005_REQUESTED; US_ADMIN_005_SUCCEEDED; US_ADMIN_005_FAILED (where applicable); AUDIT_EVENT_RECORDED

**Permissions:** admin.read / admin.write as applicable; maker-checker for financial changes

**UI Screens:** Admin Portal: dashboard/list/detail/action/confirmation screens as applicable

**QA Scenarios:** Happy-path test with valid data. | Required-field and invalid-format validation test. | Unauthorized-role/access test. | Boundary/limit test where applicable. | Failure/timeout/provider-error test where applicable. | Duplicate/idempotency test where applicable. | Audit/event verification. | Regression test for dependent modules.

**Definition of Done:** Criteria + API/DB/events/security/QA implemented and passing

### US-ADMIN-006 — View KYC statistics (P0)
**Actor:** Authorized operations/admin user or system

**Acceptance Criteria:** View KYC statistics must satisfy the source formal specification, including validation, authorization, persistence, traceability/audit and applicable financial integrity/idempotency requirements.

**API:** `/v1/admin/view-kyc-statistics`

**DB Entities:** User, Wallet, Transaction, AdminAction

**Events:** US_ADMIN_006_REQUESTED; US_ADMIN_006_SUCCEEDED; US_ADMIN_006_FAILED (where applicable); AUDIT_EVENT_RECORDED

**Permissions:** admin.read / admin.write as applicable; maker-checker for financial changes

**UI Screens:** Admin Portal: dashboard/list/detail/action/confirmation screens as applicable

**QA Scenarios:** Happy-path test with valid data. | Required-field and invalid-format validation test. | Unauthorized-role/access test. | Boundary/limit test where applicable. | Failure/timeout/provider-error test where applicable. | Duplicate/idempotency test where applicable. | Audit/event verification. | Regression test for dependent modules.

**Definition of Done:** Criteria + API/DB/events/security/QA implemented and passing

### US-ADMIN-007 — View agent statistics (P0)
**Actor:** Authorized operations/admin user or system

**Acceptance Criteria:** View agent statistics must satisfy the source formal specification, including validation, authorization, persistence, traceability/audit and applicable financial integrity/idempotency requirements.

**API:** `/v1/admin/view-agent-statistics`

**DB Entities:** User, Wallet, Transaction, AdminAction

**Events:** US_ADMIN_007_REQUESTED; US_ADMIN_007_SUCCEEDED; US_ADMIN_007_FAILED (where applicable); AUDIT_EVENT_RECORDED

**Permissions:** admin.read / admin.write as applicable; maker-checker for financial changes

**UI Screens:** Admin Portal: dashboard/list/detail/action/confirmation screens as applicable

**QA Scenarios:** Happy-path test with valid data. | Required-field and invalid-format validation test. | Unauthorized-role/access test. | Boundary/limit test where applicable. | Failure/timeout/provider-error test where applicable. | Duplicate/idempotency test where applicable. | Audit/event verification. | Regression test for dependent modules.

**Definition of Done:** Criteria + API/DB/events/security/QA implemented and passing

### US-ADMIN-008 — View risk alerts (P0)
**Actor:** Authorized operations/admin user or system

**Acceptance Criteria:** View risk alerts must satisfy the source formal specification, including validation, authorization, persistence, traceability/audit and applicable financial integrity/idempotency requirements.

**API:** `/v1/admin/view-risk-alerts`

**DB Entities:** User, Wallet, Transaction, AdminAction

**Events:** US_ADMIN_008_REQUESTED; US_ADMIN_008_SUCCEEDED; US_ADMIN_008_FAILED (where applicable); AUDIT_EVENT_RECORDED

**Permissions:** admin.read / admin.write as applicable; maker-checker for financial changes

**UI Screens:** Admin Portal: dashboard/list/detail/action/confirmation screens as applicable

**QA Scenarios:** Happy-path test with valid data. | Required-field and invalid-format validation test. | Unauthorized-role/access test. | Boundary/limit test where applicable. | Failure/timeout/provider-error test where applicable. | Duplicate/idempotency test where applicable. | Audit/event verification. | Regression test for dependent modules.

**Definition of Done:** Criteria + API/DB/events/security/QA implemented and passing

### US-ADMIN-009 — Search users (P0)
**Actor:** Authorized operations/admin user or system

**Acceptance Criteria:** Search users must satisfy the source formal specification, including validation, authorization, persistence, traceability/audit and applicable financial integrity/idempotency requirements.

**API:** `/v1/admin/search-users`

**DB Entities:** User, Wallet, Transaction, AdminAction

**Events:** US_ADMIN_009_REQUESTED; US_ADMIN_009_SUCCEEDED; US_ADMIN_009_FAILED (where applicable); AUDIT_EVENT_RECORDED

**Permissions:** admin.read / admin.write as applicable; maker-checker for financial changes

**UI Screens:** Admin Portal: dashboard/list/detail/action/confirmation screens as applicable

**QA Scenarios:** Happy-path test with valid data. | Required-field and invalid-format validation test. | Unauthorized-role/access test. | Boundary/limit test where applicable. | Failure/timeout/provider-error test where applicable. | Duplicate/idempotency test where applicable. | Audit/event verification. | Regression test for dependent modules.

**Definition of Done:** Criteria + API/DB/events/security/QA implemented and passing

### US-ADMIN-010 — View user profile (P0)
**Actor:** Authorized operations/admin user or system

**Acceptance Criteria:** View user profile must satisfy the source formal specification, including validation, authorization, persistence, traceability/audit and applicable financial integrity/idempotency requirements.

**API:** `/v1/admin/view-user-profile`

**DB Entities:** User, Wallet, Transaction, AdminAction

**Events:** US_ADMIN_010_REQUESTED; US_ADMIN_010_SUCCEEDED; US_ADMIN_010_FAILED (where applicable); AUDIT_EVENT_RECORDED

**Permissions:** admin.read / admin.write as applicable; maker-checker for financial changes

**UI Screens:** Admin Portal: dashboard/list/detail/action/confirmation screens as applicable

**QA Scenarios:** Happy-path test with valid data. | Required-field and invalid-format validation test. | Unauthorized-role/access test. | Boundary/limit test where applicable. | Failure/timeout/provider-error test where applicable. | Duplicate/idempotency test where applicable. | Audit/event verification. | Regression test for dependent modules.

**Definition of Done:** Criteria + API/DB/events/security/QA implemented and passing

### US-ADMIN-011 — Suspend user (P0)
**Actor:** Authorized operations/admin user or system

**Acceptance Criteria:** Suspend user must satisfy the source formal specification, including validation, authorization, persistence, traceability/audit and applicable financial integrity/idempotency requirements.

**API:** `/v1/admin/suspend-user`

**DB Entities:** User, Wallet, Transaction, AdminAction

**Events:** US_ADMIN_011_REQUESTED; US_ADMIN_011_SUCCEEDED; US_ADMIN_011_FAILED (where applicable); AUDIT_EVENT_RECORDED

**Permissions:** admin.read / admin.write as applicable; maker-checker for financial changes

**UI Screens:** Admin Portal: dashboard/list/detail/action/confirmation screens as applicable

**QA Scenarios:** Happy-path test with valid data. | Required-field and invalid-format validation test. | Unauthorized-role/access test. | Boundary/limit test where applicable. | Failure/timeout/provider-error test where applicable. | Duplicate/idempotency test where applicable. | Audit/event verification. | Regression test for dependent modules.

**Definition of Done:** Criteria + API/DB/events/security/QA implemented and passing

### US-ADMIN-012 — Reactivate user (P0)
**Actor:** Authorized operations/admin user or system

**Acceptance Criteria:** Reactivate user must satisfy the source formal specification, including validation, authorization, persistence, traceability/audit and applicable financial integrity/idempotency requirements.

**API:** `/v1/admin/reactivate-user`

**DB Entities:** User, Wallet, Transaction, AdminAction

**Events:** US_ADMIN_012_REQUESTED; US_ADMIN_012_SUCCEEDED; US_ADMIN_012_FAILED (where applicable); AUDIT_EVENT_RECORDED

**Permissions:** admin.read / admin.write as applicable; maker-checker for financial changes

**UI Screens:** Admin Portal: dashboard/list/detail/action/confirmation screens as applicable

**QA Scenarios:** Happy-path test with valid data. | Required-field and invalid-format validation test. | Unauthorized-role/access test. | Boundary/limit test where applicable. | Failure/timeout/provider-error test where applicable. | Duplicate/idempotency test where applicable. | Audit/event verification. | Regression test for dependent modules.

**Definition of Done:** Criteria + API/DB/events/security/QA implemented and passing

### US-ADMIN-013 — Reset user authentication (P0)
**Actor:** Authorized operations/admin user or system

**Acceptance Criteria:** Reset user authentication must satisfy the source formal specification, including validation, authorization, persistence, traceability/audit and applicable financial integrity/idempotency requirements.

**API:** `/v1/admin/reset-user-authentication`

**DB Entities:** User, Wallet, Transaction, AdminAction

**Events:** US_ADMIN_013_REQUESTED; US_ADMIN_013_SUCCEEDED; US_ADMIN_013_FAILED (where applicable); AUDIT_EVENT_RECORDED

**Permissions:** admin.read / admin.write as applicable; maker-checker for financial changes

**UI Screens:** Admin Portal: dashboard/list/detail/action/confirmation screens as applicable

**QA Scenarios:** Happy-path test with valid data. | Required-field and invalid-format validation test. | Unauthorized-role/access test. | Boundary/limit test where applicable. | Failure/timeout/provider-error test where applicable. | Duplicate/idempotency test where applicable. | Audit/event verification. | Regression test for dependent modules.

**Definition of Done:** Criteria + API/DB/events/security/QA implemented and passing

### US-ADMIN-014 — View user sessions (P1)
**Actor:** Authorized operations/admin user or system

**Acceptance Criteria:** View user sessions must satisfy the source formal specification, including validation, authorization, persistence, traceability/audit and applicable financial integrity/idempotency requirements.

**API:** `/v1/admin/view-user-sessions`

**DB Entities:** User, Wallet, Transaction, AdminAction

**Events:** US_ADMIN_014_REQUESTED; US_ADMIN_014_SUCCEEDED; US_ADMIN_014_FAILED (where applicable); AUDIT_EVENT_RECORDED

**Permissions:** admin.read / admin.write as applicable; maker-checker for financial changes

**UI Screens:** Admin Portal: dashboard/list/detail/action/confirmation screens as applicable

**QA Scenarios:** Happy-path test with valid data. | Required-field and invalid-format validation test. | Unauthorized-role/access test. | Boundary/limit test where applicable. | Failure/timeout/provider-error test where applicable. | Duplicate/idempotency test where applicable. | Audit/event verification. | Regression test for dependent modules.

**Definition of Done:** Criteria + API/DB/events/security/QA implemented and passing

### US-ADMIN-015 — Search wallet (P0)
**Actor:** Authorized operations/admin user or system

**Acceptance Criteria:** Search wallet must satisfy the source formal specification, including validation, authorization, persistence, traceability/audit and applicable financial integrity/idempotency requirements.

**API:** `/v1/admin/search-wallet`

**DB Entities:** User, Wallet, Transaction, AdminAction

**Events:** US_ADMIN_015_REQUESTED; US_ADMIN_015_SUCCEEDED; US_ADMIN_015_FAILED (where applicable); AUDIT_EVENT_RECORDED

**Permissions:** admin.read / admin.write as applicable; maker-checker for financial changes

**UI Screens:** Admin Portal: dashboard/list/detail/action/confirmation screens as applicable

**QA Scenarios:** Happy-path test with valid data. | Required-field and invalid-format validation test. | Unauthorized-role/access test. | Boundary/limit test where applicable. | Failure/timeout/provider-error test where applicable. | Duplicate/idempotency test where applicable. | Audit/event verification. | Regression test for dependent modules.

**Definition of Done:** Criteria + API/DB/events/security/QA implemented and passing

### US-ADMIN-016 — View wallet balance (P0)
**Actor:** Authorized operations/admin user or system

**Acceptance Criteria:** View wallet balance must satisfy the source formal specification, including validation, authorization, persistence, traceability/audit and applicable financial integrity/idempotency requirements.

**API:** `/v1/admin/view-wallet-balance`

**DB Entities:** User, Wallet, Transaction, AdminAction

**Events:** US_ADMIN_016_REQUESTED; US_ADMIN_016_SUCCEEDED; US_ADMIN_016_FAILED (where applicable); AUDIT_EVENT_RECORDED

**Permissions:** admin.read / admin.write as applicable; maker-checker for financial changes

**UI Screens:** Admin Portal: dashboard/list/detail/action/confirmation screens as applicable

**QA Scenarios:** Happy-path test with valid data. | Required-field and invalid-format validation test. | Unauthorized-role/access test. | Boundary/limit test where applicable. | Failure/timeout/provider-error test where applicable. | Duplicate/idempotency test where applicable. | Audit/event verification. | Regression test for dependent modules.

**Definition of Done:** Criteria + API/DB/events/security/QA implemented and passing

### US-ADMIN-017 — Freeze wallet (P0)
**Actor:** Authorized operations/admin user or system

**Acceptance Criteria:** Freeze wallet must satisfy the source formal specification, including validation, authorization, persistence, traceability/audit and applicable financial integrity/idempotency requirements.

**API:** `/v1/admin/freeze-wallet`

**DB Entities:** User, Wallet, Transaction, AdminAction

**Events:** US_ADMIN_017_REQUESTED; US_ADMIN_017_SUCCEEDED; US_ADMIN_017_FAILED (where applicable); AUDIT_EVENT_RECORDED

**Permissions:** admin.read / admin.write as applicable; maker-checker for financial changes

**UI Screens:** Admin Portal: dashboard/list/detail/action/confirmation screens as applicable

**QA Scenarios:** Happy-path test with valid data. | Required-field and invalid-format validation test. | Unauthorized-role/access test. | Boundary/limit test where applicable. | Failure/timeout/provider-error test where applicable. | Duplicate/idempotency test where applicable. | Audit/event verification. | Regression test for dependent modules.

**Definition of Done:** Criteria + API/DB/events/security/QA implemented and passing

### US-ADMIN-018 — Unfreeze wallet (P0)
**Actor:** Authorized operations/admin user or system

**Acceptance Criteria:** Unfreeze wallet must satisfy the source formal specification, including validation, authorization, persistence, traceability/audit and applicable financial integrity/idempotency requirements.

**API:** `/v1/admin/unfreeze-wallet`

**DB Entities:** User, Wallet, Transaction, AdminAction

**Events:** US_ADMIN_018_REQUESTED; US_ADMIN_018_SUCCEEDED; US_ADMIN_018_FAILED (where applicable); AUDIT_EVENT_RECORDED

**Permissions:** admin.read / admin.write as applicable; maker-checker for financial changes

**UI Screens:** Admin Portal: dashboard/list/detail/action/confirmation screens as applicable

**QA Scenarios:** Happy-path test with valid data. | Required-field and invalid-format validation test. | Unauthorized-role/access test. | Boundary/limit test where applicable. | Failure/timeout/provider-error test where applicable. | Duplicate/idempotency test where applicable. | Audit/event verification. | Regression test for dependent modules.

**Definition of Done:** Criteria + API/DB/events/security/QA implemented and passing

### US-ADMIN-019 — View wallet ledger (P0)
**Actor:** Authorized operations/admin user or system

**Acceptance Criteria:** View wallet ledger must satisfy the source formal specification, including validation, authorization, persistence, traceability/audit and applicable financial integrity/idempotency requirements.

**API:** `/v1/admin/view-wallet-ledger`

**DB Entities:** User, Wallet, Transaction, AdminAction

**Events:** US_ADMIN_019_REQUESTED; US_ADMIN_019_SUCCEEDED; US_ADMIN_019_FAILED (where applicable); AUDIT_EVENT_RECORDED

**Permissions:** admin.read / admin.write as applicable; maker-checker for financial changes

**UI Screens:** Admin Portal: dashboard/list/detail/action/confirmation screens as applicable

**QA Scenarios:** Happy-path test with valid data. | Required-field and invalid-format validation test. | Unauthorized-role/access test. | Boundary/limit test where applicable. | Failure/timeout/provider-error test where applicable. | Duplicate/idempotency test where applicable. | Audit/event verification. | Regression test for dependent modules.

**Definition of Done:** Criteria + API/DB/events/security/QA implemented and passing

### US-ADMIN-020 — Perform controlled wallet adjustment (P0)
**Actor:** Authorized operations/admin user or system

**Acceptance Criteria:** Perform controlled wallet adjustment must satisfy the source formal specification, including validation, authorization, persistence, traceability/audit and applicable financial integrity/idempotency requirements.

**API:** `/v1/admin/perform-controlled-wallet-adjustment`

**DB Entities:** User, Wallet, Transaction, AdminAction

**Events:** US_ADMIN_020_REQUESTED; US_ADMIN_020_SUCCEEDED; US_ADMIN_020_FAILED (where applicable); AUDIT_EVENT_RECORDED

**Permissions:** admin.read / admin.write as applicable; maker-checker for financial changes

**UI Screens:** Admin Portal: dashboard/list/detail/action/confirmation screens as applicable

**QA Scenarios:** Happy-path test with valid data. | Required-field and invalid-format validation test. | Unauthorized-role/access test. | Boundary/limit test where applicable. | Failure/timeout/provider-error test where applicable. | Duplicate/idempotency test where applicable. | Audit/event verification. | Regression test for dependent modules.

**Definition of Done:** Criteria + API/DB/events/security/QA implemented and passing

### US-ADMIN-021 — Require maker-checker approval for adjustment (P0)
**Actor:** Authorized operations/admin user or system

**Acceptance Criteria:** Require maker-checker approval for adjustment must satisfy the source formal specification, including validation, authorization, persistence, traceability/audit and applicable financial integrity/idempotency requirements.

**API:** `/v1/admin/require-maker-checker-approval-for-adjustment`

**DB Entities:** User, Wallet, Transaction, AdminAction

**Events:** US_ADMIN_021_REQUESTED; US_ADMIN_021_SUCCEEDED; US_ADMIN_021_FAILED (where applicable); AUDIT_EVENT_RECORDED

**Permissions:** admin.read / admin.write as applicable; maker-checker for financial changes

**UI Screens:** Admin Portal: dashboard/list/detail/action/confirmation screens as applicable

**QA Scenarios:** Happy-path test with valid data. | Required-field and invalid-format validation test. | Unauthorized-role/access test. | Boundary/limit test where applicable. | Failure/timeout/provider-error test where applicable. | Duplicate/idempotency test where applicable. | Audit/event verification. | Regression test for dependent modules.

**Definition of Done:** Criteria + API/DB/events/security/QA implemented and passing

### US-ADMIN-022 — Search transactions (P0)
**Actor:** Authorized operations/admin user or system

**Acceptance Criteria:** Search transactions must satisfy the source formal specification, including validation, authorization, persistence, traceability/audit and applicable financial integrity/idempotency requirements.

**API:** `/v1/admin/search-transactions`

**DB Entities:** User, Wallet, Transaction, AdminAction

**Events:** US_ADMIN_022_REQUESTED; US_ADMIN_022_SUCCEEDED; US_ADMIN_022_FAILED (where applicable); AUDIT_EVENT_RECORDED

**Permissions:** admin.read / admin.write as applicable; maker-checker for financial changes

**UI Screens:** Admin Portal: dashboard/list/detail/action/confirmation screens as applicable

**QA Scenarios:** Happy-path test with valid data. | Required-field and invalid-format validation test. | Unauthorized-role/access test. | Boundary/limit test where applicable. | Failure/timeout/provider-error test where applicable. | Duplicate/idempotency test where applicable. | Audit/event verification. | Regression test for dependent modules.

**Definition of Done:** Criteria + API/DB/events/security/QA implemented and passing

### US-ADMIN-023 — View transaction (P0)
**Actor:** Authorized operations/admin user or system

**Acceptance Criteria:** View transaction must satisfy the source formal specification, including validation, authorization, persistence, traceability/audit and applicable financial integrity/idempotency requirements.

**API:** `/v1/admin/view-transaction`

**DB Entities:** User, Wallet, Transaction, AdminAction

**Events:** US_ADMIN_023_REQUESTED; US_ADMIN_023_SUCCEEDED; US_ADMIN_023_FAILED (where applicable); AUDIT_EVENT_RECORDED

**Permissions:** admin.read / admin.write as applicable; maker-checker for financial changes

**UI Screens:** Admin Portal: dashboard/list/detail/action/confirmation screens as applicable

**QA Scenarios:** Happy-path test with valid data. | Required-field and invalid-format validation test. | Unauthorized-role/access test. | Boundary/limit test where applicable. | Failure/timeout/provider-error test where applicable. | Duplicate/idempotency test where applicable. | Audit/event verification. | Regression test for dependent modules.

**Definition of Done:** Criteria + API/DB/events/security/QA implemented and passing

### US-ADMIN-024 — Investigate failed transaction (P0)
**Actor:** Authorized operations/admin user or system

**Acceptance Criteria:** Investigate failed transaction must satisfy the source formal specification, including validation, authorization, persistence, traceability/audit and applicable financial integrity/idempotency requirements.

**API:** `/v1/admin/investigate-failed-transaction`

**DB Entities:** User, Wallet, Transaction, AdminAction

**Events:** US_ADMIN_024_REQUESTED; US_ADMIN_024_SUCCEEDED; US_ADMIN_024_FAILED (where applicable); AUDIT_EVENT_RECORDED

**Permissions:** admin.read / admin.write as applicable; maker-checker for financial changes

**UI Screens:** Admin Portal: dashboard/list/detail/action/confirmation screens as applicable

**QA Scenarios:** Happy-path test with valid data. | Required-field and invalid-format validation test. | Unauthorized-role/access test. | Boundary/limit test where applicable. | Failure/timeout/provider-error test where applicable. | Duplicate/idempotency test where applicable. | Audit/event verification. | Regression test for dependent modules.

**Definition of Done:** Criteria + API/DB/events/security/QA implemented and passing

### US-ADMIN-025 — Reverse transaction (P0)
**Actor:** Authorized operations/admin user or system

**Acceptance Criteria:** Reverse transaction must satisfy the source formal specification, including validation, authorization, persistence, traceability/audit and applicable financial integrity/idempotency requirements.

**API:** `/v1/admin/reverse-transaction`

**DB Entities:** User, Wallet, Transaction, AdminAction

**Events:** US_ADMIN_025_REQUESTED; US_ADMIN_025_SUCCEEDED; US_ADMIN_025_FAILED (where applicable); AUDIT_EVENT_RECORDED

**Permissions:** admin.read / admin.write as applicable; maker-checker for financial changes

**UI Screens:** Admin Portal: dashboard/list/detail/action/confirmation screens as applicable

**QA Scenarios:** Happy-path test with valid data. | Required-field and invalid-format validation test. | Unauthorized-role/access test. | Boundary/limit test where applicable. | Failure/timeout/provider-error test where applicable. | Duplicate/idempotency test where applicable. | Audit/event verification. | Regression test for dependent modules.

**Definition of Done:** Criteria + API/DB/events/security/QA implemented and passing

### US-ADMIN-026 — Refund transaction (P1)
**Actor:** Authorized operations/admin user or system

**Acceptance Criteria:** Refund transaction must satisfy the source formal specification, including validation, authorization, persistence, traceability/audit and applicable financial integrity/idempotency requirements.

**API:** `/v1/admin/refund-transaction`

**DB Entities:** User, Wallet, Transaction, AdminAction

**Events:** US_ADMIN_026_REQUESTED; US_ADMIN_026_SUCCEEDED; US_ADMIN_026_FAILED (where applicable); AUDIT_EVENT_RECORDED

**Permissions:** admin.read / admin.write as applicable; maker-checker for financial changes

**UI Screens:** Admin Portal: dashboard/list/detail/action/confirmation screens as applicable

**QA Scenarios:** Happy-path test with valid data. | Required-field and invalid-format validation test. | Unauthorized-role/access test. | Boundary/limit test where applicable. | Failure/timeout/provider-error test where applicable. | Duplicate/idempotency test where applicable. | Audit/event verification. | Regression test for dependent modules.

**Definition of Done:** Criteria + API/DB/events/security/QA implemented and passing

### US-ADMIN-027 — Export transactions (P1)
**Actor:** Authorized operations/admin user or system

**Acceptance Criteria:** Export transactions must satisfy the source formal specification, including validation, authorization, persistence, traceability/audit and applicable financial integrity/idempotency requirements.

**API:** `/v1/admin/export-transactions`

**DB Entities:** User, Wallet, Transaction, AdminAction

**Events:** US_ADMIN_027_REQUESTED; US_ADMIN_027_SUCCEEDED; US_ADMIN_027_FAILED (where applicable); AUDIT_EVENT_RECORDED

**Permissions:** admin.read / admin.write as applicable; maker-checker for financial changes

**UI Screens:** Admin Portal: dashboard/list/detail/action/confirmation screens as applicable

**QA Scenarios:** Happy-path test with valid data. | Required-field and invalid-format validation test. | Unauthorized-role/access test. | Boundary/limit test where applicable. | Failure/timeout/provider-error test where applicable. | Duplicate/idempotency test where applicable. | Audit/event verification. | Regression test for dependent modules.

**Definition of Done:** Criteria + API/DB/events/security/QA implemented and passing


## Epic-level Definition of Done
- All stories in this epic meet their story-specific Definition of Done.
- Cross-story dependencies are tested.
- API contracts and migrations are reviewed.
- Security and authorization checks pass.
- UI states and accessibility requirements pass where applicable.
- No unresolved Critical/High defect blocks completion.
