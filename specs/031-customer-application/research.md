# Research — E31 Customer Application

## Decision 1: State Management — Riverpod 2.x (code-gen)

**Decision**: Use `flutter_riverpod ^2.5.1` with `riverpod_generator` and `@riverpod` annotations.

**Rationale**: Already present in `pubspec.yaml` and used by the existing `device` and `multi_currency` modules. Riverpod 2.x provides compile-time safety for provider lookups, automated disposal, scoped overrides for testing, and `AsyncValue<T>` which removes manual loading/error state management. The code-generation approach reduces boilerplate and prevents common mistakes like missing `ref.watch` vs `ref.read` distinctions.

**Alternatives considered**:
- `bloc/cubit` — more verbose, doesn't benefit from Riverpod's dependency graph; would break the existing module pattern.
- `provider` (legacy) — simpler but lacks compile-time safety; deprecated in favor of Riverpod.
- `GetX` — global singletons conflict with testability requirements; unapproved stack addition.

---

## Decision 2: Navigation — GoRouter 13.x

**Decision**: Use `go_router ^13.2.0` already in `pubspec.yaml`.

**Rationale**: GoRouter offers declarative, URL-based navigation with deep link support (required for push notification payloads), type-safe named routes, shell routes for the bottom navigation bar, and built-in redirect guards (`redirect:` callback) for authentication gating. It integrates naturally with Riverpod via `ref.watch` in the `redirect` function.

**Alternatives considered**:
- `auto_route` — powerful but heavy code-gen overhead; GoRouter is official Flutter team recommendation.
- `Navigator 2.0` raw — far more boilerplate for the same result.

---

## Decision 3: HTTP Client — Dio 5.x with five interceptors

**Decision**: Retain existing `dio ^5.4.3` setup; extend `AuthInterceptor` to handle token refresh.

**Rationale**: `api_client.dart` already wires: `AuthInterceptor` (stub — to be implemented), `CorrelationIdInterceptor` (generates UUID per request), `RetryInterceptor` (3 retries with exponential backoff), `LoggingInterceptor`, `ErrorInterceptor`. Dio's `Interceptor` chain with request/response/error callbacks is the right abstraction for adding per-request idempotency keys on financial endpoints.

**Token refresh strategy**: On 401, `AuthInterceptor.onError` calls `POST /v1/customer/auth/refresh`, updates `flutter_secure_storage`, retries the original request. A `bool _isRefreshing` guard plus a `Completer` queue prevents concurrent refresh storms.

**Alternatives considered**:
- `http` package — no interceptor chain; retry and auth headers require middleware wrapping.
- `chopper` — generates typed clients but adds a code-gen step on top of Dio.

---

## Decision 4: Secure Storage — flutter_secure_storage 9.x

**Decision**: Use `flutter_secure_storage ^9.0.0` already in `pubspec.yaml`.

**Stored values**:
- `access_token` — JWT access token (short-lived, ≤15 min)
- `refresh_token` — long-lived refresh token
- `pin_hash` — bcrypt hash of the user's PIN (never the PIN plaintext)
- `device_id` — registered device UUID

**Platform key storage**:
- Android: `EncryptedSharedPreferences` with `AES-256-GCM` key in Android Keystore.
- iOS: Keychain Services with `kSecAttrAccessibleWhenUnlockedThisDeviceOnly`.

**Alternatives considered**:
- `shared_preferences` — not encrypted; unacceptable for JWTs and PIN hash.
- Custom SQLCipher — overkill; `flutter_secure_storage` wraps native secure APIs correctly.

---

## Decision 5: Biometric Authentication — local_auth ^2.2.0

**Decision**: Add `local_auth: ^2.2.0`.

**Rationale**: The official Flutter plugin for biometric/device authentication. Supports fingerprint, Face ID (iOS), and device credential fallback. Used to gate access to the app after background + to pre-authorize financial flows instead of re-entering PIN. The `authenticate(localizedReason:)` API surfaces native OS prompts ensuring compliance with platform security guidelines.

**Implementation pattern**:
1. User opens app from background → `local_auth.isAvailable` check → if true, prompt biometric before showing home.
2. Financial confirm screen → biometric attempt → success unlocks submit; failure falls back to PIN entry.
3. `canCheckBiometrics` + `getAvailableBiometrics()` used in security settings to show/hide biometric toggle.

**Alternatives considered**:
- Custom PIN-only flow — does not leverage device hardware security; worse UX.
- `flutter_biometrics` (community) — less maintained; `local_auth` is the official plugin.

---

## Decision 6: QR Code — qr_flutter + mobile_scanner

**Decision**: Add `qr_flutter: ^4.1.0` (generate) and `mobile_scanner: ^5.1.0` (scan).

**Rationale**:
- `qr_flutter` renders a `QrImageView` widget from a string (wallet number/address) with customizable size, color, and embedded logo. Used on the Receive Money screen.
- `mobile_scanner` uses the device camera and ML Kit (Android) / AVFoundation (iOS) to decode QR codes in real time. Used on the Send Money screen to scan recipient wallet QR. It is the most actively maintained scanner on pub.dev as of 2026.

**Alternatives considered**:
- `qr_code_scanner` — largely unmaintained since 2022.
- `barcode_scan2` — also unmaintained; mobile_scanner is the community standard.

---

## Decision 7: Push Notifications — firebase_messaging + flutter_local_notifications

**Decision**: Add `firebase_messaging: ^14.9.0` and `flutter_local_notifications: ^17.2.0`.

**Rationale**: Firebase Cloud Messaging provides a unified push delivery layer over APNs (iOS) and FCM (Android). `flutter_local_notifications` is required to display foreground push messages as visible banners (FCM alone only delivers the data payload when the app is in foreground). The two-package pattern is the canonical Flutter approach.

**Deep link mapping** (notification `type` → GoRouter path):
- `type: "transaction"` + `transaction_id` → `/home/transactions/:id`
- `type: "transfer"` → `/home/transactions`
- `type: "alert"` → `/home/notifications`
- `type: "support_reply"` + `ticket_id` → `/support/:id`
- `type: "kyc_approved"` → `/profile`

**FCM token registration**: On successful login, the app calls `POST /v1/customer/devices/{id}/push-token` with the FCM token. On logout, it calls `DELETE /v1/customer/devices/{id}/push-token`.

**Alternatives considered**:
- OneSignal — third-party SaaS dependency; adds cost and data-sharing concern.
- Pusher Beams — same concern; FCM is preferred for control and cost.

---

## Decision 8: Offline Detection — connectivity_plus ^6.0.5

**Decision**: Add `connectivity_plus: ^6.0.5`.

**Rationale**: Exposes a `Stream<ConnectivityResult>` that fires on network state changes. The app subscribes in a root-level Riverpod `StreamProvider` and propagates offline status to an `OfflineBanner` widget injected at the shell route level. Financial write operations check the provider before submission and show a blocking dialog.

**Note**: `ConnectivityResult.none` does not guarantee actual internet; it indicates no network interface is active. For true connectivity verification before financial submissions, a lightweight `HEAD` request to the API health endpoint provides a reliable check.

---

## Decision 9: PIN Hashing — bcrypt via dart bcrypt

**Decision**: Hash PIN with bcrypt (cost factor 10) before storing in `flutter_secure_storage`.

**Rationale**: The PIN (4–6 digits) must not be stored in plaintext or with a reversible cipher. bcrypt's adaptive cost factor makes brute-force expensive even if the device storage is extracted. On PIN entry, the user's input is hashed with the same cost factor and compared; the stored hash is never sent to the backend (PIN auth uses the server-side verification flow).

**PIN auth flow**:
1. App computes `bcrypt(enteredPin, storedSalt)`.
2. Compares with stored hash.
3. On match, retrieves the JWT access token from secure storage and proceeds.
4. For server-side operations requiring PIN proof (financial transactions), the app sends the PIN hash to the backend, which re-verifies against its own stored hash.

---

## Decision 10: Screenshot Prevention — Platform Channel (FLAG_SECURE / Overlay)

**Decision**: Custom `MethodChannel` in `screenshot_guard.dart`; native implementations in `MainActivity.kt` and `AppDelegate.swift`.

**Rationale**: No maintained Flutter plugin covers both platforms reliably as of 2026. A two-method channel (`enable`/`disable`) is < 30 lines of native code per platform and gives precise control over which screens are protected.

**Screens protected**: login, PIN entry/change, wallet balance, transaction detail, transfer confirmation, withdrawal confirmation, receive QR, profile (with phone/name).

**Alternatives considered**:
- `flutter_windowmanager` — Android-only; no iOS support.
- `no_screenshot` — community plugin, infrequently maintained.

---

## Decision 11: Module Architecture — Clean Architecture Layers

**Decision**: Follow the pattern established by `lib/modules/device/` and `lib/modules/multi_currency/`.

**Layer mapping**:
- `domain/entities/` — immutable Dart value classes (no JSON); business rules only.
- `domain/repositories/` — abstract interfaces returning `Future<T>` or `Stream<T>`.
- `data/models/` — JSON-serializable DTOs with `fromJson`/`toJson`.
- `data/repositories/` — `Dio`-backed implementations of domain interfaces.
- `presentation/screens/` — `ConsumerWidget` / `ConsumerStatefulWidget` screens.
- `presentation/<feature>_providers.dart` — Riverpod `@riverpod` annotated providers.

**Rationale**: Testability — domain and data layers can be tested with `mocktail` without Flutter widgets. Extractability — each module is independently replaceable. Consistency — new contributors follow an established pattern.

---

## Decision 12: Internationalization — intl ^0.19.0

**Decision**: Add `intl: ^0.19.0` for currency and date formatting.

**Rationale**: The existing `l10n/` directory exists for string localization. The `intl` package provides `NumberFormat.currency(locale: ..., symbol: 'KES')` for locale-aware money display and `DateFormat.yMMMd()` for date strings. Both are used in `AmountDisplay` widget and `TransactionListTile`.

**Locale source**: User profile locale preference from backend (`profile.locale`), falling back to `Intl.systemLocale`.
