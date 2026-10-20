# E02 — User & Profile Management

**Feature**: `002-user-profile-management` | **Epic**: E02 | **Stories**: 15 | **Status**: Specified

---

## 1. Problem Statement

Once a user identity record is created by E01 (Central Identity & Authentication), the system requires a separate capability to manage the richer user profile — personal information, contact details, profile photo, and account lifecycle state. Without E02, there is no authoritative store for profile data and no controlled mechanism for users to update their personal information, change contact details through a verification flow, or manage their account status (suspension, closure, data export).

E02 owns the profile domain. It builds on the authenticated identity context established by E01 and provides the personal data layer that downstream domains (KYC, Wallet, Agent Management, Notifications) depend on.

---

## Clarifications

### Session 2026-08-31

- Q: In FR-E02-040, what does "profile change audit summary" mean — is it account_status_history, or a cross-module query of platform.audit_events for personal-info/contact mutations? → A: **Deferred — awaiting product decision.** Interim implementation includes `account_status_history` (explicit status transitions) and contact information (`email`, `mobile_number`). Cross-module query of `platform.audit_events` for mutation events (personal-info-updated, email-changed, etc.) is deferred pending product owner confirmation that E02 may read from the platform audit store; implementation placeholder is in `ProcessDataExportJob` (T246).

### Session 2026-08-27

- Q: When a user initiates a contact information change (email or mobile), should the system require a step-up MFA challenge before sending the OTP to the new contact, and should the old contact receive a security alert independently of the new-contact OTP? → A: No step-up required; OTP sent to new contact AND a security alert notification sent to old contact.
- Q: Can a user or admin cancel a pending account closure request, transitioning the account status from `pending_closure` back to `active`? → A: Yes (Option A). Both the owning user (self-service) and an admin can cancel a pending closure. A dedicated endpoint, status history record, and `Profile.Account.ClosureCancelled` event are required.
- Q: Should the E02 `GET /v1/users/profile` response include E01's identity-level lock status (e.g., `is_locked` from too many failed login attempts), or only E02's own `account_status`? → A: Option B — return only E02's own `account_status`; E01 lock state is owned by E01 and surfaced via E01's session/auth endpoints; E02 does not query E01 for lock state on profile reads.
- Q: At profile creation time (on `Identity.User.Registered`), which fields does E01's event payload supply? → A: Option B — the event supplies only `user_id` and `actor_type`; E02 fetches `email` and `mobile_number` from E01 via a synchronous service call at profile creation time; all personal fields (`first_name`, `last_name`, `date_of_birth`, etc.) start as NULL.
- Q: When contact-change OTP verification succeeds but the subsequent atomic update to E01's `identity.users` record fails, what is the user-visible behavior and recovery path? → A: Option A — the entire operation is rolled back; neither E02's nor E01's record is updated; the pending challenge remains active so the user can retry verification without re-initiating the OTP flow; the client receives a retryable `500 INTERNAL_ERROR` with retry guidance.

---

## 2. Scope

E02 covers exactly the 15 stories assigned to Epic E02 in the approved 538-story backlog:

- **Profile viewing**: self-service profile view, account status view
- **Profile editing**: personal information update, profile photo update
- **Contact changes**: email change initiation + verification, mobile number change initiation + verification
- **Account lifecycle**: activate, suspend, reactivate, request closure, prevent closure with obligations, permanently close
- **Data portability**: export personal account data

**In scope**:
- `profile` PostgreSQL schema creation and ownership
- Profile record creation triggered by `Identity.User.Registered` event (E01)
- Self-service profile operations (authenticated user acting on their own record)
- Admin-privileged profile operations (admin actor acting on any user's record)
- Admin profile oversight dashboard summary for active users, account status, closure risk, and pending contact changes
- OTP-gated contact information change flows
- Account status lifecycle state machine with history
- Financial obligation check gate for account closure
- Personal data export (GDPR/data portability)
- Audit recording for all profile mutations and status changes

**Out of scope**:
- Identity credential management (password, PIN, biometric) — E01
- Role and permission management — E04
- KYC verification and identity document collection — E05
- Wallet creation or financial account setup — E06
- Notification delivery — E17 (E02 emits events; E17 delivers)
- Agent-specific profile attributes (agent profile, agent status, float management) — E12
- Admin user onboarding and admin-specific profile fields — E19
- Fraud scoring or risk assessment — E18

---

## 3. Goals

1. Provide a single authoritative profile domain for personal information across all user types (Customer, Agent).
2. Allow authenticated users to view and update their own profile data with appropriate validation.
3. Enforce IDOR/BOLA prevention: users can only access their own profile; admin access requires explicit permission.
4. Implement OTP-verified change flows for sensitive contact information (email, mobile number).
5. Manage the account status lifecycle with complete audit history.
7. Provide admin dashboard oversight for profile health, account status, pending closure risk, and recent profile-change activity.
8. Block account closure when unresolved financial obligations exist.
9. Provide a personal data export capability for regulatory compliance.
10. Produce auditable, structured profile and status events consumable by downstream domains.
11. Remain extraction-ready: the Profile bounded context must be isolatable as an independent service.

---

## 4. Non-Goals

E02 does NOT:
- Implement authentication, sessions, tokens, PIN, or MFA — E01.
- Create or modify role or permission records — E04.
- Implement KYC flows, document capture, or biometric verification — E05.
- Create wallet records — E06.
- Deliver notifications — E17.
- Implement fraud or risk scoring on profile changes — E18.
- Manage agent-specific business attributes (float, commission, agent status) — E12.
- Implement admin portal screen layout or admin-wide RBAC management — E19.
- Hard-code any configurable policy value (file size limits, age minimums, photo dimension requirements).

---

## 5. Actors

| Actor | Description | Profile Access |
|---|---|---|
| **Customer** | End-user of the Customer mobile application | Own profile only (self-service) |
| **Agent** | Registered field agent using the Agent mobile application | Own profile only (self-service) |
| **Admin** | Administrator using the Admin/Enterprise web application | Any user's profile (with permission) |
| **System** | Internal event-driven process (E02's own event consumer) | Creates profile on `Identity.User.Registered`; enforces closure gate |

---

## 6. User Journeys

### 6.1 View Own Profile
Authenticated user opens profile section → system returns their own profile data including personal information, contact details, profile photo URL, account status.

### 6.2 Update Personal Information
Authenticated user navigates to profile edit → changes name, date of birth, address, or preferred language → submits → system validates and persists → audit record created → success confirmation shown.

### 6.3 Update Profile Photo
Authenticated user selects a new photo from device → uploads it → system validates format and size → stores photo and updates profile → old photo cleaned up → profile photo URL updated.

### 6.4 Change Email Address
Authenticated user submits new email → system simultaneously (a) sends OTP to new email address and (b) emits a security alert notification event to the current (old) email address → user enters OTP → system verifies and atomically updates email in both identity and profile records → audit recorded. Old email remains active until verification succeeds. The security alert to the old email is sent regardless of whether the new email OTP is ever verified.

### 6.5 Change Mobile Number
Authenticated user submits new mobile number → system simultaneously (a) sends OTP to new mobile number and (b) emits a security alert notification event to the current (old) mobile number → user enters OTP → system verifies and atomically updates mobile in both identity and profile records → audit recorded. Old mobile remains active until verification succeeds. The security alert to the old mobile is sent regardless of whether the new mobile OTP is ever verified.

### 6.6 View Account Status
Authenticated user views account status page → system returns current status, reason for current status (where applicable), and status history.

### 6.7 Account Suspension
Admin with appropriate permission suspends a user account → provides suspension reason → system transitions account to `suspended`, records in status history, emits event → user's access privileges may be restricted by downstream domains.

### 6.8 Account Reactivation
Admin with appropriate permission reactivates a suspended account → system transitions to `active`, records in status history, emits event.

### 6.9 Request Account Closure
Authenticated user submits account closure request → system checks for unresolved financial obligations → if obligations exist, closure is blocked with clear enumeration → if none, transitions to `pending_closure`, records in history, emits event.

### 6.10 Permanently Close Account
Admin with appropriate permission confirms permanent closure of a `pending_closure` account → system performs final obligation check → transitions to `closed`, records in history, emits event.

### 6.11 Export Personal Data
Authenticated user requests data export → system compiles all personal profile data excluding credentials and internal system fields → generates export package → delivers download link → audit recorded.

### 6.12 Cancel Pending Account Closure
Authenticated user (or Admin with `account.reactivate` permission) cancels a pending closure request → system transitions account from `pending_closure` back to `active` → records transition in `profile.account_status_history` → emits `Profile.Account.ClosureCancelled` event → audit recorded.

---

## 7. Business Rules

| ID | Rule |
|---|---|
| BR-001 | Authenticated users MUST only access and modify their own profile. Accessing another user's profile by ID is IDOR and MUST be rejected. |
| BR-002 | Admin actors with `profile.read` permission MAY view any user's profile. Admin actors with `profile.update` permission MAY update any user's profile. |
| BR-003 | Email address and mobile number CANNOT be changed via the personal information update endpoint. They require dedicated change flows with OTP verification. |
| BR-004 | Email/mobile changes MUST NOT take effect until the OTP sent to the new contact address is successfully verified. The old contact remains authoritative until verification completes. |
| BR-005 | A new email or mobile number that is already associated with another active account MUST be rejected without disclosing which account holds it. |
| BR-006 | Date of birth MUST represent a valid calendar date. Minimum age requirement is configurable via platform configuration. Users below the configured minimum age MUST be rejected. |
| BR-007 | System-managed fields (`id`, `user_id`, `created_at`, `updated_at`, `account_status`) MUST NOT be accepted via any profile update request body. |
| BR-008 | Profile photo files MUST NOT exceed the configured maximum size. Accepted MIME types and minimum dimensions are configurable. |
| BR-009 | A user account CANNOT be permanently closed if any of the following unresolved obligations exist: non-zero wallet balance, pending or processing transactions, active loans, pending disputes. |
| BR-010 | Account suspension and reactivation require a privileged role with explicit `account.suspend` or `account.reactivate` permission respectively. |
| BR-011 | Permanent account closure requires `account.close` permission. Where maker-checker is configured, both maker approval and checker confirmation are required before closure is applied. |
| BR-012 | All profile mutations (personal information, photo, contact change initiation, contact change verification) MUST produce an immutable audit record in `platform.audit_events`. |
| BR-013 | All account status transitions (activate, suspend, reactivate, pending_closure, close) MUST be recorded in `profile.account_status_history` with: previous status, new status, reason, actor ID, and timestamp. |
| BR-014 | The data export MUST include all personal information and contact data but MUST exclude credential hashes, OTP values, system-internal tokens, and raw IP addresses. |
| BR-015 | Profile photo storage provider is TBD. E02 MUST abstract photo storage behind a `ProfilePhotoStorageContract` interface. |
| BR-016 | All configurable policy values (minimum age, photo size limit, photo accepted types, accepted dimensions) MUST be read from `platform.configuration_values`; they MUST NOT be hard-coded in domain logic. |
| BR-017 | Mobile number changes MUST use E01's OTP challenge infrastructure. E02 MUST NOT maintain its own OTP challenge mechanism. |
| BR-018 | Email changes MUST use E01's OTP challenge infrastructure via the `OtpDeliveryContract`. E02 MUST NOT maintain its own OTP delivery mechanism. |
| BR-019 | Profile mutations for Admin-path operations on another user MUST be audited with both the acting admin ID and the subject user ID. |
| BR-020 | A closure request (pending_closure) that has been rejected due to financial obligations MUST be auditable with the specific reason(s) for rejection. |
| BR-021 | When a contact change (email or mobile) is initiated, the system MUST simultaneously emit a security alert notification event to the CURRENT (old) contact address. This alert is independent of the OTP sent to the new contact and MUST be emitted regardless of whether the OTP is ever verified. Failure to deliver the security alert to the old contact MUST NOT block the contact change flow. |
| BR-022 | An account in `pending_closure` status MAY be transitioned back to `active` by the owning authenticated user (self-service cancellation) or by an Admin with `account.reactivate` permission. Cancellation MUST produce a `profile.account_status_history` record and emit `Profile.Account.ClosureCancelled`. |

---

## 8. Functional Requirements

| ID | Story | Requirement |
|---|---|---|
| FR-E02-001 | System | The system MUST create a `profile.user_profiles` record upon receiving the `Identity.User.Registered` event from E01. The event payload supplies `user_id` and `actor_type`; E02 MUST fetch `email` and `mobile_number` from E01 via a synchronous service call at creation time. All personal information fields (`first_name`, `last_name`, `date_of_birth`, `gender`, `address`, `preferred_language`) MUST be initialized as NULL. |
| FR-E02-002 | US-USER-001 | An authenticated user MUST be able to retrieve their own profile, including personal information, contact details, profile photo URL, and account status. |
| FR-E02-003 | US-USER-001 | An Admin actor with `profile.read` permission MUST be able to retrieve any user's profile by user ID. |
| FR-E02-004 | US-USER-001 | The system MUST reject any attempt by a non-admin authenticated user to retrieve another user's profile with a `FORBIDDEN` error. |
| FR-E02-005 | US-USER-001 | Profile photo URLs in API responses MUST be time-limited signed URLs. The signing mechanism is implementation detail; the URL MUST NOT expose raw storage keys or credentials. |
| FR-E02-006 | US-USER-002 | An authenticated user MUST be able to update their own personal information fields: first name, last name, date of birth, gender, address (street, city, region, postal code, country), and preferred language. |
| FR-E02-007 | US-USER-002 | An Admin actor with `profile.update` permission MUST be able to update personal information for any user. |
| FR-E02-008 | US-USER-002 | The system MUST validate that date of birth is a valid calendar date and that the user's age meets the configured minimum. |
| FR-E02-009 | US-USER-002 | Personal information updates MUST be idempotent within the platform's idempotency window when an `Idempotency-Key` header is provided. |
| FR-E02-010 | US-USER-003 | An authenticated user MUST be able to upload a new profile photo. |
| FR-E02-011 | US-USER-003 | The system MUST validate profile photo file size against the configurable maximum, MIME type against the accepted list, and minimum dimensions against the configurable minimum. |
| FR-E02-012 | US-USER-003 | Upon successful upload, the system MUST update the profile photo reference and clean up (delete or archive) the previous photo from storage. |
| FR-E02-013 | US-USER-004 | An authenticated user MUST be able to initiate an email address change by providing their new email address. |
| FR-E02-014 | US-USER-004 | Upon initiating an email change, the system MUST send a verification OTP to the new email address via E01's `OtpDeliveryContract`. |
| FR-E02-014a | US-USER-004 | When an email change is initiated, the system MUST simultaneously emit a security alert notification event targeting the current (old) email address via E17. The alert MUST be emitted regardless of whether the new email OTP is ever verified. Alert delivery failure is non-blocking; the change flow proceeds regardless. |
| FR-E02-015 | US-USER-004 | The existing email address MUST remain active and unmodified until OTP verification succeeds. |
| FR-E02-016 | US-USER-004 | If the new email address is already associated with another account, the system MUST reject the request without disclosing that the email is in use. |
| FR-E02-017 | US-USER-005 | An authenticated user MUST be able to initiate a mobile number change by providing their new mobile number in E.164 format. |
| FR-E02-018 | US-USER-005 | Upon initiating a mobile change, the system MUST send a verification OTP to the new mobile number via E01's `OtpDeliveryContract`. |
| FR-E02-018a | US-USER-005 | When a mobile change is initiated, the system MUST simultaneously emit a security alert notification event targeting the current (old) mobile number via E17. The alert MUST be emitted regardless of whether the new mobile OTP is ever verified. Alert delivery failure is non-blocking; the change flow proceeds regardless. |
| FR-E02-019 | US-USER-005 | The existing mobile number MUST remain active and unmodified until OTP verification succeeds. |
| FR-E02-020 | US-USER-005 | If the new mobile number is already associated with another account, the system MUST reject the request without disclosing that the number is in use. |
| FR-E02-021 | US-USER-006 | An authenticated user MUST be able to verify a pending mobile number change by submitting the OTP sent to the new mobile number. |
| FR-E02-022 | US-USER-006 | Upon successful OTP verification, the system MUST atomically update `identity.users.mobile_number` (E01's record) and `profile.user_profiles.mobile_number` to the new value. |
| FR-E02-022a | US-USER-006 | If the atomic contact update fails (e.g., E01 service call fails after E02 local update), the entire operation MUST be rolled back: neither `profile.user_profiles.mobile_number` nor `identity.users.mobile_number` is changed. The pending challenge MUST remain active so the user can retry verification without re-initiating the OTP flow. The response MUST be a retryable `500 INTERNAL_ERROR` with retry guidance. |
| FR-E02-023 | US-USER-007 | An authenticated user MUST be able to verify a pending email change by submitting the OTP sent to the new email. |
| FR-E02-024 | US-USER-007 | Upon successful OTP verification, the system MUST atomically update `identity.users.email` (E01's record) and `profile.user_profiles.email` to the new value. |
| FR-E02-024a | US-USER-007 | If the atomic contact update fails, the entire operation MUST be rolled back: neither `profile.user_profiles.email` nor `identity.users.email` is changed. The pending challenge MUST remain active so the user can retry verification without re-initiating the OTP flow. The response MUST be a retryable `500 INTERNAL_ERROR` with retry guidance. |
| FR-E02-025 | US-USER-008 | An authenticated user MUST be able to view their own account status and a history of status transitions. |
| FR-E02-026 | US-USER-008 | An Admin actor with `profile.read` permission MUST be able to view any user's account status and status history. |
| FR-E02-027 | US-USER-009 | The system MUST set account status to `active` in `profile.user_profiles` upon creating a profile record from the `Identity.User.Registered` event. |
| FR-E02-028 | US-USER-010 | An Admin actor with `account.suspend` permission MUST be able to suspend an active account. A suspension reason MUST be provided. |
| FR-E02-029 | US-USER-010 | Suspending an account MUST transition `profile.user_profiles.account_status` from `active` to `suspended` and record the transition in `profile.account_status_history`. |
| FR-E02-030 | US-USER-011 | An Admin actor with `account.reactivate` permission MUST be able to reactivate a suspended account. |
| FR-E02-031 | US-USER-011 | Reactivating an account MUST transition `profile.user_profiles.account_status` from `suspended` to `active` and record the transition in `profile.account_status_history`. |
| FR-E02-032 | US-USER-012 | An Admin actor with `account.close` permission MUST be able to permanently close an account in `pending_closure` status. |
| FR-E02-033 | US-USER-012 | The system MUST perform a final financial obligation check before applying permanent closure. If obligations exist, the operation MUST be rejected. |
| FR-E02-034 | US-USER-012 | Permanent closure MUST transition `profile.user_profiles.account_status` to `closed` and record the transition in `profile.account_status_history`. |
| FR-E02-035 | US-USER-013 | An authenticated user MUST be able to request account closure (self-service). |
| FR-E02-036 | US-USER-013 | Upon receiving a closure request, the system MUST check for unresolved financial obligations. If none exist, the account transitions to `pending_closure`. |
| FR-E02-037 | US-USER-014 | The system MUST block any closure operation (self-service request or admin permanent closure) if the user has: a non-zero wallet balance, any transaction in `pending` or `processing` state, any active loan, or any open dispute. |
| FR-E02-038 | US-USER-014 | When closure is blocked, the API response MUST enumerate the specific obligation categories that prevent closure. |
| FR-E02-039 | US-USER-015 | An authenticated user MUST be able to request an export of their personal profile data. |
| FR-E02-040 | US-USER-015 | The export MUST include: all personal profile fields, contact information, account status history, and profile change audit summary. It MUST exclude credential hashes, tokens, OTP values, and raw IP addresses. |
| FR-E02-041 | US-USER-015 | The export operation MUST be audited. |
| FR-E02-042 | All | All profile mutations and account status changes MUST produce an immutable audit record in `platform.audit_events` via the Platform Foundation `AuditService`. |
| FR-E02-043 | All | All E02 domain events MUST be emitted via the Platform Foundation outbox pattern (`platform.outbox_events`). |
| FR-E02-044 | All | All configurable policy values used by E02 MUST be read from `platform.configuration_values` via `ConfigurationService`. No values may be hard-coded in domain logic. |
| FR-E02-045 | US-USER-013 | An authenticated user MUST be able to cancel their own pending account closure request via a dedicated endpoint. |
| FR-E02-046 | US-USER-013 | An Admin actor with `account.reactivate` permission MUST be able to cancel a pending closure for any user. |
| FR-E02-047 | US-USER-013 | Cancelling a pending closure MUST atomically: (a) transition `profile.user_profiles.account_status` from `pending_closure` to `active`, (b) record the transition in `profile.account_status_history` with actor ID and reason, and (c) emit `Profile.Account.ClosureCancelled`. |

---

## 9. Security Requirements

| Requirement | Detail |
|---|---|
| IDOR/BOLA prevention | Every profile read and write operation MUST assert that the `user_id` in the authenticated token matches the resource being accessed. Admin paths use explicit `profile.read` / `profile.update` permission checks. No route parameter alone determines access. |
| PII masking in logs | Mobile numbers and email addresses MUST be masked (not omitted) in structured logs. Raw PII MUST NOT appear in log sinks. |
| PII in audit records | Audit `before_state`/`after_state` for contact changes MUST mask mobile/email to show only the change occurred, not the full values. Audit records are themselves accessible only to authorized admin actors. |
| Protected fields | `id`, `user_id`, `created_at`, `updated_at`, `account_status` MUST be server-controlled. These MUST be rejected from all request bodies with a `FIELD_NOT_PERMITTED` error if included. |
| Contact change verification | Email and mobile number changes MUST require OTP verification of the new address/number. Direct update without verification MUST NOT be possible, even via admin path except as explicitly specified. |
| Profile photo security | Signed, time-limited URLs MUST be returned for profile photos. The raw object storage path or credentials MUST NOT be exposed in any API response or event payload. |
| Data export security | Export requests MUST be authorized to the requesting user only. Exports MUST NOT include credentials, tokens, session data, or raw audit IP fields. |
| Rate limiting | Profile mutation endpoints are subject to configurable rate limiting via platform configuration. Contact change initiation endpoints (email/mobile change) have stricter rate limits (configurable). Values MUST NOT be hard-coded. |
| Admin operation audit | Admin-path operations MUST record both the admin actor ID and the subject user ID in every audit record. |
| Suspension/closure privilege | Account suspension, reactivation, and permanent closure require privileged role + explicit named permission. These MUST NOT be accessible to regular authenticated users or service tokens without the named permission. |
| Transport | All API communication MUST use HTTPS/TLS. Inherited from Platform Foundation (FR-071). |
| Error responses | Error responses MUST use the standard API error envelope. Stack traces and internal state MUST NOT appear in production error responses. |
| Contact uniqueness check | Checking whether an email/mobile is already in use MUST NOT disclose the result — both "in use" and "valid" return identical success responses at the initiation stage; only verification failure distinguishes them. |

### Performance Requirements

| Operation | P95 Target | Notes |
|---|---|---|
| View own profile | ≤ 200 ms | Single record read; photo URL signing included |
| Update personal information | ≤ 300 ms | Validation + write + audit |
| Initiate contact change | ≤ 500 ms | Includes OTP challenge creation and delivery trigger |
| View account status + history | ≤ 200 ms | Status record + history list |

Performance tests are mandatory before release. Concurrent volume and load profiles are TBD.

### Observability Requirements

E02 MUST emit the following metrics per Constitution Principle 13:

| Metric | Type | Description |
|---|---|---|
| `profile.view.success` | Counter | Profile view requests that succeeded |
| `profile.update.success` | Counter | Personal info update operations that succeeded |
| `profile.contact_change.initiated` | Counter | Email/mobile change initiations; broken down by type |
| `profile.contact_change.verified` | Counter | Email/mobile change verifications completed |
| `profile.account_status.transition` | Counter | Account status transitions; broken down by from/to status |
| `profile.closure.blocked` | Counter | Closure operations blocked by financial obligations |
| `profile.data_export.requested` | Counter | Data export requests |

---

## 10. API Contracts

All endpoints use Platform Foundation API conventions:
- Base path: `/v1`
- Standard response envelope: `{ success, data, error, meta }`
- `X-Correlation-ID` header propagated
- `Idempotency-Key` header for state-changing operations
- OpenAPI documentation generated for all endpoints

### 10.1 Endpoint Summary

| Story | Method | Path | Auth | Idempotent | Permission |
|---|---|---|---|---|---|
| US-USER-001 | GET | `/v1/users/profile` | Bearer | — | Self only |
| US-USER-001 | GET | `/v1/admin/users/{user_id}/profile` | Admin Bearer | — | `profile.read` |
| US-USER-002 | PUT | `/v1/users/profile/personal` | Bearer | Yes | Self only |
| US-USER-002 | PUT | `/v1/admin/users/{user_id}/profile/personal` | Admin Bearer | Yes | `profile.update` |
| US-USER-003 | POST | `/v1/users/profile/photo` | Bearer | No | Self only |
| US-USER-004 | POST | `/v1/users/profile/email/change` | Bearer | Yes | Self only |
| US-USER-005 | POST | `/v1/users/profile/mobile/change` | Bearer | Yes | Self only |
| US-USER-006 | POST | `/v1/users/profile/mobile/verify` | Bearer | Yes | Self only |
| US-USER-007 | POST | `/v1/users/profile/email/verify` | Bearer | Yes | Self only |
| US-USER-008 | GET | `/v1/users/profile/status` | Bearer | — | Self only |
| US-USER-008 | GET | `/v1/admin/users/{user_id}/profile/status` | Admin Bearer | — | `profile.read` |
| US-USER-009 | — | System event-driven (on `Identity.User.Registered`) | — | Idempotent | System |
| US-USER-010 | POST | `/v1/admin/users/{user_id}/suspend` | Admin Bearer | Yes | `account.suspend` |
| US-USER-011 | POST | `/v1/admin/users/{user_id}/reactivate` | Admin Bearer | Yes | `account.reactivate` |
| US-USER-012 | POST | `/v1/admin/users/{user_id}/close` | Admin Bearer | Yes | `account.close` |
| US-USER-013 | POST | `/v1/users/profile/closure/request` | Bearer | Yes | Self only |
| US-USER-013 | DELETE | `/v1/users/profile/closure/request` | Bearer | Yes | Self only (cancellation) |
| US-USER-013 | POST | `/v1/admin/users/{user_id}/cancel-closure` | Admin Bearer | Yes | `account.reactivate` |
| US-USER-014 | — | Business rule enforced within closure and request-closure flows | — | — | — |
| US-USER-015 | POST | `/v1/users/profile/data-export` | Bearer | Yes | Self only |

### 10.2 Request/Response Intent

#### GET /v1/users/profile
- **Intent**: Return the authenticated user's complete profile.
- **Response data**: `user_id`, `first_name`, `last_name`, `date_of_birth`, `gender`, `address`, `preferred_language`, `email`, `mobile_number`, `profile_photo_url` (signed, expiring), `account_status`, `created_at`, `updated_at`.
- **`account_status` boundary**: This field reflects only E02's account status (`active`, `suspended`, `pending_closure`, `closed`). E01's identity-level lock state (e.g., account locked after failed logins) is NOT included and MUST NOT be queried by E02. E01 enforces its lock at token validation; a locked user's token will be rejected before reaching E02.
- **Auth**: Bearer token. `user_id` derived from token — no path parameter accepted.
- **Error conditions**: `401 UNAUTHORIZED` (no/invalid token), `404 NOT_FOUND` (profile record not yet created — transient post-registration state).

#### PUT /v1/users/profile/personal
- **Intent**: Update the authenticated user's personal information.
- **Request data**: Any subset of: `first_name`, `last_name`, `date_of_birth`, `gender`, `address` (object), `preferred_language`.
- **Forbidden fields**: Any field not listed above is ignored or rejected with `FIELD_NOT_PERMITTED`.
- **Validation**: `date_of_birth` must be a valid date; age ≥ configured minimum. Name fields: non-empty, ≤ 100 characters. Country code: ISO 3166-1 alpha-2.
- **Auth**: Bearer token. Admin path accepts `user_id` as path parameter.
- **Error conditions**: `400 VALIDATION_ERROR` (invalid fields), `403 FORBIDDEN` (wrong user on non-admin path), `422 MINIMUM_AGE_VIOLATION`.

#### POST /v1/users/profile/email/change
- **Intent**: Initiate email change; send OTP to new email.
- **Request data**: `{ "new_email": "..." }` + `Idempotency-Key` header.
- **Response data**: `{ "challenge_id": "..." }` — opaque reference for subsequent verification.
- **Error conditions**: `400 INVALID_EMAIL_FORMAT`, `409 CONTACT_CHANGE_IN_PROGRESS` (pending change already exists), `429 RATE_LIMIT_EXCEEDED`.
- **Note**: The response MUST be identical whether `new_email` is already in use or not (no disclosure).

#### POST /v1/users/profile/email/verify
- **Intent**: Complete email change by verifying the OTP.
- **Request data**: `{ "challenge_id": "...", "otp": "..." }` + `Idempotency-Key`.
- **Response data**: Success confirmation; updated email (masked).
- **Error conditions**: `400 OTP_INVALID`, `400 OTP_EXPIRED`, `404 CHALLENGE_NOT_FOUND`, `409 CHALLENGE_ALREADY_COMPLETED`, `500 INTERNAL_ERROR` (atomic update failed — challenge remains active; client MUST offer retry without re-entering OTP flow).

#### POST /v1/users/profile/mobile/change + /mobile/verify
- Same structure as email change/verify, substituting mobile number in E.164 format.

#### POST /v1/admin/users/{user_id}/suspend
- **Intent**: Suspend an active user account.
- **Request data**: `{ "reason": "..." }` — mandatory suspension reason.
- **Auth**: Admin Bearer + `account.suspend` permission.
- **Error conditions**: `400 INVALID_STATUS_TRANSITION` (account not in `active` state), `403 FORBIDDEN`.

#### POST /v1/users/profile/closure/request
- **Intent**: User requests own account closure.
- **Response data**: On success: `{ "account_status": "pending_closure" }`. On blocked: `{ "blocked_by": ["wallet_balance", "pending_transactions", ...] }`.
- **Error conditions**: `409 CLOSURE_BLOCKED_BY_OBLIGATIONS` with obligation list.

#### DELETE /v1/users/profile/closure/request
- **Intent**: User cancels their own pending closure request.
- **Request data**: `Idempotency-Key` header; optional `{ "reason": "..." }` body.
- **Response data**: `{ "account_status": "active" }`.
- **Auth**: Bearer token. `user_id` derived from token.
- **Error conditions**: `400 INVALID_STATUS_TRANSITION` (account not in `pending_closure`), `401 UNAUTHORIZED`.

#### POST /v1/admin/users/{user_id}/cancel-closure
- **Intent**: Admin cancels a pending closure for a user.
- **Request data**: Optional `{ "reason": "..." }` + `Idempotency-Key`.
- **Auth**: Admin Bearer + `account.reactivate` permission.
- **Error conditions**: `400 INVALID_STATUS_TRANSITION`, `403 FORBIDDEN`.

#### POST /v1/admin/users/{user_id}/close
- **Intent**: Admin permanently closes an account in `pending_closure` status.
- **Auth**: Admin Bearer + `account.close` permission.
- **Error conditions**: `400 INVALID_STATUS_TRANSITION`, `409 CLOSURE_BLOCKED_BY_OBLIGATIONS`, `403 FORBIDDEN`.

#### POST /v1/users/profile/data-export
- **Intent**: Request a personal data export package.
- **Response data**: `{ "export_id": "...", "status": "processing" }` — export is asynchronous.
- **Delivery**: Download link delivered via notification event (E17) when ready, OR a subsequent `GET /v1/users/profile/data-export/{export_id}` poll endpoint (implementation detail for planning).

Exact request/response schemas, error codes, and OpenAPI definitions are produced in the `contracts/` directory during the planning phase.

---

## 11. DB Entities

E02 owns the `profile` PostgreSQL schema. E02 creates all tables within it.

### `profile.user_profiles`
One record per registered user. Created on `Identity.User.Registered` event.

| Column | Type | Notes |
|---|---|---|
| `id` | `uuid` PK | UUID v7 |
| `user_id` | `uuid` NOT NULL UNIQUE | References `identity.users.id` (cross-schema reference; stored as value, no FK across schemas) |
| `first_name` | `varchar(100)` NULL | |
| `last_name` | `varchar(100)` NULL | |
| `date_of_birth` | `date` NULL | |
| `gender` | `varchar(20)` NULL | `male`, `female`, `non_binary`, `prefer_not_to_say` |
| `address_street` | `varchar(255)` NULL | |
| `address_city` | `varchar(100)` NULL | |
| `address_region` | `varchar(100)` NULL | |
| `address_postal_code` | `varchar(20)` NULL | |
| `address_country` | `char(2)` NULL | ISO 3166-1 alpha-2 |
| `preferred_language` | `char(5)` NULL | BCP 47 language tag (e.g., `en`, `tn-BW`) |
| `email` | `varchar(255)` NULL | Populated at creation via E01 service call; mirrors `identity.users.email`; updated by E02 contact-change flow |
| `mobile_number` | `varchar(20)` NULL | E.164 format; populated at creation via E01 service call; mirrors `identity.users.mobile_number` |
| `profile_photo_key` | `varchar(500)` NULL | Internal object storage reference; NEVER returned directly in API |
| `account_status` | `varchar(30)` NOT NULL | `active`, `suspended`, `pending_closure`, `closed` |
| `pending_email` | `varchar(255)` NULL | New email awaiting OTP verification |
| `pending_email_challenge_id` | `uuid` NULL | E01 OTP challenge ID for pending email change |
| `pending_mobile` | `varchar(20)` NULL | New mobile awaiting OTP verification |
| `pending_mobile_challenge_id` | `uuid` NULL | E01 OTP challenge ID for pending mobile change |
| `created_at` | `timestamptz` NOT NULL DEFAULT `now()` | |
| `updated_at` | `timestamptz` NOT NULL DEFAULT `now()` | |

### `profile.account_status_history`
Append-only record of account status transitions.

| Column | Type | Notes |
|---|---|---|
| `id` | `uuid` PK | UUID v7 |
| `user_id` | `uuid` NOT NULL | Cross-schema reference to `identity.users.id` |
| `from_status` | `varchar(30)` NULL | NULL for initial activation |
| `to_status` | `varchar(30)` NOT NULL | |
| `reason` | `text` NULL | Required for suspension; optional for other transitions |
| `actor_id` | `uuid` NOT NULL | User or admin who caused the transition |
| `actor_type` | `varchar(20)` NOT NULL | `USER`, `ADMIN`, `SYSTEM` |
| `closure_obligations` | `jsonb` NULL | Populated when closure is blocked; lists obligation categories and amounts |
| `correlation_id` | `uuid` NOT NULL | From `X-Correlation-ID` header |
| `created_at` | `timestamptz` NOT NULL DEFAULT `now()` | Append-only; no `updated_at` |

### `profile.data_exports`
Tracks personal data export requests.

| Column | Type | Notes |
|---|---|---|
| `id` | `uuid` PK | UUID v7 |
| `user_id` | `uuid` NOT NULL | |
| `status` | `varchar(20)` NOT NULL | `processing`, `ready`, `downloaded`, `expired`, `failed` |
| `download_key` | `varchar(500)` NULL | Internal object storage reference; NEVER returned directly |
| `expires_at` | `timestamptz` NULL | Download link expiry |
| `requested_at` | `timestamptz` NOT NULL | |
| `completed_at` | `timestamptz` NULL | |
| `correlation_id` | `uuid` NOT NULL | |

---

## 12. Events

All events use the Platform Foundation outbox pattern and event envelope. Events follow the `{Module}.{Aggregate}.{Verb}` naming convention.

| Event Name | Trigger | Consumers |
|---|---|---|
| `Profile.User.ProfileCreated` | `Identity.User.Registered` consumed; profile record created | E05 (KYC trigger), E17 (welcome notification) |
| `Profile.User.PersonalInfoUpdated` | Personal information successfully updated | E17 (notification optional) |
| `Profile.User.PhotoUpdated` | Profile photo successfully updated | E17 (notification optional) |
| `Profile.User.EmailChangeInitiated` | Email change OTP sent AND security alert event emitted to old email | E17 (OTP delivery to new email; security alert notification to old email) |
| `Profile.User.EmailChanged` | Email change OTP verified and email updated | E17 (confirmation notification) |
| `Profile.User.MobileChangeInitiated` | Mobile change OTP sent AND security alert event emitted to old mobile | E17 (OTP delivery to new mobile; security alert notification to old mobile) |
| `Profile.User.ContactSecurityAlertSent` | Security alert emitted to current (old) contact address on email or mobile change initiation; carries `contact_type` (`email` or `mobile`) | E17 (security alert notification to old contact address) |
| `Profile.User.MobileChanged` | Mobile change OTP verified and mobile updated | E17 (confirmation notification) |
| `Profile.Account.Suspended` | Account suspended by admin | E17 (notification), E18 (risk signal), downstream domains |
| `Profile.Account.Reactivated` | Account reactivated by admin | E17 (notification) |
| `Profile.Account.ClosureRequested` | User requested account closure | E17 (notification), admin workflow |
| `Profile.Account.ClosureCancelled` | Pending closure cancelled by user or admin | E17 (notification to user) |
| `Profile.Account.ClosureBlocked` | Closure blocked by financial obligations | E17 (notification to user) |
| `Profile.Account.Closed` | Account permanently closed | E17 (notification), E06 (wallet closure), downstream domains |
| `Profile.User.DataExportRequested` | Personal data export requested | E17 (processing notification) |
| `Profile.User.DataExportReady` | Personal data export package ready | E17 (download link delivery) |

**Event envelope**: Platform Foundation `platform.outbox_events` schema. All events include `correlation_id`, `causation_id`, `event_version: "1.0.0"`, `actor_id`, `aggregate_id` (`user_id`).

---

## 13. Audit Requirements

All profile operations and account status changes MUST be recorded in `platform.audit_events` using the Platform Foundation `AuditService`. E02 MUST NOT create a second audit infrastructure.

| Audit Action | Trigger | Severity |
|---|---|---|
| `profile.user.profile-created` | Profile record created from Identity.User.Registered | INFO |
| `profile.user.personal-info-updated` | Personal information updated | INFO |
| `profile.user.photo-updated` | Profile photo updated | INFO |
| `profile.user.email-change-initiated` | Email change OTP initiated | INFO |
| `profile.user.email-changed` | Email successfully changed | WARN |
| `profile.user.mobile-change-initiated` | Mobile change OTP initiated | INFO |
| `profile.user.mobile-changed` | Mobile number successfully changed | WARN |
| `profile.account.suspended` | Account suspended | WARN |
| `profile.account.reactivated` | Account reactivated | INFO |
| `profile.account.closure-requested` | User requested closure | INFO |
| `profile.account.closure-cancelled` | Pending closure cancelled by user or admin | INFO |
| `profile.account.closure-blocked` | Closure blocked by obligations | INFO |
| `profile.account.closed` | Account permanently closed | WARN |
| `profile.user.data-export-requested` | Personal data export requested | INFO |

**Audit payload rules**:
- Mobile/email in `before_state`/`after_state`: MUST be masked (e.g., `+26777***789`)
- Actor ID: authenticated user's UUID or admin UUID for admin-path operations; admin-path must also record `subject_user_id`
- `ip_address`: Hashed using `HMAC-SHA256(platform_secret, ip_address)` per Platform Foundation audit standard (inherited from E01 BR-027)
- Audit records are INSERT-only; E02 MUST NOT attempt UPDATE or DELETE on `platform.audit_events`

---

## 14. Permissions / Authorization Boundary

### 14.1 Authorization Model

E02 relies on E01 for authenticated identity and E04 for permission enforcement. E02 performs server-side authorization checks per Constitution Principle 5.

| Operation | Actor | Required Permission |
|---|---|---|
| View own profile, status, history | Authenticated user | None beyond valid session (`self` resource) |
| Update own personal info, photo, contact | Authenticated user | None beyond valid session (`self` resource) |
| Request own account closure | Authenticated user | None beyond valid session |
| Cancel own pending closure request | Authenticated user | None beyond valid session |
| Request own data export | Authenticated user | None beyond valid session |
| View any user's profile or status | Admin | `profile.read` |
| Update any user's personal info | Admin | `profile.update` |
| Suspend an account | Admin | `account.suspend` |
| Reactivate a suspended account | Admin | `account.reactivate` |
| Cancel a pending closure (admin path) | Admin | `account.reactivate` |
| Permanently close an account | Admin | `account.close` |

### 14.2 IDOR Prevention

Every profile operation extracts `user_id` from the authenticated token (E01-issued JWT). Any resource fetch or mutation compares the resource's `user_id` to the token's `user_id`. A mismatch returns `403 FORBIDDEN` — never `404`. Admin paths accept a `user_id` path parameter but require the named permission; without it, the response is always `403 FORBIDDEN`.

### 14.3 What E02 Does NOT Own

- Permission definitions (names, capabilities) — E04
- Role assignments — E04
- Identity record (`identity.users`) direct writes — E01 (E02 coordinates with E01 for contact updates)
- Identity-level account lock state (`is_locked`, failed-login counters, lock reason) — E01; E02 does not read, surface, or replicate this state

---

## 15. UI Screens

Design tokens, shared components, accessibility, and all design system elements are provided by the OleKard Design System. E02 screens use them without modification.

### 15.1 Customer Flutter — Profile Screens

| Screen | Stories |
|---|---|
| Profile: View profile | US-USER-001 |
| Profile: Edit personal information | US-USER-002 |
| Profile: Upload/change photo | US-USER-003 |
| Profile: Change email — enter new email | US-USER-004 |
| Profile: Change email — enter OTP | US-USER-007 |
| Profile: Change mobile — enter new number | US-USER-005 |
| Profile: Change mobile — enter OTP | US-USER-006 |
| Profile: Account status | US-USER-008 |
| Profile: Request account closure | US-USER-013 |
| Profile: Closure blocked (with obligation summary) | US-USER-014 |
| Profile: Data export request | US-USER-015 |

All screens include: loading state, success state, error state, retry CTA, accessibility labels, keyboard navigation, and screen-reader compatibility per Platform Foundation accessibility foundation.

### 15.2 Agent Flutter — Profile Screens

Agent profile is a subset of Customer profile:
- View profile (US-USER-001)
- Update personal information (US-USER-002)
- Update profile photo (US-USER-003)
- Change mobile number + verify (US-USER-005, US-USER-006)
- View account status (US-USER-008)

Agents cannot close their own accounts without agent-specific workflow (E12).

### 15.3 Admin Next.js — Profile Management Screens

| Screen | Stories |
|---|---|
| User profile detail (read-only with edit option) | US-USER-001, US-USER-002 |
| Account status + history | US-USER-008 |
| Suspend account (reason entry + confirmation) | US-USER-010 |
| Reactivate account (confirmation) | US-USER-011 |
| Permanently close account (confirmation + obligation check result) | US-USER-012 |

Admin screens follow the Admin dark teal theme from the OleKard design system (`[data-theme="admin"]`). All destructive actions (suspend, close) require explicit confirmation dialogs before submission.

---

## 16. Error / Edge Cases

| Scenario | Expected Behaviour |
|---|---|
| Profile not yet created (E01 event lag) | `GET /v1/users/profile` returns `404 PROFILE_NOT_FOUND` with a retry-after hint; client retries |
| Contact change with new value matching current value | Rejected with `400 SAME_CONTACT_VALUE`; no OTP sent |
| Concurrent email and mobile change requests | Only one pending change per contact type allowed at a time; second request returns `409 CONTACT_CHANGE_IN_PROGRESS` |
| OTP expired for contact change | Pending change challenge expired; user must re-initiate |
| Photo upload exceeds size limit | `413 FILE_TOO_LARGE` with the configured maximum |
| Unsupported photo MIME type | `415 UNSUPPORTED_MEDIA_TYPE` with accepted types list |
| Attempt to suspend already-suspended account | `400 INVALID_STATUS_TRANSITION` |
| Attempt to close account with non-zero wallet balance | `409 CLOSURE_BLOCKED_BY_OBLIGATIONS` with `blocked_by: ["wallet_balance"]` |
| Admin attempts to close `active` (not `pending_closure`) account | `400 INVALID_STATUS_TRANSITION` |
| Cancellation of closure on account not in `pending_closure` | `400 INVALID_STATUS_TRANSITION`; no state change occurs |
| Data export requested again before previous export delivered | `409 EXPORT_IN_PROGRESS` |
| Concurrent personal info updates with same idempotency key | First wins; subsequent within window return same 200 without re-applying |
| User_id in path parameter differs from token on non-admin endpoint | `403 FORBIDDEN`; no profile data disclosed |
| E01-locked user attempts profile read | E01 rejects the token at authentication middleware before E02 is reached; `401 UNAUTHORIZED` returned; E02 is never invoked and does not need to check lock state |
| E01 service call fails during profile creation event handler | Event is NOT acknowledged; Horizon retries the job; no partial profile record is persisted; alert fires if retry budget is exhausted |
| Atomic contact update failure (E01 update fails after E02 update) | Entire operation rolled back; contact unchanged in both `profile.user_profiles` and `identity.users`; pending challenge remains active; client receives retryable `500 INTERNAL_ERROR`; user can retry verification without re-initiating the OTP flow |
| Security alert delivery failure to old contact | Non-blocking: the contact change flow proceeds normally; the failure is logged and a metric incremented; no error returned to the user |

---

## 17. Integration Dependencies

### 17.1 Platform Foundation (Required)

| Dependency | E02 Usage |
|---|---|
| `platform.outbox_events` + OutboxService | All E02 domain/integration events |
| `platform.audit_events` + AuditService | All E02 audit records |
| `platform.configuration_values` + ConfigurationService | Photo limits, minimum age, rate limits |
| `platform.idempotency_records` + IdempotencyService | Personal info updates, contact change initiation/verification |
| Platform API envelope (`ApiResponse`) | All E02 API responses |
| Platform error taxonomy | All E02 error codes |
| `X-Correlation-ID` middleware | Propagated through all E02 requests |
| Laravel Horizon | Async data export processing job |
| PostgreSQL `profile` schema | E02's dedicated schema |

### 17.2 E01 — Central Identity & Authentication (Required)

| Integration Point | Detail |
|---|---|
| `Identity.User.Registered` event | E02 consumes this event to create the user profile record; event payload carries `user_id` and `actor_type` only |
| E01 user detail service call (profile creation) | E02 calls E01 synchronously at profile creation time to fetch `email` and `mobile_number`; if this call fails, the event MUST be retried; E02 MUST NOT create a profile record with NULL email/mobile silently |
| E01 `identity.users` table | E02 coordinates with E01 (cross-module via service call, not direct SQL) to apply contact updates to `identity.users.email` and `identity.users.mobile_number` |
| E01 `OtpDeliveryContract` | E02 uses E01's OTP challenge infrastructure for email/mobile verification; E02 MUST NOT implement its own OTP mechanism |
| E01 JWT access tokens | E02 validates tokens via cached JWKS; extracts `user_id`, `actor_type`, `roles` |

**Cross-module update pattern**: When a contact change OTP is verified, E02 MUST coordinate the update to `identity.users` through a service boundary call — not direct SQL across schema boundaries. The exact cross-module coordination mechanism (synchronous API call vs. event-driven command) is an implementation decision for the planning phase.

### 17.3 Future Epic Integration Boundaries

| Epic | Integration Point |
|---|---|
| E04 Roles, Permissions & Authorization | E04 provides named permissions (`profile.read`, `profile.update`, `account.suspend`, `account.reactivate`, `account.close`) that E02 checks |
| E05 KYC & Identity Verification | Consumes `Profile.User.ProfileCreated` to initiate KYC flow |
| E06 Wallet Management | Consulted by E02 during financial obligation check for closure |
| E11 Transaction Management | Consulted by E02 during financial obligation check (pending transactions) |
| E17 Notifications | Consumes E02 events for profile change confirmations, closure notifications, export delivery |
| E18 Fraud, Risk & AML | Consumes `Profile.Account.Suspended` as a risk signal |
| E19 Admin Portal | Admin portal UI surfaces E02 admin API endpoints |
| E23 Audit & Compliance | Uses `platform.audit_events` records produced by E02 |

---

## 18. E2E Scenarios

### Scenario 1: New Customer — Profile View After Registration
1. Customer registers via E01 → `Identity.User.Registered` emitted (payload: `user_id`, `actor_type`)
2. E02 consumes event → calls E01 service to fetch `email` and `mobile_number` → creates `profile.user_profiles` record with `account_status = active`; personal fields (`first_name`, etc.) NULL
3. Customer authenticates and calls `GET /v1/users/profile`
4. **Verify**: Profile returned with empty optional fields; `account_status = active`
5. **Verify**: `profile.user.profile-created` audit record in `platform.audit_events`

### Scenario 2: Customer Updates Personal Information
1. Customer calls `PUT /v1/users/profile/personal` with new `first_name`, `last_name`, `date_of_birth`
2. System validates fields; persists; emits `Profile.User.PersonalInfoUpdated`
3. **Verify**: Profile reflects new values
4. **Verify**: `profile.user.personal-info-updated` audit record with masked identifiers

### Scenario 3: Mobile Number Change Flow
1. Customer calls `POST /v1/users/profile/mobile/change` with `new_mobile: "+26777123456"`
2. E02 sends OTP to new number via E01's `OtpDeliveryContract`
3. Customer enters OTP via `POST /v1/users/profile/mobile/verify`
4. E02 verifies OTP, atomically updates mobile in `identity.users` and `profile.user_profiles`
5. **Verify**: Both records reflect new mobile number; `Profile.User.MobileChanged` event emitted
6. **Verify**: `profile.user.mobile-changed` audit record created with masked old/new values

### Scenario 4: Account Suspension and Reactivation
1. Admin calls `POST /v1/admin/users/{user_id}/suspend` with reason
2. Account status transitions to `suspended`; history record created; `Profile.Account.Suspended` emitted
3. **Verify**: `GET /v1/admin/users/{user_id}/profile/status` shows `suspended` with reason and timestamp
4. Admin calls `POST /v1/admin/users/{user_id}/reactivate`
5. Account transitions to `active`; history record created; `Profile.Account.Reactivated` emitted
6. **Verify**: Status history shows full transition chain

### Scenario 5: Closure Blocked by Financial Obligation
1. Customer calls `POST /v1/users/profile/closure/request`
2. System checks E06: wallet balance = 50 BWP (non-zero)
3. System returns `409 CLOSURE_BLOCKED_BY_OBLIGATIONS` with `blocked_by: ["wallet_balance"]`
4. **Verify**: Account status remains unchanged; `profile.account.closure-blocked` audit record with obligation detail
5. Customer withdraws funds → tries closure again
6. E06 reports zero balance → `profile.user_profiles.account_status = pending_closure`
7. Admin confirms permanent closure → account transitions to `closed`

---

## 19. QA Scenarios

### 19.1 Functional Tests (per story)
Every story requires:
- Happy-path test with valid data and expected success response
- Required-field validation test (missing/null mandatory fields)
- Format validation test (invalid date, invalid mobile format, oversized photo, etc.)
- Idempotency test where applicable (same request with same `Idempotency-Key` twice → same result)
- Audit event verification (assert `platform.audit_events` record created with correct fields)
- Event emission verification (assert `platform.outbox_events` contains correct event)

### 19.2 Security Tests
- IDOR: authenticated user cannot view or modify another user's profile (`403 FORBIDDEN`)
- Admin without `profile.read` cannot access admin profile endpoint (`403 FORBIDDEN`)
- Protected fields rejected: include `id` in PUT body → `FIELD_NOT_PERMITTED`
- Contact change without verification: direct PUT with new email in body → field ignored/rejected
- Email change: confirm both `identity.users.email` and `profile.user_profiles.email` are unchanged until OTP verified
- Profile photo URL: assert response contains signed URL, not raw storage key
- Data export: assert raw credentials/hashes are absent from export package
- Suspension without `account.suspend` permission → `403 FORBIDDEN`
- Mobile/email masking in structured logs

### 19.3 Concurrency Tests
- Concurrent PUT personal info with same `Idempotency-Key`: exactly one persisted; duplicate returns same 200
- Concurrent email change initiation requests: only one pending challenge created; second returns `409 CONTACT_CHANGE_IN_PROGRESS`
- Concurrent closure request and admin suspension: state machine correctly serializes transitions
- Concurrent profile reads: no inconsistency; consistent reads

### 19.4 Financial Obligation Gate Tests
- Closure blocked when wallet balance > 0: verify `blocked_by` includes `wallet_balance`
- Closure blocked when pending transaction exists: verify `blocked_by` includes `pending_transactions`
- Closure allowed when all obligations resolved: verify status transitions to `pending_closure`
- Admin permanent closure of account with obligations: verify rejection even on admin path

### 19.5 Accessibility Tests
- Profile edit screens pass WCAG AA contrast requirements
- Photo upload control is keyboard-accessible with screen-reader labeling
- Status history list is navigable by keyboard
- Error messages associated with their form fields via ARIA

### 19.6 Performance Tests
- `GET /v1/users/profile` P95 ≤ 200 ms under sustained concurrent load
- `PUT /v1/users/profile/personal` P95 ≤ 300 ms
- Status history view with 100 history entries P95 ≤ 200 ms

---

## 20. Acceptance Criteria

| ID | Criterion |
|---|---|
| AC-001 | An authenticated user can call `GET /v1/users/profile` and receive their own profile data including signed photo URL and account status |
| AC-002 | An authenticated user who calls `GET /v1/users/profile` using a user_id path parameter for a different user receives `403 FORBIDDEN` |
| AC-003 | An authenticated user can update their first name, last name, date of birth, and address via `PUT /v1/users/profile/personal` and the changes persist |
| AC-004 | A PUT request that includes `id` or `account_status` in the request body is rejected with a field-not-permitted error |
| AC-005 | A date of birth value representing an age below the configured minimum returns a validation error |
| AC-006 | An authenticated user can upload a profile photo and the photo URL returned in subsequent profile reads is a time-limited signed URL |
| AC-007 | A profile photo upload that exceeds the configured maximum file size returns `413 FILE_TOO_LARGE` |
| AC-008 | An authenticated user can initiate an email change, receive an OTP at the new email, verify it, and have the email updated in both the profile and identity records |
| AC-009 | After initiating an email change but before OTP verification, a `GET /v1/users/profile` response still shows the original email |
| AC-010 | An authenticated user can initiate a mobile number change, receive an OTP at the new number, verify it, and have the mobile updated in both the profile and identity records |
| AC-011 | Attempting to change to a mobile number already in use by another account returns a success-shaped response that does not disclose the conflict (OTP is sent to non-existent number scenario or silent block) |
| AC-012 | An authenticated user can view their account status and see a list of all historical status transitions with timestamps |
| AC-013 | An admin with `account.suspend` permission can suspend an active account with a reason, and the status history records the transition with actor ID and reason |
| AC-014 | An admin with `account.reactivate` permission can reactivate a suspended account, and the status history records the transition |
| AC-015 | A closure request on an account with a non-zero wallet balance is rejected with `409 CLOSURE_BLOCKED_BY_OBLIGATIONS` and `blocked_by` includes `wallet_balance` |
| AC-016 | A closure request on an account with no financial obligations transitions the account to `pending_closure` |
| AC-017 | An admin with `account.close` permission can permanently close a `pending_closure` account; the account transitions to `closed` |
| AC-018 | An admin permanent closure attempt on a `pending_closure` account with a non-zero wallet balance is rejected even on the admin path |
| AC-019 | An authenticated user can request a data export; a `Profile.User.DataExportRequested` audit record is created |
| AC-020 | The data export package does not contain any credential hashes, OTP values, raw IP addresses, or session tokens |
| AC-021 | When `Identity.User.Registered` is received, E02 creates a `profile.user_profiles` record with `account_status = active`; processing the same event twice produces no duplicate records |
| AC-022 | All profile mutation operations produce an immutable record in `platform.audit_events` with correct action string, actor ID, and correlation ID |
| AC-023 | All E02 integration events are published to `platform.outbox_events` using the outbox pattern |
| AC-024 | All configurable policy values (minimum age, photo size limit, rate limits) are sourced from `platform.configuration_values`; none are hard-coded |
| AC-025 | A personal information update with the same `Idempotency-Key` submitted twice within the idempotency window results in a single write and returns the same `200` response |
| AC-026 | When a user initiates an email or mobile number change, a security alert notification event is emitted for the current (old) contact address independently of and simultaneously with the OTP sent to the new contact address |
| AC-027 | An authenticated user in `pending_closure` status can cancel their closure request via `DELETE /v1/users/profile/closure/request`; the account returns to `active`, a `profile.account_status_history` record is created, and `profile.account.closure-cancelled` audit record is written |
| AC-028 | When OTP verification for a contact change succeeds but the subsequent atomic update to E01 fails, both `profile.user_profiles` and `identity.users` remain at their original values, the pending challenge remains active, and the response is a retryable `500`; a subsequent retry of the verify endpoint with the same challenge and OTP succeeds when E01 is available |

---

## 21. Definition of Done

**Story-level**:
- All story-specific acceptance criteria implemented and passing
- API endpoint(s) returning standard envelope with correct status codes
- DB entities created with migrations; indexes and constraints applied
- Domain/integration events published via outbox for all required transitions
- Audit records written to `platform.audit_events` for all auditable operations
- All configurable values sourced from platform configuration (no hard-coding)
- Unit tests, integration tests, and API tests written and passing
- Security test cases written and passing (IDOR assertions, protected-field assertions, masked PII in logs)
- Flutter screens implemented for applicable stories with all states (loading, success, error)
- Admin Next.js screens implemented for applicable stories with all states
- Accessibility requirements met for all screens
- OpenAPI documentation generated and accurate

**Epic-level**:
- All 15 stories meet their story-specific DoD
- Full E2E profile lifecycle (registration → profile view → update → contact change → status view → closure request) passes
- Cross-story dependency tests pass (closure gate, contact change atomicity, admin audit)
- Security review: no IDOR paths, no unprotected fields, no plaintext PII in logs/events
- No unresolved CRITICAL or HIGH defects blocking release
- Test coverage ≥ 80% for the profile module
- OpenAPI contract published and validated
- All E02 audit events verified as INSERT-only in `platform.audit_events`
- All TBD items explicitly flagged and not invented

---

## 22. Dependencies

### 22.1 Dependencies on Platform Foundation (E999)

| Platform Foundation Deliverable | E02 Usage |
|---|---|
| `profile` PostgreSQL schema (empty) | E02 creates all `profile.*` tables here |
| `platform.outbox_events` + OutboxService | All E02 integration events |
| `platform.audit_events` + AuditService | All E02 audit records |
| `platform.configuration_values` + ConfigurationService | Profile policy values |
| `platform.idempotency_records` + IdempotencyService | Idempotent profile operations |
| Standard API envelope + error taxonomy | All E02 API responses |
| `X-Correlation-ID` middleware | Correlation ID in all E02 logs and events |
| Laravel Horizon | Async export processing jobs |
| Design system tokens and shared components | All E02 Flutter and Next.js screens |

### 22.2 Dependencies on E01 — Central Identity & Authentication

| E01 Deliverable | E02 Usage |
|---|---|
| `Identity.User.Registered` event | Trigger for profile record creation |
| `identity.users` table | Read for contact info initialization; updated via service call during contact change |
| `OtpDeliveryContract` | OTP challenge issuance and verification for contact changes |
| JWT access token + JWKS | Authentication validation for all E02 protected endpoints |
| Audit HMAC-IP standard (BR-027) | E02 audit records use same HMAC-SHA256 IP protection |

### 22.3 Dependencies on Future Epics (Blocking for specific features)

| Epic | Feature | Blocker |
|---|---|---|
| E04 | Account suspension/reactivation/close operations | Requires named permissions to be seeded |
| E06 | Closure gate check | Requires wallet balance query capability |
| E11 | Closure gate check | Requires pending transaction query capability |
| E17 | Contact change OTP delivery, closure/export notifications | Without E17, OTP delivery is stub/mock |

---

## 23. Risks

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| Object storage provider not selected (TBD) | High | Medium | Abstract behind `ProfilePhotoStorageContract`; use local disk/stub for development |
| Atomic cross-module contact update failure (E02 updates, E01 call fails) | Low | High | Use saga/outbox pattern or compensating event; clear error response; retry guidance |
| Financial obligation check API availability (E06/E11 not yet implemented) | High | Medium | Mock/stub in E02; closure gate always blocks in stub mode (fail-safe) |
| E01 OTP challenge infrastructure changes affecting mobile/email change flows | Low | Medium | E02 consumes via contract; interface changes require coordinated update |
| Data export performance for users with large history | Low | Medium | Asynchronous processing via Horizon job; streaming output format |
| Profile record creation race (duplicate `Identity.User.Registered` delivery) | Low | High | Idempotent insert using `user_id` unique constraint; event processed once |
| E01 service call failure at profile creation time | Medium | High | Event handler MUST NOT create a partial (email/mobile-less) record silently; the event MUST be retried via dead-letter / Horizon retry until E01 responds; alert if retry budget exhausted |

---

## 24. TBD / Open Questions

| ID | Status | Item | Impact |
|---|---|---|---|
| TBD-E02-001 | **OPEN** | Object storage provider for profile photos and data exports | Affects `ProfilePhotoStorageContract` concrete implementation; production deployment |
| TBD-E02-002 | **OPEN** | Cross-module contact update coordination mechanism (synchronous API call vs. event-driven command to E01) | Affects atomicity guarantee and rollback strategy for contact changes |
| TBD-E02-003 | **OPEN** | Data export format (JSON, CSV, ZIP archive) and delivery mechanism (download link vs. in-app) | Affects US-USER-015 export implementation |
| TBD-E02-004 | **OPEN** | Minimum user age configuration key name and default value | Affects FR-E02-008 date-of-birth validation; key must be registered in `platform.configuration_values` |
| TBD-E02-005 | **OPEN** | Maker-checker configuration for account suspension/closure (which operations require dual approval and which role combination) | Affects US-USER-010, US-USER-012 admin workflow |

No TBD values have been invented. All open items above are explicitly marked and not resolved in this specification.

---

## 25. Platform Alignment

- Backend API: Laravel / PHP 8.4+
- Admin/Web: Next.js + TypeScript
- Mobile: Flutter (Customer and Agent applications)
- DB: PostgreSQL (`profile` schema)
- Cache/Queue: Redis + Laravel Horizon
- API: REST/OpenAPI
- Design: OleKard shared token-driven design system
- State management: Riverpod (Flutter)
- No TBD values invented
- No configurable values hard-coded
