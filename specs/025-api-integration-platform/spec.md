# Feature Specification: E25 — API & Integration Platform

**Feature Branch**: `025-api-integration-platform`

**Created**: 2026-10-01

**Status**: Draft

**Input**: User description: "E25 api-integration-platform"

## Overview

The API & Integration Platform provides the Wallet Ecosystem's cross-cutting integration backbone. It encompasses two major areas: (1) an API gateway layer that enforces authentication, authorization, rate limiting, payload validation, correlation tracking, and structured logging for every inbound request; and (2) an outbound provider integration layer that connects the platform to external OTP, SMS, email, KYC, payment, bank, and mobile-money services, plus an inbound webhook processing pipeline that reliably receives, validates, de-duplicates, and retries callbacks from those providers.

All 20 backlog stories (US-API-001 through US-API-020) are in scope for this epic.

## Clarifications

### Session 2026-10-01

- Q: Should calls to external providers (OTP, payment, KYC, bank, mobile-money) execute synchronously within the HTTP request or via a background queue? → A: Asynchronous via background queue; the HTTP response returns immediately with `202 Accepted` plus a job/reference ID; provider webhooks or polling closes the loop. **Note:** this `202` applies to *outbound* provider dispatch only. The inbound webhook receiver (`POST /v1/webhooks/{slug}`) correctly returns `200 OK` (industry standard — prevents provider re-delivery while the platform is still processing). For OTP dispatch specifically, if the queue itself is unavailable the response MUST be `503`, not `202`; once queued, `202` is returned — provider delivery failures are surfaced as job failure events, not as a retraction of the original `202`.
- Q: What credential mechanism should API clients use to authenticate their requests? → A: API key + secret pair; requests authenticated as `Authorization: Bearer <base64(key:secret)>` or HMAC-SHA256 signed; secrets stored hashed (never in plaintext); keys are rotatable without downtime.
- Q: Does this epic include admin portal screens for managing API clients (create, list, rotate secret, activate/deactivate)? → A: Yes — full admin UI for API client lifecycle management is in scope.
- Q: What is the complete set of states a webhook event moves through from receipt to final outcome? → A: Five-state machine — `received → processing → processed | failed → dead_letter`; `failed` events are retried back to `processing`; `dead_letter` is terminal unless an operator manually re-queues.
- Q: Should rate limiting be enforced only per API client, or also per source IP address? → A: Per API client + per source IP — two independent windows; either can trigger a `429`; per-endpoint granularity deferred.

## User Scenarios & Testing

### User Story 1 — API Gateway, Cross-Cutting Concerns & Client Management (Priority: P1)

An integration client (a third-party system, a mobile app backend, or an internal service) calls a Wallet Ecosystem API endpoint. Before any business logic executes, the platform must verify the caller's identity and permissions, reject or throttle requests that exceed rate limits, validate the request payload against the declared contract, assign a unique correlation ID for end-to-end tracing, and record a structured log entry for both the request and the response. Platform administrators must also be able to manage API clients (create, list, rotate secrets, activate/deactivate) through the admin portal.

This story covers US-API-001 (authenticate), US-API-002 (authorize), US-API-003 (rate limit), US-API-004 (validate payload), US-API-005 (generate correlation ID), US-API-006 (log request), US-API-007 (log response), and admin UI for API client lifecycle management.

**Why this priority**: Every other story in this epic and every consumer of the platform API depends on a secure, observable gateway. No integration can be trusted without it, and administrators need the portal to onboard clients without direct database access.

**Independent Test**: Can be fully tested by (a) submitting API requests with various credential states (valid, missing, expired, insufficient permission), payloads (valid, missing fields, oversized), and traffic volumes (within and beyond rate limits), verifying the correct HTTP status code, error envelope, rate-limit headers, correlation ID header, and audit log entry for each combination; and (b) using the admin portal to create a new API client, retrieve its credentials, rotate its secret, deactivate and reactivate it.

**Acceptance Scenarios**:

1. **Given** a registered API client with valid credentials and the required permission, **When** the client submits a well-formed request, **Then** the gateway authenticates and authorizes the request, assigns a correlation ID, validates the payload, logs the request, forwards to business logic, and logs the response — all within the rate-limit window.
2. **Given** an API client with expired or missing credentials, **When** the client submits a request, **Then** the gateway returns `401 Unauthorized` with a standard error envelope and records a failed-authentication audit event without forwarding the request.
3. **Given** a valid client that lacks the required permission, **When** the client submits a request, **Then** the gateway returns `403 Forbidden` with a standard error envelope and records a failed-authorization audit event.
4. **Given** a valid client that has exceeded its rate-limit quota, **When** the client submits another request within the window, **Then** the gateway returns `429 Too Many Requests` with `Retry-After` header and does not forward the request.
5. **Given** a valid client within limits, **When** the client submits a request with a missing required field or an oversized payload, **Then** the gateway returns `422 Unprocessable Entity` with a field-level validation error envelope before business logic is invoked.
6. **Given** any inbound request, **When** no correlation ID is present in the request headers, **Then** the gateway generates a UUID v7 correlation ID and injects it into the request context and response headers.
7. **Given** any completed request (success or failure), **When** the response is sent, **Then** both the structured request log and the structured response log entries are written, each containing the correlation ID, actor reference, endpoint, HTTP status, and elapsed time — with no sensitive credentials or PINs in plaintext.
8. **Given** an administrator with the required permission, **When** they create a new API client in the admin portal, **Then** the client is assigned a unique API key and a one-time-visible secret (shown once at creation, stored only as a hash), and the creation event is audit-logged.
9. **Given** an administrator, **When** they rotate the secret for an existing API client, **Then** a new secret is generated and shown once, the old secret is immediately invalidated, and the rotation event is audit-logged.
10. **Given** an administrator, **When** they deactivate an API client, **Then** all subsequent requests from that client are rejected with `401` until the client is reactivated.

---

### User Story 2 — External Provider Integrations (Priority: P2)

Operations staff and platform administrators need to connect the Wallet Ecosystem to external service providers: OTP delivery, SMS notifications, email delivery, KYC identity verification, payment gateways, bank transfer rails, and mobile-money networks. The platform must support configuring one or more providers per service type and switching between them without service disruption.

This story covers US-API-008 (OTP provider), US-API-009 (SMS provider), US-API-010 (email provider), US-API-011 (KYC provider), US-API-012 (payment provider), US-API-013 (bank integration), and US-API-014 (mobile-money provider).

**Why this priority**: Provider integrations unlock core product functionality: identity verification (KYC), communications (OTP/SMS/email), and money movement (payment/bank/mobile-money). They are consumed by nearly every other epic.

**Independent Test**: Can be fully tested by sending a controlled request through each provider adapter (using sandbox/test credentials) and verifying: the provider receives the correct payload, the platform records the outcome, a failure from the provider is surfaced as a structured error with retry eligibility, and sensitive credentials are never logged.

**Acceptance Scenarios**:

1. **Given** a configured OTP provider with valid credentials, **When** the platform requests OTP delivery for a phone number, **Then** the request returns `202 Accepted` with a job reference ID, the OTP dispatch is queued, the provider dispatches the OTP asynchronously, and the delivery attempt outcome is recorded when the queue job completes.
2. **Given** a provider that returns an error (e.g., invalid number, quota exceeded), **When** the platform calls the provider, **Then** the error is captured, mapped to a standard platform error code, and the caller receives a structured error response with a retry-eligible flag where applicable.
3. **Given** a payment provider integration configured with sandbox credentials, **When** a funding or withdrawal request is submitted, **Then** the adapter correctly maps the platform request to the provider's contract, transmits it, and maps the response back to the platform's transaction state model — without directly mutating wallet balances.
4. **Given** a KYC provider integration, **When** a customer identity verification is requested, **Then** the KYC adapter dispatches the identity documents asynchronously and returns `202 Accepted` with a job reference ID; when the provider's verification-result webhook arrives at the E25 webhook receiver, it is dispatched to the KYC module (E05) domain handler, which stores the verification outcome linked to the customer's KYC record — E25 does not write to KYC records directly.
5. **Given** any provider credential configuration, **When** an administrator updates the credentials or switches to an alternate provider, **Then** subsequent requests use the new configuration without requiring a deployment, and the change is audit-logged.

---

### User Story 3 — Webhook Processing Pipeline (Priority: P3)

External providers asynchronously notify the platform of transaction outcomes, KYC results, payment confirmations, and other events by posting callbacks (webhooks) to the platform's public endpoint. The platform must securely receive these callbacks, verify their authenticity, de-duplicate repeated deliveries, persist the raw event, dispatch it to the appropriate domain handler, and retry processing if the handler fails — while making webhook failure trends visible to operators.

This story covers US-API-015 (receive webhook), US-API-016 (validate signature), US-API-017 (idempotency), US-API-018 (retry), US-API-019 (store event), and US-API-020 (monitor failures).

**Why this priority**: Webhook reliability is essential for payment reconciliation, KYC completion, and transfer confirmation. A failed or duplicate webhook can cause incorrect financial state if not handled correctly.

**Independent Test**: Can be fully tested by simulating webhook POSTs (valid, tampered, replayed, malformed) and verifying: signature validation outcome, idempotency de-duplication, event storage, domain handler dispatch, retry scheduling after handler failure, and failure-rate metrics visible in the monitoring surface.

**Acceptance Scenarios**:

1. **Given** a provider webhook with a valid HMAC signature, **When** the platform receives it, **Then** the signature is verified, the event is stored with a `received` status, and processing is dispatched to the correct domain handler.
2. **Given** a webhook with an invalid or missing signature, **When** the platform receives it, **Then** the request is rejected with `401 Unauthorized`, the event is not stored, and a security audit entry is recorded.
3. **Given** a webhook that has already been received and processed (same provider event ID), **When** the provider re-delivers it, **Then** the platform returns `200 OK` (to stop retries) but does not re-process the event, maintaining idempotency.
4. **Given** a valid webhook whose domain handler throws an exception during processing, **When** the failure occurs, **Then** the event status transitions from `processing` to `failed`, a retry is scheduled with exponential back-off; if the retry succeeds the event reaches `processed`; if the maximum retry count is exceeded the event transitions to `dead_letter` and an alert is emitted.
5. **Given** an operator reviewing webhook health, **When** they view the monitoring surface, **Then** they can see the count of failed webhooks by provider and event type, the failure rate over time, and can trigger a manual re-process for individual events.
6. **Given** any stored webhook event, **When** it is retrieved for audit purposes, **Then** the raw payload, signature, source IP, receipt timestamp, processing status, and processing history are all available.

---

### Edge Cases

- What happens when all configured providers for a service type are unavailable simultaneously?
- How does the platform behave if a correlation ID is injected by the caller (potential spoofing or tracing poisoning)?
- How are provider credential rotation failures handled mid-request?
- What happens when a webhook payload exceeds the maximum accepted size?
- What happens when the webhook event storage fails (disk/DB error) after signature verification?
- How does the platform prevent webhook endpoint enumeration by unauthorized callers?
- What is the maximum retry count and back-off ceiling for failed webhook processing, and what happens after exhaustion?

## Requirements

### Functional Requirements

**API Gateway (US-API-001 to US-API-007)**

- **FR-001**: The platform MUST authenticate every inbound API request using an API key + secret pair; requests MUST supply credentials as `Authorization: Bearer <base64(key:secret)>`; secrets MUST be stored hashed (never in plaintext) and MUST be rotatable without service interruption. HMAC-SHA256 request signing is out of scope for this epic and deferred to a future hardening epic.
- **FR-002**: The platform MUST enforce permission-based authorization on every API endpoint; requests lacking the required permission MUST be rejected with a `403` response.
- **FR-003**: The platform MUST enforce two independent rate-limit windows per request: one per API client and one per source IP address; if either window is exceeded the request MUST be rejected with `429` and a `Retry-After` header indicating when that window resets; both window quotas MUST be centrally configurable.
- **FR-004**: The platform MUST validate request payloads against the declared endpoint contract; invalid payloads MUST return `422` with field-level error details before business logic is invoked.
- **FR-005**: The platform MUST assign a UUID v7 correlation ID to every request; if the caller supplies one, it MUST be validated for format and then propagated; if absent, one MUST be generated.
- **FR-006**: The platform MUST write a structured request log entry on receipt of every request, containing at minimum: correlation ID, client identifier, endpoint, HTTP method, timestamp, and user agent — with no credentials, OTPs, or PINs in plaintext.
- **FR-007**: The platform MUST write a structured response log entry on completion of every request, containing: correlation ID, HTTP status code, elapsed time, and outcome classification.

**API Client Management (Admin UI)**

- **FR-007a**: Administrators with the `integrations.manage` permission MUST be able to create a new API client via the admin portal, specifying its name, assigned permissions, and rate-limit configuration. (Implementation note: the single `integrations.manage` permission guards all API client and provider management operations; the more granular `integrations.clients.write` / `integrations.read` split is deferred to a future permission-hardening pass.)
- **FR-007b**: On client creation, the platform MUST generate a unique API key and a cryptographically random secret; the secret MUST be displayed once in plaintext at creation time and stored only as a hashed value thereafter.
- **FR-007c**: Administrators MUST be able to rotate an API client's secret; the old secret MUST be invalidated immediately upon rotation; the new secret MUST be shown once in plaintext.
- **FR-007d**: Administrators MUST be able to activate or deactivate an API client; a deactivated client's requests MUST be rejected with `401` until reactivated.
- **FR-007e**: The admin portal MUST display a paginated list of all API clients with their name, API key prefix (first 8 characters), status, assigned permissions, and last-used timestamp.
- **FR-007f**: All API client management actions (create, rotate, activate, deactivate) MUST be audit-logged with the acting administrator's identity and timestamp.

**Provider Integrations (US-API-008 to US-API-014)**

- **FR-008**: The platform MUST support integration with at least one OTP delivery provider; OTP dispatch calls MUST be executed asynchronously via a background queue; the active provider MUST be configurable without code deployment. E25 owns the `SmsChannelInterface` runtime binding and the `IntegrationRegistry` that resolves the active SMS/OTP provider. The `503` (queue unavailable) / `202` (queued) response contract is owned by **E01** (the Central Identity & Authentication epic) at the point of dispatching the OTP job — E01 wraps the channel call with queue-availability detection and returns `503` if the queue cannot accept the job. E25's responsibility is ensuring the channel binding resolves to an active provider and throws a detectable exception when none is available; provider delivery failures (e.g., invalid number, quota exceeded) are recorded as job failure events by the E01 queue job, not by E25.
- **FR-009**: The platform MUST support integration with at least one SMS provider; SMS dispatch calls MUST be executed asynchronously; provider selection and credentials MUST be centrally configurable.
- **FR-010**: The platform MUST support integration with at least one email delivery provider; email dispatch calls MUST be executed asynchronously; provider selection and credentials MUST be centrally configurable.
- **FR-011**: The platform MUST support integration with at least one KYC identity-verification provider; KYC submission calls MUST be dispatched asynchronously; provider responses MUST be mapped to the platform's KYC verification status model via webhook callback or polling.
- **FR-012**: The platform MUST support integration with at least one payment provider; provider calls MUST be dispatched asynchronously via a background queue; the initiating HTTP request returns `202 Accepted` with a job reference ID; payment adapter responses MUST map to the platform's transaction state machine without directly mutating wallet balances.
- **FR-013**: The platform MUST support integration with at least one bank transfer rail; bank adapter calls MUST be dispatched asynchronously; requests and responses MUST be idempotent.
- **FR-014**: The platform MUST support integration with at least one mobile-money provider; provider calls MUST be dispatched asynchronously; the adapter MUST handle provider-specific error codes and surface retry-eligible failures.
- **FR-015**: Every provider integration MUST record the outbound request reference, provider response, timestamp, and outcome in a durable store for auditability.
- **FR-016**: Changing a provider configuration (credentials, endpoint, active provider) MUST be audit-logged and MUST NOT require a deployment.

**Webhook Processing (US-API-015 to US-API-020)**

- **FR-017**: The platform MUST expose a public webhook endpoint for each integrated provider; the endpoint MUST validate the provider's HMAC or signature scheme before accepting the payload.
- **FR-018**: A webhook with an invalid or missing signature MUST be rejected with `401` and MUST NOT be stored or processed.
- **FR-019**: The platform MUST de-duplicate webhook events using a provider-supplied event ID; a duplicate delivery MUST return `200` without re-processing.
- **FR-020**: Every accepted webhook event MUST be stored durably in `received` status with its raw payload, signature, source IP, and receipt timestamp before processing begins.
- **FR-021**: Webhook processing MUST be dispatched asynchronously; the event status MUST be advanced to `processing` when the handler is dispatched; the HTTP acknowledgement (`200`) MUST be returned to the provider before handler execution completes.
- **FR-022**: If a webhook domain handler fails, the event status MUST be set to `failed` and a retry scheduled with exponential back-off; after the configured maximum retry attempts the event MUST be moved to `dead_letter` status and an alert emitted; an operator MUST be able to manually re-queue a `dead_letter` event back to `received` for reprocessing.
- **FR-023**: Operators MUST be able to view webhook failure counts and failure rates by provider and event type, see all events in `failed` and `dead_letter` status, and trigger manual re-queue for individual `dead_letter` events.

### Key Entities

- **ApiClient**: Represents a registered external or internal system authorized to call the platform API; holds a public API key, a hashed secret (never stored in plaintext), rate-limit configuration, assigned permissions, active/inactive status, and rotation history.
- **Integration**: A configured connection to a specific external provider instance; holds provider type, credentials reference (not raw secrets), endpoint, and active/inactive status.
- **WebhookEvent**: A received provider callback; holds provider identity, event type, raw payload, signature, receipt timestamp, retry count, and processing status. State machine: `received → processing → processed | failed → dead_letter`. Transitions: `received → processing` on dispatch; `processing → processed` on handler success; `processing → failed` on handler exception; `failed → processing` on retry attempt; `failed → dead_letter` when retry count exceeds the configured maximum. `dead_letter` is terminal; operators may manually re-queue a dead-letter event back to `received`.
- **ProviderCallLog**: A record of an outbound call to an external provider; holds integration reference, request summary, provider response code, outcome, and elapsed time.
- **RateLimitWindow**: A sliding-window counter keyed by either API client ID or source IP address; holds the window key, request count, window start time, and quota ceiling. Two windows are evaluated per request (client window and IP window); either exceeding its quota blocks the request.

## Success Criteria

### Measurable Outcomes

- **SC-001**: Every API request that reaches the platform either receives a business response or a structured error envelope — no unhandled exceptions are surfaced to callers.
- **SC-002**: The gateway layer adds no more than 20 ms of latency (p99) to any request under normal operating load.
- **SC-003**: Rate limiting correctly throttles clients exceeding their quota with zero false positives (valid clients within quota are never blocked).
- **SC-004**: 100% of accepted webhook events are stored durably before their processing status is reported back to the provider.
- **SC-005**: Duplicate webhook deliveries (same provider event ID) are de-duplicated with no double-processing in at least 99.9% of cases under concurrent delivery scenarios.
- **SC-006**: Failed webhook processing is retried automatically; operators are alerted within 5 minutes of a webhook reaching dead-letter state.
- **SC-007**: Provider configuration changes (credentials, active provider) take effect for new requests within 60 seconds of being saved, with no service interruption.
- **SC-008**: No provider credential, API key, OTP, or PIN appears in any log entry or audit record in plaintext.
- **SC-009**: All 20 epic stories (US-API-001 through US-API-020) pass their defined QA scenarios, including happy-path, validation failure, unauthorized access, idempotency, and audit-event verification tests.

## Assumptions

- The platform already has a centralized platform configuration service (from E28/Configuration Management) for storing and retrieving provider credentials and integration settings; if not yet available, a safe stub with admin-only write access is acceptable.
- Provider selection for each service type (OTP, SMS, email, KYC, payment, bank, mobile-money) remains TBD per constitution Principle 2. This epic implements the adapter contract framework (`ProviderAdapterInterface`, `ProviderWebhookContract`, `IntegrationRegistry`) and runtime channel bindings for SMS and email (via `SmsChannelInterface` / `EmailChannelInterface`). Concrete adapter implementations for KYC, payment, bank, and mobile-money are owned by their respective consuming epics (E05, E08, E09, E10) and registered in the E25 service container at runtime — E25 does not own those implementations.
- Webhook signature schemes vary by provider; each adapter is responsible for implementing the correct scheme (HMAC-SHA256 is the assumed default unless a provider mandates otherwise).
- Rate-limit policies (quota values, window sizes) are configurable through the administration surface; no hard-coded values.
- The correlation ID header name follows the existing convention already established in earlier epics (`X-Correlation-ID` — all-caps ID, consistent with E06–E22 controllers and OpenAPI specs).
- Provider sandbox/test credentials are used for development and CI; production credentials are secrets-managed and never committed to source control.
- The webhook public endpoint URL pattern follows `/v1/webhooks/{provider-slug}`; providers must be pre-registered before their webhooks are accepted.
- Mobile application changes are out of scope for this epic; provider integrations are consumed by the backend API layer only.
