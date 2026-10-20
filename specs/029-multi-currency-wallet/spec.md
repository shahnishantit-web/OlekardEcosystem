# E29 — Multi-Currency Wallet

## Intent
This feature specification decomposes Epic E29 from the approved 538-story backlog into an independently executable Spec-Kit feature.

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

### US-MCW-001 — Create currency wallet (P2)
**Actor:** Customer / authorized financial service

**Uniqueness Rule:** A customer may hold at most one wallet per currency. Attempting to create a second wallet in the same currency MUST be rejected with a conflict error (idempotent retry with same idempotency key returns the existing wallet).

**Acceptance Criteria:** Create currency wallet must satisfy the source formal specification, including validation, authorization, persistence, traceability/audit and applicable financial integrity/idempotency requirements.

**API:** `/v1/multi-currency/create-currency-wallet`

**DB Entities:** Wallet, Currency, FxRate, FxTransaction

**Events:** US_MCW_001_REQUESTED; US_MCW_001_SUCCEEDED; US_MCW_001_FAILED (where applicable); AUDIT_EVENT_RECORDED

**Permissions:** Authenticated actor + feature-specific permission

**UI Screens:** No dedicated UI if service-only; otherwise consuming application screen + loading, success and error states

**QA Scenarios:** Happy-path test with valid data. | Required-field and invalid-format validation test. | Unauthorized-role/access test. | Boundary/limit test where applicable. | Failure/timeout/provider-error test where applicable. | Duplicate/idempotency test where applicable. | Audit/event verification. | Regression test for dependent modules.

**Definition of Done:** Criteria + API/DB/events/security/QA implemented and passing

### US-MCW-002 — Support multiple currencies (P2)
**Actor:** Customer / authorized financial service

**Acceptance Criteria:** Support multiple currencies must satisfy the source formal specification, including validation, authorization, persistence, traceability/audit and applicable financial integrity/idempotency requirements.

**API:** `/v1/multi-currency/support-multiple-currencies`

**DB Entities:** Wallet, Currency, FxRate, FxTransaction

**Events:** US_MCW_002_REQUESTED; US_MCW_002_SUCCEEDED; US_MCW_002_FAILED (where applicable); AUDIT_EVENT_RECORDED

**Permissions:** Authenticated actor + feature-specific permission

**UI Screens:** No dedicated UI if service-only; otherwise consuming application screen + loading, success and error states

**QA Scenarios:** Happy-path test with valid data. | Required-field and invalid-format validation test. | Unauthorized-role/access test. | Boundary/limit test where applicable. | Failure/timeout/provider-error test where applicable. | Duplicate/idempotency test where applicable. | Audit/event verification. | Regression test for dependent modules.

**Definition of Done:** Criteria + API/DB/events/security/QA implemented and passing

### US-MCW-003 — View currency balances (P2)
**Actor:** Customer / authorized financial service

**Acceptance Criteria:** View currency balances must satisfy the source formal specification, including validation, authorization, persistence, traceability/audit and applicable financial integrity/idempotency requirements.

**API:** `/v1/multi-currency/view-currency-balances`

**DB Entities:** Wallet, Currency, FxRate, FxTransaction

**Events:** US_MCW_003_REQUESTED; US_MCW_003_SUCCEEDED; US_MCW_003_FAILED (where applicable); AUDIT_EVENT_RECORDED

**Permissions:** Authenticated user + resource-specific read permission

**UI Screens:** No dedicated UI if service-only; otherwise consuming application screen + loading, success and error states

**QA Scenarios:** Happy-path test with valid data. | Required-field and invalid-format validation test. | Unauthorized-role/access test. | Boundary/limit test where applicable. | Failure/timeout/provider-error test where applicable. | Duplicate/idempotency test where applicable. | Audit/event verification. | Regression test for dependent modules.

**Definition of Done:** Criteria + API/DB/events/security/QA implemented and passing

### US-MCW-004 — Transfer between own currency wallets (P2)
**Actor:** Customer / authorized financial service

**Scope:** Same-currency internal transfer only — moves funds between two wallets owned by the same customer in the same currency. No FX rate is applied. Cross-currency moves between own wallets MUST use the US-MCW-007 quote-then-confirm conversion flow instead.

**Acceptance Criteria:** Transfer between own currency wallets must satisfy the source formal specification, including validation, authorization, persistence, traceability/audit and applicable financial integrity/idempotency requirements.

**API:** `/v1/multi-currency/transfer-between-own-currency-wallets`

**DB Entities:** Wallet, Currency, FxRate, FxTransaction

**Events:** US_MCW_004_REQUESTED; US_MCW_004_SUCCEEDED; US_MCW_004_FAILED (where applicable); AUDIT_EVENT_RECORDED

**Permissions:** Authenticated actor + feature-specific permission

**UI Screens:** No dedicated UI if service-only; otherwise consuming application screen + loading, success and error states

**QA Scenarios:** Happy-path test with valid data. | Required-field and invalid-format validation test. | Unauthorized-role/access test. | Boundary/limit test where applicable. | Failure/timeout/provider-error test where applicable. | Duplicate/idempotency test where applicable. | Audit/event verification. | Regression test for dependent modules.

**Definition of Done:** Criteria + API/DB/events/security/QA implemented and passing

### US-MCW-005 — Configure exchange rate (P2)
**Actor:** Admin / back-office operator (requires `multi_currency.configure_rate` permission; maker-checker approval via E28 Configuration Management)

**Acceptance Criteria:** Configure exchange rate must satisfy the source formal specification, including validation, authorization, persistence, traceability/audit and applicable financial integrity/idempotency requirements. Rate configuration changes require a second approver and are not active until approved (FR from E28 workflow).

**API:** `/v1/admin/multi-currency/configure-exchange-rate`

**DB Entities:** Wallet, Currency, FxRate, FxTransaction

**Events:** US_MCW_005_REQUESTED; US_MCW_005_SUCCEEDED; US_MCW_005_FAILED (where applicable); AUDIT_EVENT_RECORDED

**Permissions:** Authenticated actor + feature-specific permission

**UI Screens:** No dedicated UI if service-only; otherwise consuming application screen + loading, success and error states

**QA Scenarios:** Happy-path test with valid data. | Required-field and invalid-format validation test. | Unauthorized-role/access test. | Boundary/limit test where applicable. | Failure/timeout/provider-error test where applicable. | Duplicate/idempotency test where applicable. | Audit/event verification. | Regression test for dependent modules.

**Definition of Done:** Criteria + API/DB/events/security/QA implemented and passing

### US-MCW-006 — Calculate FX fee (P2)
**Actor:** Customer / authorized financial service

**Acceptance Criteria:** Calculate FX fee must satisfy the source formal specification, including validation, authorization, persistence, traceability/audit and applicable financial integrity/idempotency requirements.

**API:** `/v1/multi-currency/calculate-fx-fee`

**DB Entities:** Wallet, Currency, FxRate, FxTransaction

**Events:** US_MCW_006_REQUESTED; US_MCW_006_SUCCEEDED; US_MCW_006_FAILED (where applicable); AUDIT_EVENT_RECORDED

**Permissions:** Authenticated actor + feature-specific permission

**UI Screens:** No dedicated UI if service-only; otherwise consuming application screen + loading, success and error states

**QA Scenarios:** Happy-path test with valid data. | Required-field and invalid-format validation test. | Unauthorized-role/access test. | Boundary/limit test where applicable. | Failure/timeout/provider-error test where applicable. | Duplicate/idempotency test where applicable. | Audit/event verification. | Regression test for dependent modules.

**Definition of Done:** Criteria + API/DB/events/security/QA implemented and passing

### US-MCW-007 — Execute currency conversion (P2)
**Actor:** Customer / authorized financial service

**Conversion Flow:** Two-phase quote-then-confirm. Phase 1: customer requests a quote — system returns the locked rate and exact output amount, valid for 60 seconds. Phase 2: customer confirms within the lock window — system executes conversion synchronously, posts FX ledger entries within the same confirm response, and returns a completed FxTransaction. Expired or already-used quotes MUST be rejected. The confirm call MUST be idempotent. No pending/async state — the FxTransaction is either completed or failed at the end of the confirm call.

**Acceptance Criteria:** Execute currency conversion must satisfy the source formal specification, including validation, authorization, persistence, traceability/audit and applicable financial integrity/idempotency requirements.

**API:** `/v1/multi-currency/execute-currency-conversion`

**DB Entities:** Wallet, Currency, FxRate, FxTransaction

**Events:** US_MCW_007_REQUESTED; US_MCW_007_SUCCEEDED; US_MCW_007_FAILED (where applicable); AUDIT_EVENT_RECORDED

**Permissions:** Authenticated actor + feature-specific permission

**UI Screens:** No dedicated UI if service-only; otherwise consuming application screen + loading, success and error states

**QA Scenarios:** Happy-path test with valid data. | Required-field and invalid-format validation test. | Unauthorized-role/access test. | Boundary/limit test where applicable. | Failure/timeout/provider-error test where applicable. | Duplicate/idempotency test where applicable. | Audit/event verification. | Regression test for dependent modules.

**Definition of Done:** Criteria + API/DB/events/security/QA implemented and passing

### US-MCW-008 — Record FX ledger entries (P2)
**Actor:** Wallet / Ledger service

**Acceptance Criteria:** Record FX ledger entries must satisfy the source formal specification, including validation, authorization, persistence, traceability/audit and applicable financial integrity/idempotency requirements.

**API:** `/v1/multi-currency/record-fx-ledger-entries`

**DB Entities:** Wallet, Currency, FxRate, FxTransaction

**Events:** US_MCW_008_REQUESTED; US_MCW_008_SUCCEEDED; US_MCW_008_FAILED (where applicable); AUDIT_EVENT_RECORDED

**Permissions:** Authenticated actor + feature-specific permission

**UI Screens:** No dedicated UI if service-only; otherwise consuming application screen + loading, success and error states

**QA Scenarios:** Happy-path test with valid data. | Required-field and invalid-format validation test. | Unauthorized-role/access test. | Boundary/limit test where applicable. | Failure/timeout/provider-error test where applicable. | Duplicate/idempotency test where applicable. | Audit/event verification. | Regression test for dependent modules.

**Definition of Done:** Criteria + API/DB/events/security/QA implemented and passing

### US-MCW-009 — Maintain FX transaction history (P2)
**Actor:** Customer / authorized financial service

**Acceptance Criteria:** Maintain FX transaction history must satisfy the source formal specification, including validation, authorization, persistence, traceability/audit and applicable financial integrity/idempotency requirements.

**API:** `/v1/multi-currency/maintain-fx-transaction-history`

**DB Entities:** Wallet, Currency, FxRate, FxTransaction

**Events:** US_MCW_009_REQUESTED; US_MCW_009_SUCCEEDED; US_MCW_009_FAILED (where applicable); AUDIT_EVENT_RECORDED

**Permissions:** Authenticated user + resource-specific read permission

**UI Screens:** No dedicated UI if service-only; otherwise consuming application screen + loading, success and error states

**QA Scenarios:** Happy-path test with valid data. | Required-field and invalid-format validation test. | Unauthorized-role/access test. | Boundary/limit test where applicable. | Failure/timeout/provider-error test where applicable. | Duplicate/idempotency test where applicable. | Audit/event verification. | Regression test for dependent modules.

**Definition of Done:** Criteria + API/DB/events/security/QA implemented and passing


## Clarifications

### Session 2026-10-03

- Q: Who is the intended actor who configures exchange rates — a back-office administrator or an end-user customer? → A: Admin/back-office actor — rate config goes through the E28 admin configuration surface with maker-checker approval.
- Q: Can a customer hold more than one wallet in the same currency, or is there a strict limit of one wallet per currency per customer? → A: One wallet per currency per customer — creation rejected if a wallet in that currency already exists; idempotent retry with same key returns the existing wallet.
- Q: When a customer requests a currency conversion, how long is the quoted exchange rate locked in before the transaction must complete or be abandoned? → A: Two-phase quote-then-confirm; rate locked for 60 seconds; expired or already-used quotes rejected; confirm call is idempotent.
- Q: Does "Transfer between own currency wallets" (US-MCW-004) involve a currency conversion, or is it a same-currency move between two wallets the customer owns? → A: Same-currency internal transfer only — no FX rate applied; cross-currency moves between own wallets use the US-MCW-007 conversion flow.
- Q: Does the currency conversion (US-MCW-007) complete synchronously within the confirm API call, or does it transition through an intermediate pending state that resolves asynchronously? → A: Synchronous — confirm API posts FX ledger entries and returns a completed FxTransaction in the response; no pending/async state.

## Epic-level Definition of Done
- All stories in this epic meet their story-specific Definition of Done.
- Cross-story dependencies are tested.
- API contracts and migrations are reviewed.
- Security and authorization checks pass.
- UI states and accessibility requirements pass where applicable.
- No unresolved Critical/High defect blocks completion.
