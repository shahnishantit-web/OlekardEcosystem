# Spec Quality Checklist — E01 Central Identity & Authentication

## Problem & Scope
- [x] Problem statement clearly defines what is missing without this feature
- [x] Scope explicitly names what is IN and OUT of scope
- [x] No wallet, ledger, KYC, financial transaction logic included
- [x] RBAC management explicitly deferred to E04
- [x] 38 approved stories accounted for — no invented stories

## Actors & Journeys
- [x] All actors defined (Customer, Agent, Admin, Enterprise, System Service, Risk Engine)
- [x] System Service actor updated: JWT service-identity token (ES256, audience-restricted, time-bounded) issued by E01
- [x] Three actor types defined: USER, SERVICE, SYSTEM — all carried in token `actor_type` claim
- [x] User journeys cover all major flows (registration, login, step-up, lockout, PIN management, MFA)
- [x] Edge cases and failure journeys included

## Business Rules
- [x] Configurable values referenced from platform configuration (not hard-coded)
- [x] GAP-027 resolved: step-up threshold = 10000 BWP, runtime-configurable
- [x] OTP non-disclosure rule stated
- [x] PIN hash requirement stated
- [x] Refresh token rotation and reuse detection stated
- [x] No identifier disclosure in auth errors stated
- [x] Default role assignment boundary (E01 uses code CUSTOMER, E04 owns definitions) stated
- [x] ConfigurationService abstraction required (BR-018): no direct SQL to configuration_values from domain
- [x] Idempotent seed rule stated (BR-017): seed must not overwrite existing values
- [x] Configuration authorization permissions stated (BR-019)
- [x] Configuration validation with security minimums stated (BR-020)
- [x] Configuration audit requirements stated (BR-021)
- [x] Optimistic concurrency for config updates stated (BR-022)
- [x] Dual step-up trigger stated (BR-023): amount threshold OR risk-engine signal
- [x] Multi-currency step-up fail-safe stated (BR-024)
- [x] Progressive lockout stated (BR-025)
- [x] Secrets in secret manager only stated (BR-026)
- [x] HMAC-SHA256 IP protection in audit stated (BR-027)
- [x] Admin MFA defaults (TOTP + FIDO2 enabled, SMS disabled) stated (BR-028)

## Functional Requirements
- [x] One FR per story (FR-E01-001 to FR-E01-038)
- [x] Each FR traces to a story ID
- [x] No invented FRs beyond the 38 approved stories
- [x] Configuration management FRs added (FR-E01-039 to FR-E01-052) per second speckit-specify clarification
- [x] Each configuration FR traces to the clarification invocation, not an invented story

## Technical Flows
- [x] Authentication flows documented (OTP registration, PIN login, token refresh, step-up)
- [x] Token design specified (access, refresh, step-up)
- [x] JWT signing algorithm: ES256; `kid` in header; JWKS endpoint for public-key distribution
- [x] Service token design documented (Section 10.5): ES256, audience-restricted, `jti`, delegation model
- [x] JWKS endpoint documented in API contracts
- [x] Session lifecycle documented
- [x] Refresh token rotation and reuse detection documented
- [x] MFA enable/verify/step-up flows documented

## Security
- [x] Credential storage requirements (Argon2id/bcrypt)
- [x] OTP non-logging requirement
- [x] Biometric assertion (never transit network) documented
- [x] PII masking in logs/events stated
- [x] Rate limiting as configurable (not hard-coded)
- [x] Secure error responses (no stack traces in production)
- [x] Audit requirement for all security events
- [x] IP protection as HMAC-SHA256(platform_secret, ip) stated — secret in secret manager
- [x] Secrets exclusion from platform.configuration_values stated
- [x] Configuration validation with security minimums stated
- [x] Admin MFA defaults (TOTP + FIDO2, SMS disabled) stated

## API Contracts
- [x] All 38 stories mapped to endpoint or flow
- [x] Platform Foundation conventions referenced (envelope, error taxonomy, correlation ID)
- [x] Idempotency noted where applicable
- [x] OpenAPI documentation requirement stated
- [x] JWKS endpoint documented: GET /v1/.well-known/jwks.json
- [x] Service token provisioning endpoint noted (path TBD — planning phase)

## DB Entities
- [x] All 5 tables defined: users, credentials, sessions, devices, otp_challenges
- [x] UUID v7 primary keys
- [x] UTC timestamps (timestamptz)
- [x] No cross-schema FK constraints
- [x] Unique constraints documented
- [x] No wallet/ledger/KYC tables included
- [x] Data retention policy defined: sessions 90 days post-expiry/revocation; OTP challenges 24 hours post-expiry/invalidation
- [x] Retention values sourced from platform.configuration_values (not hard-coded in Horizon jobs)

## Events
- [x] All 22 events defined with trigger and consumer
- [x] Event naming follows `{Module}.{Aggregate}.{Verb}` convention
- [x] Platform Foundation outbox envelope referenced
- [x] At-least-once delivery acknowledged; consumer idempotency required

## Audit
- [x] All 18 audit actions defined with severity (17 original + identity.service.token-issued)
- [x] Uses `platform.audit_events` — no second audit registry
- [x] PII masking in audit payload rules stated
- [x] INSERT-only constraint acknowledged

## Authorization Boundary
- [x] E01 provides authenticated context only (not RBAC evaluation)
- [x] Handoff to E04 documented
- [x] Default role assignment boundary documented
- [x] Self-service vs admin operations table included

## UI & Mobile
- [x] Customer Flutter screens listed per story
- [x] Agent Flutter screens listed (subset of Customer)
- [x] Admin Next.js flows documented
- [x] All screen states required (loading, success, error, security)
- [x] Platform Foundation design system reuse stated

## QA
- [x] Functional tests per story
- [x] Security tests (credential non-disclosure, OTP/PIN protection, token reuse)
- [x] Concurrency tests (OTP race, refresh rotation race, PIN change race)
- [x] Mobile tests (biometric, secure storage, session expiry)
- [x] Accessibility tests (WCAG AA)
- [x] API tests (envelope, correlation ID, error codes)
- [x] Performance tests: login P95 ≤ 500ms, token refresh P95 ≤ 200ms, OTP verify P95 ≤ 500ms (Section 24.7)
- [x] Observability: standard metric set defined (auth counters, OTP send rate, lockout rate, refresh rate, step-up trigger rate, latency histograms)

## Configuration Management
- [x] Configuration Registry section (8.1) lists all 25 configuration keys with defaults and validation boundaries
- [x] Runtime configuration pattern documented (domain → ConfigurationService → repository → DB)
- [x] Idempotent seed requirements stated (insert-only, no overwrite)
- [x] Admin Portal Security Configuration navigation defined
- [x] Configuration API endpoints defined (GET/PUT /v1/admin/platform/configuration/security)
- [x] Configuration authorization permissions defined (view / update)
- [x] Configuration audit requirements stated
- [x] Optimistic concurrency requirements stated
- [x] Cache invalidation behavior described

## Acceptance Criteria
- [x] 28 epic-level acceptance criteria defined (AC-001 to AC-028)
- [x] Each AC is testable and unambiguous
- [x] GAP-027 threshold reflected in AC-013 with resolved value
- [x] Configuration management ACs added (AC-021 to AC-028)

## Definition of Done
- [x] Story-level DoD defined
- [x] Epic-level DoD defined
- [x] Test coverage target stated (≥ 80%)
- [x] Configuration management DoD items added to epic-level DoD

## Dependencies
- [x] All Platform Foundation dependencies listed
- [x] OTP provider abstraction (TBD-001 resolved) documented
- [x] Future epic dependencies listed (E02, E03, E04, E17, E18)

## TBD Governance
- [x] All 12 TBD items listed; 11 resolved, 1 open (TBD-004 biometric SDK)
- [x] GAP-027 resolved (10000 BWP)
- [x] TBD-003 resolved (ES256; JWKS; `kid`; key rotation)
- [x] No TBD values invented; all configurable values assigned defaults in Configuration Registry
- [x] Remaining open TBD (TBD-004 biometric SDK) is a Flutter-platform-specific decision outside E01's core scope

## Risks
- [x] 8 risks identified with likelihood, impact, and mitigation
- [x] OTP provider risk mitigated (OtpDeliveryContract abstraction)
- [x] Step-up threshold resolved; risk updated accordingly
