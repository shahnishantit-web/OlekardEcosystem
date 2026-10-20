# E03 — Device & Session Management

**Feature**: `003-device-session-management` | **Epic**: E03 | **Stories**: 9 | **Status**: Specified

---

## 1. Problem Statement

E01 (Central Identity & Authentication) establishes authenticated identity and includes the minimal device-awareness required for authentication — detecting new device fingerprints during login, requiring OTP verification for unrecognised devices, and maintaining session-to-device associations. However, E01 does not provide users or admins with a first-class, dedicated surface for managing the full lifecycle of registered devices: explicitly registering devices, viewing all devices across sessions, controlling trust levels, detecting fingerprint drift, identifying suspicious device patterns, sending actionable new-device alerts, or permanently blocking compromised devices.

Without E03, users cannot see which devices access their account, cannot proactively block a device they suspect has been stolen, and the platform has no systematic capability to detect device-level security anomalies beyond the initial new-device-at-login detection. Admins similarly lack the tools to block a specific device across all sessions with a single operation.

E03 builds directly on E01's `identity.devices` and `identity.sessions` foundation and adds the extended device lifecycle management, device security intelligence, and user-facing device management that a mobile-first payment platform requires.

---

## 2. Scope

E03 covers exactly the 9 stories assigned to Epic E03 in the approved 538-story backlog:

- **Device registration**: explicit API for registering a device outside of the authentication flow
- **Fingerprint generation**: server-validated device fingerprint creation
- **Trust management**: user-controlled device trust promotion and revocation
- **Device listing**: paginated view of all registered devices with metadata
- **Device removal**: removing a device and revoking all its associated sessions
- **Device change detection**: detecting fingerprint drift on known devices
- **Suspicious device detection**: identifying anomalous device usage patterns
- **New device notifications**: alerting users to new device registrations with acknowledgment workflow
- **Device blocking**: blocking a compromised device from all future authentication

**In scope**:
- Extensions to the `identity.devices` record (new columns and status values via migration)
- New `identity` schema tables: `device_trust_history`, `device_security_events`
- All 9 device management stories with API, persistence, events, audit, and QA
- Admin panel device oversight (device list, block)
- Configuration keys for all device policy values

**Out of scope**:
- Session creation, refresh, or revocation outside of device-triggered operations — E01
- OTP challenge infrastructure — E01 (E03 coordinates with E01's `OtpDeliveryContract`)
- New-device OTP verification during login — E01 (US-AUTH-017)
- User profile management — E02
- Role and permission management — E04
- Notification delivery — E17 (E03 emits events; E17 delivers)
- Risk scoring and fraud rules — E18 (E03 emits signals; E18 processes them)
- Geolocation analysis — E18

---

## 3. Goals

1. Provide authenticated users with a complete, self-service device management surface.
2. Detect fingerprint drift on known devices and re-trigger verification when any mismatch is detected between the submitted fingerprint and the stored hash (binary comparison — no similarity threshold).
3. Identify suspicious device patterns (multi-account usage, emulator detection, abnormal device counts) and surface them as risk signals.
4. Notify users of new device registrations with an acknowledgment workflow; auto-block on denial.
5. Allow users and admins to permanently block compromised devices, with immediate session revocation.
6. Enforce IDOR/BOLA prevention: users manage only their own devices; admin operations require named permissions.
7. Produce auditable, structured device-lifecycle events consumable by downstream domains (E17, E18, E26).
8. Remain extraction-ready: the Device Management bounded context is isolatable as an independent service.

---

## 4. Non-Goals

E03 does NOT:
- Implement new-device detection during authentication — E01 (US-AUTH-016, US-AUTH-017).
- Implement session listing or individual session revocation — E01 (US-AUTH-026, US-AUTH-027).
- Implement global logout (all-sessions) — E01 (US-AUTH-023).
- Implement OTP delivery infrastructure — E01.
- Implement notification delivery — E17.
- Implement fraud scoring, AML rules, or geolocation anomaly detection — E18.
- Implement full RBAC permission management — E04.
- Implement user profile management — E02.
- Hard-code any configurable policy value (max devices per user, trust validity, change detection toggle, suspicious detection rules).
- Create a second audit registry, outbox table, or idempotency store.

---

## 5. Actors

| Actor | Description | Device Access |
|---|---|---|
| **Customer** | End-user of the Customer mobile application | Own devices only (self-service) |
| **Agent** | Registered field agent using the Agent mobile application | Own devices only (self-service) |
| **Admin** | Administrator using the Admin/Enterprise web application | Any user's devices (with named permission) |
| **System** | Internal event consumer (E03's own event consumers for E01 events) | Processes new-device and session events; updates device records |

---

## 6. User Journeys

### 6.1 Explicit Device Registration
Authenticated user opens device management settings → selects "Add Device" → app sends device attributes to API → server validates and hashes fingerprint → device registered in `pending_verification` state → user receives OTP challenge via E01 infrastructure → verifies OTP → device promoted to `trusted`.

### 6.2 Generate Device Fingerprint
Mobile app needs a stable, server-validated device fingerprint → sends collected device attributes to `POST /v1/devices/fingerprint` → server computes and validates the fingerprint hash → returns the hash for use in subsequent registration and authentication requests.

### 6.3 Mark Device as Trusted
Authenticated user views device list → selects an existing registered device in `pending_verification` state → marks it as trusted → system updates device status, records trust history, emits event.

### 6.4 View Registered Devices
Authenticated user navigates to "My Devices" → receives paginated list of all registered devices with: device name, platform, trust status, last-seen timestamp. No raw fingerprint data returned.

### 6.5 Remove a Device
Authenticated user selects a device → chooses "Remove" → confirms → system removes the device, immediately revokes all active sessions on that device (via E01 session revocation), records history, emits event.

### 6.6 Device Fingerprint Change Detection
User authenticates from a known device but with a changed fingerprint (OS update, hardware swap, device reset) → E03 change detection middleware intercepts → compares current fingerprint against stored hash → any mismatch (when change detection is enabled) demotes the device to `pending_verification` → user must re-verify via OTP (E01 challenge flow) before session is granted. The comparison is binary: an exact hash match means no change; any difference, however small, is treated as a fingerprint change. No similarity threshold or partial-match tolerance is applied.

### 6.7 Suspicious Device Detection
E03 background job or authentication hook analyses device usage patterns → detects a device shared across multiple accounts, virtual device indicators, or anomalous device counts → emits `Device.Device.SuspiciousDetected` event → E18 receives signal and applies risk rules → Admin portal surfaces alert.

### 6.8 New Device Notification and Acknowledgment
User logs in from a new device (E01 US-AUTH-016 detected) → E03 consumes `Identity.Device.NewDeviceDetected` → triggers notification via E17 with device details and two CTAs → if user confirms "This was me": acknowledgment recorded → if user denies "This wasn't me": device blocked and all sessions revoked immediately.

### 6.9 Block Compromised Device
Authenticated user suspects device is compromised → selects device → "Block Device" → system updates status to `blocked`, revokes all sessions, records history, emits event, notifies user. Blocked device is permanently denied authentication until admin unblocks.

### 6.10 Admin Device Oversight
Admin views any user's device list → can block any specific device → system enforces `device.block` permission → audit recorded with admin actor ID and subject user ID.

---

## 7. Business Rules

| ID | Rule |
|---|---|
| BR-001 | Authenticated users MUST only view and manage their own devices. Accessing another user's devices is IDOR and MUST be rejected with `403 FORBIDDEN`. |
| BR-002 | Admin actors with `device.read` permission MAY view any user's device list. Admin actors with `device.block` permission MAY block any device. Admin actors with `device.remove` permission MAY remove any device. |
| BR-003 | A device fingerprint is computed by hashing device attributes. Raw attribute components MUST NOT be stored server-side. Only the resulting hash is persisted in `identity.devices.device_fingerprint`. |
| BR-004 | Device registration is idempotent per `(user_id, device_fingerprint)`: registering the same device twice MUST return the existing device record without creating a duplicate. |
| BR-005 | The maximum number of registered devices per user is configurable via platform configuration (`device.max_devices_per_user`). Registration exceeding this limit MUST be rejected with `DEVICE_LIMIT_EXCEEDED`. |
| BR-006 | A blocked device MUST be denied authentication in E01's authentication middleware, regardless of session state. E01 MUST check device status prior to token issuance. |
| BR-007 | Blocking a device MUST immediately revoke all active sessions associated with that device by coordinating with E01's session revocation service. |
| BR-008 | Removing a device MUST immediately revoke all active sessions associated with that device. |
| BR-009 | All device trust state changes (pending → trusted, trusted → blocked, blocked → revoked, etc.) MUST be recorded in `identity.device_trust_history` as an append-only entry. |
| BR-010 | Device fingerprint change detection MUST compare the submitted fingerprint against the stored hash for the declared `device_id`. Any mismatch (when change detection is enabled via `device.change_detection_enabled`) demotes the device to `pending_verification`. |
| BR-011 | Suspicious device detection rules MUST NOT be disclosed to end users in API responses. Risk signals are communicated internally via events to E18 only. |
| BR-012 | A new device notification (triggered by `Identity.Device.NewDeviceDetected`) MUST include device name, platform, timestamp, and management CTAs. It MUST be delivered via E17; delivery failure is non-blocking for the authentication flow. |
| BR-013 | A "This wasn't me" response to a new device notification MUST trigger: (a) device block, (b) all-session revocation for that device, (c) `Device.Device.NewDeviceDenied` event, (d) audit record. |
| BR-014 | New device acknowledgment windows are configurable (`device.new_device_acknowledgment_ttl`). After the TTL, no acknowledgment or denial can be submitted for that notification. |
| BR-015 | All device management operations (register, trust, remove, block, detect) MUST produce an immutable audit record in `platform.audit_events` via the Platform Foundation `AuditService`. |
| BR-016 | All configurable device policy values MUST be read from `platform.configuration_values` via `ConfigurationService`. No values may be hard-coded in domain logic. |
| BR-017 | State-changing device operations (register, trust, remove, block) MUST be idempotent when an `Idempotency-Key` header is provided. |
| BR-018 | Admin device operations MUST record both the admin actor ID and the subject user ID in every audit record. |
| BR-019 | Blocked devices remain blocked until an admin with `device.unblock` permission explicitly unblocks them. Users cannot self-unblock a device they blocked. |
| BR-020 | Device fingerprint MUST NOT appear in any API response — only opaque device metadata (name, platform, status, timestamps) is returned. |

---

## 8. Functional Requirements

| ID | Story | Requirement |
|---|---|---|
| FR-E03-001 | US-DEVICE-001 | The system MUST provide `POST /v1/devices` for authenticated users to explicitly register a device outside of the authentication flow. |
| FR-E03-002 | US-DEVICE-001 | Device registration MUST be idempotent: submitting the same fingerprint for the same user returns the existing device record without creating a new one. |
| FR-E03-003 | US-DEVICE-001 | The system MUST reject registration when the user's device count equals or exceeds the configured maximum (`device.max_devices_per_user`). |
| FR-E03-004 | US-DEVICE-001 | A new device registration MUST create an `identity.devices` record with `status = pending_verification` and initiate an OTP challenge via E01's `OtpDeliveryContract`. |
| FR-E03-005 | US-DEVICE-001 | All device registrations MUST produce an `identity.device.registered` audit record and emit a `Device.Device.Registered` event. |
| FR-E03-006 | US-DEVICE-002 | The system MUST provide `POST /v1/devices/fingerprint` for mobile clients to compute and validate a device fingerprint from submitted device attributes. |
| FR-E03-007 | US-DEVICE-002 | The server MUST hash the submitted attributes to produce a fingerprint. Raw attribute values MUST NOT be stored. The resulting hash is returned to the client for use in registration and authentication. |
| FR-E03-008 | US-DEVICE-002 | The system MUST validate that the submitted attributes provide sufficient entropy (minimum configurable via `device.fingerprint_min_entropy_bits`). Insufficient-entropy submissions MUST be rejected. |
| FR-E03-009 | US-DEVICE-002 | Fingerprint generation MUST produce an `identity.device.fingerprint-generated` audit record. |
| FR-E03-010 | US-DEVICE-003 | An authenticated user MUST be able to mark a registered device in `pending_verification` or `trusted` state as explicitly trusted via `POST /v1/devices/{device_id}/trust`. |
| FR-E03-011 | US-DEVICE-003 | Marking a device as trusted MUST: update `identity.devices.status` to `trusted` and `trusted_at` to the current timestamp, append an `identity.device_trust_history` entry, produce an `identity.device.trusted` audit record, and emit a `Device.Trust.Promoted` event. |
| FR-E03-012 | US-DEVICE-003 | Marking a blocked device as trusted MUST be rejected with `DEVICE_BLOCKED` — only an admin can unblock. |
| FR-E03-013 | US-DEVICE-004 | An authenticated user MUST be able to retrieve a paginated list of all devices registered to their account via `GET /v1/devices`. |
| FR-E03-014 | US-DEVICE-004 | The device list response MUST include: `device_id`, `device_name`, `platform`, `status`, `trusted_at`, `last_seen_at`, `created_at`. The raw fingerprint hash MUST NOT be included in any response. |
| FR-E03-015 | US-DEVICE-004 | An Admin actor with `device.read` permission MUST be able to retrieve any user's device list via `GET /v1/admin/users/{user_id}/devices`. |
| FR-E03-016 | US-DEVICE-004 | The system MUST reject any attempt by a non-admin authenticated user to retrieve another user's device list with `403 FORBIDDEN`. |
| FR-E03-017 | US-DEVICE-005 | An authenticated user MUST be able to remove a registered device from their account via `DELETE /v1/devices/{device_id}`. |
| FR-E03-018 | US-DEVICE-005 | Device removal MUST: revoke all active sessions associated with the device (via E01's session revocation service), soft-delete or mark the `identity.devices` record as `revoked`, append an `identity.device_trust_history` entry, produce an `identity.device.removed` audit record, and emit a `Device.Device.Removed` event. |
| FR-E03-019 | US-DEVICE-005 | An Admin actor with `device.remove` permission MUST be able to remove any device from any user's account via `DELETE /v1/admin/devices/{device_id}`. |
| FR-E03-020 | US-DEVICE-005 | Removing a blocked device MUST be permitted. Removing a device that is the only device on the account MUST be permitted (user becomes device-less). |
| FR-E03-021 | US-DEVICE-006 | During authentication, when a known device (`device_id` provided) presents a fingerprint that differs from the stored hash (any mismatch, when change detection is enabled via `device.change_detection_enabled`), E03's change detection middleware MUST classify this as a device change. |
| FR-E03-022 | US-DEVICE-006 | On device change detection, the system MUST: demote the device to `pending_verification`, record a `device_security_events` entry with `event_type = change_detected`, produce an `identity.device.change-detected` audit record at WARN severity, and emit a `Device.Device.ChangeDetected` event. |
| FR-E03-023 | US-DEVICE-006 | After a device change is detected, authentication MUST proceed through the new-device OTP verification flow (E01 US-AUTH-017) before a session is issued. |
| FR-E03-046 | US-DEVICE-006 | After the OTP re-verification (FR-E03-023) completes successfully, the system MUST replace `identity.devices.device_fingerprint` with the newly submitted and verified fingerprint hash. If OTP re-verification fails or is abandoned, the stored hash MUST NOT be replaced and the device MUST remain in `pending_verification` state. This ensures that subsequent authentications from the updated device fingerprint match the stored hash and do not re-trigger change detection. |
| FR-E03-024 | US-DEVICE-006 | Device change detection is toggled by `device.change_detection_enabled` (configurable). When disabled, fingerprint drift is silently accepted and the device remains at its current trust level. |
| FR-E03-025 | US-DEVICE-007 | The system MUST detect suspicious device patterns using configurable rules including: a single device fingerprint associated with more than the configured maximum number of accounts, submission of known emulator/virtual device attribute signatures, and a single user with an abnormal number of device changes within a rolling window. |
| FR-E03-026 | US-DEVICE-007 | Suspicious device detection MUST record a `device_security_events` entry with `event_type = suspicious_detected` and emit a `Device.Device.SuspiciousDetected` event containing the internal risk signals for E18 consumption. |
| FR-E03-027 | US-DEVICE-007 | The suspicious classification and detection signals MUST NOT be disclosed to end users in API responses. The API response for the triggering authentication or registration request proceeds normally; the detection is a side-channel signal only. |
| FR-E03-028 | US-DEVICE-007 | Suspicious device detection is toggled by `device.suspicious_detection_enabled` (configurable). |
| FR-E03-029 | US-DEVICE-008 | When E03 receives the `Identity.Device.NewDeviceDetected` event from E01, it MUST emit a `Device.Device.NewDeviceNotificationSent` event for E17 to deliver a notification to the user. |
| FR-E03-030 | US-DEVICE-008 | The notification payload MUST include: device name, platform, registration timestamp, and a direct link to the device management screen. |
| FR-E03-031 | US-DEVICE-008 | The system MUST provide `POST /v1/devices/{device_id}/acknowledge` for authenticated users to respond to new-device notifications. The request body MUST include `acknowledged: true` ("This was me") or `acknowledged: false` ("This wasn't me"). |
| FR-E03-032 | US-DEVICE-008 | A "This wasn't me" (`acknowledged: false`) response MUST: block the device, revoke all its sessions, record a `Device.Device.NewDeviceDenied` event, and produce an `identity.device.new-device-denied` audit record. |
| FR-E03-033 | US-DEVICE-008 | The acknowledgment window is configurable (`device.new_device_acknowledgment_ttl`). After the TTL, the endpoint MUST return `ACKNOWLEDGMENT_WINDOW_EXPIRED`. |
| FR-E03-034 | US-DEVICE-008 | Notification delivery failure to E17 is non-blocking: the authentication flow is not affected. The failure MUST be logged and a metric incremented. |
| FR-E03-035 | US-DEVICE-009 | An authenticated user MUST be able to block a device registered to their account via `POST /v1/devices/{device_id}/block`. |
| FR-E03-036 | US-DEVICE-009 | An Admin actor with `device.block` permission MUST be able to block any device via `POST /v1/admin/devices/{device_id}/block`. Blocking MUST require a `reason` field in the request body for admin-path operations. |
| FR-E03-037 | US-DEVICE-009 | Device blocking MUST atomically: update `identity.devices.status` to `blocked` and set `blocked_at` / `blocked_reason`, revoke all active sessions on that device (via E01 session revocation), append an `identity.device_trust_history` entry, produce an `identity.device.blocked` audit record at WARN severity, and emit a `Device.Device.Blocked` event. |
| FR-E03-038 | US-DEVICE-009 | A blocked device MUST be permanently denied authentication. E01's authentication middleware MUST reject tokens for blocked devices with `DEVICE_BLOCKED`. |
| FR-E03-039 | US-DEVICE-009 | Only an Admin with `device.unblock` permission can unblock a blocked device via `POST /v1/admin/devices/{device_id}/unblock`. Self-service unblocking is not permitted. |
| FR-E03-040 | All | All E03 device operations MUST enforce IDOR/BOLA: `user_id` from the authenticated JWT MUST match the device's owner. Mismatches MUST return `403 FORBIDDEN`. |
| FR-E03-041 | All | All device management operations MUST produce immutable audit records in `platform.audit_events` via `AuditService`. |
| FR-E03-042 | All | All E03 domain events MUST be published via the Platform Foundation outbox pattern (`platform.outbox_events`). |
| FR-E03-043 | All | All configurable device policy values MUST be read from `platform.configuration_values` via `ConfigurationService`. No values may be hard-coded. |
| FR-E03-044 | Config | The system MUST seed all device management configuration defaults in `platform.configuration_values` via an idempotent migration. |
| FR-E03-045 | Config | E01's authentication middleware MUST be updated to check `identity.devices.status` for the `blocked` value and reject authentication with `DEVICE_BLOCKED` when the device is blocked. |

---

## 8.1 Configuration Registry

All device management configuration keys are seeded into `platform.configuration_values` via an idempotent migration. Defaults are initial values only; all are runtime-configurable by authorized admins.

| Key | Default | Type | Description |
|---|---|---|---|
| `device.max_devices_per_user` | `10` | number | Maximum registered devices per user |
| `device.new_device_notification_enabled` | `true` | boolean | Send notifications on new device registration |
| `device.new_device_acknowledgment_ttl` | `86400` | number | Seconds user has to acknowledge/deny a new device (24 hours) |
| `device.change_detection_enabled` | `true` | boolean | Enable fingerprint change detection on known devices |
| `device.suspicious_detection_enabled` | `true` | boolean | Enable suspicious device pattern detection |
| `device.fingerprint_min_entropy_bits` | `32` | number | Minimum entropy (bits) required for a valid device fingerprint |
| `device.max_accounts_per_fingerprint` | `3` | number | Maximum accounts sharing the same device fingerprint before suspicious detection fires |
| `device.max_device_changes_per_window` | `3` | number | Maximum device fingerprint changes per user per rolling window before suspicious detection fires |
| `device.suspicious_change_window_hours` | `24` | number | Rolling window (hours) for device-change anomaly counting |

### Platform Security Minimums

| Key | Minimum | Notes |
|---|---|---|
| `device.max_devices_per_user` | `1` | Must allow at least one device |
| `device.new_device_acknowledgment_ttl` | `300` | At least 5 minutes |
| `device.fingerprint_min_entropy_bits` | `16` | At least 16 bits |

---

## 9. Security Requirements

| Requirement | Detail |
|---|---|
| IDOR/BOLA prevention | Every device read and write MUST assert that the `user_id` from the authenticated JWT matches `identity.devices.user_id`. Admin paths require `device.read`, `device.block`, or `device.remove` permissions. A mismatch returns `403 FORBIDDEN` — never `404`. |
| Fingerprint privacy | Raw device attribute components MUST NOT be stored. Only the server-computed fingerprint hash is persisted. The fingerprint hash MUST NOT appear in any API response. |
| Blocked device enforcement | E01's authentication middleware MUST reject sessions for devices with `status = blocked`. The check MUST occur before token issuance and cannot be bypassed by presenting a valid refresh token for a blocked device. |
| PII masking in logs | Mobile numbers and email addresses in device notification events and logs MUST be masked. Device-related structured logs MUST NOT expose raw PII. |
| Suspicious detection opacity | Detection rules and risk signal payloads MUST NOT be returned in API responses. Only internal events carry full detail. |
| Concurrent operations | Trust promotion and device blocking MUST use database-level optimistic or pessimistic locking to prevent race conditions on the same device record. |
| Device removal security | Device removal MUST revoke all sessions immediately — the revocation MUST complete before the API returns success. Partial revocation is not acceptable. |
| Acknowledgment authorization | The acknowledgment endpoint MUST verify the authenticated user is the device owner. Users cannot acknowledge/deny devices owned by another user. |
| Admin operation audit | Admin-path device operations MUST record both the admin actor ID and the subject user ID in the audit record. |
| Transport | All API communication MUST use HTTPS/TLS. Inherited from Platform Foundation (FR-071). |
| Error responses | Error responses MUST use the standard API error envelope. Stack traces and internal state MUST NOT appear in production responses. |

### Performance Requirements

| Operation | P95 Target | Notes |
|---|---|---|
| View device list | ≤ 200 ms | Paginated query; no fingerprint data loaded |
| Register device | ≤ 300 ms | Includes idempotency check, hash validation, record creation |
| Block device | ≤ 500 ms | Includes status update and synchronous session revocation |
| Acknowledge new device | ≤ 300 ms | Includes acknowledgment window check and conditional block |

Performance tests are mandatory before release. Validated load profile (from performance test suite): 100 concurrent authenticated users, 10 devices per user, sustained 60-second load. P95 targets verified in `tests/performance/profile/`.

### Observability Requirements

E03 MUST emit the following metrics per Constitution Principle 13:

| Metric | Type | Description |
|---|---|---|
| `device.registered` | Counter | Explicit device registrations; broken down by platform |
| `device.removed` | Counter | Device removals; broken down by actor type (USER, ADMIN) |
| `device.blocked` | Counter | Device blocks; broken down by actor type |
| `device.change_detected` | Counter | Fingerprint change detections |
| `device.suspicious_detected` | Counter | Suspicious device pattern detections; broken down by rule |
| `device.new_device_notification.sent` | Counter | New device notifications dispatched |
| `device.new_device_notification.failed` | Counter | New device notification dispatch failures (non-blocking; delivery failure does not affect auth) |
| `device.new_device_notification.acknowledged` | Counter | New device acknowledgments (true/false) |

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
| US-DEVICE-001 | POST | `/v1/devices` | Bearer | Yes | Self only |
| US-DEVICE-002 | POST | `/v1/devices/fingerprint` | Bearer | No | Self only |
| US-DEVICE-003 | POST | `/v1/devices/{device_id}/trust` | Bearer | Yes | Self only |
| US-DEVICE-004 | GET | `/v1/devices` | Bearer | — | Self only |
| US-DEVICE-004 | GET | `/v1/devices/{device_id}` | Bearer | — | Self only |
| US-DEVICE-004 | GET | `/v1/admin/users/{user_id}/devices` | Admin Bearer | — | `device.read` |
| US-DEVICE-005 | DELETE | `/v1/devices/{device_id}` | Bearer | Yes | Self only |
| US-DEVICE-005 | DELETE | `/v1/admin/devices/{device_id}` | Admin Bearer | Yes | `device.remove` |
| US-DEVICE-008 | POST | `/v1/devices/{device_id}/acknowledge` | Bearer | Yes | Self only |
| US-DEVICE-009 | POST | `/v1/devices/{device_id}/block` | Bearer | Yes | Self only |
| US-DEVICE-009 | POST | `/v1/admin/devices/{device_id}/block` | Admin Bearer | Yes | `device.block` |
| US-DEVICE-009 | POST | `/v1/admin/devices/{device_id}/unblock` | Admin Bearer | Yes | `device.unblock` |

Device change detection (US-DEVICE-006) and suspicious device detection (US-DEVICE-007) are internal operations with no direct public API — they are invoked by authentication middleware hooks and background jobs. US-DEVICE-008 (notification trigger) is event-driven from E01's `Identity.Device.NewDeviceDetected`.

### 10.2 Request/Response Intent

#### POST /v1/devices
- **Intent**: Register a device explicitly for the authenticated user.
- **Request data**: `{ "device_name": "...", "platform": "ios|android|web", "fingerprint_hash": "..." }` + `Idempotency-Key` header.
- **Response data**: `{ "device_id": "...", "status": "pending_verification", "challenge_id": "..." }` (challenge for OTP verification via E01 `/v1/auth/devices/verify`).
- **Idempotency**: same fingerprint + same user → returns existing device with `device_id` and current status (no new OTP challenge if already verified).
- **Error conditions**: `400 INVALID_FINGERPRINT_ENTROPY`, `403 FORBIDDEN` (IDOR), `409 DEVICE_LIMIT_EXCEEDED`, `429 RATE_LIMIT_EXCEEDED`.

#### POST /v1/devices/fingerprint
- **Intent**: Generate a validated device fingerprint hash from device attributes.
- **Request data**: `{ "attributes": { "platform": "...", "os_version": "...", "hardware_id": "...", ... } }` — attribute schema is platform-specific; documentation in contracts/.
- **Response data**: `{ "fingerprint_hash": "..." }` — the hash to use in registration and authentication. Raw attributes are not echoed back.
- **Error conditions**: `400 INSUFFICIENT_ENTROPY`, `400 INVALID_ATTRIBUTE_FORMAT`.

#### POST /v1/devices/{device_id}/trust
- **Intent**: Mark a registered device as explicitly trusted.
- **Request data**: `Idempotency-Key` header; optional `{ "reason": "..." }`.
- **Response data**: `{ "device_id": "...", "status": "trusted", "trusted_at": "..." }`.
- **Error conditions**: `400 INVALID_STATUS_TRANSITION`, `403 FORBIDDEN` (IDOR or device blocked), `404 DEVICE_NOT_FOUND`.

#### GET /v1/devices
- **Intent**: Retrieve paginated list of the authenticated user's registered devices.
- **Query params**: `page`, `per_page`, `status` (filter by status), `platform` (filter).
- **Response data**: paginated list of device objects — `device_id`, `device_name`, `platform`, `status`, `trusted_at`, `last_seen_at`, `created_at`. No fingerprint hash included.

#### GET /v1/devices/{device_id}
- **Intent**: Retrieve a single registered device by ID for the authenticated user.
- **Auth**: Bearer token (authenticated user).
- **Authorization**: `user_id` extracted from the authenticated JWT MUST match `identity.devices.user_id`. A mismatch MUST return `403 FORBIDDEN` — never `404` — to prevent device enumeration (IDOR prevention, see §14.2).
- **Response data**: device object — `device_id`, `device_name`, `platform`, `status`, `trusted_at`, `last_seen_at`, `created_at`. No fingerprint hash included.
- **Error conditions**: `403 FORBIDDEN` (IDOR — device owned by a different user), `404 DEVICE_NOT_FOUND` (device does not exist or has been removed).

#### DELETE /v1/devices/{device_id}
- **Intent**: Remove a registered device and revoke all its sessions.
- **Request data**: `Idempotency-Key` header.
- **Response data**: `{ "device_id": "...", "sessions_revoked": N }` — count of sessions revoked.
- **Error conditions**: `403 FORBIDDEN` (IDOR), `404 DEVICE_NOT_FOUND`.

#### POST /v1/devices/{device_id}/acknowledge
- **Intent**: User responds to a new-device notification — confirms ("This was me") or denies ("This wasn't me").
- **Request data**: `{ "acknowledged": true|false }` + `Idempotency-Key`.
- **Response data on `true`**: `{ "device_id": "...", "status": "trusted" }`.
- **Response data on `false`**: `{ "device_id": "...", "status": "blocked", "sessions_revoked": N }`.
- **Error conditions**: `400 ACKNOWLEDGMENT_WINDOW_EXPIRED`, `400 DEVICE_ALREADY_ACKNOWLEDGED`, `403 FORBIDDEN`.

#### POST /v1/devices/{device_id}/block
- **Intent**: Block a compromised device and revoke all its sessions.
- **Request data**: `Idempotency-Key`; optional `{ "reason": "..." }` for user path; mandatory `{ "reason": "..." }` for admin path.
- **Response data**: `{ "device_id": "...", "status": "blocked", "sessions_revoked": N }`.
- **Error conditions**: `400 DEVICE_ALREADY_BLOCKED`, `403 FORBIDDEN`, `404 DEVICE_NOT_FOUND`.

#### POST /v1/admin/devices/{device_id}/unblock
- **Intent**: Admin unblocks a previously blocked device.
- **Request data**: `{ "reason": "..." }` + `Idempotency-Key`.
- **Response data**: `{ "device_id": "...", "status": "trusted" }` (unblock promotes to `trusted`).
- **Error conditions**: `400 INVALID_STATUS_TRANSITION` (device not blocked), `403 FORBIDDEN`.

Exact request/response schemas, error codes, and OpenAPI definitions are produced in the `contracts/` directory during the planning phase.

---

## 11. DB Entities

E03 operates within the `identity` PostgreSQL schema (the same bounded context as E01). E03 does not create a new schema — device management is part of the Identity bounded context.

### Migrations to existing table: `identity.devices`

E03 adds the following columns to the existing `identity.devices` table via migration:

| Column | Type | Notes |
|---|---|---|
| `blocked_at` | `timestamptz` NULL | When device was blocked; NULL if not blocked |
| `blocked_reason` | `varchar(255)` NULL | Reason for block; NULL if not blocked |

E03 also extends the `status` column's valid values to include `blocked`.

Updated complete status enumeration for `identity.devices.status`:
- `pending_verification` (E01) — newly detected or registered device awaiting OTP verification
- `trusted` (E01) — verified device
- `revoked` (E01) — device de-registered via session revocation
- `blocked` (E03 new) — device permanently blocked from authentication

### New table: `identity.device_trust_history`
Append-only log of device trust and status transitions.

| Column | Type | Notes |
|---|---|---|
| `id` | `uuid` PK | UUID v7 |
| `device_id` | `uuid` NOT NULL | References `identity.devices.id` (same schema) |
| `user_id` | `uuid` NOT NULL | References `identity.users.id` (same schema) |
| `from_status` | `varchar(30)` NULL | Previous device status; NULL for initial entry |
| `to_status` | `varchar(30)` NOT NULL | New device status |
| `actor_id` | `uuid` NOT NULL | User or admin who caused the transition |
| `actor_type` | `varchar(20)` NOT NULL | `USER`, `ADMIN`, `SYSTEM` |
| `reason` | `text` NULL | Required for block operations; optional otherwise |
| `correlation_id` | `uuid` NOT NULL | From `X-Correlation-ID` header |
| `created_at` | `timestamptz` NOT NULL DEFAULT `now()` | Append-only; no `updated_at` |

**Index**: `(device_id, created_at)` for efficient device history queries.
**Cross-module constraint**: No FK across schemas. References stored as values.

### New table: `identity.device_security_events`
Records device-level security detection events (change detection, suspicious detection, acknowledgment).

| Column | Type | Notes |
|---|---|---|
| `id` | `uuid` PK | UUID v7 |
| `device_id` | `uuid` NULL | References `identity.devices.id`; NULL if device not yet registered at detection time |
| `user_id` | `uuid` NOT NULL | References `identity.users.id` |
| `event_type` | `varchar(50)` NOT NULL | `change_detected`, `suspicious_detected`, `new_device_notified`, `new_device_acknowledged`, `new_device_denied` |
| `fingerprint_hash` | `varchar(64)` NULL | Fingerprint hash at time of event |
| `previous_fingerprint_hash` | `varchar(64)` NULL | Prior stored hash (for `change_detected` events) |
| `risk_signals` | `jsonb` NULL | Internal risk signal payload (NOT exposed to end users) |
| `resolution` | `varchar(30)` NULL | `acknowledged`, `blocked`, `trusted`, `pending`, `expired` |
| `resolved_at` | `timestamptz` NULL | When resolution was recorded |
| `acknowledgment_expires_at` | `timestamptz` NULL | Deadline for user acknowledgment/denial |
| `correlation_id` | `uuid` NOT NULL | |
| `created_at` | `timestamptz` NOT NULL DEFAULT `now()` | |
| `updated_at` | `timestamptz` NOT NULL DEFAULT `now()` | |

**Index**: `(user_id, event_type, created_at)` for user-scoped event queries.
**Index**: `(device_id, event_type)` for device-scoped queries.

---

## 12. Events

All events use the Platform Foundation outbox pattern and event envelope. Events follow the `{Module}.{Aggregate}.{Verb}` naming convention.

### Events Published by E03

| Event Name | Trigger | Consumers |
|---|---|---|
| `Device.Device.Registered` | Explicit device registration | E17 (notification optional), E26 (security monitoring) |
| `Device.Trust.Promoted` | Device marked as trusted | E17 (notification optional) |
| `Device.Device.Removed` | Device removed from account | E17 (notification), E26 |
| `Device.Device.ChangeDetected` | Fingerprint drift detected on known device | E17 (security alert), E18 (risk signal), E26 |
| `Device.Device.SuspiciousDetected` | Suspicious device pattern detected | E18 (risk signal), E26 (security alert) |
| `Device.Device.NewDeviceNotificationSent` | New device notification dispatched via E17 | — |
| `Device.Device.NewDeviceAcknowledged` | User confirmed new device ("This was me") | — |
| `Device.Device.NewDeviceDenied` | User denied new device ("This wasn't me") | E18 (risk signal), E26 |
| `Device.Device.Blocked` | Device blocked | E17 (notification to user), E26 |
| `Device.Device.Unblocked` | Device unblocked by admin | E17 (notification to user) |

### Events Consumed by E03

| Event | Producer | Purpose |
|---|---|---|
| `Identity.Device.NewDeviceDetected` | E01 | Triggers new device notification workflow (US-DEVICE-008) |
| `Identity.Session.Created` | E01 | Updates `identity.devices.last_seen_at` for session-to-device association |

**Event envelope**: Platform Foundation `platform.outbox_events` schema. All events include `correlation_id`, `causation_id`, `event_version: "1.0.0"`, `actor_id`, `aggregate_id` (`device_id`).

**Delivery guarantee**: At-least-once. E03 event consumers MUST implement idempotency using Platform Foundation idempotency infrastructure.

---

## 13. Audit Requirements

All device operations MUST be recorded in `platform.audit_events` using the Platform Foundation `AuditService`. E03 MUST NOT create a second audit registry.

| Audit Action | Trigger | Severity |
|---|---|---|
| `identity.device.registered` | Device explicitly registered | INFO |
| `identity.device.fingerprint-generated` | Device fingerprint generated | INFO |
| `identity.device.trusted` | Device marked trusted | INFO |
| `identity.device.removed` | Device removed | WARN |
| `identity.device.change-detected` | Fingerprint change detected | WARN |
| `identity.device.suspicious-detected` | Suspicious device pattern detected | WARN |
| `identity.device.new-device-notified` | New device notification sent | INFO |
| `identity.device.new-device-acknowledged` | New device confirmed by user | INFO |
| `identity.device.new-device-denied` | New device denied by user | WARN |
| `identity.device.blocked` | Device blocked | WARN |
| `identity.device.unblocked` | Device unblocked by admin | INFO |

**Audit payload rules**:
- `actor_id`: authenticated user's UUID (or admin UUID for admin-path operations); admin-path MUST also record `subject_user_id`
- `before_state` / `after_state`: MUST include device status transitions; MUST NOT include raw fingerprint hash
- `ip_address`: Hashed using `HMAC-SHA256(platform_secret, ip_address)` (inherited from E01 BR-027)
- `risk_signals` in suspicious detection records: MUST NOT appear in audit `before_state`/`after_state` (internal only)
- Audit records are INSERT-only; E03 MUST NOT attempt UPDATE or DELETE on `platform.audit_events`

---

## 14. Permissions / Authorization Boundary

### 14.1 Authorization Model

E03 relies on E01 for authenticated identity and E04 for permission enforcement.

| Operation | Actor | Required Permission |
|---|---|---|
| Register device, generate fingerprint | Authenticated user | Self only (no named permission) |
| View own device list | Authenticated user | Self only |
| Mark own device trusted | Authenticated user | Self only |
| Remove own device | Authenticated user | Self only |
| Block own device | Authenticated user | Self only |
| Acknowledge/deny new device notification | Authenticated user | Self only |
| View any user's device list | Admin | `device.read` |
| Block any device | Admin | `device.block` |
| Remove any device | Admin | `device.remove` |
| Unblock any device | Admin | `device.unblock` |

### 14.2 IDOR Prevention

Every device operation extracts `user_id` from the authenticated JWT. The resource's `identity.devices.user_id` is compared against the token's `user_id`. A mismatch returns `403 FORBIDDEN` — never `404`. Admin paths accept a `user_id` or `device_id` path parameter but require the named permission; without it the response is always `403 FORBIDDEN`.

### 14.3 What E03 Does NOT Own

- Session listing or individual session revocation (outside of device operations) — E01
- New-device OTP verification challenge during authentication — E01 (US-AUTH-017)
- Notification delivery — E17
- Risk scoring logic for suspicious devices — E18

---

## 15. UI Screens

Design tokens, shared components, accessibility, and all design system elements are provided by the OleKard Design System. E03 screens use them without modification.

### 15.1 Customer Flutter — Device Management Screens

| Screen | Stories |
|---|---|
| Device list: View all registered devices | US-DEVICE-004 |
| Device detail: Device name, platform, status, trust badge, last seen | US-DEVICE-004 |
| Register device: Submit attributes and await OTP verification | US-DEVICE-001 |
| Trust device: Confirm trust promotion | US-DEVICE-003 |
| Remove device: Confirm removal dialog | US-DEVICE-005 |
| Block device: Confirm block dialog | US-DEVICE-009 |
| New device notification: Alert with "This was me" / "This wasn't me" CTAs | US-DEVICE-008 |
| New device denied: Confirmation and security guidance | US-DEVICE-008 |

All screens include: loading state, success state, error state, retry CTA, accessibility labels, keyboard navigation, and screen-reader compatibility per Platform Foundation accessibility foundation.

### 15.2 Agent Flutter — Device Management Screens

Agent device management is a subset of Customer flows:
- Device list view (US-DEVICE-004)
- Register device (US-DEVICE-001)
- Remove device (US-DEVICE-005)
- New device notification acknowledgment (US-DEVICE-008)
- Block compromised device (US-DEVICE-009)

Agents do not have a trust-promotion UI (trust is granted at registration verification time in E01).

### 15.3 Admin Next.js — Device Management Screens

| Screen | Stories |
|---|---|
| User device list (read-only with block/remove actions) | US-DEVICE-004, US-DEVICE-005, US-DEVICE-009 |
| Device block confirmation dialog (reason required) | US-DEVICE-009 |
| Device unblock confirmation dialog (reason required) | US-DEVICE-009 |
| Device security events log (change detected, suspicious) | US-DEVICE-006, US-DEVICE-007 |

Admin screens follow the Admin dark teal theme (`[data-theme="admin"]`). All destructive actions (block, remove) require explicit confirmation dialogs before submission.

---

## 16. Error / Edge Cases

| Scenario | Expected Behaviour |
|---|---|
| Register device at maximum limit | `409 DEVICE_LIMIT_EXCEEDED` with current count and configured maximum |
| Register existing device (same fingerprint, same user) | Idempotent — return existing device record; if already trusted, no new OTP challenge |
| Trust a blocked device | `400 INVALID_STATUS_TRANSITION` — blocked devices cannot be user-trusted; require admin unblock first |
| Block an already-blocked device | `400 DEVICE_ALREADY_BLOCKED` — idempotent if same `Idempotency-Key`; otherwise distinct error |
| Acknowledge after window expires | `400 ACKNOWLEDGMENT_WINDOW_EXPIRED`; device remains in its current state |
| Acknowledge an already-acknowledged device | `400 DEVICE_ALREADY_ACKNOWLEDGED` — duplicate acknowledgment not applied |
| Remove a device with zero sessions | Removal succeeds; `sessions_revoked: 0` in response |
| Remove a blocked device | Permitted — removal is a superset of blocking |
| Device change detected during authentication | Demote device to `pending_verification`; force OTP re-verification (E01 US-AUTH-017); authentication is not immediately rejected — the user re-verifies |
| Suspicious detection during authentication | Authentication proceeds normally (detection is a side-channel); `Device.Device.SuspiciousDetected` event emitted |
| E01 session revocation fails during device block | Device block MUST NOT be committed if session revocation cannot complete — return `500 INTERNAL_ERROR`; client should retry; audit record is NOT written |
| E17 notification delivery failure for new device | Non-blocking — authentication is not affected; log failure; increment `device.new_device_notification.failed` counter |
| Concurrent block and removal on same device | Serialized by database lock; first operation wins; second returns current state |
| Concurrent trust-promotion requests with same `Idempotency-Key` | Idempotency enforced — exactly one write; duplicate returns cached result |
| Admin blocks a device already removed by user | `404 DEVICE_NOT_FOUND` (or `400 INVALID_STATUS_TRANSITION` if device exists but is `revoked`) |
| User attempts to unblock their own blocked device | `403 FORBIDDEN` — self-unblock is not permitted; must contact admin |
| Fingerprint entropy below minimum | `400 INSUFFICIENT_ENTROPY` with configured minimum value |

---

## 17. Integration Dependencies

### 17.1 Platform Foundation (Required)

| Dependency | E03 Usage |
|---|---|
| `platform.outbox_events` + OutboxService | All E03 domain/integration events |
| `platform.audit_events` + AuditService | All E03 audit records |
| `platform.configuration_values` + ConfigurationService | All E03 policy values |
| `platform.idempotency_records` + IdempotencyService | Idempotent device operations |
| Platform API envelope (`ApiResponse`) | All E03 API responses |
| Platform error taxonomy | All E03 error codes |
| `X-Correlation-ID` middleware | Propagated through all E03 requests |
| Redis (via Laravel) | Rate limiting counters for device registration |
| Laravel Horizon | Background suspicious device detection jobs |
| PostgreSQL `identity` schema | E03 tables and E01 table extensions |

### 17.2 E01 — Central Identity & Authentication (Required)

| Integration Point | Detail |
|---|---|
| `Identity.Device.NewDeviceDetected` event | E03 consumes to trigger new device notification workflow (US-DEVICE-008) |
| `Identity.Session.Created` event | E03 consumes to update `identity.devices.last_seen_at` |
| E01 session revocation service | E03 calls E01's session revocation to revoke all sessions for a device (on removal or block) |
| E01 `OtpDeliveryContract` | E03 uses E01's OTP challenge infrastructure for device registration verification |
| E01 authentication middleware | MUST be extended to check `identity.devices.status = blocked` (FR-E03-045); E03 implements the `DeviceBlockCheckService` that the E01 middleware calls |
| `identity.devices` table | E03 reads and updates this table; adds `blocked_at`, `blocked_reason` columns, `blocked` status value |
| `identity.sessions` table | E03 reads session-to-device associations during revocation coordination |
| JWT access tokens + JWKS | E03 validates tokens via cached JWKS; extracts `user_id`, `actor_type`, `roles` |
| Audit HMAC-IP standard (E01 BR-027) | E03 audit records use the same `HMAC-SHA256(platform_secret, ip_address)` protection |

### 17.3 Future Epic Integration Boundaries

| Epic | Integration Point |
|---|---|
| E04 Roles & Permissions | E04 seeds `device.read`, `device.block`, `device.remove`, `device.unblock` permissions |
| E17 Notifications | Consumes `Device.Device.Blocked`, `Device.Device.Removed`, `Device.Device.NewDeviceNotificationSent`, `Device.Device.NewDeviceDenied` events for user notifications |
| E18 Fraud, Risk & AML | Consumes `Device.Device.SuspiciousDetected`, `Device.Device.ChangeDetected`, `Device.Device.NewDeviceDenied` as risk signals |
| E26 Security | Consumes device security events for monitoring and alerting |

---

## 18. E2E Scenarios

### Scenario 1: Explicit Device Registration and Trust
1. Authenticated Customer calls `POST /v1/devices/fingerprint` with device attributes → receives `fingerprint_hash`
2. Customer calls `POST /v1/devices` with `fingerprint_hash`, `platform: android`, `device_name: "My Pixel 9"` → device created with `status = pending_verification`; OTP challenge issued
3. Customer verifies device via E01 `POST /v1/auth/devices/verify` → device status set to `trusted`
4. Customer calls `GET /v1/devices` → device appears in list with `status: trusted`
5. **Verify**: `identity.device_trust_history` has entry from `null → pending_verification → trusted`
6. **Verify**: `identity.device.registered` and `identity.device.trusted` audit records in `platform.audit_events`

### Scenario 2: New Device Notification and Denial
1. Customer logs in from an unrecognised device → E01 detects new device → `Identity.Device.NewDeviceDetected` emitted
2. E03 consumes event → emits `Device.Device.NewDeviceNotificationSent` → E17 delivers notification to user
3. User taps "This wasn't me" → `POST /v1/devices/{device_id}/acknowledge` with `acknowledged: false`
4. System blocks device, revokes all sessions → `Device.Device.NewDeviceDenied` event emitted
5. **Verify**: `identity.devices.status = blocked`; all sessions for device in `identity.sessions` have `status = revoked`
6. **Verify**: `identity.device.blocked` and `identity.device.new-device-denied` audit records

### Scenario 3: Device Change Detection
1. Customer authenticates providing a known `device_id` but new fingerprint (OS update changed device)
2. E03 change detection middleware detects drift → device demoted to `pending_verification`
3. `Device.Device.ChangeDetected` event emitted → E17 sends security alert
4. Customer receives OTP challenge (E01 US-AUTH-017) and verifies → device re-trusted
5. **Verify**: `identity.device_security_events` has `change_detected` entry with old and new fingerprint hashes (no raw values — only hashes)

### Scenario 4: Admin Blocks Compromised Device
1. Admin calls `POST /v1/admin/devices/{device_id}/block` with `reason: "Reported stolen"`
2. System blocks device, revokes all associated sessions
3. **Verify**: `identity.devices.status = blocked`; `blocked_reason = "Reported stolen"`
4. Customer attempts login from that device → E01 returns `DEVICE_BLOCKED`
5. Admin calls `POST /v1/admin/devices/{device_id}/unblock` → device returns to `trusted`
6. **Verify**: `identity.device_trust_history` shows full transition chain

### Scenario 5: Maximum Device Limit
1. User has 10 registered devices (configured maximum)
2. User calls `POST /v1/devices` with a new fingerprint
3. System returns `409 DEVICE_LIMIT_EXCEEDED` with current count
4. User removes one device → `DELETE /v1/devices/{device_id}` succeeds; sessions revoked
5. User retries device registration → succeeds
6. **Verify**: device count never exceeds configured maximum

---

## 19. QA Scenarios

### 19.1 Functional Tests (per story)
Every story requires:
- Happy-path test with valid data and expected success response
- Required-field validation test
- Idempotency test where applicable (same request with same `Idempotency-Key` → same result, no duplicate records)
- IDOR test: authenticated user cannot access or modify another user's device (`403 FORBIDDEN`)
- Audit event verification: assert `platform.audit_events` record created with correct fields
- Event emission verification: assert `platform.outbox_events` contains correct event

### 19.2 Security Tests
- IDOR: user A cannot view, trust, remove, block, or acknowledge devices of user B
- Admin without `device.read` cannot access admin device endpoint (`403 FORBIDDEN`)
- Admin without `device.block` cannot call admin block endpoint (`403 FORBIDDEN`)
- Blocked device authentication rejection: E01 middleware rejects `DEVICE_BLOCKED` before token issuance
- Fingerprint not in response: assert API responses never include `device_fingerprint` field
- Suspicious detection opacity: assert API responses for suspicious devices contain no detection signal detail
- Concurrent block and removal race: exactly one operation succeeds; no data corruption

### 19.3 Concurrency Tests
- Concurrent device registration with same `Idempotency-Key` for same user+fingerprint: exactly one device created
- Concurrent block and trust-promotion on same device: serialized; one wins, one receives `INVALID_STATUS_TRANSITION`
- Concurrent device removal and session creation: session revocation completes atomically

### 19.4 Detection Tests
- Device change detection fires when any mismatch is detected between the submitted fingerprint and the stored hash (binary comparison)
- Device change detection disabled when `device.change_detection_enabled = false`
- Suspicious detection fires when one fingerprint is used across more than `device.max_accounts_per_fingerprint` accounts
- Suspicious detection disabled when `device.suspicious_detection_enabled = false`
- Acknowledgment window expiry: `POST /v1/devices/{device_id}/acknowledge` returns `ACKNOWLEDGMENT_WINDOW_EXPIRED` after TTL

### 19.5 Accessibility Tests
- Device list screen passes WCAG AA contrast requirements
- Block/remove confirmation dialogs are keyboard-accessible and screen-reader labelled
- New device notification alert is announced to screen readers with device details
- Loading states for device operations are announced

### 19.6 Performance Tests
- `GET /v1/devices` P95 ≤ 200 ms with 10 registered devices under sustained load
- `POST /v1/devices/{device_id}/block` P95 ≤ 500 ms (includes session revocation)
- `POST /v1/devices` P95 ≤ 300 ms

---

## 20. Acceptance Criteria

| ID | Criterion |
|---|---|
| AC-001 | An authenticated user can register a device via `POST /v1/devices`; the device appears in subsequent `GET /v1/devices` responses with `status: pending_verification` |
| AC-002 | Registering the same device fingerprint twice with the same user returns the existing device record without creating a duplicate; submitting the same request with the same `Idempotency-Key` twice produces no duplicate audit records |
| AC-003 | Registration is rejected with `409 DEVICE_LIMIT_EXCEEDED` when the user's registered device count equals the configured maximum |
| AC-004 | `POST /v1/devices/fingerprint` returns a fingerprint hash; raw attribute values are not stored or returned |
| AC-005 | An authenticated user can mark a registered device as trusted; the device status transitions to `trusted`, `identity.device_trust_history` records the transition, and a `Device.Trust.Promoted` event is published |
| AC-006 | `GET /v1/devices` returns all registered devices with metadata (name, platform, status, timestamps); no fingerprint hash is included in any response |
| AC-007 | A non-admin authenticated user who calls `GET /v1/devices` or `DELETE /v1/devices/{device_id}` using a `device_id` owned by another user receives `403 FORBIDDEN` |
| AC-008 | `DELETE /v1/devices/{device_id}` removes the device and the response includes the count of sessions revoked; all associated sessions in `identity.sessions` have `status = revoked` |
| AC-009 | When a known device presents a changed fingerprint and change detection is enabled, the device is demoted to `pending_verification` and the user must re-verify before a session is granted |
| AC-010 | Suspicious device detection emits a `Device.Device.SuspiciousDetected` event for E18; the API response for the triggering request contains no detection detail |
| AC-011 | When `Identity.Device.NewDeviceDetected` is received, a `Device.Device.NewDeviceNotificationSent` event is emitted; notification failure does not affect the authentication flow |
| AC-012 | `POST /v1/devices/{device_id}/acknowledge` with `acknowledged: false` blocks the device and revokes all its sessions; `Device.Device.NewDeviceDenied` event is published |
| AC-013 | `POST /v1/devices/{device_id}/acknowledge` returns `400 ACKNOWLEDGMENT_WINDOW_EXPIRED` when called after the configured TTL |
| AC-014 | An authenticated user can block their own device; the device transitions to `blocked`, all sessions are revoked, and a `Device.Device.Blocked` event is published |
| AC-015 | A blocked device cannot authenticate — E01 authentication middleware returns `DEVICE_BLOCKED` before token issuance |
| AC-016 | An admin with `device.block` can block any device on any user's account; the audit record includes both admin actor ID and subject user ID |
| AC-017 | An admin with `device.unblock` can unblock a blocked device; a self-service user cannot unblock a blocked device (`403 FORBIDDEN`) |
| AC-018 | All device management operations produce immutable records in `platform.audit_events` with correct action strings, actor IDs, and correlation IDs |
| AC-019 | All E03 domain events are published to `platform.outbox_events` via the outbox pattern |
| AC-020 | All configurable device policy values (max devices, acknowledgment TTL, detection toggles) are sourced from `platform.configuration_values`; none are hard-coded |
| AC-021 | The device configuration seed executes without error; running it a second time produces no changes to existing values |

---

## 21. Definition of Done

**Story-level**:
- All story-specific acceptance criteria implemented and passing
- API endpoint(s) returning standard envelope with correct status codes
- DB migrations applied with indexes and constraints
- Device trust history and security event records created for all transitions
- Domain/integration events published via outbox for all required transitions
- Audit records written to `platform.audit_events` for all auditable operations
- All configurable values sourced from platform configuration (no hard-coding)
- Unit tests, integration tests, and API tests written and passing
- Security test cases written and passing (IDOR assertions, fingerprint not in responses, blocked device enforcement)
- Flutter screens implemented for applicable stories with all states (loading, success, error)
- Admin Next.js screens implemented for applicable stories with all states
- Accessibility requirements met for all screens
- OpenAPI documentation generated and accurate

**Epic-level**:
- All 9 stories meet their story-specific DoD
- Full E2E device lifecycle (register → trust → new device notification → acknowledge/deny → block → admin unblock) passes
- E01 authentication middleware updated to enforce `blocked` status (FR-E03-045) and tested
- Cross-story dependency tests pass (block-with-revocation, denial-auto-block, change-detection)
- Security review: no IDOR paths, no fingerprint hashes in responses, no detection signals disclosed
- No unresolved CRITICAL or HIGH defects blocking release
- Test coverage ≥ 80% for device management module
- OpenAPI contract published and validated
- All E03 audit events verified as INSERT-only in `platform.audit_events`
- All TBD items explicitly flagged, not invented, and resolved (TBD-E03-001 through TBD-E03-004 — see §24)

---

## 22. Dependencies

### 22.1 Dependencies on Platform Foundation (E999)

| Platform Foundation Deliverable | E03 Usage |
|---|---|
| `platform.outbox_events` + OutboxService | All E03 integration events |
| `platform.audit_events` + AuditService | All E03 audit records |
| `platform.configuration_values` + ConfigurationService | All E03 policy values |
| `platform.idempotency_records` + IdempotencyService | Idempotent device operations |
| Standard API envelope + error taxonomy | All E03 API responses |
| `X-Correlation-ID` middleware | Correlation ID in all E03 logs and events |
| Redis (via Laravel) | Rate limiting for device registration |
| Laravel Horizon | Suspicious device detection background jobs |
| Design system tokens and shared components | All E03 Flutter and Next.js screens |

### 22.2 Dependencies on E01 — Central Identity & Authentication

| E01 Deliverable | E03 Usage |
|---|---|
| `identity.devices` table | E03 extends (new columns, new status value) and manages device records |
| `identity.sessions` table | E03 reads to identify sessions per device for revocation |
| E01 session revocation service | E03 calls to revoke device sessions (removal, block) |
| `Identity.Device.NewDeviceDetected` event | Trigger for E03's new-device notification workflow |
| `Identity.Session.Created` event | Updates device `last_seen_at` |
| E01 authentication middleware | Extended to check `identity.devices.status = blocked` via E03's `DeviceBlockCheckService` |
| E01 `OtpDeliveryContract` | Device registration OTP verification |
| JWT access tokens + JWKS | Authentication for all E03 protected endpoints |
| Audit HMAC-IP standard | E03 audit records use same IP hashing |

### 22.3 Dependencies on Future Epics

| Epic | Feature | Blocker |
|---|---|---|
| E04 | `device.read`, `device.block`, `device.remove`, `device.unblock` named permissions | Required before admin device operations work |
| E17 | New device notifications, block/removal notifications | Without E17, notification dispatch is stub/mock |
| E18 | Suspicious device risk processing | Without E18, `Device.Device.SuspiciousDetected` events are emitted but not actioned |

---

## 23. Risks

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| E01 authentication middleware update introduces regressions in token issuance | Medium | High | Dedicated regression suite for E01 auth flows after middleware change; feature flag for `blocked` check |
| Session revocation in E01 fails during device block (partial state) | Low | High | Atomic coordination: device block MUST NOT commit if session revocation throws; retry guidance in error response |
| Device change detection causes false positives on routine OS updates | Medium | Medium | Toggle via `device.change_detection_enabled`; binary comparison means any hash mismatch triggers re-verification; user re-verification flow is non-disruptive |
| Suspicious device detection generates excessive false positives for shared devices (family phones) | Medium | Low | Configurable `device.max_accounts_per_fingerprint`; signals go to E18 for risk scoring, not direct blocking |
| Acknowledgment notifications delivered with delay (E17 latency) | Low | Medium | Acknowledgment window TTL provides adequate response time; authentication is not gated on acknowledgment |
| Concurrent block + removal race condition on same device | Low | Medium | Database-level lock on device record; explicit test coverage for concurrent operations |
| `identity.devices` schema migration (add `blocked_at`, `blocked_reason`) causes downtime | Low | High | Zero-downtime migration: add nullable columns with no DEFAULT, then backfill; test on staging before production |

---

## 24. TBD / Open Questions

| ID | Status | Item | Impact |
|---|---|---|---|
| TBD-E03-001 | **RESOLVED** | Device fingerprint attribute schema — per-platform required keys defined in `contracts/user-device-api.md`: iOS (`os_version`, `hardware_model`, `idfv`), Android (`os_version`, `hardware_model`, `android_id`), Web (`user_agent`, `timezone`). Validated by `DeviceFingerprintService`. | Fingerprint contract is stable; attribute schema is documented and enforced in the implementation. |
| TBD-E03-002 | **RESOLVED** | Cross-module session revocation — synchronous in-process call via `IdentityModuleContract::revokeSession()`. Sessions are invalidated atomically within the same DB transaction as the device state change. | Chosen approach: synchronous call. Simple, atomic. Coupling is acceptable within the same bounded context (E01/E03 share the `identity` schema). |
| TBD-E03-003 | **RESOLVED** | Suspicious device detection rules implemented in `DeviceFingerprintService::detectEmulatorIndicators()` and `SuspiciousDeviceDetectionJob`. Rules: (1) Android ID `0000000000000000`, (2) hardware model containing `sdk`/`emulator`/`generic`, (3) iOS IDFV matching simulator patterns, (4) single fingerprint associated with more than `device.suspicious_multi_account_threshold` accounts. Rules are configurable via platform configuration; not hard-coded. | Resolved in implementation. |
| TBD-E03-004 | **RESOLVED** | E03 provides `DeviceBlockCheckService` implementing `DeviceBlockCheckServiceInterface`. E01 resolves it via Laravel's DI container in the authentication middleware to check device block status before issuing tokens. | Chosen approach: E03 owns the service; E01 is the caller. Clean boundary: E01 depends on the interface, not the implementation. |

No TBD values have been invented. All open items are explicitly marked.

---

## 25. Platform Alignment

- Backend API: Laravel / PHP 8.4+
- Admin/Web: Next.js + TypeScript
- Mobile: Flutter (Customer and Agent applications)
- DB: PostgreSQL (`identity` schema — same bounded context as E01)
- Cache/Queue: Redis + Laravel Horizon
- API: REST/OpenAPI
- Design: OleKard shared token-driven design system
- State management: Riverpod (Flutter)
- No TBD values invented
- No configurable values hard-coded

---

## Epic Stories

### US-DEVICE-001 — Register device (P0)
**Actor:** Authenticated user

**Acceptance Criteria:** An authenticated user can explicitly register a device via `POST /v1/devices`. Registration is idempotent per `(user_id, device_fingerprint)`. Registration is rejected at the configured maximum device count. New device record created with `status = pending_verification`; OTP challenge initiated via E01.

**API:** `POST /v1/devices` (register); `POST /v1/devices/fingerprint` (fingerprint generation — US-DEVICE-002)

**DB Entities:** `identity.devices` (create), `identity.device_trust_history` (append)

**Events:** `Device.Device.Registered`; `AUDIT_EVENT_RECORDED`

**Permissions:** Authenticated user (self only)

**UI Screens:** Customer/Agent: Register device flow — enter device name, await OTP verification, success/error states

**QA Scenarios:** Happy-path registration. | Idempotent re-registration. | Device limit rejection. | Fingerprint entropy rejection. | IDOR rejection. | Audit event verification.

**Definition of Done:** All AC implemented and passing; API, DB, events, audit, screen, and tests complete.

---

### US-DEVICE-002 — Generate device fingerprint (P0)
**Actor:** Authenticated user (mobile client)

**Acceptance Criteria:** The client submits device attributes and receives a validated fingerprint hash. Raw attributes are not stored server-side. The hash is stable for the same attributes on the same device.

**API:** `POST /v1/devices/fingerprint`

**DB Entities:** None (hash returned; not persisted until device registration)

**Events:** `AUDIT_EVENT_RECORDED` (`identity.device.fingerprint-generated`)

**Permissions:** Authenticated user

**UI Screens:** No dedicated screen — called by SDK/client library during registration flow

**QA Scenarios:** Happy-path hash generation. | Insufficient entropy rejection. | Raw attributes not stored verification. | Hash stability for same input.

**Definition of Done:** All AC implemented and passing; API, audit, and tests complete.

---

### US-DEVICE-003 — Mark trusted device (P1)
**Actor:** Authenticated user

**Acceptance Criteria:** An authenticated user can promote a registered device to `trusted` status. Trust promotion is idempotent. Blocked devices cannot be self-trusted. History record created; event emitted.

**API:** `POST /v1/devices/{device_id}/trust`

**DB Entities:** `identity.devices` (update status, trusted_at), `identity.device_trust_history` (append)

**Events:** `Device.Trust.Promoted`; `AUDIT_EVENT_RECORDED`

**Permissions:** Authenticated user (self only)

**UI Screens:** Customer/Agent: Device detail — "Mark Trusted" button with confirmation; success/error states

**QA Scenarios:** Happy-path trust promotion. | Blocked device rejection. | IDOR rejection. | Idempotency verification. | History record verification.

**Definition of Done:** All AC implemented and passing; API, DB, events, audit, screen, and tests complete.

---

### US-DEVICE-004 — View registered devices (P1)
**Actor:** Authenticated user / Admin with `device.read`

**Acceptance Criteria:** Authenticated user sees all their registered devices. Admin sees any user's device list. Non-admin cannot access other users' device lists. No fingerprint hash in response.

**API:** `GET /v1/devices`; `GET /v1/devices/{device_id}`; `GET /v1/admin/users/{user_id}/devices`

**DB Entities:** `identity.devices` (read)

**Events:** None

**Permissions:** Self or Admin (`device.read`)

**UI Screens:** Customer/Agent: Device list screen (loading, empty, populated, error states); Admin: User device list panel

**QA Scenarios:** Happy-path list retrieval. | IDOR rejection (non-admin). | Admin without permission rejection. | Fingerprint not in response verification. | Empty list state.

**Definition of Done:** All AC implemented and passing; API, screen, and tests complete.

---

### US-DEVICE-005 — Remove registered device (P1)
**Actor:** Authenticated user / Admin with `device.remove`

**Acceptance Criteria:** User removes a device; all sessions on that device are immediately revoked. Blocked or revoked devices can also be removed. Session revocation count returned in response. History record and event created.

**API:** `DELETE /v1/devices/{device_id}`; `DELETE /v1/admin/devices/{device_id}`

**DB Entities:** `identity.devices` (status = revoked), `identity.device_trust_history` (append), `identity.sessions` (revoke via E01)

**Events:** `Device.Device.Removed`; `AUDIT_EVENT_RECORDED`

**Permissions:** Self or Admin (`device.remove`)

**UI Screens:** Customer/Agent: Device detail — "Remove Device" action with confirmation dialog; success/error states; Admin: Remove device in device list panel

**QA Scenarios:** Happy-path removal with sessions revoked. | Zero-session removal. | IDOR rejection. | Admin without permission rejection. | Idempotency verification.

**Definition of Done:** All AC implemented and passing; API, DB, events, audit, screen, and tests complete.

---

### US-DEVICE-006 — Detect device change (P0)
**Actor:** System (authentication middleware)

**Acceptance Criteria:** When a known device presents a fingerprint that does not exactly match the stored hash (any mismatch, binary comparison), the device is demoted to `pending_verification`. The user must re-verify via OTP before a session is granted. Detection is togglable via `device.change_detection_enabled`. Security event and audit record created.

**API:** Internal middleware hook (no direct public endpoint)

**DB Entities:** `identity.devices` (status update), `identity.device_security_events` (create), `identity.device_trust_history` (append)

**Events:** `Device.Device.ChangeDetected`; `AUDIT_EVENT_RECORDED`

**Permissions:** System (authentication middleware)

**UI Screens:** Customer/Agent: Existing E01 new-device verification screen (no dedicated E03 screen)

**QA Scenarios:** Change detected when submitted fingerprint does not exactly match stored hash (any mismatch). | No detection when `device.change_detection_enabled = false`. | Re-verification forced after detection. | Audit record at WARN severity. | History record created.

**Definition of Done:** All AC implemented and passing; middleware integration, DB, events, audit, and tests complete.

---

### US-DEVICE-007 — Detect suspicious device (P0)
**Actor:** System (background job and authentication hook)

**Acceptance Criteria:** Suspicious device patterns (multi-account fingerprint, emulator indicators, abnormal device change rate) are detected. `Device.Device.SuspiciousDetected` event emitted with internal risk signals. No detection detail disclosed to end user. Detection is togglable.

**API:** Internal (background job + authentication hook; no direct public endpoint)

**DB Entities:** `identity.device_security_events` (create)

**Events:** `Device.Device.SuspiciousDetected`; `AUDIT_EVENT_RECORDED`

**Permissions:** System

**UI Screens:** Admin: Device security events log (read-only)

**QA Scenarios:** Suspicious detection fires on multi-account fingerprint. | No detection when disabled. | No detection detail in API response. | Event contains risk signals. | Audit record at WARN severity.

**Definition of Done:** All AC implemented and passing; background job, authentication hook, DB, events, audit, and tests complete.

---

### US-DEVICE-008 — Notify user about new device (P0)
**Actor:** System (event consumer) / Authenticated user (acknowledgment)

**Acceptance Criteria:** When `Identity.Device.NewDeviceDetected` is received, a notification event is emitted for E17. User can acknowledge ("This was me") or deny ("This wasn't me") within the TTL window. Denial auto-blocks the device and revokes sessions. Notification delivery failure is non-blocking.

**API:** `POST /v1/devices/{device_id}/acknowledge`

**DB Entities:** `identity.device_security_events` (create, update), `identity.devices` (block on denial), `identity.device_trust_history` (append on denial)

**Events:** `Device.Device.NewDeviceNotificationSent`; `Device.Device.NewDeviceAcknowledged`; `Device.Device.NewDeviceDenied` (on denial)

**Permissions:** Authenticated user (self only for acknowledgment)

**UI Screens:** Customer/Agent: New device alert notification screen with "This was me" / "This wasn't me" CTAs; denial confirmation screen

**QA Scenarios:** Happy-path acknowledgment. | Happy-path denial with block + session revocation. | Expired acknowledgment window rejection. | Duplicate acknowledgment rejection. | Notification delivery failure non-blocking. | IDOR rejection.

**Definition of Done:** All AC implemented and passing; event consumer, API, DB, events, audit, screen, and tests complete.

---

### US-DEVICE-009 — Block compromised device (P1)
**Actor:** Authenticated user (self) / Admin with `device.block`

**Acceptance Criteria:** User or admin can block a device. Blocking atomically revokes all sessions and sets device status to `blocked`. Blocked devices are permanently denied authentication. Only admin can unblock. Admin block requires reason.

**API:** `POST /v1/devices/{device_id}/block`; `POST /v1/admin/devices/{device_id}/block`; `POST /v1/admin/devices/{device_id}/unblock`

**DB Entities:** `identity.devices` (status = blocked, blocked_at, blocked_reason), `identity.device_trust_history` (append), `identity.sessions` (revoke via E01)

**Events:** `Device.Device.Blocked`; `Device.Device.Unblocked` (on admin unblock); `AUDIT_EVENT_RECORDED`

**Permissions:** Self (block only) or Admin (`device.block`, `device.unblock`)

**UI Screens:** Customer/Agent: Device detail — "Block Device" action with confirmation; success screen; Admin: Block/unblock in device list panel with reason entry

**QA Scenarios:** Happy-path user block with session revocation. | Admin block with mandatory reason. | Blocked device authentication rejection in E01. | Admin unblock. | User cannot self-unblock. | Already-blocked idempotency. | Audit record at WARN severity.

**Definition of Done:** All AC implemented and passing; API, DB, E01 middleware integration, events, audit, screen, and tests complete.

---

## Epic-level Definition of Done
- All 9 stories in this epic meet their story-specific Definition of Done.
- E01 authentication middleware updated to enforce `blocked` device status and tested with full E01 regression suite.
- Cross-story dependencies tested (block-with-revocation atomicity, denial-auto-block, change-detection re-verification).
- API contracts and migrations reviewed.
- Security and authorization checks pass for all stories.
- UI states and accessibility requirements pass where applicable.
- No unresolved Critical/High defect blocks completion.
