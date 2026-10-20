# Research — E32 Agent Application

## Decision: Flutter project structure mirrors E31 Customer Application

**Decision**: The agent app follows the identical clean-architecture module pattern established by E31 (`wallet-ecosystem/apps/customer/`): domain/entities, domain/repositories, data/models, data/repositories, presentation/screens per module.

**Rationale**: E31 is production-ready and validated. The same framework versions (Flutter 3.x, Riverpod 2.x, GoRouter 13.x, Dio 5.x) are confirmed working. Diverging would require a new ADR and increase maintenance cost.

**Alternatives considered**: Shared code monorepo between agent and customer apps — rejected because the agent app has fundamentally different business flows (cash-in/cash-out vs wallet-to-wallet); sharing domain entities would create coupling.

---

## Decision: Receipt generation using `pdf` + `printing` packages

**Decision**: Receipts are PDF documents generated client-side using `pdf: ^3.10.8` and shared via `share_plus` / printed via `printing: ^5.12.0`. No server-side receipt rendering required.

**Rationale**: Agents often need to share receipts immediately without network connectivity (after the transaction has been submitted but before re-connectivity). Client-side PDF generation from the locally available transaction response is more reliable.

**Alternatives considered**:
- Server-generated PDF receipt endpoint — requires connectivity at share time; adds server load
- HTML-to-image screenshot — platform-inconsistent rendering; harder to print
- Plain text receipt shared as SMS — poor UX, not professional

---

## Decision: QR scanning for cash-out pickup codes via `mobile_scanner`

**Decision**: `mobile_scanner: ^5.1.0` handles camera-based QR scanning on both Android and iOS. The same package is used in E31 Customer Application.

**Rationale**: `mobile_scanner` is actively maintained (vs `qr_code_scanner` which is no longer maintained), uses the native camera API on each platform, and supports real-time scanning with a `MobileScannerController`.

**Alternatives considered**: `qr_code_scanner` — deprecated. `flutter_barcode_scanner` — not null-safe.

---

## Decision: Agent API base path is `/v1/agent/`

**Decision**: All agent-app API calls use the `/v1/agent/` prefix (e.g., `POST /v1/agent/auth/login`, `POST /v1/agent/cash-in`). This is consistent with the existing E12/E13/E14 backend modules which register agent routes under the `agent` middleware group.

**Rationale**: The E01 auth service supports multiple contexts (customer, agent, enterprise, admin). The agent-specific context uses the `agent` role and a separate route prefix to avoid permission bleed-over.

**Alternatives considered**: A unified `/v1/` namespace with role-based routing — rejected because it makes middleware separation harder and was not the pattern adopted in E12–E14.

---

## Decision: Step-up threshold is runtime-configurable, not hard-coded

**Decision**: The step-up amount threshold is read from the backend response or from a cached config value (`GET /v1/agent/config/step-up-threshold`). It defaults to `10000 BWP` as defined in the constitution (GAP-027).

**Rationale**: Constitution Principle 5 explicitly states the threshold is runtime-configurable through the Platform Configuration service and MUST NOT be hard-coded. Caching the config value locally avoids a blocking network call on every transaction.

**Alternatives considered**: Hard-coding 10000 in the app — violates constitution Principle 2 (TBD governance) and Principle 5.

---

## Decision: Float top-up request allows one pending request at a time (warn, not block)

**Decision**: The app checks for an existing pending float request before submitting a new one. If one exists, a warning dialog is shown: "A float top-up request is already pending. Submit a follow-up note instead?" The agent can choose to add a note (POST with `is_followup: true`) or cancel.

**Rationale**: From spec FR-026: "Only one pending request is allowed at a time unless the prior request has been resolved." The UI enforces this with a warning but defers final enforcement to the backend (which returns a 422 if violated).

**Alternatives considered**: Hard-block (show error, no path forward) — poor UX for agents who need urgent float.

---

## Decision: Screenshot guard pattern mirrors E31 (MethodChannel + FLAG_SECURE)

**Decision**: `MethodChannel('screenshot_guard')` with `enable` / `disable` methods, wired to `FLAG_SECURE` on Android and a UIWindow-level overlay on iOS. Called in `initState`/`dispose` of all financial confirmation screens and PIN entry screens.

**Rationale**: Same implementation validated in E31. No new platform-channel work needed.

---

## Decision: Offline financial writes are blocked (no queuing)

**Decision**: Cash-in and cash-out POST requests are blocked when `connectivityProvider` returns offline. An `AlertDialog` is shown with "You are offline — please reconnect and try again." No background queuing of financial mutations.

**Rationale**: Constitution Principle 4 (Financial Integrity): partial or uncertain financial mutations must not be queued silently. An agent paying out cash on the basis of a locally queued request that may later fail would create a fraud risk and ledger imbalance. Spec FR-013 and FR-032 make this explicit.

**Alternatives considered**: Offline queue with retry — rejected on financial integrity grounds; it cannot guarantee the transaction will succeed, and the agent may have already paid out cash.

---

## Decision: Biometric authentication is opt-in per device

**Decision**: After the first PIN login on a device, the agent is prompted: "Enable fingerprint/face login?" If accepted, `local_auth.authenticate()` is used on subsequent app opens. The preference is stored in `shared_preferences` (not sensitive). Biometric cannot be the only authentication method — PIN is always the fallback.

**Rationale**: Spec FR-002; constitution Principle 5 (centralized authentication). Biometric is a UX layer over the secure PIN, not a replacement.

---

## Inherited from E31 (no re-research needed)

- **AuthInterceptor pattern**: Completer<void> queue + `_isRefreshing` flag for 401 handling
- **AppLifecycleObserver**: 5-min background timeout → `requireReauth()`
- **BCrypt PIN hashing**: `BCrypt.hashpw(pin, BCrypt.gensalt(logRounds: 10))`
- **Firebase FCM + APNs**: `firebase_messaging 14.x` + `flutter_local_notifications 17.x`
- **PaginatedResult<T>**: `{data, currentPage, total, lastPage, hasNextPage}` envelope
- **Riverpod `FutureProvider.family<T, int>`**: page-keyed pagination providers
- **`flutter_secure_storage 9.x`**: stores `agent_access_token`, `agent_refresh_token`, `agent_pin_hash`
