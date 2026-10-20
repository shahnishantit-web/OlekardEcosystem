# E20 — Reconciliation & Settlement

## Intent
This feature specification decomposes Epic E20 from the approved 538-story backlog into an independently executable Spec-Kit feature.

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

## Epic Stories

### US-RECON-001 — Import provider transaction file (P0)
**Actor:** Authorized operations/admin user or system

**Acceptance Criteria:** Import provider transaction file must satisfy the source formal specification, including validation, authorization, persistence, traceability/audit and applicable financial integrity/idempotency requirements.

**API:** `POST /v1/reconciliation/batches/file`

**DB Entities:** ReconciliationBatch, ReconciliationItem, ReconciliationException

**Events:** US_RECON_001_REQUESTED; US_RECON_001_SUCCEEDED; US_RECON_001_FAILED (where applicable); AUDIT_EVENT_RECORDED

**Permissions:** Authenticated actor + feature-specific permission

**UI Screens:** Admin Portal: dashboard/list/detail/action/confirmation screens as applicable

**QA Scenarios:** Happy-path test with valid data. | Required-field and invalid-format validation test. | Unauthorized-role/access test. | Boundary/limit test where applicable. | Failure/timeout/provider-error test where applicable. | Duplicate/idempotency test where applicable. | Audit/event verification. | Regression test for dependent modules.

**Definition of Done:** Criteria + API/DB/events/security/QA implemented and passing

### US-RECON-002 — Receive provider transaction API (P0)
**Actor:** Authorized operations/admin user or system

**Acceptance Criteria:** Receive provider transaction API must satisfy the source formal specification, including validation, authorization, persistence, traceability/audit and applicable financial integrity/idempotency requirements.

**API:** `POST /v1/reconciliation/batches/api` (HMAC webhook auth — `X-Provider-Signature` header; exempt from `identity.auth`)

**DB Entities:** ReconciliationBatch, ReconciliationItem, ReconciliationException

**Events:** US_RECON_002_REQUESTED; US_RECON_002_SUCCEEDED; US_RECON_002_FAILED (where applicable); AUDIT_EVENT_RECORDED

**Permissions:** Authenticated actor + feature-specific permission

**UI Screens:** Admin Portal: dashboard/list/detail/action/confirmation screens as applicable

**QA Scenarios:** Happy-path test with valid data. | Required-field and invalid-format validation test. | Unauthorized-role/access test. | Boundary/limit test where applicable. | Failure/timeout/provider-error test where applicable. | Duplicate/idempotency test where applicable. | Audit/event verification. | Regression test for dependent modules.

**Definition of Done:** Criteria + API/DB/events/security/QA implemented and passing

### US-RECON-003 — Match provider transaction (P0)
**Actor:** Authorized operations/admin user or system

**Acceptance Criteria:** Match provider transaction must satisfy the source formal specification, including validation, authorization, persistence, traceability/audit and applicable financial integrity/idempotency requirements.

**API:** `POST /v1/reconciliation/batches/{id}/match`

**DB Entities:** ReconciliationBatch, ReconciliationItem, ReconciliationException

**Events:** US_RECON_003_REQUESTED; US_RECON_003_SUCCEEDED; US_RECON_003_FAILED (where applicable); AUDIT_EVENT_RECORDED

**Permissions:** Authenticated actor + feature-specific permission

**UI Screens:** Admin Portal: dashboard/list/detail/action/confirmation screens as applicable

**QA Scenarios:** Happy-path test with valid data. | Required-field and invalid-format validation test. | Unauthorized-role/access test. | Boundary/limit test where applicable. | Failure/timeout/provider-error test where applicable. | Duplicate/idempotency test where applicable. | Audit/event verification. | Regression test for dependent modules.

**Definition of Done:** Criteria + API/DB/events/security/QA implemented and passing

### US-RECON-004 — Mark transaction reconciled (P0)
**Actor:** Authorized operations/admin user or system

**Acceptance Criteria:** Mark transaction reconciled must satisfy the source formal specification, including validation, authorization, persistence, traceability/audit and applicable financial integrity/idempotency requirements.

**API:** `POST /v1/reconciliation/items/{id}/mark-reconciled`

**DB Entities:** ReconciliationBatch, ReconciliationItem, ReconciliationException

**Events:** US_RECON_004_REQUESTED; US_RECON_004_SUCCEEDED; US_RECON_004_FAILED (where applicable); AUDIT_EVENT_RECORDED

**Permissions:** Authenticated actor + feature-specific permission

**UI Screens:** Admin Portal: dashboard/list/detail/action/confirmation screens as applicable

**QA Scenarios:** Happy-path test with valid data. | Required-field and invalid-format validation test. | Unauthorized-role/access test. | Boundary/limit test where applicable. | Failure/timeout/provider-error test where applicable. | Duplicate/idempotency test where applicable. | Audit/event verification. | Regression test for dependent modules.

**Definition of Done:** Criteria + API/DB/events/security/QA implemented and passing

### US-RECON-005 — Detect unmatched transaction (P0)
**Actor:** Authorized operations/admin user or system

**Acceptance Criteria:** Detect unmatched transaction must satisfy the source formal specification, including validation, authorization, persistence, traceability/audit and applicable financial integrity/idempotency requirements.

**API:** `GET /v1/reconciliation/batches/{id}/unmatched`

**DB Entities:** ReconciliationBatch, ReconciliationItem, ReconciliationException

**Events:** US_RECON_005_REQUESTED; US_RECON_005_SUCCEEDED; US_RECON_005_FAILED (where applicable); AUDIT_EVENT_RECORDED

**Permissions:** Authenticated actor + feature-specific permission

**UI Screens:** Admin Portal: dashboard/list/detail/action/confirmation screens as applicable

**QA Scenarios:** Happy-path test with valid data. | Required-field and invalid-format validation test. | Unauthorized-role/access test. | Boundary/limit test where applicable. | Failure/timeout/provider-error test where applicable. | Duplicate/idempotency test where applicable. | Audit/event verification. | Regression test for dependent modules.

**Definition of Done:** Criteria + API/DB/events/security/QA implemented and passing

### US-RECON-006 — Create reconciliation exception (P0)
**Actor:** Authorized operations/admin user or system

**Acceptance Criteria:** Create reconciliation exception must satisfy the source formal specification, including validation, authorization, persistence, traceability/audit and applicable financial integrity/idempotency requirements.

**API:** System-internal — auto-created by `ProcessReconciliationBatchJob`; view via `GET /v1/reconciliation/exceptions`

**DB Entities:** ReconciliationBatch, ReconciliationItem, ReconciliationException

**Events:** US_RECON_006_REQUESTED; US_RECON_006_SUCCEEDED; US_RECON_006_FAILED (where applicable); AUDIT_EVENT_RECORDED

**Permissions:** Authenticated actor + feature-specific permission

**UI Screens:** Admin Portal: dashboard/list/detail/action/confirmation screens as applicable

**QA Scenarios:** Happy-path test with valid data. | Required-field and invalid-format validation test. | Unauthorized-role/access test. | Boundary/limit test where applicable. | Failure/timeout/provider-error test where applicable. | Duplicate/idempotency test where applicable. | Audit/event verification. | Regression test for dependent modules.

**Definition of Done:** Criteria + API/DB/events/security/QA implemented and passing

### US-RECON-007 — Resolve reconciliation exception (P0)
**Actor:** Authorized operations/admin user or system

**Acceptance Criteria:** Resolve reconciliation exception must satisfy the source formal specification, including validation, authorization, persistence, traceability/audit and applicable financial integrity/idempotency requirements.

**API:** `POST /v1/reconciliation/exceptions/{id}/resolve`

**DB Entities:** ReconciliationBatch, ReconciliationItem, ReconciliationException

**Events:** US_RECON_007_REQUESTED; US_RECON_007_SUCCEEDED; US_RECON_007_FAILED (where applicable); AUDIT_EVENT_RECORDED

**Permissions:** Authenticated actor + feature-specific permission

**UI Screens:** Admin Portal: dashboard/list/detail/action/confirmation screens as applicable

**QA Scenarios:** Happy-path test with valid data. | Required-field and invalid-format validation test. | Unauthorized-role/access test. | Boundary/limit test where applicable. | Failure/timeout/provider-error test where applicable. | Duplicate/idempotency test where applicable. | Audit/event verification. | Regression test for dependent modules.

**Definition of Done:** Criteria + API/DB/events/security/QA implemented and passing

### US-RECON-008 — Import bank statement (P1)
**Actor:** Authorized operations/admin user or system

**Acceptance Criteria:** Import bank statement must satisfy the source formal specification, including validation, authorization, persistence, traceability/audit and applicable financial integrity/idempotency requirements.

**API:** `POST /v1/reconciliation/batches/bank-statement`

**DB Entities:** ReconciliationBatch, ReconciliationItem, Exception, SettlementBatch

**Events:** US_RECON_008_REQUESTED; US_RECON_008_SUCCEEDED; US_RECON_008_FAILED (where applicable); AUDIT_EVENT_RECORDED

**Permissions:** Authenticated actor + feature-specific permission

**UI Screens:** Admin Portal: dashboard/list/detail/action/confirmation screens as applicable

**QA Scenarios:** Happy-path test with valid data. | Required-field and invalid-format validation test. | Unauthorized-role/access test. | Boundary/limit test where applicable. | Failure/timeout/provider-error test where applicable. | Duplicate/idempotency test where applicable. | Audit/event verification. | Regression test for dependent modules.

**Definition of Done:** Criteria + API/DB/events/security/QA implemented and passing

### US-RECON-009 — Match bank transaction (P1)
**Actor:** Authorized operations/admin user or system

**Acceptance Criteria:** Match bank transaction must satisfy the source formal specification, including validation, authorization, persistence, traceability/audit and applicable financial integrity/idempotency requirements.

**API:** `POST /v1/reconciliation/batches/{id}/match` (same endpoint as US-RECON-003; processes `item_type=bank_transaction` items)

**DB Entities:** ReconciliationBatch, ReconciliationItem, Exception, SettlementBatch

**Events:** US_RECON_009_REQUESTED; US_RECON_009_SUCCEEDED; US_RECON_009_FAILED (where applicable); AUDIT_EVENT_RECORDED

**Permissions:** Authenticated actor + feature-specific permission

**UI Screens:** Admin Portal: dashboard/list/detail/action/confirmation screens as applicable

**QA Scenarios:** Happy-path test with valid data. | Required-field and invalid-format validation test. | Unauthorized-role/access test. | Boundary/limit test where applicable. | Failure/timeout/provider-error test where applicable. | Duplicate/idempotency test where applicable. | Audit/event verification. | Regression test for dependent modules.

**Definition of Done:** Criteria + API/DB/events/security/QA implemented and passing

### US-RECON-010 — Identify unmatched deposit (P1)
**Actor:** Authorized operations/admin user or system

**Acceptance Criteria:** Identify unmatched deposit must satisfy the source formal specification, including validation, authorization, persistence, traceability/audit and applicable financial integrity/idempotency requirements.

**API:** `GET /v1/reconciliation/batches/{id}/unmatched?item_type=bank_transaction` (positive `amount` items)

**DB Entities:** ReconciliationBatch, ReconciliationItem, Exception, SettlementBatch

**Events:** US_RECON_010_REQUESTED; US_RECON_010_SUCCEEDED; US_RECON_010_FAILED (where applicable); AUDIT_EVENT_RECORDED

**Permissions:** Authenticated actor + feature-specific permission

**UI Screens:** Admin Portal: dashboard/list/detail/action/confirmation screens as applicable

**QA Scenarios:** Happy-path test with valid data. | Required-field and invalid-format validation test. | Unauthorized-role/access test. | Boundary/limit test where applicable. | Failure/timeout/provider-error test where applicable. | Duplicate/idempotency test where applicable. | Audit/event verification. | Regression test for dependent modules.

**Definition of Done:** Criteria + API/DB/events/security/QA implemented and passing

### US-RECON-011 — Identify unmatched withdrawal (P1)
**Actor:** Authorized operations/admin user or system

**Acceptance Criteria:** Identify unmatched withdrawal must satisfy the source formal specification, including validation, authorization, persistence, traceability/audit and applicable financial integrity/idempotency requirements.

**API:** `GET /v1/reconciliation/batches/{id}/unmatched?item_type=bank_transaction` (negative `amount` items)

**DB Entities:** ReconciliationBatch, ReconciliationItem, Exception, SettlementBatch

**Events:** US_RECON_011_REQUESTED; US_RECON_011_SUCCEEDED; US_RECON_011_FAILED (where applicable); AUDIT_EVENT_RECORDED

**Permissions:** Authenticated actor + feature-specific permission

**UI Screens:** Admin Portal: dashboard/list/detail/action/confirmation screens as applicable

**QA Scenarios:** Happy-path test with valid data. | Required-field and invalid-format validation test. | Unauthorized-role/access test. | Boundary/limit test where applicable. | Failure/timeout/provider-error test where applicable. | Duplicate/idempotency test where applicable. | Audit/event verification. | Regression test for dependent modules.

**Definition of Done:** Criteria + API/DB/events/security/QA implemented and passing

### US-RECON-012 — Calculate settlement amount (P1)
**Actor:** Authorized operations/admin user or system

**Acceptance Criteria:** Calculate settlement amount must satisfy the source formal specification, including validation, authorization, persistence, traceability/audit and applicable financial integrity/idempotency requirements.

**API:** `POST /v1/reconciliation/settlements` (merged with US-RECON-013 — calculate and generate batch atomically)

**DB Entities:** ReconciliationBatch, ReconciliationItem, Exception, SettlementBatch

**Events:** US_RECON_012_REQUESTED; US_RECON_012_SUCCEEDED; US_RECON_012_FAILED (where applicable); AUDIT_EVENT_RECORDED

**Permissions:** Authenticated actor + feature-specific permission

**UI Screens:** Admin Portal: dashboard/list/detail/action/confirmation screens as applicable

**QA Scenarios:** Happy-path test with valid data. | Required-field and invalid-format validation test. | Unauthorized-role/access test. | Boundary/limit test where applicable. | Failure/timeout/provider-error test where applicable. | Duplicate/idempotency test where applicable. | Audit/event verification. | Regression test for dependent modules.

**Definition of Done:** Criteria + API/DB/events/security/QA implemented and passing

### US-RECON-013 — Generate settlement batch (P1)
**Actor:** Authorized operations/admin user or system

**Acceptance Criteria:** Generate settlement batch must satisfy the source formal specification, including validation, authorization, persistence, traceability/audit and applicable financial integrity/idempotency requirements.

**API:** `POST /v1/reconciliation/settlements` (merged with US-RECON-012 — calculate and generate batch atomically)

**DB Entities:** ReconciliationBatch, ReconciliationItem, Exception, SettlementBatch

**Events:** US_RECON_013_REQUESTED; US_RECON_013_SUCCEEDED; US_RECON_013_FAILED (where applicable); AUDIT_EVENT_RECORDED

**Permissions:** Authenticated actor + feature-specific permission

**UI Screens:** Admin Portal: dashboard/list/detail/action/confirmation screens as applicable

**QA Scenarios:** Happy-path test with valid data. | Required-field and invalid-format validation test. | Unauthorized-role/access test. | Boundary/limit test where applicable. | Failure/timeout/provider-error test where applicable. | Duplicate/idempotency test where applicable. | Audit/event verification. | Regression test for dependent modules.

**Definition of Done:** Criteria + API/DB/events/security/QA implemented and passing

### US-RECON-014 — Approve settlement (P1)
**Actor:** Authorized operations/admin user or system

**Acceptance Criteria:** Approve settlement must satisfy the source formal specification, including validation, authorization, persistence, traceability/audit and applicable financial integrity/idempotency requirements.

**API:** `POST /v1/reconciliation/settlements/{id}/approve` (maker-checker — checker must differ from maker)

**DB Entities:** ReconciliationBatch, ReconciliationItem, Exception, SettlementBatch

**Events:** US_RECON_014_REQUESTED; US_RECON_014_SUCCEEDED; US_RECON_014_FAILED (where applicable); AUDIT_EVENT_RECORDED

**Permissions:** Privileged role + explicit action permission + maker-checker where configured

**UI Screens:** Admin Portal: dashboard/list/detail/action/confirmation screens as applicable

**QA Scenarios:** Happy-path test with valid data. | Required-field and invalid-format validation test. | Unauthorized-role/access test. | Boundary/limit test where applicable. | Failure/timeout/provider-error test where applicable. | Duplicate/idempotency test where applicable. | Audit/event verification. | Regression test for dependent modules.

**Definition of Done:** Criteria + API/DB/events/security/QA implemented and passing

### US-RECON-015 — Execute settlement (P1)
**Actor:** Authorized operations/admin user or system

**Acceptance Criteria:** Execute settlement must satisfy the source formal specification, including validation, authorization, persistence, traceability/audit and applicable financial integrity/idempotency requirements.

**API:** `POST /v1/reconciliation/settlements/{id}/execute`

**DB Entities:** ReconciliationBatch, ReconciliationItem, Exception, SettlementBatch

**Events:** US_RECON_015_REQUESTED; US_RECON_015_SUCCEEDED; US_RECON_015_FAILED (where applicable); AUDIT_EVENT_RECORDED

**Permissions:** Authenticated actor + feature-specific permission

**UI Screens:** Admin Portal: dashboard/list/detail/action/confirmation screens as applicable

**QA Scenarios:** Happy-path test with valid data. | Required-field and invalid-format validation test. | Unauthorized-role/access test. | Boundary/limit test where applicable. | Failure/timeout/provider-error test where applicable. | Duplicate/idempotency test where applicable. | Audit/event verification. | Regression test for dependent modules.

**Definition of Done:** Criteria + API/DB/events/security/QA implemented and passing

### US-RECON-016 — Record settlement ledger (P1)
**Actor:** Authorized operations/admin user or system

**Acceptance Criteria:** Record settlement ledger must satisfy the source formal specification, including validation, authorization, persistence, traceability/audit and applicable financial integrity/idempotency requirements.

**API:** Internal — executed by `ExecuteSettlementJob` via `E07 LedgerModuleContract::postEntry(entry_type='settlement')`; no HTTP endpoint exposed

**DB Entities:** ReconciliationBatch, ReconciliationItem, Exception, SettlementBatch

**Events:** US_RECON_016_REQUESTED; US_RECON_016_SUCCEEDED; US_RECON_016_FAILED (where applicable); AUDIT_EVENT_RECORDED

**Permissions:** Authenticated actor + feature-specific permission

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
