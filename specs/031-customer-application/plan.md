# Implementation Plan — E31 Customer Application

## Constitution Check

- [x] Approved Flutter mobile stack respected (no framework substitution)
- [x] No TBD value invented — backend API base URL from `API_BASE_URL` env var, not hardcoded
- [x] Authorization remains server-side — app sends JWT, backend enforces all permissions
- [x] Financial integrity preserved — confirmation screens, idempotency keys, no client-side balance mutation
- [x] Security/audit requirements incorporated — PIN, biometric, secure storage, screenshot guard
- [x] UX/design-system requirements incorporated — Material 3 + design tokens, accessibility labels
- [x] QA strategy incorporated — widget tests, integration tests, mock API fixtures

---

## Technical Context

| Concern | Decision |
|---------|----------|
| Platform | Flutter 3.x, iOS 14+, Android 8+ (API 26+) |
| State management | Riverpod 2.x with code-generation (`riverpod_generator`) — confirmed by existing `pubspec.yaml` |
| Navigation | GoRouter 13.x with deep-link support — confirmed by existing `pubspec.yaml` |
| HTTP client | Dio 5.x with `AuthInterceptor`, `CorrelationIdInterceptor`, `RetryInterceptor` — confirmed by `api_client.dart` |
| Secure storage | `flutter_secure_storage` 9.x — confirmed by `pubspec.yaml`; stores JWT access+refresh tokens and PIN hash |
| Biometric | `local_auth` — to be added; fingerprint + face ID, falls back to PIN |
| QR generation | `qr_flutter` — to be added; wallet receive screen |
| QR scanning | `mobile_scanner` — to be added; scan recipient wallet QR |
| Push notifications | `firebase_messaging` — to be added; FCM for Android + APNs via FCM for iOS |
| Local notifications | `flutter_local_notifications` — to be added; display foreground push messages |
| Offline detection | `connectivity_plus` — to be added; show offline banner, block writes |
| Sharing | `share_plus` — to be added; share wallet number/QR |
| Screenshot prevention | `FLAG_SECURE` (Android) + `isIgnoringApplicationActivity` (iOS) via platform channels on financial screens |
| Currency/date formatting | `intl` package — to be added; locale-aware amounts |
| PIN entry UI | `pin_code_fields` — to be added; masked PIN input widget |
| Loading states | `shimmer` — to be added; skeleton placeholders |
| Design tokens | Shared token constants in `lib/core/theme/design_tokens.dart` |
| DI | Riverpod `Provider`/`StateNotifierProvider` — overridable in tests |
| Base URL | `String.fromEnvironment('API_BASE_URL')`, default `http://localhost:8000` — confirmed in `providers.dart` |

---

## Project Location

`wallet-ecosystem/apps/customer/`

All new code lives under this directory. The module-based clean architecture already established by the `device` and `multi_currency` modules is the canonical pattern.

---

## Module Structure

```
lib/
├── main.dart                              # App entry + ProviderScope
├── core/
│   ├── api/
│   │   ├── api_client.dart                # Dio + interceptors (existing, extend AuthInterceptor)
│   │   └── api_response.dart              # ApiResponse<T> envelope (existing)
│   ├── auth/
│   │   └── auth_provider.dart             # AuthState + AuthNotifier (existing stub → full impl)
│   ├── analytics/
│   │   └── analytics_service.dart         # existing
│   ├── di/
│   │   └── providers.dart                 # Root DI (existing, extend)
│   ├── router/
│   │   └── app_router.dart                # GoRouter config with all named routes
│   ├── theme/
│   │   ├── app_theme.dart                 # Material 3 theme
│   │   └── design_tokens.dart             # Color, typography, spacing constants
│   ├── security/
│   │   ├── pin_service.dart               # PIN hashing + secure storage
│   │   ├── biometric_service.dart         # local_auth wrapper
│   │   ├── secure_storage_service.dart    # flutter_secure_storage wrapper
│   │   └── screenshot_guard.dart          # Platform channel to set/clear FLAG_SECURE
│   ├── notifications/
│   │   ├── push_notification_service.dart # firebase_messaging initialization
│   │   └── deep_link_handler.dart         # Map push payloads → GoRouter routes
│   └── connectivity/
│       └── connectivity_service.dart      # connectivity_plus wrapper + offline banner provider
│
├── modules/
│   ├── device/          (existing — US-CUST-005)
│   ├── multi_currency/  (existing — E29)
│   │
│   ├── auth/
│   │   ├── domain/
│   │   │   ├── entities/customer.dart
│   │   │   ├── entities/auth_token.dart
│   │   │   └── repositories/auth_repository.dart
│   │   ├── data/
│   │   │   ├── models/register_request_dto.dart
│   │   │   ├── models/login_request_dto.dart
│   │   │   ├── models/auth_response_dto.dart
│   │   │   └── repositories/auth_repository_impl.dart
│   │   └── presentation/
│   │       ├── auth_providers.dart
│   │       ├── screens/splash_screen.dart
│   │       ├── screens/login_screen.dart
│   │       ├── screens/register_screen.dart
│   │       ├── screens/otp_verification_screen.dart
│   │       ├── screens/profile_setup_screen.dart
│   │       ├── screens/pin_creation_screen.dart
│   │       ├── screens/forgot_pin_screen.dart
│   │       └── screens/pin_reset_screen.dart
│   │
│   ├── home/
│   │   ├── domain/entities/home_summary.dart
│   │   ├── data/repositories/home_repository_impl.dart
│   │   └── presentation/
│   │       ├── home_providers.dart
│   │       └── screens/home_screen.dart
│   │
│   ├── wallet/
│   │   ├── domain/entities/wallet.dart
│   │   ├── domain/repositories/wallet_repository.dart
│   │   ├── data/models/wallet_dto.dart
│   │   ├── data/repositories/wallet_repository_impl.dart
│   │   └── presentation/
│   │       ├── wallet_providers.dart
│   │       ├── screens/wallet_detail_screen.dart
│   │       └── screens/receive_money_screen.dart
│   │
│   ├── transactions/
│   │   ├── domain/entities/transaction.dart
│   │   ├── domain/repositories/transaction_repository.dart
│   │   ├── data/models/transaction_dto.dart
│   │   ├── data/repositories/transaction_repository_impl.dart
│   │   └── presentation/
│   │       ├── transaction_providers.dart
│   │       ├── screens/transaction_list_screen.dart
│   │       └── screens/transaction_detail_screen.dart
│   │
│   ├── funding/
│   │   ├── domain/entities/funding_result.dart
│   │   ├── domain/repositories/funding_repository.dart
│   │   ├── data/models/fund_request_dto.dart
│   │   ├── data/repositories/funding_repository_impl.dart
│   │   └── presentation/
│   │       ├── funding_providers.dart
│   │       ├── screens/add_money_screen.dart
│   │       ├── screens/funding_method_screen.dart
│   │       └── screens/funding_result_screen.dart
│   │
│   ├── transfer/
│   │   ├── domain/entities/transfer_result.dart
│   │   ├── domain/repositories/transfer_repository.dart
│   │   ├── data/models/transfer_request_dto.dart
│   │   ├── data/repositories/transfer_repository_impl.dart
│   │   └── presentation/
│   │       ├── transfer_providers.dart
│   │       ├── screens/send_money_screen.dart
│   │       ├── screens/transfer_confirmation_screen.dart
│   │       └── screens/transfer_result_screen.dart
│   │
│   ├── withdrawal/
│   │   ├── domain/entities/withdrawal_result.dart
│   │   ├── domain/repositories/withdrawal_repository.dart
│   │   ├── data/models/withdraw_request_dto.dart
│   │   ├── data/repositories/withdrawal_repository_impl.dart
│   │   └── presentation/
│   │       ├── withdrawal_providers.dart
│   │       ├── screens/withdraw_screen.dart
│   │       ├── screens/withdrawal_confirmation_screen.dart
│   │       └── screens/withdrawal_result_screen.dart
│   │
│   ├── services/
│   │   ├── domain/entities/service_category.dart
│   │   ├── domain/entities/service_provider.dart
│   │   ├── domain/entities/service_payment.dart
│   │   ├── domain/repositories/services_repository.dart
│   │   ├── data/models/service_dto.dart
│   │   ├── data/repositories/services_repository_impl.dart
│   │   └── presentation/
│   │       ├── services_providers.dart
│   │       ├── screens/services_home_screen.dart
│   │       ├── screens/service_providers_screen.dart
│   │       ├── screens/service_purchase_screen.dart
│   │       ├── screens/service_confirmation_screen.dart
│   │       ├── screens/service_result_screen.dart
│   │       └── screens/service_history_screen.dart
│   │
│   ├── profile/
│   │   ├── domain/entities/profile.dart
│   │   ├── domain/repositories/profile_repository.dart
│   │   ├── data/models/profile_dto.dart
│   │   ├── data/repositories/profile_repository_impl.dart
│   │   └── presentation/
│   │       ├── profile_providers.dart
│   │       ├── screens/profile_screen.dart
│   │       └── screens/edit_profile_screen.dart
│   │
│   ├── security_settings/
│   │   └── presentation/
│   │       ├── security_providers.dart
│   │       ├── screens/security_screen.dart
│   │       └── screens/change_pin_screen.dart
│   │
│   ├── notifications/
│   │   ├── domain/entities/notification.dart
│   │   ├── domain/repositories/notification_repository.dart
│   │   ├── data/models/notification_dto.dart
│   │   ├── data/repositories/notification_repository_impl.dart
│   │   └── presentation/
│   │       ├── notification_providers.dart
│   │       └── screens/notifications_screen.dart
│   │
│   └── support/
│       ├── domain/entities/support_ticket.dart
│       ├── domain/entities/dispute.dart
│       ├── domain/repositories/support_repository.dart
│       ├── data/models/support_dto.dart
│       ├── data/repositories/support_repository_impl.dart
│       └── presentation/
│           ├── support_providers.dart
│           ├── screens/support_home_screen.dart
│           ├── screens/new_inquiry_screen.dart
│           ├── screens/ticket_detail_screen.dart
│           ├── screens/raise_dispute_screen.dart
│           ├── screens/dispute_list_screen.dart
│           └── screens/dispute_detail_screen.dart
│
├── shared/
│   └── widgets/
│       ├── amount_display.dart             # Locale-aware currency display
│       ├── pin_pad_widget.dart             # 0-9 PIN entry UI
│       ├── loading_shimmer.dart            # Skeleton loader
│       ├── offline_banner.dart             # Offline indicator strip
│       ├── transaction_list_tile.dart      # Reused in home + transactions
│       ├── primary_button.dart             # Primary CTA button
│       ├── error_view.dart                 # Generic error + retry
│       └── success_view.dart              # Generic success with reference
│
└── l10n/                                   (existing)
```

---

## Navigation (GoRouter)

```
/                         → SplashScreen (auth check + redirect)
/login                    → LoginScreen
/register                 → RegisterScreen (phone entry)
/register/otp             → OtpVerificationScreen (registration)
/register/profile         → ProfileSetupScreen
/register/pin             → PinCreationScreen
/forgot-pin               → ForgotPinScreen (phone entry)
/forgot-pin/otp           → OtpVerificationScreen (PIN reset)
/forgot-pin/new-pin       → PinResetScreen

ShellRoute /shell         → BottomNavShell (Home | Wallet | Services | Profile)
  /home                   → HomeScreen
  /home/notifications     → NotificationsScreen
  /home/transactions      → TransactionListScreen
  /home/transactions/:id  → TransactionDetailScreen
  /wallet                 → WalletDetailScreen
  /receive                → ReceiveMoneyScreen
  /services               → ServicesHomeScreen
  /services/:categoryId   → ServiceProvidersScreen
  /services/purchase/:id  → ServicePurchaseScreen (+ confirmation + result)
  /services/history       → ServiceHistoryScreen
  /profile                → ProfileScreen
  /profile/edit           → EditProfileScreen
  /security               → SecurityScreen
  /security/change-pin    → ChangePinScreen
  /security/devices       → (existing DeviceListScreen)
  /support                → SupportHomeScreen
  /support/new            → NewInquiryScreen
  /support/:id            → TicketDetailScreen
  /disputes               → DisputeListScreen
  /disputes/new/:txId     → RaiseDisputeScreen
  /disputes/:id           → DisputeDetailScreen

Full-screen overlays (no bottom nav):
  /send                   → SendMoneyScreen → TransferConfirmationScreen → TransferResultScreen
  /add-money              → AddMoneyScreen → FundingMethodScreen → FundingResultScreen
  /withdraw               → WithdrawScreen → WithdrawalConfirmationScreen → WithdrawalResultScreen
```

---

## New pubspec.yaml Dependencies

```yaml
# Add to dependencies:
local_auth: ^2.2.0
qr_flutter: ^4.1.0
mobile_scanner: ^5.1.0
firebase_messaging: ^14.9.0
flutter_local_notifications: ^17.2.0
connectivity_plus: ^6.0.5
share_plus: ^10.0.0
image_picker: ^1.1.2
intl: ^0.19.0
pin_code_fields: ^8.0.1
shimmer: ^3.0.0
```

---

## Key Technical Patterns

### Repository Pattern (per existing `device` module)
```
domain/repositories/foo_repository.dart   → abstract class FooRepository { ... }
data/repositories/foo_repository_impl.dart → class FooRepositoryImpl implements FooRepository
```
Riverpod `Provider<FooRepository>` binds the impl; tests override with mocks.

### Riverpod Providers (per feature)
```dart
// <feature>_providers.dart
@riverpod
Future<List<Transaction>> transactionList(TransactionListRef ref) => ...
```
Code-generated with `riverpod_generator`. Annotate with `@riverpod` or `@Riverpod(keepAlive: true)`.

### Financial Transaction Flow (every P0 financial story)
1. User fills form → client-side validation (amount limits from backend config)
2. Summary screen: amount + fee + net debit (all from backend's `/preview` endpoint or calculated from displayed fee schedule)
3. PIN entry (masked, never logged)
4. Submit with `Idempotency-Key: <uuid-v4>` header
5. Success / failure result screen with reference number
6. Refresh wallet balance

### Security — PIN
- PIN stored as bcrypt hash in `flutter_secure_storage` (never plaintext)
- PIN entry widget: `pin_code_fields`, `obscureText: true`
- No PIN value in logs, crash reports, or analytics

### Security — Biometric
- `local_auth.authenticate(localizedReason: '...')` wraps access to secure storage token
- Falls back to PIN if biometric fails or is not enrolled
- Enable/disable in security settings; preference stored in `shared_preferences` (not secure — not sensitive)

### Security — Screenshot Guard
- `screenshot_guard.dart` exposes `enable()` / `disable()` via `MethodChannel`
- Called in `initState` / `dispose` of financial screens and profile screens
- Android: `WindowManager.LayoutParams.FLAG_SECURE`
- iOS: `isIgnoringApplicationActivity = true` on text field overlay trick

### Offline Handling
- `connectivity_plus` `ConnectivityResult` stream exposed via `connectivityProvider`
- `OfflineBanner` widget subscribes and renders a sticky "You are offline" strip
- Read-only screens serve cached data (last-fetched state in Riverpod state)
- Financial write operations check connectivity and show a blocking dialog if offline

### Push Notifications
- `firebase_messaging` initialized in `main.dart`; FCM token registered on login via `POST /v1/customer/devices/{id}/push-token`
- Foreground messages handled by `flutter_local_notifications`
- Background/terminated: `FirebaseMessaging.onBackgroundMessage` handler
- `deep_link_handler.dart` maps notification `type` field → GoRouter route

---

## Integration Points (Backend API Dependencies)

All endpoints are prefixed `/api/v1/` unless noted. JWT in `Authorization: Bearer <token>`.

| Story | Method | Path |
|-------|--------|------|
| Register | POST | `/v1/customer/auth/register` |
| Login | POST | `/v1/customer/auth/login` |
| Logout | POST | `/v1/customer/auth/logout` |
| Reset PIN | POST | `/v1/customer/auth/reset-pin` |
| Devices | GET/DELETE | `/v1/customer/devices` |
| Wallet balance | GET | `/v1/customer/wallets/me` |
| Transactions | GET | `/v1/customer/transactions` |
| Transaction detail | GET | `/v1/customer/transactions/{id}` |
| Notifications | GET/PATCH | `/v1/customer/notifications` |
| Add money | POST | `/v1/customer/funding` |
| Send money | POST | `/v1/customer/transfers` |
| Withdraw | POST | `/v1/customer/withdrawals` |
| Services catalog | GET | `/v1/customer/services` |
| Purchase service | POST | `/v1/customer/payments/service` |
| Service history | GET | `/v1/customer/payments/service` |
| Profile | GET/PATCH | `/v1/customer/profile` |
| Support ticket | GET/POST | `/v1/customer/support/tickets` |
| Dispute | GET/POST | `/v1/customer/support/disputes` |

---

## Testing Strategy

| Layer | Tool | Coverage target |
|-------|------|-----------------|
| Unit (providers, repositories) | `flutter_test` + `mocktail` | 80%+ for domain/data layers |
| Widget tests (screens) | `flutter_test` | All screens with loading/error/success states |
| Integration tests | `flutter_integration_test` | Happy-path for all 23 user stories |
| API contract tests | JSON fixture files in `test/fixtures/` | All DTO `fromJson`/`toJson` round-trip |

Tests are in `test/` (unit + widget) and `integration_test/` (integration).

---

## Implementation Phases

**Phase 1 — Core Infrastructure (unblocks all other modules)**
- Extend `AuthInterceptor` with real JWT attach + token refresh
- `app_router.dart` with full GoRouter config and splash redirect
- `app_theme.dart` with Material 3 and design tokens
- `pin_service.dart`, `biometric_service.dart`, `secure_storage_service.dart`
- `connectivity_service.dart` + `OfflineBanner` widget
- Push notification init + deep link handler
- `screenshot_guard.dart`
- Update `main.dart` to wire router + notifications + theme

**Phase 2 — Authentication (US-CUST-001 to 004)**
- `auth` module: entities, repository interface + impl, providers
- Screens: splash, login, register, OTP, profile setup, PIN creation, forgot PIN, PIN reset
- Tests: auth provider, login form validation, OTP screen

**Phase 3 — Home & Wallet (US-CUST-005 to 010)**
- `home`, `wallet`, `transactions`, `notifications` modules
- Bottom nav shell with 4 tabs
- Screens: home, wallet detail, receive QR, transaction list, transaction detail, notifications
- Tests: wallet provider, balance display, transaction list pagination

**Phase 4 — Financial Transactions (US-CUST-011 to 014)**
- `funding`, `transfer`, `withdrawal` modules
- Confirmation + PIN authorization pattern for all financial flows
- Idempotency key generation
- Screenshot guard on all financial screens
- Tests: all financial flows with mock API + idempotency key verification

**Phase 5 — Profile & Security (US-CUST-018 to 020)**
- `profile`, `security_settings` modules (and extends existing `device` module)
- Biometric toggle, PIN change, device list/revoke
- Tests: PIN change flow, biometric toggle

**Phase 6 — Services (US-CUST-015 to 017)**
- `services` module: catalog, purchase, history
- Dynamic service fields from backend schema
- Tests: service catalog loading, purchase flow

**Phase 7 — Support & Disputes (US-CUST-021 to 023)**
- `support` module: inquiries, disputes, tracking
- Image picker for attachments
- Tests: ticket creation, dispute raise

**Phase 8 — Polish & QA**
- Accessibility labels (Semantics widgets) on all interactive elements
- Shimmer loaders on all list/detail screens
- Offline banner integration on all screens
- `intl` currency/date formatting audit
- Integration test suite execution
- PHPStan equivalent for Flutter: `dart analyze --fatal-infos` (0 warnings/errors)
