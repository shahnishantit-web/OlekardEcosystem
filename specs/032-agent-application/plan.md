# Implementation Plan — E32 Agent Application

## Constitution Check

- [x] Approved Flutter mobile stack respected — no framework substitution (Principle 14)
- [x] No TBD value invented — backend API base URL from `API_BASE_URL` env var; step-up threshold from server config (Principle 2)
- [x] Authorization remains server-side — app sends JWT; backend enforces all permissions and role checks (Principle 5)
- [x] Financial integrity preserved — confirmation screens, idempotency keys for cash-in/cash-out, no client-side balance mutation (Principle 4)
- [x] Security/audit requirements incorporated — PIN bcrypt, biometric fallback, secure storage, screenshot guard, PIN never logged (Principle 6)
- [x] UX/design-system requirements incorporated — Material 3 + shared design tokens, min 48dp touch targets, offline banner (Principles 10 + 11)
- [x] QA strategy incorporated — widget tests, integration tests, fixture-based DTO tests, double-submit concurrency test (Principle 12)
- [x] Step-up threshold runtime-configurable (Principle 5 / GAP-027): `auth.step_up_threshold = 10000`, `auth.step_up_threshold_currency = BWP`
- [x] Sensitive data never logged — PIN, OTP, JWT never appear in structured logs or analytics (Principle 6)

---

## Technical Context

| Concern | Decision |
|---------|----------|
| Platform | Flutter 3.x, iOS 14+, Android 8+ (API 26+) |
| App location | `wallet-ecosystem/apps/agent/` (new Flutter project) |
| State management | Riverpod 2.x with code-generation (`riverpod_generator`) — mirrors E31 customer app |
| Navigation | GoRouter 13.x with `ShellRoute` (4-tab bottom nav), deep-link support |
| HTTP client | Dio 5.x with `AuthInterceptor` (Completer queue + `_isRefreshing` flag), `CorrelationIdInterceptor`, `RetryInterceptor` |
| Secure storage | `flutter_secure_storage 9.x` — stores JWT access+refresh tokens and PIN hash |
| Biometric | `local_auth 2.x` — fingerprint + face ID, falls back to PIN |
| QR scanning | `mobile_scanner 5.x` — scan pickup codes for cash-out |
| Receipt generation | `pdf 3.x` + `printing 5.x` — generate agent receipt as PDF; shared via `share_plus` |
| Push notifications | `firebase_messaging 14.x` — FCM (Android) + APNs via FCM (iOS) |
| Local notifications | `flutter_local_notifications 17.x` — foreground push messages |
| Offline detection | `connectivity_plus 6.x` — offline banner; financial writes blocked when offline |
| Sharing | `share_plus 10.x` — share receipts via native share sheet |
| Camera / document capture | `image_picker 1.x` — KYC document capture |
| Currency/date formatting | `intl 0.19.x` — locale-aware amount display |
| PIN entry UI | `pin_code_fields 8.x` — masked PIN widget |
| Loading states | `shimmer 3.x` — skeleton placeholders |
| Screenshot prevention | `FLAG_SECURE` (Android) + UIWindow overlay (iOS) via `MethodChannel('screenshot_guard')` |
| Design tokens | Shared constants in `lib/core/theme/design_tokens.dart` |
| DI | Riverpod `Provider`/`StateNotifierProvider` — overridable in tests |
| Base URL | `String.fromEnvironment('API_BASE_URL')`, default `http://localhost:8000` |
| App identifier | `com.olekard.agent` |

---

## Project Location

`wallet-ecosystem/apps/agent/`

This is a new Flutter project. It follows the same clean architecture and file conventions established by E31 (`wallet-ecosystem/apps/customer/`). Modules are feature-scoped with `domain/`, `data/`, and `presentation/` layers.

---

## Module Structure

```
lib/
├── main.dart                                   # App entry + ProviderScope + FCM init
├── core/
│   ├── api/
│   │   ├── api_client.dart                     # Dio + AuthInterceptor + CorrelationIdInterceptor
│   │   └── api_response.dart                   # ApiResponse<T> envelope + PaginatedResult<T>
│   ├── router/
│   │   └── app_router.dart                     # GoRouter config, ShellRoute, auth redirect
│   ├── theme/
│   │   ├── app_theme.dart                      # Material 3 theme (uses design tokens)
│   │   └── design_tokens.dart                  # Color, typography, spacing constants
│   ├── security/
│   │   ├── pin_service.dart                    # bcrypt hash/verify + secure storage
│   │   ├── biometric_service.dart              # local_auth wrapper
│   │   ├── secure_storage_service.dart         # flutter_secure_storage wrapper
│   │   ├── screenshot_guard.dart               # MethodChannel enable/disable FLAG_SECURE
│   │   └── app_lifecycle_observer.dart         # 5-min background timeout → re-auth
│   ├── notifications/
│   │   ├── push_notification_service.dart      # firebase_messaging init + FCM token POST
│   │   └── deep_link_handler.dart              # notification type → GoRouter route
│   ├── connectivity/
│   │   └── connectivity_service.dart           # connectivity_plus stream + offline provider
│   └── receipt/
│       └── receipt_service.dart                # pdf + printing: generate ReceiptDocument
│
├── modules/
│   │
│   ├── auth/
│   │   ├── domain/
│   │   │   ├── entities/agent.dart             # Agent entity (id, phone, name, locationCode, status)
│   │   │   ├── entities/auth_token.dart        # AuthToken (accessToken, refreshToken, expiresAt)
│   │   │   └── repositories/auth_repository.dart
│   │   ├── data/
│   │   │   ├── models/login_request_dto.dart
│   │   │   ├── models/auth_response_dto.dart
│   │   │   └── repositories/auth_repository_impl.dart
│   │   └── presentation/
│   │       ├── auth_providers.dart             # AuthNotifier (StateNotifier<AuthState>)
│   │       ├── screens/splash_screen.dart
│   │       ├── screens/login_screen.dart
│   │       ├── screens/forgot_pin_screen.dart
│   │       ├── screens/otp_verification_screen.dart
│   │       └── screens/pin_reset_screen.dart
│   │
│   ├── dashboard/
│   │   ├── domain/entities/dashboard_summary.dart  # floatBalance, todayCashIn, todayCashOut
│   │   ├── domain/repositories/dashboard_repository.dart
│   │   ├── data/models/dashboard_dto.dart
│   │   ├── data/repositories/dashboard_repository_impl.dart
│   │   └── presentation/
│   │       ├── dashboard_providers.dart
│   │       └── screens/dashboard_screen.dart
│   │
│   ├── cash_in/
│   │   ├── domain/entities/customer_lookup.dart    # name, maskedWalletNumber, walletId
│   │   ├── domain/entities/cash_in_result.dart     # referenceNumber, amount, fee, status
│   │   ├── domain/repositories/cash_in_repository.dart
│   │   ├── data/models/cash_in_request_dto.dart
│   │   ├── data/repositories/cash_in_repository_impl.dart
│   │   └── presentation/
│   │       ├── cash_in_providers.dart
│   │       ├── screens/customer_search_screen.dart
│   │       ├── screens/cash_in_form_screen.dart
│   │       ├── screens/cash_in_confirmation_screen.dart
│   │       └── screens/cash_in_result_screen.dart
│   │
│   ├── cash_out/
│   │   ├── domain/entities/pickup_code_lookup.dart  # customerName, amount, expiresAt
│   │   ├── domain/entities/cash_out_result.dart
│   │   ├── domain/repositories/cash_out_repository.dart
│   │   ├── data/models/cash_out_request_dto.dart
│   │   ├── data/repositories/cash_out_repository_impl.dart
│   │   └── presentation/
│   │       ├── cash_out_providers.dart
│   │       ├── screens/pickup_code_screen.dart      # scan QR or type code
│   │       ├── screens/cash_out_confirmation_screen.dart
│   │       └── screens/cash_out_result_screen.dart
│   │
│   ├── customer_onboarding/
│   │   ├── domain/entities/customer_profile.dart   # name, phone, kycStatus, accountStatus
│   │   ├── domain/entities/registration_result.dart
│   │   ├── domain/repositories/customer_repository.dart
│   │   ├── data/models/registration_request_dto.dart
│   │   ├── data/repositories/customer_repository_impl.dart
│   │   └── presentation/
│   │       ├── customer_providers.dart
│   │       ├── screens/customer_lookup_screen.dart
│   │       ├── screens/register_customer_screen.dart
│   │       ├── screens/kyc_capture_screen.dart
│   │       └── screens/customer_status_screen.dart
│   │
│   ├── transactions/
│   │   ├── domain/entities/agent_transaction.dart  # id, referenceNumber, type, amount, fee, customer
│   │   ├── domain/repositories/transaction_repository.dart
│   │   ├── data/models/agent_transaction_dto.dart
│   │   ├── data/repositories/transaction_repository_impl.dart
│   │   └── presentation/
│   │       ├── transaction_providers.dart
│   │       ├── screens/transaction_list_screen.dart
│   │       └── screens/transaction_detail_screen.dart
│   │
│   ├── float/
│   │   ├── domain/entities/float_balance.dart      # balance, lastTopUpAt, lowFloatThreshold
│   │   ├── domain/entities/float_transaction.dart  # id, type, amount, createdAt, referenceNumber
│   │   ├── domain/entities/float_top_up_request.dart # id, amount, status, submittedAt, resolvedAt
│   │   ├── domain/repositories/float_repository.dart
│   │   ├── data/models/float_dto.dart
│   │   ├── data/repositories/float_repository_impl.dart
│   │   └── presentation/
│   │       ├── float_providers.dart
│   │       ├── screens/float_screen.dart
│   │       ├── screens/float_history_screen.dart
│   │       └── screens/float_topup_screen.dart
│   │
│   ├── commission/
│   │   ├── domain/entities/commission_balance.dart  # total, cashIn, cashOut, registration
│   │   ├── domain/entities/commission_entry.dart    # id, type, amount, rateApplied, txReference
│   │   ├── domain/repositories/commission_repository.dart
│   │   ├── data/models/commission_dto.dart
│   │   ├── data/repositories/commission_repository_impl.dart
│   │   └── presentation/
│   │       ├── commission_providers.dart
│   │       └── screens/commission_screen.dart
│   │
│   ├── notifications/
│   │   ├── domain/entities/notification.dart
│   │   ├── domain/repositories/notification_repository.dart
│   │   ├── data/repositories/notification_repository_impl.dart
│   │   └── presentation/
│   │       ├── notification_providers.dart
│   │       └── screens/notifications_screen.dart
│   │
│   └── security_settings/
│       └── presentation/
│           ├── screens/security_settings_screen.dart
│           └── screens/change_pin_screen.dart
│
├── shared/
│   └── widgets/
│       ├── amount_display.dart             # Locale-aware currency display (intl)
│       ├── pin_pad_widget.dart             # 0-9 PIN entry, masked
│       ├── loading_shimmer.dart            # Skeleton loader
│       ├── offline_banner.dart             # Sticky offline indicator strip
│       ├── primary_button.dart             # CTA button (loading state)
│       ├── error_view.dart                 # Generic error + retry
│       └── success_view.dart              # Reference number + share/done actions
│
├── android/
│   ├── app/src/main/kotlin/com/olekard/agent/MainActivity.kt   # FLAG_SECURE channel
│   ├── app/src/main/AndroidManifest.xml
│   ├── app/google-services.json           # PLACEHOLDER — replace before release
│   ├── app/build.gradle.kts
│   └── build.gradle.kts
│
└── ios/
    ├── Runner/AppDelegate.swift             # Firebase init + screenshot_guard channel
    ├── Runner/Info.plist
    └── Runner/GoogleService-Info.plist      # PLACEHOLDER — replace before release
```

---

## Navigation (GoRouter)

```
/                         → SplashScreen (auth check → /login or /dashboard)
/login                    → LoginScreen
/forgot-pin               → ForgotPinScreen (phone entry)
/forgot-pin/otp           → OtpVerificationScreen (PIN reset)
/forgot-pin/new-pin       → PinResetScreen

ShellRoute /shell         → BottomNavShell (Dashboard | Transactions | Float | Profile)
  /dashboard              → DashboardScreen
  /dashboard/notifications → NotificationsScreen

  /transactions           → TransactionListScreen
  /transactions/:id       → TransactionDetailScreen

  /float                  → FloatScreen (balance + shortcuts)
  /float/history          → FloatHistoryScreen
  /float/topup            → FloatTopupScreen
  /commissions            → CommissionScreen

  /profile                → ProfileScreen (agent name, location, KYC status, links)
  /security               → SecuritySettingsScreen
  /security/change-pin    → ChangePinScreen

Full-screen flows (no bottom nav, push onto root navigator):
  /cash-in/search         → CustomerSearchScreen
  /cash-in/form           → CashInFormScreen
  /cash-in/confirm        → CashInConfirmationScreen
  /cash-in/result         → CashInResultScreen

  /cash-out/scan          → PickupCodeScreen (scan QR or type code)
  /cash-out/confirm       → CashOutConfirmationScreen
  /cash-out/result        → CashOutResultScreen

  /customers              → CustomerLookupScreen (search existing)
  /customers/register     → RegisterCustomerScreen
  /customers/:id/kyc      → KycCaptureScreen
  /customers/:id/status   → CustomerStatusScreen

  /step-up-pin            → StepUpPinScreen (full-screen, no nav bar)
```

---

## pubspec.yaml Dependencies

```yaml
name: wallet_agent
description: Wallet Ecosystem agent mobile application.
publish_to: 'none'
version: 1.0.0+1

environment:
  sdk: '>=3.3.0 <4.0.0'

dependencies:
  flutter:
    sdk: flutter
  flutter_riverpod: ^2.5.1
  riverpod_annotation: ^2.3.5
  dio: ^5.4.3
  go_router: ^13.2.0
  shared_preferences: ^2.3.0
  flutter_secure_storage: ^9.0.0
  device_info_plus: ^10.1.2
  uuid: ^4.4.0
  local_auth: ^2.2.0
  mobile_scanner: ^5.1.0
  firebase_messaging: ^14.9.0
  flutter_local_notifications: ^17.2.0
  connectivity_plus: ^6.0.5
  share_plus: ^10.0.0
  image_picker: ^1.1.2
  intl: ^0.19.0
  pin_code_fields: ^8.0.1
  shimmer: ^3.0.0
  bcrypt: ^1.1.3
  cached_network_image: ^3.3.1
  pdf: ^3.10.8
  printing: ^5.12.0

dev_dependencies:
  flutter_test:
    sdk: flutter
  flutter_lints: ^3.0.0
  riverpod_lint: ^2.3.10
  build_runner: ^2.4.9
  riverpod_generator: ^2.4.0
  custom_lint: ^0.6.4
  mocktail: ^1.0.4
```

---

## Key Technical Patterns

### AuthNotifier (mirrors E31 AuthNotifier)
```dart
enum AuthStateEnum { unknown, unauthenticated, authenticated, requiresReauth }

class AuthState {
  final AuthStateEnum status;
  final Agent? agent;
  final String? otpReference;
  final String? challengeToken;
  const AuthState({...});
}
class AuthNotifier extends StateNotifier<AuthState> { ... }
```
Stored in `StateNotifierProvider<AuthNotifier, AuthState>`.

### AuthInterceptor (mirrors E31)
```dart
class AuthInterceptor extends Interceptor {
  final Completer<void> _completer = Completer<void>();
  bool _isRefreshing = false;
  // 401 → queue requests, refresh once, replay; 403 step_up_required → push /step-up-pin
}
```

### Financial Transaction Flow (cash-in and cash-out)
```
1. Customer search / pickup code scan
2. Form entry + client validation
3. Confirmation screen: amount, fee, net, float impact
4. PinPadWidget (6-digit, masked, ScreenshotGuard active)
5. Submit POST with Idempotency-Key: Uuid().v4()
6. On 409: return original response (idempotent result shown)
7. Result screen: reference number + "Share Receipt" button
8. Invalidate dashboardProvider + floatProvider
```

### ReceiptService
```dart
class ReceiptService {
  Future<File> generateReceipt(ReceiptData data) async {
    final pdf = pw.Document();
    pdf.addPage(pw.Page(build: (ctx) => ...));
    final dir = await getTemporaryDirectory();
    final file = File('${dir.path}/receipt_${data.referenceNumber}.pdf');
    await file.writeAsBytes(await pdf.save());
    return file;
  }
}
```
`SharePlus.share(XFile(file.path))` opens the native share sheet.

### Security — PIN (identical to E31)
- Hashed with `BCrypt.hashpw(pin, BCrypt.gensalt(logRounds: 10))`
- Stored in `flutter_secure_storage` as `agent_pin_hash`
- Never appear in logs, crash analytics, or network requests
- `PinPadWidget` uses `obscureText: true`

### Step-Up Authentication
```dart
// In AuthInterceptor, on 403 response body { "code": "step_up_required" }:
context.push('/step-up-pin', extra: {'onComplete': () => retryRequest()});
```
StepUpPinScreen re-uses PinPadWidget; on success calls `POST /v1/agent/auth/step-up` and replays the original request.

### Offline Handling
- `connectivity_plus` stream → `connectivityProvider` (StateProvider<bool> `isOnline`)
- `OfflineBanner` widget rendered as a persistent strip at top of every screen
- `CashInFormScreen` and `CashOutConfirmationScreen` check `ref.read(connectivityProvider)` before submitting; show `AlertDialog` if offline

### Screenshot Guard
- `screenshot_guard.dart` wraps `MethodChannel('screenshot_guard')`
- `enable()` / `disable()` called in `initState` / `dispose` on: CashInConfirmationScreen, CashOutConfirmationScreen, PinPadWidget-containing screens, CustomerStatusScreen

### Push Notifications
- `firebase_messaging` initialized in `main.dart`
- FCM token registered after login: `POST /v1/agent/devices/{id}/push-token`
- `deep_link_handler.dart` maps `type` field: `float_approved` → `/float`, `commission_credited` → `/commissions`, `security_alert` → `/security`

---

## Integration Points (Backend API Dependencies)

All paths are prefixed with the API base URL. JWT in `Authorization: Bearer <token>`. Financial mutations include `Idempotency-Key: <uuid-v4>`.

| Story | Method | Path |
|-------|--------|------|
| Login | POST | `/v1/agent/auth/login` |
| Logout | POST | `/v1/agent/auth/logout` |
| Refresh token | POST | `/v1/agent/auth/refresh` |
| Forgot PIN (send OTP) | POST | `/v1/agent/auth/forgot-pin` |
| Reset PIN (OTP + new) | POST | `/v1/agent/auth/reset-pin` |
| Change PIN (authed) | POST | `/v1/agent/auth/change-pin` |
| Step-up challenge | POST | `/v1/agent/auth/step-up` |
| Agent profile | GET | `/v1/agent/profile` |
| Register device | POST | `/v1/agent/devices` |
| Push token update | POST | `/v1/agent/devices/{id}/push-token` |
| Dashboard summary | GET | `/v1/agent/dashboard` |
| Customer search | GET | `/v1/agent/customers?q={phone_or_wallet}` |
| Cash-in | POST | `/v1/agent/cash-in` |
| Lookup pickup code | GET | `/v1/agent/cash-out/lookup?code={code}` |
| Cash-out redeem | POST | `/v1/agent/cash-out` |
| Register customer | POST | `/v1/agent/customers` |
| Submit KYC docs | POST | `/v1/agent/customers/{id}/kyc` |
| Customer status | GET | `/v1/agent/customers/{id}` |
| Transactions | GET | `/v1/agent/transactions` |
| Transaction detail | GET | `/v1/agent/transactions/{id}` |
| Notifications | GET | `/v1/agent/notifications` |
| Mark notification read | PATCH | `/v1/agent/notifications/{id}/read` |
| Mark all read | POST | `/v1/agent/notifications/mark-all-read` |
| Float balance | GET | `/v1/agent/float` |
| Float history | GET | `/v1/agent/float/history` |
| Submit float top-up | POST | `/v1/agent/float/requests` |
| Commission balance | GET | `/v1/agent/commissions/balance` |
| Commission history | GET | `/v1/agent/commissions` |

---

## Testing Strategy

| Layer | Tool | Coverage target |
|-------|------|-----------------|
| Unit (providers, entities) | `flutter_test` + `mocktail` | 80%+ for domain/data layers |
| Widget tests (screens) | `flutter_test` | All screens with loading/error/success states |
| Integration tests | `flutter_integration_test` | Happy-path for all 9 user stories |
| API contract tests | JSON fixtures in `test/fixtures/` | All DTO `fromJson`/`toJson` round-trips |
| Double-submit test | `flutter_test` | Cash-in + cash-out idempotency with concurrent requests |

Tests in `test/` (unit + widget) and `integration_test/` (integration + scenario).

---

## Implementation Phases

**Phase 1 — Core Infrastructure** (unblocks all modules)
- New Flutter project scaffold at `wallet-ecosystem/apps/agent/`
- `pubspec.yaml` with all dependencies
- `main.dart` with ProviderScope + Firebase + router
- `api_client.dart` with Dio + AuthInterceptor + CorrelationIdInterceptor
- `app_router.dart` with GoRouter config, ShellRoute, splash redirect
- `app_theme.dart` + `design_tokens.dart`
- `pin_service.dart`, `biometric_service.dart`, `secure_storage_service.dart`
- `screenshot_guard.dart` + native platform files (Android/iOS)
- `connectivity_service.dart` + `OfflineBanner` widget
- `app_lifecycle_observer.dart` (5-min timeout → requireReauth)
- `receipt_service.dart` (pdf + printing + share_plus)
- Shared widgets: `amount_display.dart`, `pin_pad_widget.dart`, `loading_shimmer.dart`, `primary_button.dart`, `error_view.dart`, `success_view.dart`

**Phase 2 — Authentication (US-AG-001)**
- `auth` module: Agent entity, AuthToken, AuthRepository, AuthNotifier
- Screens: splash, login, forgot-pin, OTP verification, PIN reset
- Tests: auth_state_test, login_flow_test

**Phase 3 — Dashboard (US-AG-002)**
- `dashboard` module: DashboardSummary entity + repository + providers
- DashboardScreen: float balance card, today's stats, 4 shortcut tiles, low-float warning
- Test: dashboard_providers_test

**Phase 4 — Cash-In (US-AG-003)**
- `cash_in` module: CustomerLookup entity, CashInResult, CashInRepository
- CustomerSearchScreen → CashInFormScreen → CashInConfirmationScreen → CashInResultScreen
- PinPadWidget + ScreenshotGuard + Idempotency-Key
- Test: cash_in_idempotency_test (double-submit)

**Phase 5 — Cash-Out (US-AG-004)**
- `cash_out` module: PickupCodeLookup, CashOutResult, CashOutRepository
- PickupCodeScreen (QR + manual) → CashOutConfirmationScreen → CashOutResultScreen
- PinPadWidget + ScreenshotGuard + Idempotency-Key
- Test: cash_out_idempotency_test

**Phase 6 — Customer Onboarding (US-AG-005)**
- `customer_onboarding` module: CustomerProfile, RegistrationResult, CustomerRepository
- CustomerLookupScreen → RegisterCustomerScreen → KycCaptureScreen → CustomerStatusScreen
- image_picker for KYC document capture
- Test: customer_registration_test

**Phase 7 — Transaction History (US-AG-006)**
- `transactions` module: AgentTransaction entity, TransactionRepository
- TransactionListScreen (filter + paginate) → TransactionDetailScreen (+ share receipt)
- Test: transaction_list_test

**Phase 8 — Float Management (US-AG-007)**
- `float` module: FloatBalance, FloatTransaction, FloatTopUpRequest, FloatRepository
- FloatScreen → FloatHistoryScreen → FloatTopupScreen
- Test: float_topup_test

**Phase 9 — Commission Tracking (US-AG-008)**
- `commission` module: CommissionBalance, CommissionEntry, CommissionRepository
- CommissionScreen with balance breakdown + history + filter
- Test: commission_history_test

**Phase 10 — Notifications & Security Settings (US-AG-009)**
- `notifications` module (mirrors E31): Notification entity, NotificationRepository
- NotificationsScreen with mark-all-read + deep-link navigation
- SecuritySettingsScreen → ChangePinScreen (3-step: current → new → confirm)
- Test: notification_providers_test

**Phase 11 — Native Platform + Firebase**
- `android/app/src/main/kotlin/.../MainActivity.kt` — FLAG_SECURE MethodChannel
- `ios/Runner/AppDelegate.swift` — Firebase init + screenshot_guard channel
- `AndroidManifest.xml`, `Info.plist`, build.gradle.kts files
- Firebase placeholder files (`google-services.json`, `GoogleService-Info.plist`)
- Test: integration_test/app_test.dart (SC-01–SC-10 stubs)

**Phase 12 — Polish & QA**
- Accessibility `Semantics` labels on all interactive elements (min 48dp targets)
- `flutter analyze --fatal-infos` → 0 errors (requires `build_runner` first)
- Quickstart SC-01–SC-10 Docker stack validation
