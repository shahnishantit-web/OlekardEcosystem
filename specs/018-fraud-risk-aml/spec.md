# E18 — Fraud, Risk & AML

## Intent
This feature specification decomposes Epic E18 from the approved 538-story backlog into an independently executable Spec-Kit feature.

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
- FIA portal integration is deferred to E28/E1000; `FiaReportingStub` is in place for E18 scope.

## Epic Stories

> **Note:** The "DB Entities" field in each story below lists the four canonical entities from the original backlog spec. The implementation introduced six additional entities (RiskEvaluation, RiskAlertEvent, WatchlistEntry, WatchlistMatch, AccountHold, SuspiciousActivityReport) — see the **Entity Reconciliation Note (F1)** at the end of this document for the complete list and purpose of each.

### US-RISK-001 — Evaluate transaction risk (P0)
**Actor:** Risk engine / authorized risk officer

**Acceptance Criteria:** Evaluate transaction risk must satisfy the source formal specification, including validation, authorization, persistence, traceability/audit and applicable financial integrity/idempotency requirements.

**API:** `/v1/risk/evaluate-transaction-risk`

**DB Entities:** RiskRule, RiskEvent, RiskAlert, RiskDecision

**Events:** US_RISK_001_REQUESTED; US_RISK_001_SUCCEEDED; US_RISK_001_FAILED (where applicable); AUDIT_EVENT_RECORDED

**Permissions:** Authenticated actor + feature-specific permission

**UI Screens:** No dedicated UI if service-only; otherwise consuming application screen + loading, success and error states

**QA Scenarios:** Happy-path test with valid data. | Required-field and invalid-format validation test. | Unauthorized-role/access test. | Boundary/limit test where applicable. | Failure/timeout/provider-error test where applicable. | Duplicate/idempotency test where applicable. | Audit/event verification. | Regression test for dependent modules.

**Definition of Done:** Criteria + API/DB/events/security/QA implemented and passing

### US-RISK-002 — Evaluate transaction velocity (P0)
**Actor:** Risk engine / authorized risk officer

**Acceptance Criteria:** Evaluate transaction velocity must satisfy the source formal specification, including validation, authorization, persistence, traceability/audit and applicable financial integrity/idempotency requirements.

**API:** `/v1/risk/evaluate-transaction-velocity`

**DB Entities:** RiskRule, RiskEvent, RiskAlert, RiskDecision

**Events:** US_RISK_002_REQUESTED; US_RISK_002_SUCCEEDED; US_RISK_002_FAILED (where applicable); AUDIT_EVENT_RECORDED

**Permissions:** Authenticated actor + feature-specific permission

**UI Screens:** No dedicated UI if service-only; otherwise consuming application screen + loading, success and error states

**QA Scenarios:** Happy-path test with valid data. | Required-field and invalid-format validation test. | Unauthorized-role/access test. | Boundary/limit test where applicable. | Failure/timeout/provider-error test where applicable. | Duplicate/idempotency test where applicable. | Audit/event verification. | Regression test for dependent modules.

**Definition of Done:** Criteria + API/DB/events/security/QA implemented and passing

### US-RISK-003 — Evaluate amount threshold (P0)
**Actor:** Risk engine / authorized risk officer

**Acceptance Criteria:** Evaluate amount threshold must satisfy the source formal specification, including validation, authorization, persistence, traceability/audit and applicable financial integrity/idempotency requirements.

**API:** `/v1/risk/evaluate-amount-threshold`

**DB Entities:** RiskRule, RiskEvent, RiskAlert, RiskDecision

**Events:** US_RISK_003_REQUESTED; US_RISK_003_SUCCEEDED; US_RISK_003_FAILED (where applicable); AUDIT_EVENT_RECORDED

**Permissions:** Authenticated actor + feature-specific permission

**UI Screens:** No dedicated UI if service-only; otherwise consuming application screen + loading, success and error states

**QA Scenarios:** Happy-path test with valid data. | Required-field and invalid-format validation test. | Unauthorized-role/access test. | Boundary/limit test where applicable. | Failure/timeout/provider-error test where applicable. | Duplicate/idempotency test where applicable. | Audit/event verification. | Regression test for dependent modules.

**Definition of Done:** Criteria + API/DB/events/security/QA implemented and passing

### US-RISK-004 — Evaluate device risk (P0)
**Actor:** Risk engine / authorized risk officer

**Acceptance Criteria:** Evaluate device risk must satisfy the source formal specification, including validation, authorization, persistence, traceability/audit and applicable financial integrity/idempotency requirements.

**API:** `/v1/risk/evaluate-device-risk`

**DB Entities:** RiskRule, RiskEvent, RiskAlert, RiskDecision

**Events:** US_RISK_004_REQUESTED; US_RISK_004_SUCCEEDED; US_RISK_004_FAILED (where applicable); AUDIT_EVENT_RECORDED

**Permissions:** Authenticated actor + feature-specific permission

**UI Screens:** No dedicated UI if service-only; otherwise consuming application screen + loading, success and error states

**QA Scenarios:** Happy-path test with valid data. | Required-field and invalid-format validation test. | Unauthorized-role/access test. | Boundary/limit test where applicable. | Failure/timeout/provider-error test where applicable. | Duplicate/idempotency test where applicable. | Audit/event verification. | Regression test for dependent modules.

**Definition of Done:** Criteria + API/DB/events/security/QA implemented and passing

### US-RISK-005 — Evaluate IP risk (P1)
**Actor:** Risk engine / authorized risk officer

**Acceptance Criteria:** Evaluate IP risk must satisfy the source formal specification, including validation, authorization, persistence, traceability/audit and applicable financial integrity/idempotency requirements.

**API:** `/v1/risk/evaluate-ip-risk`

**DB Entities:** RiskRule, RiskEvent, RiskAlert, RiskDecision

**Events:** US_RISK_005_REQUESTED; US_RISK_005_SUCCEEDED; US_RISK_005_FAILED (where applicable); AUDIT_EVENT_RECORDED

**Permissions:** Authenticated actor + feature-specific permission

**UI Screens:** No dedicated UI if service-only; otherwise consuming application screen + loading, success and error states

**QA Scenarios:** Happy-path test with valid data. | Required-field and invalid-format validation test. | Unauthorized-role/access test. | Boundary/limit test where applicable. | Failure/timeout/provider-error test where applicable. | Duplicate/idempotency test where applicable. | Audit/event verification. | Regression test for dependent modules.

**Definition of Done:** Criteria + API/DB/events/security/QA implemented and passing

### US-RISK-006 — Evaluate beneficiary risk (P1)
**Actor:** Risk engine / authorized risk officer

**Acceptance Criteria:** Evaluate beneficiary risk must satisfy the source formal specification, including validation, authorization, persistence, traceability/audit and applicable financial integrity/idempotency requirements.

**API:** `/v1/risk/evaluate-beneficiary-risk`

**DB Entities:** RiskRule, RiskEvent, RiskAlert, RiskDecision

**Events:** US_RISK_006_REQUESTED; US_RISK_006_SUCCEEDED; US_RISK_006_FAILED (where applicable); AUDIT_EVENT_RECORDED

**Permissions:** Authenticated actor + feature-specific permission

**UI Screens:** No dedicated UI if service-only; otherwise consuming application screen + loading, success and error states

**QA Scenarios:** Happy-path test with valid data. | Required-field and invalid-format validation test. | Unauthorized-role/access test. | Boundary/limit test where applicable. | Failure/timeout/provider-error test where applicable. | Duplicate/idempotency test where applicable. | Audit/event verification. | Regression test for dependent modules.

**Definition of Done:** Criteria + API/DB/events/security/QA implemented and passing

### US-RISK-007 — Allow transaction (P0)
**Actor:** Risk engine / authorized risk officer

**Acceptance Criteria:** Allow transaction must satisfy the source formal specification, including validation, authorization, persistence, traceability/audit and applicable financial integrity/idempotency requirements.

**API:** `/v1/risk/allow-transaction`

**DB Entities:** RiskRule, RiskEvent, RiskAlert, RiskDecision

**Events:** US_RISK_007_REQUESTED; US_RISK_007_SUCCEEDED; US_RISK_007_FAILED (where applicable); AUDIT_EVENT_RECORDED

**Permissions:** Authenticated actor + feature-specific permission

**UI Screens:** No dedicated UI if service-only; otherwise consuming application screen + loading, success and error states

**QA Scenarios:** Happy-path test with valid data. | Required-field and invalid-format validation test. | Unauthorized-role/access test. | Boundary/limit test where applicable. | Failure/timeout/provider-error test where applicable. | Duplicate/idempotency test where applicable. | Audit/event verification. | Regression test for dependent modules.

**Definition of Done:** Criteria + API/DB/events/security/QA implemented and passing

### US-RISK-008 — Block transaction (P0)
**Actor:** Risk engine / authorized risk officer

**Acceptance Criteria:** Block transaction must satisfy the source formal specification, including validation, authorization, persistence, traceability/audit and applicable financial integrity/idempotency requirements.

**API:** `/v1/risk/block-transaction`

**DB Entities:** RiskRule, RiskEvent, RiskAlert, RiskDecision

**Events:** US_RISK_008_REQUESTED; US_RISK_008_SUCCEEDED; US_RISK_008_FAILED (where applicable); AUDIT_EVENT_RECORDED

**Permissions:** Authenticated actor + feature-specific permission

**UI Screens:** No dedicated UI if service-only; otherwise consuming application screen + loading, success and error states

**QA Scenarios:** Happy-path test with valid data. | Required-field and invalid-format validation test. | Unauthorized-role/access test. | Boundary/limit test where applicable. | Failure/timeout/provider-error test where applicable. | Duplicate/idempotency test where applicable. | Audit/event verification. | Regression test for dependent modules.

**Definition of Done:** Criteria + API/DB/events/security/QA implemented and passing

### US-RISK-009 — Send transaction for manual review (P0)
**Actor:** Risk engine / authorized risk officer

**Acceptance Criteria:** Send transaction for manual review must satisfy the source formal specification, including validation, authorization, persistence, traceability/audit and applicable financial integrity/idempotency requirements.

**API:** `/v1/risk/send-transaction-for-manual-review`

**DB Entities:** RiskRule, RiskEvent, RiskAlert, RiskDecision

**Events:** US_RISK_009_REQUESTED; US_RISK_009_SUCCEEDED; US_RISK_009_FAILED (where applicable); AUDIT_EVENT_RECORDED

**Permissions:** Authenticated user + resource-specific read permission

**UI Screens:** No dedicated UI if service-only; otherwise consuming application screen + loading, success and error states

**QA Scenarios:** Happy-path test with valid data. | Required-field and invalid-format validation test. | Unauthorized-role/access test. | Boundary/limit test where applicable. | Failure/timeout/provider-error test where applicable. | Duplicate/idempotency test where applicable. | Audit/event verification. | Regression test for dependent modules.

**Definition of Done:** Criteria + API/DB/events/security/QA implemented and passing

### US-RISK-010 — Configure per-transaction limit (P0)
**Actor:** Risk engine / authorized risk officer

**Acceptance Criteria:** Configure per-transaction limit must satisfy the source formal specification, including validation, authorization, persistence, traceability/audit and applicable financial integrity/idempotency requirements.

**API:** `/v1/risk/configure-per-transaction-limit`

**DB Entities:** RiskRule, RiskEvent, RiskAlert, RiskDecision

**Events:** US_RISK_010_REQUESTED; US_RISK_010_SUCCEEDED; US_RISK_010_FAILED (where applicable); AUDIT_EVENT_RECORDED

**Permissions:** Authenticated actor + feature-specific permission

**UI Screens:** No dedicated UI if service-only; otherwise consuming application screen + loading, success and error states

**QA Scenarios:** Happy-path test with valid data. | Required-field and invalid-format validation test. | Unauthorized-role/access test. | Boundary/limit test where applicable. | Failure/timeout/provider-error test where applicable. | Duplicate/idempotency test where applicable. | Audit/event verification. | Regression test for dependent modules.

**Definition of Done:** Criteria + API/DB/events/security/QA implemented and passing

### US-RISK-011 — Configure daily limit (P0)
**Actor:** Risk engine / authorized risk officer

**Acceptance Criteria:** Configure daily limit must satisfy the source formal specification, including validation, authorization, persistence, traceability/audit and applicable financial integrity/idempotency requirements.

**API:** `/v1/risk/configure-daily-limit`

**DB Entities:** RiskRule, RiskEvent, RiskAlert, RiskDecision

**Events:** US_RISK_011_REQUESTED; US_RISK_011_SUCCEEDED; US_RISK_011_FAILED (where applicable); AUDIT_EVENT_RECORDED

**Permissions:** Authenticated actor + feature-specific permission

**UI Screens:** No dedicated UI if service-only; otherwise consuming application screen + loading, success and error states

**QA Scenarios:** Happy-path test with valid data. | Required-field and invalid-format validation test. | Unauthorized-role/access test. | Boundary/limit test where applicable. | Failure/timeout/provider-error test where applicable. | Duplicate/idempotency test where applicable. | Audit/event verification. | Regression test for dependent modules.

**Definition of Done:** Criteria + API/DB/events/security/QA implemented and passing

### US-RISK-012 — Configure monthly limit (P0)
**Actor:** Risk engine / authorized risk officer

**Acceptance Criteria:** Configure monthly limit must satisfy the source formal specification, including validation, authorization, persistence, traceability/audit and applicable financial integrity/idempotency requirements.

**API:** `/v1/risk/configure-monthly-limit`

**DB Entities:** RiskRule, RiskEvent, RiskAlert, RiskDecision

**Events:** US_RISK_012_REQUESTED; US_RISK_012_SUCCEEDED; US_RISK_012_FAILED (where applicable); AUDIT_EVENT_RECORDED

**Permissions:** Authenticated actor + feature-specific permission

**UI Screens:** No dedicated UI if service-only; otherwise consuming application screen + loading, success and error states

**QA Scenarios:** Happy-path test with valid data. | Required-field and invalid-format validation test. | Unauthorized-role/access test. | Boundary/limit test where applicable. | Failure/timeout/provider-error test where applicable. | Duplicate/idempotency test where applicable. | Audit/event verification. | Regression test for dependent modules.

**Definition of Done:** Criteria + API/DB/events/security/QA implemented and passing

### US-RISK-013 — Configure customer limits (P0)
**Actor:** Risk engine / authorized risk officer

**Acceptance Criteria:** Configure customer limits must satisfy the source formal specification, including validation, authorization, persistence, traceability/audit and applicable financial integrity/idempotency requirements.

**API:** `/v1/risk/configure-customer-limits`

**DB Entities:** RiskRule, RiskEvent, RiskAlert, RiskDecision

**Events:** US_RISK_013_REQUESTED; US_RISK_013_SUCCEEDED; US_RISK_013_FAILED (where applicable); AUDIT_EVENT_RECORDED

**Permissions:** Authenticated actor + feature-specific permission

**UI Screens:** No dedicated UI if service-only; otherwise consuming application screen + loading, success and error states

**QA Scenarios:** Happy-path test with valid data. | Required-field and invalid-format validation test. | Unauthorized-role/access test. | Boundary/limit test where applicable. | Failure/timeout/provider-error test where applicable. | Duplicate/idempotency test where applicable. | Audit/event verification. | Regression test for dependent modules.

**Definition of Done:** Criteria + API/DB/events/security/QA implemented and passing

### US-RISK-014 — Configure agent limits (P0)
**Actor:** Risk engine / authorized risk officer

**Acceptance Criteria:** Configure agent limits must satisfy the source formal specification, including validation, authorization, persistence, traceability/audit and applicable financial integrity/idempotency requirements.

**API:** `/v1/risk/configure-agent-limits`

**DB Entities:** RiskRule, RiskEvent, RiskAlert, RiskDecision

**Events:** US_RISK_014_REQUESTED; US_RISK_014_SUCCEEDED; US_RISK_014_FAILED (where applicable); AUDIT_EVENT_RECORDED

**Permissions:** Authenticated actor + feature-specific permission

**UI Screens:** No dedicated UI if service-only; otherwise consuming application screen + loading, success and error states

**QA Scenarios:** Happy-path test with valid data. | Required-field and invalid-format validation test. | Unauthorized-role/access test. | Boundary/limit test where applicable. | Failure/timeout/provider-error test where applicable. | Duplicate/idempotency test where applicable. | Audit/event verification. | Regression test for dependent modules.

**Definition of Done:** Criteria + API/DB/events/security/QA implemented and passing

### US-RISK-015 — Configure wallet limits (P0)
**Actor:** Risk engine / authorized risk officer

**Acceptance Criteria:** Configure wallet limits must satisfy the source formal specification, including validation, authorization, persistence, traceability/audit and applicable financial integrity/idempotency requirements.

**API:** `/v1/risk/configure-wallet-limits`

**DB Entities:** RiskRule, RiskEvent, RiskAlert, RiskDecision

**Events:** US_RISK_015_REQUESTED; US_RISK_015_SUCCEEDED; US_RISK_015_FAILED (where applicable); AUDIT_EVENT_RECORDED

**Permissions:** Authenticated actor + feature-specific permission

**UI Screens:** No dedicated UI if service-only; otherwise consuming application screen + loading, success and error states

**QA Scenarios:** Happy-path test with valid data. | Required-field and invalid-format validation test. | Unauthorized-role/access test. | Boundary/limit test where applicable. | Failure/timeout/provider-error test where applicable. | Duplicate/idempotency test where applicable. | Audit/event verification. | Regression test for dependent modules.

**Definition of Done:** Criteria + API/DB/events/security/QA implemented and passing

### US-RISK-016 — Detect unusual transaction pattern (P1)
**Actor:** Risk engine / authorized risk officer

**Acceptance Criteria:** Detect unusual transaction pattern must satisfy the source formal specification, including validation, authorization, persistence, traceability/audit and applicable financial integrity/idempotency requirements.

**API:** `/v1/risk/detect-unusual-transaction-pattern`

**DB Entities:** RiskRule, RiskEvent, RiskAlert, RiskDecision

**Events:** US_RISK_016_REQUESTED; US_RISK_016_SUCCEEDED; US_RISK_016_FAILED (where applicable); AUDIT_EVENT_RECORDED

**Permissions:** Authenticated actor + feature-specific permission

**UI Screens:** No dedicated UI if service-only; otherwise consuming application screen + loading, success and error states

**QA Scenarios:** Happy-path test with valid data. | Required-field and invalid-format validation test. | Unauthorized-role/access test. | Boundary/limit test where applicable. | Failure/timeout/provider-error test where applicable. | Duplicate/idempotency test where applicable. | Audit/event verification. | Regression test for dependent modules.

**Definition of Done:** Criteria + API/DB/events/security/QA implemented and passing

### US-RISK-017 — Detect rapid fund movement (P1)
**Actor:** Risk engine / authorized risk officer

**Acceptance Criteria:** Detect rapid fund movement must satisfy the source formal specification, including validation, authorization, persistence, traceability/audit and applicable financial integrity/idempotency requirements.

**API:** `/v1/risk/detect-rapid-fund-movement`

**DB Entities:** RiskRule, RiskEvent, RiskAlert, RiskDecision

**Events:** US_RISK_017_REQUESTED; US_RISK_017_SUCCEEDED; US_RISK_017_FAILED (where applicable); AUDIT_EVENT_RECORDED

**Permissions:** Authenticated actor + feature-specific permission

**UI Screens:** No dedicated UI if service-only; otherwise consuming application screen + loading, success and error states

**QA Scenarios:** Happy-path test with valid data. | Required-field and invalid-format validation test. | Unauthorized-role/access test. | Boundary/limit test where applicable. | Failure/timeout/provider-error test where applicable. | Duplicate/idempotency test where applicable. | Audit/event verification. | Regression test for dependent modules.

**Definition of Done:** Criteria + API/DB/events/security/QA implemented and passing

### US-RISK-018 — Detect repeated failed authentication (P0)
**Actor:** Risk engine / authorized risk officer

**Acceptance Criteria:** Detect repeated failed authentication must satisfy the source formal specification, including validation, authorization, persistence, traceability/audit and applicable financial integrity/idempotency requirements.

**API:** `/v1/risk/detect-repeated-failed-authentication`

**DB Entities:** RiskRule, RiskEvent, RiskAlert, RiskDecision

**Events:** US_RISK_018_REQUESTED; US_RISK_018_SUCCEEDED; US_RISK_018_FAILED (where applicable); AUDIT_EVENT_RECORDED

**Permissions:** Authenticated actor + feature-specific permission

**UI Screens:** No dedicated UI if service-only; otherwise consuming application screen + loading, success and error states

**QA Scenarios:** Happy-path test with valid data. | Required-field and invalid-format validation test. | Unauthorized-role/access test. | Boundary/limit test where applicable. | Failure/timeout/provider-error test where applicable. | Duplicate/idempotency test where applicable. | Audit/event verification. | Regression test for dependent modules.

**Definition of Done:** Criteria + API/DB/events/security/QA implemented and passing

### US-RISK-019 — Create risk alert (P0)
**Actor:** Risk engine / authorized risk officer

**Acceptance Criteria:** Create risk alert must satisfy the source formal specification, including validation, authorization, persistence, traceability/audit and applicable financial integrity/idempotency requirements.

**API:** `/v1/risk/create-risk-alert`

**DB Entities:** RiskRule, RiskEvent, RiskAlert, RiskDecision

**Events:** US_RISK_019_REQUESTED; US_RISK_019_SUCCEEDED; US_RISK_019_FAILED (where applicable); AUDIT_EVENT_RECORDED

**Permissions:** Authenticated actor + feature-specific permission

**UI Screens:** No dedicated UI if service-only; otherwise consuming application screen + loading, success and error states

**QA Scenarios:** Happy-path test with valid data. | Required-field and invalid-format validation test. | Unauthorized-role/access test. | Boundary/limit test where applicable. | Failure/timeout/provider-error test where applicable. | Duplicate/idempotency test where applicable. | Audit/event verification. | Regression test for dependent modules.

**Definition of Done:** Criteria + API/DB/events/security/QA implemented and passing

### US-RISK-020 — Investigate risk alert (P0)
**Actor:** Risk engine / authorized risk officer

**Acceptance Criteria:** Investigate risk alert must satisfy the source formal specification, including validation, authorization, persistence, traceability/audit and applicable financial integrity/idempotency requirements.

**API:** `/v1/risk/investigate-risk-alert`

**DB Entities:** RiskRule, RiskEvent, RiskAlert, RiskDecision

**Events:** US_RISK_020_REQUESTED; US_RISK_020_SUCCEEDED; US_RISK_020_FAILED (where applicable); AUDIT_EVENT_RECORDED

**Permissions:** Authenticated actor + feature-specific permission

**UI Screens:** No dedicated UI if service-only; otherwise consuming application screen + loading, success and error states

**QA Scenarios:** Happy-path test with valid data. | Required-field and invalid-format validation test. | Unauthorized-role/access test. | Boundary/limit test where applicable. | Failure/timeout/provider-error test where applicable. | Duplicate/idempotency test where applicable. | Audit/event verification. | Regression test for dependent modules.

**Definition of Done:** Criteria + API/DB/events/security/QA implemented and passing

### US-RISK-021 — Resolve risk alert (P0)
**Actor:** Risk engine / authorized risk officer

**Acceptance Criteria:** Resolve risk alert must satisfy the source formal specification, including validation, authorization, persistence, traceability/audit and applicable financial integrity/idempotency requirements.

**API:** `/v1/risk/resolve-risk-alert`

**DB Entities:** RiskRule, RiskEvent, RiskAlert, RiskDecision

**Events:** US_RISK_021_REQUESTED; US_RISK_021_SUCCEEDED; US_RISK_021_FAILED (where applicable); AUDIT_EVENT_RECORDED

**Permissions:** Authenticated actor + feature-specific permission

**UI Screens:** No dedicated UI if service-only; otherwise consuming application screen + loading, success and error states

**QA Scenarios:** Happy-path test with valid data. | Required-field and invalid-format validation test. | Unauthorized-role/access test. | Boundary/limit test where applicable. | Failure/timeout/provider-error test where applicable. | Duplicate/idempotency test where applicable. | Audit/event verification. | Regression test for dependent modules.

**Definition of Done:** Criteria + API/DB/events/security/QA implemented and passing

### US-RISK-022 — Freeze account due to risk (P0)
**Actor:** Risk engine / authorized risk officer

**Acceptance Criteria:** Freeze account due to risk must satisfy the source formal specification, including validation, authorization, persistence, traceability/audit and applicable financial integrity/idempotency requirements.

**API:** `/v1/risk/freeze-account-due-to-risk`

**DB Entities:** RiskRule, RiskEvent, RiskAlert, RiskDecision

**Events:** US_RISK_022_REQUESTED; US_RISK_022_SUCCEEDED; US_RISK_022_FAILED (where applicable); AUDIT_EVENT_RECORDED

**Permissions:** Privileged role + explicit action permission + maker-checker where configured

**UI Screens:** No dedicated UI if service-only; otherwise consuming application screen + loading, success and error states

**QA Scenarios:** Happy-path test with valid data. | Required-field and invalid-format validation test. | Unauthorized-role/access test. | Boundary/limit test where applicable. | Failure/timeout/provider-error test where applicable. | Duplicate/idempotency test where applicable. | Audit/event verification. | Regression test for dependent modules.

**Definition of Done:** Criteria + API/DB/events/security/QA implemented and passing


## Epic-level Definition of Done
- All stories in this epic meet their story-specific Definition of Done.
- Cross-story dependencies are tested.
- API contracts and migrations are reviewed.
- Security and authorization checks pass.
- UI states and accessibility requirements pass where applicable.
- No unresolved Critical/High defect blocks completion.

## Entity Reconciliation Note (F1)

The DB Entities columns in the story definitions above list the canonical domain entities from the original backlog. Implementation (data-model.md) introduced 6 additional entities required to satisfy the acceptance criteria:

| Additional Entity | Purpose |
|-------------------|---------|
| `RiskEvaluation` | Persists the outcome and score of each `evaluate-transaction-risk` call; referenced by ManualDecision flows |
| `RiskAlertEvent` | Join table linking `RiskAlert` to `RiskEvent` records (many-to-many) |
| `WatchlistEntry` | Stores sanctioned / PEP / blocklisted persons for watchlist screening |
| `WatchlistMatch` | Records per-subject screening hits with similarity score and cleared_at |
| `AccountHold` | Persists an active account freeze placed by a risk officer; drives `checkHold()` in the evaluation pipeline |
| `SuspiciousActivityReport` | Stores the SAR draft created when `decision=file_sar`; tracks FIA submission lifecycle |

These entities are governed by the same schema-per-module constraint (`risk.*`) as all other Risk domain models.
