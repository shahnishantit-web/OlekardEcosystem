# E05 — KYC & Identity Verification

**Feature**: `005-kyc-identity-verification` | **Epic**: E05 | **Status**: IMPLEMENTATION READY | **Created**: 2026-09-16

---

## 1. Problem Statement

The Wallet Ecosystem requires Know Your Customer (KYC) verification before a Customer or Agent can access financial services (wallet creation, transfers, cash-in/out). Currently no KYC subsystem exists. Users can register and authenticate (E01) but cannot be verified — their KYC status is undefined, blocking wallet activation (E06) and all downstream financial epics.

Additionally, a Compliance Officer role needs tooling to perform manual KYC review, approve or reject cases, request additional information, and trigger reverification for expired or suspicious identities.

E05 establishes the KYC domain: the data model, case lifecycle state machine, customer-facing intake APIs, document validation, automated and manual review workflows, expiry detection, and reverification — providing the verified identity foundation that E06 (Wallet Management) and all downstream financial epics depend on.

---

## 2. Scope

E05 covers:

- **KYC case lifecycle**: a formal state machine governing all KYC status transitions
- **Customer-facing KYC intake**: start case, capture personal information, upload identity and supporting documents, capture selfie
- **Document validation**: format (MIME type and file size) and expiry (document must not be expired at submission time)
- **KYC submission**: atomic, idempotent transition from `documents_submitted` to `under_review`
- **Automated identity verification**: integration with a configurable external KYC provider abstracted behind a `KycVerificationProvider` interface
- **Duplicate identity detection**: checked synchronously during personal information capture; blocks duplicate national IDs
- **Manual review workflow**: Compliance Officer queue, review, approve, reject with reason, request additional information
- **Customer resubmission**: after rejection or `needs_info`, the customer submits corrected documents as a new attempt on the same case
- **Status tracking**: customers/agents query their own case; Compliance Officer/Admin query any case
- **KYC expiry detection**: scheduled job transitions approved cases past their validity period to `expired`
- **Reverification trigger**: Admin or Compliance Officer triggers reverification for an approved case, resetting it for resubmission
- **New KYC permissions** seeded via E05 migration and assigned to the admin role (extending E04)
- **Configuration entries** for all tuneable KYC policy values

---

## 3. Goals

1. Provide a complete, auditable KYC case lifecycle from initiation to approval or rejection.
2. Protect the system from duplicate identity registration by checking national ID hashes before case progression.
3. Abstract the external KYC verification provider so it is runtime-configurable without code changes.
4. Give Compliance Officers a governed, permission-controlled review workflow with audit trails.
5. Ensure no PII (national ID numbers, biometric references) is stored in plaintext — hashing is mandatory.
6. Emit `kyc.case.approved` domain event so downstream epics (E06 wallet activation) can react without polling.
7. Support reverification and expiry lifecycles so KYC approval remains time-bounded and fresh.
8. Establish the `kyc` schema and `KycModule` bounded context for all downstream KYC-related work.

---

## 4. Non-Goals

E05 does NOT:

- Implement wallet creation or activation on KYC approval (E06 consumes the `kyc.case.approved` event).
- Implement agent-assisted customer KYC intake (E12 Agent Management).
- Implement AML/sanctions screening (E18 Fraud, Risk & AML).
- Implement the Admin Portal KYC review UI (E33 Admin Application).
- Implement OCR, liveness detection, or biometric matching — these are fully delegated to the external provider.
- Define or implement fine-grained row-level security beyond ownership scoping (Customer sees own case only).
- Change the approved technology stack.
- Implement Customer or Agent mobile UI screens beyond what requires a backend API.

---

## 5. Actors

| Actor | Description | KYC Role |
|---|---|---|
| **Customer** | Authenticated end-user | Initiates KYC for their own account; submits documents; tracks status; resubmits after rejection |
| **Agent** | Authenticated field agent | Same intake flow as Customer for their own KYC; agent-assisted customer KYC is out of scope (E12) |
| **Compliance Officer** | Admin user with `kyc.case.review` permission | Views manual review queue; performs review; approves, rejects, or requests more information |
| **System / Scheduler** | Background processes (Artisan scheduler, Horizon queues) | Runs automated verification pipeline after submission; detects expired KYC on schedule |
| **Admin** | Admin user with `kyc.case.manage` permission | Triggers reverification; can query any case |

---

## 6. User Stories

### US-KYC-001 — Customer Starts KYC (Priority: P0)

As a Customer or Agent, I want to initiate a KYC case so that I can begin the identity verification process required to access financial services.

**Why this priority**: KYC initiation is the entry point to the entire E05 flow. Without it, no downstream story is reachable.

**Independent Test**: Call `POST /v1/kyc/cases` with a valid authenticated Customer token → HTTP 201 with a `case_id`. Call the same endpoint with the same `Idempotency-Key` → same 201 response (idempotent).

**Acceptance Scenarios**:

1. **Given** an authenticated Customer with no existing open KYC case, **When** they call `POST /v1/kyc/cases` with a valid `Idempotency-Key`, **Then** a new KYC case is created with `status = initiated`, `attempt_number = 1`, and the `case_id` is returned in the response.
2. **Given** an authenticated Customer already has an open case (status not `approved`/`rejected`/`expired`), **When** they attempt to start a new KYC case, **Then** HTTP 409 with `KYC_CASE_ALREADY_OPEN` is returned.
3. **Given** an authenticated Customer with an `approved` KYC case, **When** they attempt to start a new case without a reverification trigger, **Then** HTTP 409 with `KYC_ALREADY_APPROVED` is returned.
4. **Given** the same `Idempotency-Key` is submitted a second time by the same actor, **When** the request is processed, **Then** the original 201 response is returned without creating a duplicate case.
5. **Given** an unauthenticated request, **When** `POST /v1/kyc/cases` is called, **Then** HTTP 401 is returned.

---

### US-KYC-002 — Customer Captures Personal Information (Priority: P0)

As a Customer or Agent, I want to provide my personal details so that the KYC case contains the information required for identity verification.

**Why this priority**: Personal information is required before documents can be meaningfully validated and submitted.

**Independent Test**: Create a case (US-KYC-001), then `PUT /v1/kyc/cases/{id}/personal-information` with full valid payload → HTTP 200 with updated case. Call `GET /v1/kyc/cases/{id}/status` → personal information section shows `provided`.

**Acceptance Scenarios**:

1. **Given** an open KYC case in `initiated` status belonging to the authenticated actor, **When** they submit all required personal information fields, **Then** the details are persisted, national ID number is stored as a HMAC-SHA256 hash (never plaintext), and the response confirms capture.
2. **Given** a duplicate national ID hash already exists on another `approved` KYC case, **When** personal information is submitted, **Then** HTTP 409 with `DUPLICATE_IDENTITY_DETECTED` is returned and the case is not advanced.
3. **Given** required fields are missing or malformed, **When** the request is processed, **Then** HTTP 400 with field-level validation errors is returned.
4. **Given** the KYC case is in `under_review` or later status, **When** personal information update is attempted, **Then** HTTP 422 with `CASE_NOT_EDITABLE` is returned.
5. **Given** an actor attempts to update another user's case, **When** the request is processed, **Then** HTTP 404 is returned (no information about the existence of another user's case is revealed).

---

### US-KYC-003 — Customer Uploads Identity Document (Priority: P0)

As a Customer or Agent, I want to upload my primary identity document so that the system can verify my claimed identity.

**Why this priority**: The identity document (national ID, passport, or driver's licence) is the foundational verification artifact.

**Independent Test**: Upload a valid JPEG national ID image to an open case → HTTP 201, document record created with `validation_status = valid`. Upload a PDF exceeding the size limit → HTTP 400 `DOCUMENT_TOO_LARGE`.

**Acceptance Scenarios**:

1. **Given** an open case in `initiated` status, **When** a valid identity document image is uploaded with `document_type: national_id`, **Then** the file is stored by reference (not by path), format and expiry are validated, and the document record is created with appropriate `validation_status`.
2. **Given** a document with an unsupported MIME type is uploaded, **When** the request is processed, **Then** HTTP 400 with `INVALID_DOCUMENT_FORMAT` is returned.
3. **Given** a document whose `expires_on` date is in the past, **When** it is uploaded, **Then** HTTP 422 with `DOCUMENT_EXPIRED` is returned.
4. **Given** a file exceeding `kyc.max_document_size_bytes`, **When** uploaded, **Then** HTTP 400 with `DOCUMENT_TOO_LARGE` is returned.
5. **Given** an identity document already exists on the case, **When** another is uploaded for the same `document_type`, **Then** the previous document is superseded and the new one becomes current (replacement, not duplication).

---

### US-KYC-004 — Customer Uploads Supporting Document (Priority: P1)

As a Customer or Agent, I want to upload a supporting document (such as a utility bill) to satisfy proof-of-address requirements.

**Acceptance Scenarios**:

1. **Given** an open case, **When** a valid supporting document (`document_type: utility_bill`) is uploaded, **Then** it is stored, validated, and associated with the case.
2. **Given** the supporting document (`utility_bill`) has no `expires_on` date, or has an `expires_on` date in the past, **When** uploaded, **Then** expiry validation is skipped entirely and the document is accepted. Supporting documents are not subject to expiry enforcement.
3. **Given** a document exceeding size or format limits, **When** uploaded, **Then** HTTP 400 with appropriate error code is returned.

---

### US-KYC-005 — Customer Captures Selfie (Priority: P1)

As a Customer or Agent, I want to upload a selfie photograph so that biometric comparison with my identity document can be performed by the verification provider.

**Acceptance Scenarios**:

1. **Given** an open case, **When** a valid image is uploaded with `document_type: selfie`, **Then** it is stored by reference and associated with the case.
2. **Given** an invalid MIME type or oversized selfie image, **When** uploaded, **Then** HTTP 400 with `INVALID_DOCUMENT_FORMAT` or `DOCUMENT_TOO_LARGE` is returned.
3. **Given** a selfie already exists on the case, **When** a new one is uploaded, **Then** the previous selfie is superseded.

---

### US-KYC-006 — System Validates Document Format (Priority: P0)

As a Customer or Agent, I want the system to check whether my document meets the accepted format requirements before I commit to a full upload, so that I can correct issues immediately.

**Independent Test**: `POST /v1/kyc/documents/validate-format` with a JPEG within size limits → `{ "valid": true }`. Same endpoint with a BMP file → `{ "valid": false, "error": "INVALID_DOCUMENT_FORMAT" }`.

**Acceptance Scenarios**:

1. **Given** a file within the accepted MIME types and under `kyc.max_document_size_bytes`, **When** validated, **Then** `{ "valid": true }` is returned without any state persisted.
2. **Given** a file with a disallowed MIME type, **When** validated, **Then** `{ "valid": false, "error": "INVALID_DOCUMENT_FORMAT" }` is returned.
3. **Given** a file exceeding the size limit, **When** validated, **Then** `{ "valid": false, "error": "DOCUMENT_TOO_LARGE" }` is returned.
4. **Given** format validation is called, **Then** no KYC case record or document record is created — this is a stateless pre-check.

---

### US-KYC-007 — System Validates Document Expiry (Priority: P0)

As a Customer or Agent, I want the system to confirm whether my document is still valid (not expired) before I upload it.

**Acceptance Scenarios**:

1. **Given** a document expiry date in the future, **When** validated, **Then** `{ "valid": true }` is returned.
2. **Given** a document expiry date in the past or today, **When** validated, **Then** `{ "valid": false, "error": "DOCUMENT_EXPIRED" }` is returned.
3. **Given** no expiry date is provided, **When** validated, **Then** `{ "valid": true }` is returned (expiry check requires an explicit date).
4. **Given** expiry validation is called, **Then** no persistent state is created.

---

### US-KYC-008 — Customer Submits KYC for Verification (Priority: P0)

As a Customer or Agent, I want to formally submit my completed KYC case so that it enters the verification queue.

**Why this priority**: Submission is the gate between data collection and verification — no verification can begin without it.

**Independent Test**: Complete a case (personal info + identity document), call `POST /v1/kyc/cases/{id}/submit` → HTTP 200, `status = under_review`. Repeat the same call → HTTP 200 (idempotent, no duplicate event).

**Acceptance Scenarios**:

1. **Given** a case with all required documents and personal information, **When** submitted, **Then** the case transitions to `under_review`, `submitted_at` is set, and a `kyc.case.submitted` audit event is written.
2. **Given** the case is already in `under_review` or later, **When** submitted again, **Then** HTTP 200 is returned with no state change (idempotent no-op).
3. **Given** the case is missing mandatory documents or personal information, **When** submitted, **Then** HTTP 422 with `CASE_INCOMPLETE` and a list of missing items is returned.
4. **Given** the case `status` is not `initiated` or `documents_submitted`, **When** submitted, **Then** HTTP 422 with `INVALID_CASE_STATUS` is returned.
5. **Given** `kyc.auto_verify_enabled = true`, **When** a case is submitted, **Then** the automated verification pipeline is enqueued asynchronously.

---

### US-KYC-009 — System Performs Automated Identity Verification (Priority: P0)

As the system, I want to send a submitted KYC case to the configured external verification provider so that identity verification can be completed automatically where possible.

**Independent Test**: Submit a case → automated verification job runs → `KycVerificationResult` record created with `provider_name` set, `outcome` set to `pass`/`fail`/`manual_review_required`; case advances accordingly.

**Acceptance Scenarios**:

1. **Given** a case in `under_review` status and `kyc.auto_verify_enabled = true`, **When** the verification job runs, **Then** the configured `KycVerificationProvider` is called, a `KycVerificationResult` record is created, and the provider's raw response is stored only as a HMAC-SHA256 hash (never raw).
2. **Given** the provider returns `pass`, **When** the result is processed, **Then** the case is automatically transitioned to `approved`, `verified_at` and `expires_at` are set, and the `kyc.case.approved` domain event is emitted.
3. **Given** the provider returns `fail` or `manual_review_required`, **When** the result is processed, **Then** the case remains in `under_review` and appears in the Compliance Officer review queue.
4. **Given** the provider call times out or returns an error, **When** the result is processed, **Then** the job is retried up to `kyc.provider_max_retries` times; after exhausting retries the case remains `under_review` and an alert is logged.
5. **Given** `kyc.auto_verify_enabled = false`, **When** a case is submitted, **Then** no automated provider call is made and the case goes directly to the manual review queue.
6. **Given** the automated verification step runs, **Then** no PII from the case (name, national ID, document images) is logged in plaintext in application logs.

---

### US-KYC-010 — Compliance Officer Performs Manual KYC Review (Priority: P0)

As a Compliance Officer, I want to view the queue of KYC cases awaiting manual review so that I can process them.

**Independent Test**: `GET /v1/kyc/cases?status=under_review` with a Compliance Officer token → paginated list of pending cases. CUSTOMER token on same endpoint → HTTP 403.

**Acceptance Scenarios**:

1. **Given** a Compliance Officer with `kyc.case.review` permission, **When** they call `GET /v1/kyc/cases?status=under_review`, **Then** all cases in `under_review` status are returned paginated, including personal information summary (masked) and submitted documents.
2. **Given** a Compliance Officer, **When** they call `GET /v1/kyc/cases/{id}`, **Then** the full case details are returned including personal info, document list, and any prior verification results.
3. **Given** a CUSTOMER token, **When** `GET /v1/kyc/cases` without scoping to own case is attempted, **Then** HTTP 403 is returned.
4. **Given** a CUSTOMER token, **When** `GET /v1/kyc/cases/{id}` for their own case is called, **Then** their case details are returned.
5. **Given** a CUSTOMER token, **When** `GET /v1/kyc/cases/{id}` for another user's case is called, **Then** HTTP 404 is returned.

---

### US-KYC-011 — Compliance Officer Approves KYC (Priority: P0)

As a Compliance Officer, I want to approve a KYC case so that the verified identity is recorded and the customer can access financial services.

**Why this priority**: Approval is the terminal success state — it unlocks downstream wallet creation and financial access.

**Acceptance Scenarios**:

1. **Given** a case in `under_review`, **When** a Compliance Officer with `kyc.case.approve` calls `POST /v1/kyc/cases/{id}/approve`, **Then** the case transitions to `approved`, `verified_at` is set to now, `expires_at` is set to now + `kyc.validity_period_days`, reviewer info is recorded, and `kyc.case.approved` domain event is emitted.
2. **Given** the `kyc.case.approved` event is emitted, **Then** it includes `user_id`, `case_id`, `verified_at`, `expires_at`, and `correlation_id` so downstream consumers (E06) can act without polling.
3. **Given** a case is already `approved`, **When** approval is attempted again, **Then** HTTP 422 with `INVALID_CASE_STATUS` is returned.
4. **Given** a Compliance Officer without `kyc.case.approve` permission attempts approval, **When** the request is processed, **Then** HTTP 403 with `AUTHORIZATION_001` is returned.
5. **Given** approval is performed, **Then** an audit record `kyc.case.approved` is written with actor ID, target user ID, correlation ID, and IP hash.

---

### US-KYC-012 — Compliance Officer Rejects KYC with Reason (Priority: P0)

As a Compliance Officer, I want to reject a KYC case with a mandatory explanation so that the customer understands what to correct before resubmitting.

**Acceptance Scenarios**:

1. **Given** a case in `under_review`, **When** a Compliance Officer with `kyc.case.review` calls `POST /v1/kyc/cases/{id}/reject` with a non-empty `rejection_reason`, **Then** the case transitions to `rejected`, the reason is stored, and `kyc.case.rejected` audit event is written.
2. **Given** `rejection_reason` is empty or absent, **When** rejection is attempted, **Then** HTTP 400 with `REJECTION_REASON_REQUIRED` is returned.
3. **Given** the case is in a terminal state (`approved`/`expired`), **When** rejection is attempted, **Then** HTTP 422 with `INVALID_CASE_STATUS` is returned.

---

### US-KYC-013 — Compliance Officer Requests Additional Information (Priority: P0)

As a Compliance Officer, I want to request additional information from a customer during KYC review so that I can make an informed decision without outright rejecting the case.

**Acceptance Scenarios**:

1. **Given** a case in `under_review`, **When** a Compliance Officer with `kyc.case.review` calls `POST /v1/kyc/cases/{id}/request-info` with a non-empty `info_request_note`, **Then** the case transitions to `needs_info`, the note is stored, and `kyc.case.needs-info` audit event is written.
2. **Given** the case transitions to `needs_info`, **Then** the customer can view the reviewer's note when checking their case status.
3. **Given** `info_request_note` is empty, **When** the request is made, **Then** HTTP 400 with `INFO_REQUEST_NOTE_REQUIRED` is returned.

---

### US-KYC-014 — Customer Resubmits KYC (Priority: P0)

As a Customer or Agent whose KYC was rejected or needs additional information, I want to resubmit my KYC with corrected documents so that I can retry verification.

**Independent Test**: Reject a case → Customer calls `POST /v1/kyc/cases/{id}/resubmit` → new `attempt_number` created, case returns to `initiated` state, previous rejection reason cleared.

**Acceptance Scenarios**:

1. **Given** a case in `rejected` or `needs_info` status belonging to the authenticated actor, **When** they call `POST /v1/kyc/cases/{id}/resubmit`, **Then** `attempt_number` is incremented, existing documents are cleared, and the case returns to `initiated` so fresh documents can be uploaded.
2. **Given** the resubmission is idempotent (`Idempotency-Key` already processed), **When** the same request is sent again, **Then** the original response is returned without creating a second attempt.
3. **Given** the case is in a non-resubmittable state (`under_review`, `approved`, `expired`), **When** resubmission is attempted, **Then** HTTP 422 with `INVALID_CASE_STATUS` is returned.
4. **Given** resubmission is called, **Then** an audit record `kyc.case.resubmitted` is written with attempt number.

---

### US-KYC-015 — Customer Tracks KYC Status (Priority: P0)

As a Customer or Agent, I want to check the current status of my KYC case so that I know whether I have been verified and what actions, if any, I need to take.

**Independent Test**: `GET /v1/kyc/cases/my` with authenticated Customer → returns case status, documents summary, and any rejection reason or info request note.

**Acceptance Scenarios**:

1. **Given** an authenticated Customer with an open KYC case, **When** they call `GET /v1/kyc/cases/my`, **Then** the current case status, `attempt_number`, and any `rejection_reason` or `info_request_note` are returned.
2. **Given** an authenticated Customer with no KYC case, **When** they call `GET /v1/kyc/cases/my`, **Then** HTTP 404 with `KYC_CASE_NOT_FOUND` is returned.
3. **Given** a Compliance Officer calls `GET /v1/kyc/cases/my?user_id={uuid}`, **When** the request is processed, **Then** the specified user's case status is returned.
4. **Given** a Customer calls `GET /v1/kyc/cases/my?user_id={other_uuid}`, **When** the request is processed, **Then** HTTP 403 is returned (customers may not query other users' status).

---

### US-KYC-016 — System Detects Duplicate Identity (Priority: P0)

As the system, I want to detect when a national ID has already been used in an approved KYC case so that duplicate identity registrations are prevented.

**Independent Test**: Approve KYC for User A with national ID `X`. Attempt to capture personal info for User B with the same national ID `X` → HTTP 409 `DUPLICATE_IDENTITY_DETECTED`.

**Acceptance Scenarios**:

1. **Given** a national ID hash already exists on an `approved` KYC case, **When** another user submits personal information with the same national ID, **Then** HTTP 409 with `DUPLICATE_IDENTITY_DETECTED` is returned and no personal info is persisted for the duplicate.
2. **Given** the same national ID exists on a `rejected` or `expired` case, **When** a different user submits the same ID, **Then** the duplicate check DOES NOT block the submission (only `approved` cases trigger the block).
3. **Given** duplicate detection is triggered, **Then** no information about which user holds the conflicting approved case is revealed in the error response.
4. **Given** duplicate detection runs, **Then** an audit record `kyc.duplicate_identity_detected` is written.

---

### US-KYC-017 — System Detects Expired KYC (Priority: P1)

As the system, I want to automatically detect KYC cases that have passed their validity period so that users are prompted to reverify before accessing financial services.

**Independent Test**: Set `expires_at` to yesterday on an `approved` case, run the expiry detection command → case transitions to `expired`.

**Acceptance Scenarios**:

1. **Given** an `approved` KYC case whose `expires_at` timestamp is in the past, **When** the scheduled expiry detection job runs, **Then** the case transitions to `expired` and a `kyc.case.expired` audit event is written.
2. **Given** an `approved` KYC case whose `expires_at` is in the future, **When** the expiry detection job runs, **Then** the case is not modified.
3. **Given** the expiry detection job processes cases in batches, **Then** it MUST NOT hold a database lock for longer than 5 seconds per batch to avoid blocking live operations.
4. **Given** expiry detection runs, **Then** a `kyc.case.expired` domain event is emitted for each expired case so downstream systems (E06) can suspend financial access.

---

### US-KYC-018 — Admin Triggers KYC Reverification (Priority: P1)

As a Compliance Officer or Admin, I want to trigger reverification of an approved KYC case (e.g., following a risk alert or regulatory requirement) so that the case re-enters the review pipeline.

**Acceptance Scenarios**:

1. **Given** an `approved` or `expired` KYC case, **When** an actor with `kyc.case.manage` calls `POST /v1/kyc/cases/{id}/reverify`, **Then** the case transitions to `initiated`, `attempt_number` is incremented, all previous documents are cleared, and `kyc.case.reverification-triggered` audit event is written.
2. **Given** the case is in a non-reverifiable state (`initiated`, `under_review`, `needs_info`, `rejected`), **When** reverification is triggered, **Then** HTTP 422 with `INVALID_CASE_STATUS` is returned.
3. **Given** reverification is triggered, **Then** the `kyc.case.reverification-triggered` domain event is emitted so downstream systems can suspend financial access pending re-approval.

---

## 7. Business Rules

| ID | Rule |
|---|---|
| BR-E05-001 | A Customer or Agent MUST NOT have more than one open KYC case at a time. An "open" case is any case whose status is not `approved`, `rejected`, or `expired`. |
| BR-E05-002 | National ID numbers MUST be stored exclusively as HMAC-SHA256 hashes. The plaintext national ID MUST NOT be persisted in any database column, log file, or audit record. |
| BR-E05-003 | KYC case status transitions MUST follow the defined state machine. Any attempt to perform a transition that is not permitted in the current state MUST be rejected with `INVALID_CASE_STATUS`. |
| BR-E05-004 | Document files MUST be stored by opaque file reference (cloud storage key), not by filesystem path. The storage layer is abstracted — no file path appears in any API response or database record. |
| BR-E05-005 | All state-changing KYC operations MUST produce an immutable audit record in `platform.audit_events`. |
| BR-E05-006 | KYC approval MUST emit the `kyc.case.approved` domain event. No downstream system may poll for approval status — they MUST react to this event. |
| BR-E05-007 | The `approve-kyc` operation MUST require the `kyc.case.approve` permission, which is distinct from the `kyc.case.review` permission, supporting maker-checker separation. Whether full maker-checker enforcement (different reviewer and approver) is required is TBD-E05-004. |
| BR-E05-008 | KYC configuration values (`kyc.validity_period_days`, `kyc.max_document_size_bytes`, `kyc.accepted_mime_types`, `kyc.provider`, `kyc.auto_verify_enabled`) MUST be read from the platform configuration service. Hard-coding these values is prohibited. |
| BR-E05-009 | Duplicate identity detection MUST use the national ID hash only. No personal name or date-of-birth matching is performed in E05. |
| BR-E05-010 | The external KYC verification provider's raw response MUST NOT be stored. Only the HMAC-SHA256 hash of the response is persisted in `kyc_verification_results.raw_response_hash`. |
| BR-E05-011 | KYC resubmission MUST increment `attempt_number` and clear all documents from the previous attempt. Historical attempt data is preserved in audit records, not in the case record itself. |
| BR-E05-012 | `POST /v1/kyc/cases` (start KYC) and `POST /v1/kyc/cases/{id}/submit` MUST support client-controlled idempotency via `Idempotency-Key` header. |
| BR-E05-013 | KYC expiry detection MUST run as a scheduled job. The job MUST process cases in batches to avoid holding database locks. |
| BR-E05-014 | A Customer MUST only be able to read and mutate their own KYC case. Reading or mutating another user's case MUST return HTTP 404 (not 403) to avoid revealing the existence of another user's case. |
| BR-E05-015 | Rejection reason and info request notes MUST be non-empty strings. Empty strings MUST be rejected with appropriate validation errors. |

---

## 8. Functional Requirements

### 8.1 KYC Case Lifecycle

| ID | Story | Requirement |
|---|---|---|
| FR-E05-001 | US-KYC-001 | The system MUST expose `POST /v1/kyc/cases` to initiate a new KYC case for the authenticated actor. The endpoint MUST require `kyc.case.create` permission. |
| FR-E05-002 | US-KYC-001 | `POST /v1/kyc/cases` MUST be idempotent via `Idempotency-Key` header. A replayed request MUST return the original 201 response without creating a duplicate case. |
| FR-E05-003 | US-KYC-001 | If the actor already has an open KYC case, `POST /v1/kyc/cases` MUST return HTTP 409 with `KYC_CASE_ALREADY_OPEN`. |
| FR-E05-004 | US-KYC-001 | If the actor already has an `approved` KYC case, `POST /v1/kyc/cases` MUST return HTTP 409 with `KYC_ALREADY_APPROVED`. |

### 8.2 Personal Information

| ID | Story | Requirement |
|---|---|---|
| FR-E05-005 | US-KYC-002 | The system MUST expose `PUT /v1/kyc/cases/{id}/personal-information` to capture personal details for an open case. Required permission: `kyc.case.update`. |
| FR-E05-006 | US-KYC-002 | Personal information MUST include: `full_legal_name` (string), `date_of_birth` (date), `nationality` (ISO 3166-1 alpha-2 country code), `national_id_type` (enum: `national_id` / `passport` / `drivers_licence`), `national_id_number` (string — hashed before storage), `address` (object: `line_1`, `line_2` nullable, `city`, `state_province` nullable, `postal_code`, `country_code`). |
| FR-E05-007 | US-KYC-002 | The national ID number MUST be converted to a HMAC-SHA256 hash immediately upon receipt and stored as `national_id_hash`. The plaintext value MUST NOT be persisted. |
| FR-E05-008 | US-KYC-016 | When personal information is submitted, the system MUST synchronously check whether `national_id_hash` matches any existing `approved` KYC case. If a match is found, the system MUST return HTTP 409 with `DUPLICATE_IDENTITY_DETECTED` and MUST NOT persist the submitted personal information. |
| FR-E05-009 | US-KYC-002 | Personal information MUST NOT be updatable once the case status has advanced to `under_review` or later. Attempts MUST return HTTP 422 with `CASE_NOT_EDITABLE`. |

### 8.3 Document Upload and Validation

| ID | Story | Requirement |
|---|---|---|
| FR-E05-010 | US-KYC-003,004,005 | The system MUST expose `POST /v1/kyc/cases/{id}/documents` (multipart/form-data) to upload a document. Required field: `document_type` (enum: `national_id` / `passport` / `drivers_licence` / `utility_bill` / `selfie`). Required permission: `kyc.case.update`. |
| FR-E05-011 | US-KYC-003,004,005 | Upon upload, the system MUST: (1) validate MIME type against `kyc.accepted_mime_types`; (2) validate file size against `kyc.max_document_size_bytes`; (3) if `expires_on` is provided AND `document_type` is NOT `utility_bill`, validate it is in the future. Supporting documents (`utility_bill`) are exempt from expiry validation regardless of whether `expires_on` is provided. If any applicable validation fails, the file MUST NOT be persisted. |
| FR-E05-012 | US-KYC-003,004,005 | If all validations pass, the system MUST store the file via the platform's file storage abstraction, record the opaque `file_reference`, and create a `KycDocument` record with `validation_status = valid`. |
| FR-E05-013 | US-KYC-003,004,005 | If a document of the same `document_type` already exists on the case, the new upload MUST supersede the previous one. The previous `KycDocument` record MUST be soft-deleted (not physically removed). |
| FR-E05-014 | US-KYC-006 | The system MUST expose `POST /v1/kyc/documents/validate-format` to perform a stateless format pre-check. This endpoint MUST NOT create any database records. Required permission: `kyc.case.update`. |
| FR-E05-015 | US-KYC-007 | The system MUST expose `POST /v1/kyc/documents/validate-expiry` to perform a stateless expiry pre-check given a date input. This endpoint MUST NOT create any database records. Required permission: `kyc.case.update`. |

### 8.4 KYC Submission

| ID | Story | Requirement |
|---|---|---|
| FR-E05-016 | US-KYC-008 | The system MUST expose `POST /v1/kyc/cases/{id}/submit` to transition a case from `documents_submitted` or `initiated` to `under_review`. Required permission: `kyc.case.update`. |
| FR-E05-017 | US-KYC-008 | Before accepting submission, the system MUST verify completeness: personal information captured AND at least one identity document (`national_id`, `passport`, or `drivers_licence`) uploaded with `validation_status = valid`. If incomplete, HTTP 422 with `CASE_INCOMPLETE` and a list of missing items MUST be returned. |
| FR-E05-018 | US-KYC-008 | `POST /v1/kyc/cases/{id}/submit` MUST be idempotent via `Idempotency-Key`. |
| FR-E05-019 | US-KYC-008 | Upon successful submission, `submitted_at` MUST be set, the `kyc.case.submitted` audit event MUST be written, and — if `kyc.auto_verify_enabled = true` — an automated verification job MUST be enqueued asynchronously. |

### 8.5 Automated Verification

| ID | Story | Requirement |
|---|---|---|
| FR-E05-020 | US-KYC-009 | The system MUST implement a `KycVerificationProvider` interface with a single method `verify(KycCase $case): VerificationOutcome`. All interaction with external KYC providers MUST go through this interface. |
| FR-E05-021 | US-KYC-009 | The concrete provider implementation MUST be resolved via the platform configuration key `kyc.provider`. The implementation MUST be swappable without code changes by updating the configuration value. |
| FR-E05-022 | US-KYC-009 | After calling the provider, the system MUST create a `KycVerificationResult` record containing: `provider_name`, `provider_reference`, `outcome`, `confidence_score` (nullable), and `raw_response_hash` (HMAC-SHA256 of the serialized provider response). |
| FR-E05-023 | US-KYC-009 | If the provider returns `pass`, the system MUST automatically transition the case to `approved`, set `verified_at` and `expires_at`, and emit `kyc.case.approved`. |
| FR-E05-024 | US-KYC-009 | If the provider returns `fail` or `manual_review_required`, the case MUST remain `under_review` and appear in the Compliance Officer review queue. |
| FR-E05-025 | US-KYC-009 | Provider call failures MUST be retried up to `kyc.provider_max_retries` times with exponential back-off via the job queue. After exhausting retries, the case remains `under_review` and a structured log error MUST be emitted. |
| FR-E05-026 | US-KYC-009 | If `kyc.auto_verify_enabled = false`, no provider call is made. The case goes directly into the manual review queue after submission. |

### 8.6 Manual Review Workflow

| ID | Story | Requirement |
|---|---|---|
| FR-E05-027 | US-KYC-010 | The system MUST expose `GET /v1/kyc/cases` (paginated) for Compliance Officers. Required permission: `kyc.case.review`. Supports `?status=` filter. |
| FR-E05-028 | US-KYC-010 | The system MUST expose `GET /v1/kyc/cases/{id}` to retrieve full case details. A Compliance Officer sees all cases; a Customer or Agent sees only their own. |
| FR-E05-029 | US-KYC-011 | The system MUST expose `POST /v1/kyc/cases/{id}/approve` to approve a case. Required permission: `kyc.case.approve`. On success: set `status = approved`, `verified_at`, `expires_at`, `reviewer_id`; emit `kyc.case.approved` domain event; write audit record. |
| FR-E05-030 | US-KYC-012 | The system MUST expose `POST /v1/kyc/cases/{id}/reject` to reject a case with a mandatory non-empty `rejection_reason`. Required permission: `kyc.case.review`. |
| FR-E05-031 | US-KYC-013 | The system MUST expose `POST /v1/kyc/cases/{id}/request-info` to request additional information with a mandatory non-empty `info_request_note`. Required permission: `kyc.case.review`. |

### 8.7 Resubmission

| ID | Story | Requirement |
|---|---|---|
| FR-E05-032 | US-KYC-014 | The system MUST expose `POST /v1/kyc/cases/{id}/resubmit` to allow resubmission of a `rejected` or `needs_info` case. Required permission: `kyc.case.update`. |
| FR-E05-033 | US-KYC-014 | On resubmission: `attempt_number` MUST be incremented; all existing `KycDocument` records MUST be soft-deleted; personal information MAY be updated (case returns to `initiated`); `rejection_reason` and `info_request_note` MUST be cleared. |
| FR-E05-034 | US-KYC-014 | `POST /v1/kyc/cases/{id}/resubmit` MUST be idempotent via `Idempotency-Key`. |

### 8.8 Status Tracking

| ID | Story | Requirement |
|---|---|---|
| FR-E05-035 | US-KYC-015 | The system MUST expose `GET /v1/kyc/cases/my` so an authenticated Customer or Agent can retrieve their own current KYC case status, including `status`, `attempt_number`, `rejection_reason` (if applicable), and `info_request_note` (if applicable). Required permission: `kyc.case.read`. |
| FR-E05-036 | US-KYC-015 | A Compliance Officer or Admin calling `GET /v1/kyc/cases/my?user_id={uuid}` MUST see the specified user's case. Required permission: `kyc.case.review`. |

### 8.9 Lifecycle Automation

| ID | Story | Requirement |
|---|---|---|
| FR-E05-037 | US-KYC-017 | The system MUST provide an Artisan command `kyc:detect-expired` that transitions all `approved` cases whose `expires_at < NOW()` to `expired`, writes `kyc.case.expired` audit records, and emits `kyc.case.expired` domain events. |
| FR-E05-038 | US-KYC-017 | `kyc:detect-expired` MUST process cases in batches of configurable size (default 100) to avoid lock contention. |
| FR-E05-039 | US-KYC-018 | The system MUST expose `POST /v1/kyc/cases/{id}/reverify` to trigger reverification of an `approved` or `expired` case. Required permission: `kyc.case.manage`. |
| FR-E05-040 | US-KYC-018 | On reverification trigger: `attempt_number` MUST be incremented; all documents MUST be soft-deleted; case MUST transition to `initiated`; `kyc.case.reverification-triggered` audit event and domain event MUST be emitted. |

### 8.10 Permissions and Authorization

| ID | Story | Requirement |
|---|---|---|
| FR-E05-041 | All | E05 MUST seed the following permissions via an idempotent migration: `kyc.case.create`, `kyc.case.update`, `kyc.case.read`, `kyc.case.review`, `kyc.case.approve`, `kyc.case.manage`. All 6 MUST be assigned to the `admin` role. The self-service subset (`kyc.case.create`, `kyc.case.update`, `kyc.case.read`) MUST also be assigned to `customer` and `agent` roles if those roles exist at migration time. |
| FR-E05-042 | All | All KYC endpoints MUST be protected by `identity.auth` middleware. Actor-type and permission enforcement MUST use the `identity.require-permission` middleware from E04. |
| FR-E05-043 | US-KYC-010,011,012,013 | Compliance Officer access to the review, reject, request-info, and list-all-cases operations MUST require an Admin actor type with the appropriate `kyc.case.*` permission. Customer/Agent tokens on these endpoints MUST receive HTTP 403. |

### 8.11 Audit Events

| ID | Story | Requirement |
|---|---|---|
| FR-E05-044 | All | Every KYC state transition MUST produce an audit record in `platform.audit_events` with: `action`, `actor_id`, `target_user_id`, `case_id`, `correlation_id`, `ip_hash` (HMAC-SHA256 of request IP). |
| FR-E05-045 | US-KYC-011 | `kyc.case.approved` MUST be emitted as both an audit event AND a domain event (via reliable event infrastructure). |
| FR-E05-046 | US-KYC-017,018 | `kyc.case.expired` and `kyc.case.reverification-triggered` MUST be emitted as domain events so downstream systems can suspend or re-evaluate financial access. |

### 8.12 Observability

| ID | Story | Requirement |
|---|---|---|
| FR-E05-047 | All | The system MUST emit metric counter `kyc.case.initiated` when a new case is started. |
| FR-E05-048 | US-KYC-011,012 | The system MUST emit metric counters `kyc.case.approved` and `kyc.case.rejected` with label `verification_method` (`automated` or `manual`). |
| FR-E05-049 | US-KYC-009 | The system MUST emit metric counter `kyc.provider.call` with labels `provider`, `outcome` (`pass`/`fail`/`manual_review_required`/`error`). |
| FR-E05-050 | US-KYC-016 | The system MUST emit metric counter `kyc.duplicate_identity_detected` when a duplicate national ID hash is found. |

---

## 9. KYC Case State Machine

```
                ┌─────────────────────────────────────────────────────┐
                │                                                     │
         POST /v1/kyc/cases                                    reverify
                │                                                     │
                ▼                                                     │
         [ initiated ] ──────────── upload docs, capture info ────── │
                │                                                     │
          submit                                                      │
                │                                                     │
                ▼                                                     │
      [ documents_submitted ] (transition within submit flow)        │
                │                                                     │
          (submit gate)                                               │
                │                                                     │
                ▼                                                     │
         [ under_review ] ◄─── automated verify (manual_review_required / fail)
                │         ◄─── auto_verify_enabled = false
                │
        ┌───────┼──────────────────────────┐
        │       │                          │
      approve  reject         request_info
        │       │                          │
        ▼       ▼                          ▼
   [approved] [rejected]           [needs_info]
        │       │                          │
   expires   resubmit               resubmit
        │       │                          │
        ▼       └──────────┬───────────────┘
   [expired]               │
        │             [initiated] (attempt_number++)
    reverify               │
        │              (restart flow)
        └──────────────────┘
```

### Valid Transitions

| From | To | Trigger |
|---|---|---|
| `initiated` | `documents_submitted` | Internal: first document uploaded |
| `initiated` / `documents_submitted` | `under_review` | `POST /submit` |
| `under_review` | `approved` | Manual approve or auto-verify pass |
| `under_review` | `rejected` | Manual reject |
| `under_review` | `needs_info` | Manual request-info |
| `rejected` / `needs_info` | `initiated` | `POST /resubmit` |
| `approved` | `expired` | Scheduled job |
| `approved` / `expired` | `initiated` | `POST /reverify` |

---

## 10. Key Entities

### 10.1 KycCase (`kyc.kyc_cases`)

| Attribute | Type | Constraints |
|---|---|---|
| `id` | UUID v7 | Primary key |
| `user_id` | UUID | FK → `identity.users.id`, not null |
| `status` | varchar(30) | Enum: `initiated` / `documents_submitted` / `under_review` / `approved` / `rejected` / `needs_info` / `expired` |
| `attempt_number` | integer | Not null, default 1 |
| `submitted_at` | timestampTz | Nullable |
| `reviewed_at` | timestampTz | Nullable |
| `reviewer_id` | UUID | Nullable, FK → `identity.users.id` |
| `rejection_reason` | text | Nullable |
| `info_request_note` | text | Nullable |
| `verified_at` | timestampTz | Nullable |
| `expires_at` | timestampTz | Nullable |
| `idempotency_key` | varchar(255) | Nullable, unique index |
| `created_at` / `updated_at` | timestampTz | Not null |

Index: `(user_id, status)`, `(status, expires_at)` (for expiry job), `(idempotency_key)`.

### 10.2 KycPersonalInfo (`kyc.kyc_personal_info`)

| Attribute | Type | Constraints |
|---|---|---|
| `id` | UUID v7 | Primary key |
| `kyc_case_id` | UUID | FK → `kyc.kyc_cases.id`, not null, unique |
| `full_legal_name` | varchar(255) | Not null |
| `date_of_birth` | date | Not null |
| `nationality` | char(2) | Not null, ISO 3166-1 alpha-2 |
| `national_id_type` | varchar(30) | Enum: `national_id` / `passport` / `drivers_licence` |
| `national_id_hash` | varchar(64) | Not null, HMAC-SHA256 hex; indexed for duplicate detection |
| `address_line_1` | varchar(255) | Not null |
| `address_line_2` | varchar(255) | Nullable |
| `city` | varchar(100) | Not null |
| `state_province` | varchar(100) | Nullable |
| `postal_code` | varchar(20) | Not null |
| `country_code` | char(2) | Not null, ISO 3166-1 alpha-2 |
| `created_at` / `updated_at` | timestampTz | Not null |

Index: `(national_id_hash)` — used for duplicate detection query.

### 10.3 KycDocument (`kyc.kyc_documents`)

| Attribute | Type | Constraints |
|---|---|---|
| `id` | UUID v7 | Primary key |
| `kyc_case_id` | UUID | FK → `kyc.kyc_cases.id`, not null |
| `document_type` | varchar(30) | Enum: `national_id` / `passport` / `drivers_licence` / `utility_bill` / `selfie` / `other` |
| `file_reference` | varchar(500) | Not null — opaque cloud storage key |
| `mime_type` | varchar(100) | Not null |
| `file_size_bytes` | integer | Not null |
| `expires_on` | date | Nullable |
| `validation_status` | varchar(20) | Enum: `pending` / `valid` / `invalid` |
| `validation_error` | varchar(255) | Nullable |
| `deleted_at` | timestampTz | Nullable — soft delete for superseded documents |
| `created_at` | timestampTz | Not null |

### 10.4 KycVerificationResult (`kyc.kyc_verification_results`)

| Attribute | Type | Constraints |
|---|---|---|
| `id` | UUID v7 | Primary key |
| `kyc_case_id` | UUID | FK → `kyc.kyc_cases.id`, not null |
| `provider_name` | varchar(100) | Not null |
| `provider_reference` | varchar(255) | Nullable |
| `outcome` | varchar(30) | Enum: `pass` / `fail` / `manual_review_required` / `error` |
| `confidence_score` | decimal(5,4) | Nullable |
| `raw_response_hash` | varchar(64) | Not null — HMAC-SHA256 of serialized provider response |
| `created_at` | timestampTz | Not null |

---

## 11. API Contracts

### Error Codes

| Code | HTTP Status | Condition |
|---|---|---|
| `KYC_CASE_ALREADY_OPEN` | 409 | Actor already has an open KYC case |
| `KYC_ALREADY_APPROVED` | 409 | Actor's KYC is already approved |
| `KYC_CASE_NOT_FOUND` | 404 | Case does not exist or does not belong to actor |
| `CASE_NOT_EDITABLE` | 422 | Case is in a status that does not permit the requested mutation |
| `INVALID_CASE_STATUS` | 422 | The requested transition is not valid from the current status |
| `CASE_INCOMPLETE` | 422 | Required documents or personal info missing at submission time |
| `DUPLICATE_IDENTITY_DETECTED` | 409 | National ID hash matches an existing approved case |
| `INVALID_DOCUMENT_FORMAT` | 400 | Document MIME type not in `kyc.accepted_mime_types` |
| `DOCUMENT_TOO_LARGE` | 400 | Document exceeds `kyc.max_document_size_bytes` |
| `DOCUMENT_EXPIRED` | 422 | Document `expires_on` date is in the past |
| `REJECTION_REASON_REQUIRED` | 400 | Rejection reason field is empty |
| `INFO_REQUEST_NOTE_REQUIRED` | 400 | Info request note field is empty |

### Response Envelope

All E05 API responses use the platform-standard envelope:

- **Success**: `{ "success": true, "data": { ... }, "meta": { ... } }`
- **Error**: `{ "success": false, "error": { "code": "...", "message": "...", "trace_id": "..." } }`

---

## 12. Database Architecture

All E05 tables are created in the `kyc` schema (separate bounded context from `identity`). Database connection: `kyc`.

### Migration Order

1. Create `kyc.kyc_cases`
2. Create `kyc.kyc_personal_info` (FK → `kyc_cases`)
3. Create `kyc.kyc_documents` (FK → `kyc_cases`)
4. Create `kyc.kyc_verification_results` (FK → `kyc_cases`)
5. Seed KYC permissions into `identity.permissions` and assign to `admin` role
6. Seed KYC configuration defaults into `platform.configuration_values`

---

## 13. Integration with Existing Components

### 13.1 E04 Permission Middleware
All KYC endpoints use `identity.require-permission:{kyc.case.*}` middleware registered by E04. No new middleware infrastructure is needed.

### 13.2 AuditService (Platform)
E05 calls `AuditService::record()` for all state transitions. IP addresses are hashed via `PiiMasker::hashIp()` consistent with E04 practice.

### 13.3 Domain Events
`kyc.case.approved`, `kyc.case.expired`, and `kyc.case.reverification-triggered` are emitted as reliable domain events. The event infrastructure follows the platform's event/outbox pattern (established in E01 and to be formalized in E27). In E05, events are written to an outbox table as part of the same database transaction as the state transition.

### 13.4 File Storage
Document files are stored via a `FileStorageContract` interface. The concrete implementation (local, S3, Azure Blob, etc.) is resolved via platform configuration. E05 only stores the returned opaque `file_reference`.

### 13.5 KycVerificationProvider Interface
```
KycVerificationProvider
  - verify(KycCase $case): VerificationOutcome
      → VerificationOutcome { outcome, confidence_score, provider_reference, raw_response }
```
The concrete provider is bound in `KycServiceProvider` based on `kyc.provider` configuration.

---

## 14. Security Requirements

| ID | Requirement |
|---|---|
| SEC-E05-001 | National ID numbers MUST be stored exclusively as HMAC-SHA256 hashes using `PiiMasker`. Plaintext national IDs MUST NOT appear in any database column, log, or audit field. |
| SEC-E05-002 | Document file references are opaque storage keys. No file system path, signed URL, or raw file content MUST be stored in the database or returned in API responses beyond what is needed by the file storage abstraction. |
| SEC-E05-003 | The external provider's raw response MUST NOT be stored. Only its HMAC-SHA256 hash is persisted. |
| SEC-E05-004 | IP addresses in KYC audit records MUST be stored as `HMAC-SHA256(platform_secret, ip_address)`, consistent with SEC-E04-006. |
| SEC-E05-005 | A Customer MUST NOT be able to view or mutate another user's KYC case. Cross-user access MUST return HTTP 404, not 403, to avoid confirming existence. |
| SEC-E05-006 | KYC approval (`POST /v1/kyc/cases/{id}/approve`) requires the `kyc.case.approve` permission, which is separate from `kyc.case.review`. This supports maker-checker enforcement independent of whether TBD-E05-004 mandates it. |
| SEC-E05-007 | Duplicate identity detection results MUST NOT reveal which user holds the conflicting approved case. The `DUPLICATE_IDENTITY_DETECTED` error MUST contain no reference to the blocking user's identity. |
| SEC-E05-008 | File upload endpoints MUST validate MIME type server-side (not trust the `Content-Type` header alone). Magic-byte inspection or equivalent MUST be used when the platform file storage layer does not provide it. |

---

## 15. Test Requirements

### 15.1 Unit Tests

- `KycStateMachine::transition()` enforces all valid and invalid transitions
- `PiiMasker::hashNationalId()` produces the same hash for the same input and platform secret
- `KycVerificationProvider` contract: mock returns all three outcome types correctly
- `KycCaseService::startCase()` rejects duplicate open case

### 15.2 API / Feature Tests

- `POST /v1/kyc/cases`: success (201), already open (409), already approved (409), idempotent replay (201)
- `PUT /v1/kyc/cases/{id}/personal-information`: success, duplicate national ID (409), case not editable (422)
- `POST /v1/kyc/cases/{id}/documents`: valid upload, invalid format (400), oversized (400), expired (422)
- `POST /v1/kyc/documents/validate-format`: valid, invalid, too large (stateless — no DB records)
- `POST /v1/kyc/cases/{id}/submit`: success, incomplete (422), idempotent (200)
- `POST /v1/kyc/cases/{id}/approve`: success (Compliance Officer), unauthorized Customer (403), wrong status (422)
- `POST /v1/kyc/cases/{id}/reject`: success with reason, missing reason (400)
- `POST /v1/kyc/cases/{id}/request-info`: success, missing note (400)
- `POST /v1/kyc/cases/{id}/resubmit`: success increments attempt_number, idempotent
- `GET /v1/kyc/cases/my`: own case returned, no case (404)
- All endpoints return 403 for wrong-permission actors and 401 for unauthenticated

### 15.3 Security Tests

- Cross-user case access returns 404
- Customer cannot call Compliance Officer endpoints (403)
- National ID hash stored — plaintext never in DB
- Provider raw response hash stored — raw response never in DB

### 15.4 Integration Tests

- Full happy-path flow: start → personal info → document → submit → auto-verify pass → approved → `kyc.case.approved` event emitted
- Manual review path: submit → auto-verify returns `manual_review_required` → Compliance Officer approves
- Rejection and resubmission: reject → resubmit → attempt_number = 2 → re-approve
- Duplicate national ID detection: approve case A → attempt personal info on case B with same ID → 409
- Idempotency: start-kyc with same key twice → one case created

### 15.5 Concurrency Tests

- Concurrent `POST /v1/kyc/cases` with same actor: exactly one succeeds (409 for duplicates)
- Concurrent submit + approve: state machine prevents invalid double-transitions

---

## 16. Edge Cases

| # | Scenario | Expected Behavior |
|---|---|---|
| EC-01 | Customer submits KYC but external provider is unreachable | Job retried per `kyc.provider_max_retries`; case stays `under_review`; structured error logged; Compliance Officer queue catches it for manual review |
| EC-02 | `kyc.auto_verify_enabled = false` but provider is configured | No provider call is made; all cases route to manual review queue |
| EC-03 | Compliance Officer attempts to approve a case they reviewed (same reviewer_id = approver) | Permitted in E05 unless TBD-E05-004 mandates separation; reviewed in `/speckit-clarify` after TBD resolution |
| EC-04 | National ID hash collision (cryptographic) | Treat as duplicate; the probability is negligible (HMAC-SHA256); log a structured warning for monitoring |
| EC-05 | `kyc:detect-expired` runs while a case is being reviewed | The expiry job and reviewer operate on different states (`approved` vs `under_review`); no conflict |
| EC-06 | Resubmit is called on a case already in `initiated` (after reverification) | HTTP 422 `INVALID_CASE_STATUS` — resubmit is only valid from `rejected` or `needs_info` |
| EC-07 | Document `expires_on` is provided as a non-date string | HTTP 400 with field validation error |
| EC-08 | Customer has a previously `approved` case that is now `expired` and tries `POST /v1/kyc/cases` | HTTP 409 is NOT returned (expired is not an open case); a new case can be started, OR the customer can use `/reverify` |
| EC-09 | `POST /v1/kyc/cases/{id}/submit` called when case is in `initiated` with no documents uploaded | HTTP 422 `CASE_INCOMPLETE` with list of required items |
| EC-10 | Duplicate document upload for same `document_type` | Previous document soft-deleted; new document replaces it; case progresses normally |
| EC-11 | `kyc.validity_period_days` is TBD — migration must not hard-code it | Configuration entry seeded with `is_tbd = true`. TBD guard behavior: (a) on approval, `expires_at` is set to `null` with a `kyc.config.tbd_active` warning log — the case still transitions to `approved`; (b) `kyc:detect-expired` exits early (returns `[0,0,0]`) without touching any cases. Cases are NOT blocked from approving; only expiry-based lifecycle operations are deactivated until the TBD is resolved. |

---

## 17. Configuration Defaults

E05 seeds the following entries via idempotent migration (insert if not exists):

| Key | Default Value | Type | TBD? | Description |
|---|---|---|---|---|
| `kyc.validity_period_days` | **TBD** | integer | Yes — TBD-E05-002 | Days an approved KYC remains valid before expiry |
| `kyc.max_document_size_bytes` | **TBD** | integer | Yes — TBD-E05-002 | Maximum file size for document uploads |
| `kyc.accepted_mime_types` | **TBD** | string (JSON array) | Yes — TBD-E05-002 | Comma-separated or JSON array of accepted MIME types |
| `kyc.provider` | **TBD** | string | Yes — TBD-E05-001 | KYC provider identifier; maps to a bound `KycVerificationProvider` implementation |
| `kyc.auto_verify_enabled` | `false` | boolean | No | Whether to call provider automatically; defaults to `false` (manual review only) until provider TBD is resolved |
| `kyc.provider_max_retries` | `3` | integer | No | Maximum number of provider call retries before giving up |

All TBD configuration entries MUST be seeded with `is_tbd = true`. The system MUST refuse to use a TBD value for business logic; it MUST log a configuration warning and route the case to manual review instead.

---

## 18. Unresolved Decisions (TBDs)

Per Constitution Principle 2, the items below MUST NOT be invented or assumed. They require explicit decisions before implementation of the affected stories.

### TBD-E05-001 — External KYC Verification Provider

**Question**: Which external KYC verification provider is the primary integration target?

**Context**: The system requires a `KycVerificationProvider` implementation for US-KYC-009. The interface is fully defined, but no concrete provider is named.

**Impact on E05**: The provider name, its API contract, authentication mechanism, and SDK/HTTP client must be known before US-KYC-009 can be implemented. US-KYC-009 MUST remain in BACKLOG/TBD state until this is resolved.

**Decision needed from**: Product / Legal.

---

### TBD-E05-002 — KYC Configuration Defaults

**Question**: What are the correct default values for `kyc.validity_period_days`, `kyc.max_document_size_bytes`, and `kyc.accepted_mime_types`?

**Context**: These drive expiry detection (US-KYC-017), document upload validation (US-KYC-003/004/005), and the config seed migration.

**Impact on E05**: Configuration seed migration cannot finalize these values; they are seeded as `is_tbd = true`. The expiry detection job (US-KYC-017) and automatic expiry of approved cases cannot run until `kyc.validity_period_days` is set.

**Decision needed from**: Product / Compliance / Legal.

---

### TBD-E05-003 — Mandatory vs Optional Document Types by Actor

**Question**: Which document types are mandatory vs optional for Customer KYC, and does this differ for Agent KYC?

**Context**: US-KYC-003 (identity document) is P0 and US-KYC-004 (supporting document) is P1. The completeness check in FR-E05-017 currently requires at least one identity document. Whether a utility bill or selfie is mandatory is undecided.

**Impact on E05**: The `CASE_INCOMPLETE` validation logic in `POST /v1/kyc/cases/{id}/submit` depends on this. Until resolved, only an identity document (`national_id`, `passport`, or `drivers_licence`) is treated as mandatory.

**Decision needed from**: Product / Compliance.

---

### TBD-E05-004 — Maker-Checker for KYC Approval

**Question**: Must the reviewer (`kyc.case.review`) and approver (`kyc.case.approve`) be different individuals for KYC approval? Or is it sufficient to hold the `kyc.case.approve` permission regardless of who reviewed?

**Context**: US-KYC-011 notes "maker-checker where configured". E04 has already separated `kyc.case.review` and `kyc.case.approve` permissions to support this. Whether enforcement is in E05 or deferred to E23 (Audit & Compliance) is undecided.

**Impact on E05**: If in E05, `reviewer_id != approver_actor_id` must be enforced in `POST /v1/kyc/cases/{id}/approve`. If deferred to E23, E05 only enforces the permission separation (already done).

**Decision needed from**: Product / Legal / Architecture.

---

## 19. Dependencies on Previous Epics

| Dependency | Source | Nature |
|---|---|---|
| `identity.users` table | E01 | `kyc_cases.user_id` FK; authentication |
| `IdentityModuleContract::hasPermission()` | E04 | Used by `identity.require-permission` middleware on all KYC routes |
| `identity.require-permission` middleware | E04 | Applied to all KYC endpoints |
| `AuditService::record()` | Platform Foundation | E05 writes audit events for all state transitions |
| `PiiMasker::hashIp()`, `hashNationalId()` | Platform Foundation / E04 | IP hashing in audit records; national ID hashing |
| `IdempotencyMiddleware` | E01 | Applied to `POST /v1/kyc/cases` and `POST /v1/kyc/cases/{id}/submit` |
| `ConfigurationService` | Platform Foundation | E05 reads all `kyc.*` config values |
| `identity.permissions` / `identity.role_permissions` | E04 | E05 seeds 6 new permissions and assigns them to `admin` role |

---

## 20. Success Criteria

| ID | Criterion | How Verified |
|---|---|---|
| SC-E05-001 | A Customer with a completed KYC case can have it approved without any national ID plaintext appearing in any database record or log | Security test + DB assertion: `national_id_hash` column contains a 64-char hex string; no `national_id_number` column exists |
| SC-E05-002 | A duplicate national ID hash attempt is blocked with `DUPLICATE_IDENTITY_DETECTED` before any data is persisted | Integration test: approve case A, submit personal info for case B with same ID → 409, no `kyc_personal_info` record for case B |
| SC-E05-003 | The full KYC happy-path (start → personal info → document upload → submit → approval) completes and emits `kyc.case.approved` domain event | Integration test: trace event in outbox table |
| SC-E05-004 | A Customer cannot access or mutate another user's KYC case | Security test: cross-user access returns HTTP 404, not 403 |
| SC-E05-005 | All KYC endpoints return 401 for unauthenticated requests and 403 for wrong-permission actors | Feature test suite |
| SC-E05-006 | `kyc:detect-expired` transitions approved cases past their `expires_at` to `expired` and emits domain events | Integration test with backdated `expires_at` |
| SC-E05-007 | All state-changing KYC operations produce audit records in `platform.audit_events` | Audit verification per operation in feature tests |
| SC-E05-008 | Idempotent start and submit endpoints return the same response on replay without creating duplicates | Feature test: replay same `Idempotency-Key` twice |
