# Tasks: E31 â€” Customer Application

**Input**: Design documents from `specs/031-customer-application/`

**Prerequisites**: plan.md âœ“, spec.md âœ“, research.md âœ“, data-model.md âœ“, contracts/customer-api.md âœ“, quickstart.md âœ“

**App root**: `wallet-ecosystem/apps/customer/`
All Dart source paths are relative to `wallet-ecosystem/apps/customer/lib/` unless otherwise noted.

**Format**: `[ID] [P?] [Story?] Description â€” exact file path`
- **[P]**: Parallelizable (different files, no blocking dependency)
- **[USx]**: Maps to US-CUST-00x from spec.md (e.g. [US12] = US-CUST-012 Send Money)

---

## Phase 1: Setup

**Purpose**: Add new packages and create the directory skeleton for all new modules and core services.

- [X] T001 Add 14 new dependencies to `wallet-ecosystem/apps/customer/pubspec.yaml`: `local_auth: ^2.2.0`, `qr_flutter: ^4.1.0`, `mobile_scanner: ^5.1.0`, `firebase_messaging: ^14.9.0`, `flutter_local_notifications: ^17.2.0`, `connectivity_plus: ^6.0.5`, `share_plus: ^10.0.0`, `image_picker: ^1.1.2`, `intl: ^0.19.0`, `pin_code_fields: ^8.0.1`, `shimmer: ^3.0.0`, `bcrypt: ^1.1.3`, `shared_preferences: ^2.3.0`, `cached_network_image: ^3.3.1`
- [X] T002 Run `flutter pub get` in `wallet-ecosystem/apps/customer/` and confirm 0 dependency conflicts; verify `riverpod_generator: ^2.3.0` and `build_runner: ^2.4.0` are present in `dev_dependencies` (add them if missing) â€” required for `@riverpod` code-generation used throughout the app
- [X] T003 [P] Create empty placeholder `.dart` files for `lib/core/theme/app_theme.dart`, `lib/core/theme/design_tokens.dart`, `lib/core/router/app_router.dart`
- [X] T004 [P] Create empty placeholder `.dart` files for `lib/core/security/pin_service.dart`, `lib/core/security/biometric_service.dart`, `lib/core/security/secure_storage_service.dart`, `lib/core/security/screenshot_guard.dart`
- [X] T005 [P] Create empty placeholder `.dart` files for `lib/core/notifications/push_notification_service.dart`, `lib/core/notifications/deep_link_handler.dart`, `lib/core/connectivity/connectivity_service.dart`
- [X] T006 [P] Create module directory skeletons with empty `.dart` placeholders for each new module under `lib/modules/`: `auth/`, `home/`, `wallet/`, `transactions/`, `funding/`, `transfer/`, `withdrawal/`, `services/`, `profile/`, `security_settings/`, `notifications/`, `support/` â€” each with `domain/entities/`, `domain/repositories/`, `data/models/`, `data/repositories/`, `presentation/screens/` subdirectory structure
- [X] T007 [P] Create empty placeholder `.dart` files for all shared widgets in `lib/shared/widgets/`: `amount_display.dart`, `pin_pad_widget.dart`, `loading_shimmer.dart`, `offline_banner.dart`, `transaction_list_tile.dart`, `primary_button.dart`, `error_view.dart`, `success_view.dart`
- [X] T008 [P] Create `wallet-ecosystem/apps/customer/integration_test/` directory with empty stub files: `auth_flow_test.dart`, `wallet_test.dart`, `financial_flow_test.dart`, `services_test.dart`, `security_test.dart`
- [X] T009 [P] Create `wallet-ecosystem/apps/customer/test/fixtures/` directory with empty JSON stub files: `wallet.json`, `transactions.json`, `notifications.json`, `profile.json`, `service_categories.json`, `service_providers.json`
- [X] T010 Add `MethodChannel` handler for screenshot guard to `wallet-ecosystem/apps/customer/android/app/src/main/kotlin/.../MainActivity.kt` â€” channel name `screenshot_guard`; `enable` method sets `FLAG_SECURE`, `disable` clears it
- [X] T011 Add `MethodChannel` handler for screenshot guard to `wallet-ecosystem/apps/customer/ios/Runner/AppDelegate.swift` â€” channel name `screenshot_guard`; `enable` adds secure overlay, `disable` removes it
- [X] T012 [P] Add required permissions for camera (mobile_scanner), biometric (USE_BIOMETRIC), and push notifications to `wallet-ecosystem/apps/customer/android/app/src/main/AndroidManifest.xml` and `wallet-ecosystem/apps/customer/ios/Runner/Info.plist`
- [X] T159 Configure Firebase for Android: add `google-services.json` (from Firebase Console) to `wallet-ecosystem/apps/customer/android/app/`; add `com.google.gms:google-services` classpath to `wallet-ecosystem/apps/customer/android/build.gradle`; apply `google-services` plugin in `wallet-ecosystem/apps/customer/android/app/build.gradle` â€” required for `firebase_messaging` initialization
- [X] T160 Configure Firebase for iOS: add `GoogleService-Info.plist` (from Firebase Console) to `wallet-ecosystem/apps/customer/ios/Runner/`; ensure `FirebaseApp.configure()` is called in `AppDelegate.swift` before `super.application`

---

## Phase 2: Foundational (Core Infrastructure)

**Purpose**: Implement all core services and shared widgets that every user story depends on.

**âš ï¸ CRITICAL**: Phases 3â€“12 are blocked until this phase is complete.

**Checkpoint**: `dart analyze` passes, `PrimaryButton` and `PinPadWidget` render in isolation.

- [X] T013 Implement `lib/core/theme/design_tokens.dart` â€” define `AppColors` (primary, secondary, error, success, surface, background), `AppTypography` (headline, body, label text styles), `AppSpacing` constants (xs=4, sm=8, md=16, lg=24, xl=32)
- [X] T014 Implement `lib/core/theme/app_theme.dart` â€” `ThemeData` using Material 3 with `ColorScheme.fromSeed(seedColor: AppColors.primary)`; typography from `AppTypography`; `static ThemeData get light`
- [X] T015 Implement `lib/core/security/secure_storage_service.dart` â€” typed `readAccessToken()`, `writeAccessToken(String)`, `readRefreshToken()`, `writeRefreshToken(String)`, `readPinHash()`, `writePinHash(String)`, `readDeviceId()`, `writeDeviceId(String)`, `deleteAll()` wrapping `FlutterSecureStorage` with `AndroidOptions(encryptedSharedPreferences: true)` and `IOSOptions(accessibility: KeychainAccessibility.unlocked_this_device)`
- [X] T016 Implement `lib/core/security/pin_service.dart` â€” `Future<String> hashPin(String pin)` using `BCrypt.hashpw(pin, BCrypt.gensalt(logRounds: 10))`; `Future<bool> verifyPin(String pin, String storedHash)` using `BCrypt.checkpw(pin, storedHash)`; no PIN value appears in any log call
- [X] T017 Implement `lib/core/security/biometric_service.dart` â€” `Future<bool> isAvailable()`, `Future<bool> authenticate(String localizedReason)`, `Future<List<BiometricType>> getAvailableBiometrics()` wrapping `LocalAuthentication()`
- [X] T018 Implement `lib/core/security/screenshot_guard.dart` â€” Riverpod service class with `Future<void> enable()` and `Future<void> disable()` invoking `MethodChannel('screenshot_guard').invokeMethod('enable'/'disable')`; no-op on web
- [X] T019 Implement `lib/core/connectivity/connectivity_service.dart` â€” `@riverpod Stream<ConnectivityResult> connectivityStream(ConnectivityStreamRef ref)` from `Connectivity().onConnectivityChanged`; `@riverpod bool isOffline(IsOfflineRef ref)` watching the stream, returning `true` when result is `ConnectivityResult.none`
- [X] T020 Implement `lib/shared/widgets/offline_banner.dart` â€” `ConsumerWidget` watching `isOfflineProvider`; renders a sticky amber strip "You are offline â€” showing cached data" at top when offline; renders `SizedBox.shrink()` when online
- [X] T021 [P] Implement `lib/shared/widgets/amount_display.dart` â€” `AmountDisplay({required int minorUnits, required String currencyCode, TextStyle? style})` widget using `NumberFormat.currency(locale: Intl.systemLocale, symbol: currencyCode)` from `intl`; never shows raw minor-unit integers
- [X] T022 [P] Implement `lib/shared/widgets/pin_pad_widget.dart` â€” `PinPadWidget({int length = 6, required ValueChanged<String> onCompleted})` using `PinCodeTextField` with `obscureText: true`, `keyboardType: TextInputType.number`; clears after `onCompleted` fires; `Semantics(label: 'PIN entry')` wrapper
- [X] T023 [P] Implement `lib/shared/widgets/loading_shimmer.dart` â€” `ShimmerBox({double width, double height, BorderRadius? borderRadius})` and `ShimmerList({int itemCount, double itemHeight = 64})` using the `shimmer` package's `Shimmer.fromColors`
- [X] T024 [P] Implement `lib/shared/widgets/primary_button.dart` â€” `PrimaryButton({required String label, required VoidCallback? onPressed, bool isLoading = false})` with `CircularProgressIndicator` when `isLoading`; disabled and shows spinner simultaneously; `Semantics(label: label)`
- [X] T025 [P] Implement `lib/shared/widgets/error_view.dart` â€” `ErrorView({required String message, VoidCallback? onRetry})` centered column: error icon, message `Text`, optional `TextButton('Retry', onPressed: onRetry)`
- [X] T026 [P] Implement `lib/shared/widgets/success_view.dart` â€” `SuccessView({required String title, required String referenceNumber, String? actionLabel, VoidCallback? onAction})` with check icon, title, reference number in `Chip`, optional action button
- [X] T027 [P] Implement `lib/shared/widgets/transaction_list_tile.dart` â€” `TransactionListTile({required Transaction tx})` showing type icon, counterparty name or type label, `DateFormat.yMMMd().format(tx.createdAt)`, `AmountDisplay(minorUnits: tx.amountMinorUnits, ...)` right-aligned, status `Chip`
- [X] T028 Implement real `AuthInterceptor` in `lib/core/api/api_client.dart` â€” `onRequest`: read access token via `SecureStorageService.readAccessToken()` and inject `Authorization: Bearer` header; `onError`: detect 401, use `Completer<void>` queue + `bool _isRefreshing` guard to prevent concurrent refresh storms, call `POST /v1/customer/auth/refresh`, update stored tokens, retry original request with `handler.resolve(await dio.fetch(err.requestOptions))`
- [X] T029 Implement `lib/core/notifications/push_notification_service.dart` â€” `static Future<void> initialize()` requesting permissions, subscribing to `FirebaseMessaging.instance.onTokenRefresh`, setting `onBackgroundMessage` top-level handler; `Future<String?> getToken()` returning `FirebaseMessaging.instance.getToken()`; `Stream<RemoteMessage> get foregroundMessages` from `FirebaseMessaging.onMessage`
- [X] T030 Implement `lib/core/notifications/deep_link_handler.dart` â€” `String? routeForMessage(RemoteMessage message)` mapping `data['type']`: `'transaction' â†’ '/home/transactions/${data['transaction_id']}'`, `'support_reply' â†’ '/support/${data['ticket_id']}'`, `'kyc_update' â†’ '/profile'`, `'alert' â†’ '/home/notifications'`; returns `null` for unknown types
- [X] T031 Implement `lib/core/router/app_router.dart` â€” full `GoRouter` with: `/` (SplashScreen, `redirect` checks auth state), `/login`, `/register`, `/register/otp`, `/register/profile`, `/register/pin`, `/forgot-pin`, `/forgot-pin/otp`, `/forgot-pin/new-pin`; `ShellRoute` `/shell` for 4-tab `BottomNavigationBar` (Home/Wallet/Services/Profile) containing: `/home`, `/home/notifications`, `/home/transactions`, `/home/transactions/:id`, `/wallet`, `/receive`, `/services`, `/services/:categoryId`, `/services/purchase/:providerId`, `/services/history`, `/profile`, `/profile/edit`, `/security`, `/security/change-pin`, `/security/devices`, `/support`, `/support/new`, `/support/:id`, `/disputes`, `/disputes/new/:txId`, `/disputes/:id`; full-screen routes: `/send`, `/add-money`, `/withdraw`
- [X] T032 Update `lib/main.dart` â€” `MaterialApp.router(routerConfig: ref.watch(routerProvider), theme: AppTheme.light)`; call `PushNotificationService.initialize()` in `ProviderScope` init; wire foreground message stream to `DeepLinkHandler`; call `FirebaseMessaging.instance.getInitialMessage()` on startup â€” if non-null pass to `DeepLinkHandler.routeForMessage` as GoRouter `initialLocation` (handles terminated-state notification deep links per FR-014, fixes U5); register `AppLifecycleObserver` (T162) via `WidgetsBinding.instance.addObserver`
- [X] T161 Create `lib/core/api/paginated_result.dart` â€” `class PaginatedResult<T>` with `data, currentPage, total, lastPage`; `bool get hasNextPage => currentPage < lastPage`; used by all repository interfaces returning paginated collections (fixes U3)
- [X] T162 Implement `lib/core/security/app_lifecycle_observer.dart` â€” `class AppLifecycleObserver with WidgetsBindingObserver`; record `DateTime? _backgroundedAt` on `AppLifecycleState.paused`; on `AppLifecycleState.resumed` compute elapsed; if elapsed > 5 minutes (default â€” configurable from backend config), call `ref.read(authProvider.notifier).requireReauth()` setting state to `unauthenticated` so GoRouter redirect routes to `/login`; implements FR-008
- [X] T163 Handle step-up authentication challenge in `lib/core/api/api_client.dart` `AuthInterceptor.onError` â€” on `403` with body `{error: 'step_up_required', challenge_type: 'pin'}`, store the pending `RequestOptions`, navigate to `/step-up-pin` overlay, collect PIN hash, call `POST /v1/customer/auth/step-up`, retry original request with `X-StepUp-Token` header; add `/step-up-pin` full-screen route to `lib/core/router/app_router.dart`; implements Principle 5 step-up requirement

---

## Phase 3: Authentication â€” US-CUST-001 to US-CUST-004 (Priority: P0)

**Goal**: Register, login, logout, and PIN reset all work end-to-end.

**Independent Test**: New phone registers and reaches home; registered user logs in; logout shows login screen with cleared storage; OTP resets PIN and old PIN rejected.

**Checkpoint**: All four auth flows pass against local backend.

- [X] T033 [P] [US1] Create `lib/modules/auth/domain/entities/customer.dart` â€” immutable `Customer` class: `id, phone, email?, firstName, lastName, avatarUrl?, kycStatus, locale, createdAt`; `KycStatus` enum
- [X] T034 [P] [US1] Create `lib/modules/auth/domain/entities/auth_token.dart` â€” `AuthToken` class: `accessToken, refreshToken, expiresAt`; `bool get isExpired => DateTime.now().isAfter(expiresAt)`
- [X] T035 [P] [US1] Create `lib/modules/auth/domain/repositories/auth_repository.dart` â€” abstract interface with: `register`, `verifyOtp`, `completeRegistration`, `initiateLogin`, `verifyLogin`, `logout`, `refreshToken`, `initiatePinReset`, `completePinReset`, `changePin`, `registerPushToken`, `unregisterPushToken`
- [X] T036 [US1] Create `lib/modules/auth/data/models/auth_response_dto.dart` â€” `AuthResponseDto.fromJson(Map<String,dynamic>)` mapping `access_token`, `refresh_token`, `expires_in`, `customer` sub-object; `toAuthToken()` and `toCustomer()` helpers
- [X] T037 [US1] Create `lib/modules/auth/data/models/register_request_dto.dart` â€” `RegisterRequestDto({phone, firstName, lastName}).toJson()`; `LoginRequestDto({phone, method}).toJson()`; `LoginVerifyDto({challengeToken, pinHash}).toJson()`
- [X] T038 [US1] Create `lib/modules/auth/data/repositories/auth_repository_impl.dart` â€” implements `AuthRepository`; `register` calls `POST /v1/customer/auth/register/initiate`; `verifyOtp` calls `POST /v1/customer/auth/register/verify`; `completeRegistration` calls `POST /v1/customer/auth/register/complete` and stores tokens via `SecureStorageService`; parse `422` as `ValidationException`, `401` as `AuthException`
- [X] T039 [US1] Create `lib/modules/auth/presentation/auth_providers.dart` â€” replace stub `AuthNotifier extends AsyncNotifier<AuthState>`; `AuthState` enum: `unauthenticated, authenticating, authenticated, registering, otpPending, profileSetup, pinCreation`; implement `register(phone)`, `verifyOtp(ref, code)`, `completeRegistration(firstName, lastName)`, `setPin(pin)` action methods
- [X] T040 [US1] Create `lib/modules/auth/presentation/screens/splash_screen.dart` â€” `ConsumerWidget` watching `authProvider`; on `authenticated` redirect to `/home`; on `unauthenticated` redirect to `/login`; shows centered app logo + `CircularProgressIndicator` while loading
- [X] T041 [US1] Create `lib/modules/auth/presentation/screens/register_screen.dart` â€” phone number `TextFormField` with E.164 validation, `PrimaryButton("Send OTP")`; shows inline validation per FR-001; on `AuthException` for already-registered phone, show "Phone already registered â€” Log In instead?" with link
- [X] T042 [US1] Create `lib/modules/auth/presentation/screens/otp_verification_screen.dart` â€” `PinPadWidget(length: 6)` for OTP; 60-second countdown `Timer` showing "Resend in :ss"; "Resend OTP" link enabled after expiry; `Semantics(label: 'One-time password entry')`
- [X] T043 [US1] Create `lib/modules/auth/presentation/screens/profile_setup_screen.dart` â€” `TextFormField` for first name and last name (both required, min 1 char); `PrimaryButton("Continue")`; navigates to `/register/pin`
- [X] T044 [US1] Create `lib/modules/auth/presentation/screens/pin_creation_screen.dart` â€” two-step `PinPadWidget`: enter PIN then confirm PIN; validates they match; calls `PinService.hashPin(pin)` then `AuthNotifier.setPin(hash)`; never logs PIN value; navigates to `/home` on success
- [X] T045 [P] [US2] Create `lib/modules/auth/data/models/login_request_dto.dart` â€” `LoginChallengeResponseDto.fromJson({challengeToken, method, expiresInSeconds})`
- [X] T046 [US2] Create `lib/modules/auth/presentation/screens/login_screen.dart` â€” phone `TextFormField`; `PrimaryButton("Log In")`; on challenge received show `PinPadWidget`; if biometric enabled (`BiometricService.isAvailable()`), show biometric prompt first and fall back to PIN on failure; lockout countdown display when `attemptsRemaining < 3` per FR-003
- [X] T047 [US2] Extend `lib/modules/auth/presentation/auth_providers.dart` â€” add `AuthNotifier.login(phone)` and `AuthNotifier.verifyLogin(pin)` methods; `login` calls `BiometricService.authenticate` if biometric enabled in `SecuritySettingsNotifier`; `verifyLogin` hashes PIN via `PinService.hashPin` before calling `AuthRepository.verifyLogin`; on success, stores tokens and calls `AuthRepository.registerPushToken(deviceId, fcmToken)`
- [X] T048 [US3] Implement `AuthRepository.logout()` in `lib/modules/auth/data/repositories/auth_repository_impl.dart` â€” calls `POST /v1/customer/auth/logout`; calls `AuthRepository.unregisterPushToken`; then calls `SecureStorageService.deleteAll()` regardless of network result; clears tokens
- [X] T049 [US3] Add logout `TextButton` in profile screen app bar (implemented fully in Phase 9 T107); in `auth_providers.dart` add `AuthNotifier.logout()` that calls `AuthRepository.logout()` then sets state to `unauthenticated` and calls `ref.invalidate` on all providers; `GoRouter` redirect then sends to `/login`
- [X] T050 [P] [US4] Create `lib/modules/auth/presentation/screens/forgot_pin_screen.dart` â€” phone number `TextFormField`; `PrimaryButton("Send OTP")`; calls `AuthRepository.initiatePinReset`; navigates to `/forgot-pin/otp`
- [X] T051 [US4] Create `lib/modules/auth/presentation/screens/pin_reset_screen.dart` â€” new PIN (`PinPadWidget`) + confirm; validates they match; calls `AuthRepository.completePinReset(otpReference, otpCode, newPinHash)`; navigates to `/login` with success `SnackBar`
- [X] T052 [US4] Implement `AuthRepository.initiatePinReset` and `completePinReset` in `lib/modules/auth/data/repositories/auth_repository_impl.dart` â€” `POST /v1/customer/auth/reset-pin/initiate` and `POST /v1/customer/auth/reset-pin/complete`

---

## Phase 4: Home, Transactions & Notifications â€” US-CUST-006 to US-CUST-009 (Priority: P0)

**Goal**: Home screen with live balance, recent transactions, shortcuts, and notification centre.

**Independent Test**: After login, home shows balance, 4 shortcut tiles, 5 transactions; tapping transaction shows detail; notification centre shows unread badge and deep-links.

**Checkpoint**: Home screen, transaction list, and notifications function independently.

- [X] T053 [P] [US6] Create `lib/modules/wallet/domain/entities/wallet.dart` â€” `Wallet` entity: `id, walletNumber, currencyCode, balanceMinorUnits, status, updatedAt`; `double get balanceMajor => balanceMinorUnits / 100.0`; `WalletStatus` enum
- [X] T054 [P] [US6] Create `lib/modules/wallet/data/models/wallet_dto.dart` â€” `WalletDto.fromJson(Map<String,dynamic>)`; `Wallet toEntity()`
- [X] T055 [P] [US6] Create `lib/modules/wallet/domain/repositories/wallet_repository.dart` â€” abstract: `Future<Wallet> getMyWallet()`
- [X] T056 [US6] Create `lib/modules/wallet/data/repositories/wallet_repository_impl.dart` â€” `GET /v1/customer/wallets/me` â†’ parses `WalletDto` â†’ returns `Wallet`
- [X] T057 [US6] Create `lib/modules/wallet/presentation/wallet_providers.dart` â€” `@riverpod Future<Wallet> wallet(WalletRef ref)` auto-disposes; invalidated after any financial transaction completes
- [X] T164 [P] [US6] Create `lib/modules/wallet/domain/entities/wallet_limits.dart` â€” `WalletLimits({required int minFundMinorUnits, required int maxFundMinorUnits, required int maxDailyTransferMinorUnits, required int minWithdrawalMinorUnits, required int dailyUsedMinorUnits})`; add `getWalletLimits(): Future<WalletLimits>` to `wallet_repository.dart` interface; implement via `GET /v1/customer/wallets/me/limits` in `WalletRepositoryImpl`; expose `@riverpod Future<WalletLimits> walletLimits(...)` in `wallet_providers.dart`; update T083, T091, T099, T124 to `ref.watch(walletLimitsProvider)` for limit values instead of hard-coding â€” implements FR-021 and Principle 3
- [X] T058 [P] [US7] Create `lib/modules/transactions/domain/entities/transaction.dart` â€” `Transaction` entity with `TransactionType`, `TransactionStatus` enums; `double get amountMajor`, `double get feeMajor`, `double get netMajor`
- [X] T059 [P] [US7] Create `lib/modules/transactions/data/models/transaction_dto.dart` â€” `TransactionDto.fromJson()`; `Transaction toEntity()`
- [X] T060 [P] [US7] Create `lib/modules/transactions/domain/repositories/transaction_repository.dart` â€” abstract: `Future<PaginatedResult<Transaction>> getTransactions({int page, int perPage, String? type, String? status})`, `Future<Transaction> getTransaction(String id)`
- [X] T061 [US7] Create `lib/modules/transactions/data/repositories/transaction_repository_impl.dart` â€” `GET /v1/customer/transactions?page=&per_page=&type=&status=`; `GET /v1/customer/transactions/{id}`
- [X] T062 [US7] Create `lib/modules/transactions/presentation/transaction_providers.dart` â€” `@riverpod Future<PaginatedResult<Transaction>> transactionList(TransactionListRef ref, int page)` and `@riverpod Future<Transaction> transactionDetail(TransactionDetailRef ref, String id)`
- [X] T063 [US7] Create `lib/modules/transactions/presentation/screens/transaction_list_screen.dart` â€” infinite-scroll `ListView` using `transactionListProvider`; `TransactionListTile` rows; filter bar with `DropdownButton` for type and status; `ShimmerList` on first load; `ErrorView` with retry; pull-to-refresh
- [X] T064 [US7] Create `lib/modules/transactions/presentation/screens/transaction_detail_screen.dart` â€” full record: reference number, type badge, amount (`AmountDisplay`), fee, net debit, counterparty name + wallet number, status chip, `createdAt` + `completedAt`; screenshot guard (financial data); "Dispute this transaction" `OutlinedButton` (wired in Phase 11 T138)
- [X] T065 [P] [US8] Create `lib/modules/home/domain/entities/home_summary.dart` â€” `HomeSummary({required Wallet wallet, required List<Transaction> recentTransactions, required int unreadNotificationCount})`
- [X] T066 [US8] Create `lib/modules/home/presentation/home_providers.dart` â€” `@riverpod Future<HomeSummary> homeSummary(HomeSummaryRef ref)` using `Future.wait([ref.watch(walletProvider.future), ref.watch(transactionListProvider(1).future), ref.watch(unreadCountProvider.future)])`
- [X] T067 [US8] Create `lib/modules/home/presentation/screens/home_screen.dart` â€” balance card (`AmountDisplay` + low-balance indicator when `balanceMinorUnits < threshold`, pull-to-refresh); 4 shortcut tiles (Send Money â†’ `/send`, Add Money â†’ `/add-money`, Pay Bills â†’ `/services`, Receive Money â†’ `/receive`); recent transactions section (last 5 `TransactionListTile`s with "View All" link to `/home/transactions`); `OfflineBanner`; screenshot guard on balance card area
- [X] T068 [P] [US9] Create `lib/modules/notifications/domain/entities/notification.dart` â€” `AppNotification` entity: `id, type, title, body, isRead, metadata, createdAt`; `NotificationType` enum
- [X] T069 [P] [US9] Create `lib/modules/notifications/data/models/notification_dto.dart` â€” `NotificationDto.fromJson()`; `AppNotification toEntity()`
- [X] T070 [P] [US9] Create `lib/modules/notifications/domain/repositories/notification_repository.dart` â€” abstract: `Future<PaginatedResult<AppNotification>> getNotifications({bool? unreadOnly, int page})`, `Future<void> markRead(List<String> ids)`, `Future<void> markAllRead()`
- [X] T071 [US9] Create `lib/modules/notifications/data/repositories/notification_repository_impl.dart` â€” `GET /v1/customer/notifications`; `PATCH /v1/customer/notifications/read` with `{ids: [...]}` or `{}` for mark-all
- [X] T072 [US9] Create `lib/modules/notifications/presentation/notification_providers.dart` â€” `@riverpod Future<PaginatedResult<AppNotification>> notifications(...)` with page; `@riverpod Future<int> unreadCount(...)` from `meta.unread_count`
- [X] T073 [US9] Create `lib/modules/notifications/presentation/screens/notifications_screen.dart` â€” paginated `ListView` of `AppNotification` tiles; unread items have distinct background; `Text(unreadCount)` badge in app bar; "Mark all read" `IconButton`; tap â†’ call `DeepLinkHandler.routeForMessage` and navigate; `ShimmerList` on first load
- [X] T074 [US9] Wire foreground push in `lib/core/notifications/push_notification_service.dart` â€” subscribe to `FirebaseMessaging.onMessage` stream; for each message, show `flutter_local_notifications` local notification; call `ref.invalidate(notificationsProvider)` and `ref.invalidate(homeSummaryProvider)` to refresh badge count

---

## Phase 5: Wallet Detail & Receive Money â€” US-CUST-010, US-CUST-013 (Priority: P0)

**Goal**: Wallet detail with copy/share; QR code for receiving money.

**Independent Test**: Wallet detail shows wallet number; copy copies to clipboard; Receive screen QR encodes wallet number; scanning QR on another device populates sender's recipient field.

- [X] T075 [P] [US10] Create `lib/modules/wallet/presentation/screens/wallet_detail_screen.dart` â€” displays wallet number, account name, currency, balance (`AmountDisplay`), status badge; `IconButton` copy calls `Clipboard.setData(ClipboardData(text: wallet.walletNumber))`; "Share" via `share_plus`; screenshot guard; "Receive Money" button navigates to `/receive`
- [X] T076 [US10] Register FCM push token post-login in `lib/modules/auth/data/repositories/auth_repository_impl.dart` â€” after tokens stored in `completeRegistration` and `verifyLogin`, call `POST /v1/customer/devices/{deviceId}/push-token` with `{push_token, platform}`; on logout call `DELETE /v1/customer/devices/{deviceId}/push-token`
- [X] T077 [P] [US13] Create `lib/modules/wallet/presentation/screens/receive_money_screen.dart` â€” `QrImageView(data: wallet.walletNumber, size: 240, version: QrVersions.auto)` from `qr_flutter`; `SelectableText(wallet.walletNumber)` below with copy button; "Share QR" button uses `share_plus` to share image; screenshot guard

---

## Phase 6: Add Money â€” US-CUST-011 (Priority: P0)

**Goal**: Customer funds wallet via bank transfer or agent cash-in with idempotency protection.

**Independent Test**: Add Money shows confirmation (amount, fee, net), PIN authorizes, success screen shows reference, wallet balance refreshes.

- [X] T078 [P] [US11] Create `lib/modules/funding/domain/entities/funding_result.dart` â€” `FundingResult` entity: `transactionId, referenceNumber, status, amountMinorUnits, currencyCode, redirectUrl?, createdAt`; `FundingResultStatus` enum
- [X] T079 [P] [US11] Create `lib/modules/funding/data/models/fund_request_dto.dart` â€” `FundRequestDto({required String idempotencyKey, required FundingMethod method, required int amountMinorUnits, required String currencyCode}).toJson()`
- [X] T080 [P] [US11] Create `lib/modules/funding/domain/repositories/funding_repository.dart` â€” abstract: `Future<FundingResult> initiateFunding(FundRequestDto dto)`
- [X] T081 [US11] Create `lib/modules/funding/data/repositories/funding_repository_impl.dart` â€” `POST /v1/customer/funding` with `Idempotency-Key` header; handle `409` idempotent replay by parsing `{idempotent_replayed: true, data: {...}}` and returning as `FundingResult`
- [X] T082 [US11] Create `lib/modules/funding/presentation/funding_providers.dart` â€” `@riverpod class FundingNotifier extends AsyncNotifier<FundingResult?>` with `Future<void> initiate(FundRequestDto dto)` method
- [X] T083 [US11] Create `lib/modules/funding/presentation/screens/add_money_screen.dart` â€” amount `TextField` with numeric keyboard; method `SegmentedButton` (Bank Transfer / Agent Cash-In); validates amount within limits (FR-021); `PrimaryButton("Continue")`; screenshot guard; navigates to confirmation with amount + method
- [X] T084 [US11] Create `lib/modules/funding/presentation/screens/funding_method_screen.dart` â€” confirmation summary: method label, amount (`AmountDisplay`), fee (`AmountDisplay`), net debit (`AmountDisplay`); `PinPadWidget` authorization; generates `idempotencyKey = const Uuid().v4()` just before submit; calls `FundingNotifier.initiate(dto)`; shows `CircularProgressIndicator` while submitting
- [X] T085 [US11] Create `lib/modules/funding/presentation/screens/funding_result_screen.dart` â€” on success: `SuccessView(referenceNumber: result.referenceNumber)` with "Done" that navigates to `/home` and calls `ref.invalidate(walletProvider)`; on failure: `ErrorView` with retry and "Choose different method" button

---

## Phase 7: Send Money â€” US-CUST-012 (Priority: P0)

**Goal**: Customer sends money via manual entry or QR scan with preview and PIN authorization.

**Independent Test**: QR scan or manual wallet number resolves recipient name; confirmation shows correct fee from preview; PIN authorizes; sender balance decreases; transaction in history.

- [X] T086 [P] [US12] Create `lib/modules/transfer/domain/entities/transfer_result.dart` â€” `TransferResult` entity: `transactionId, referenceNumber, status, amountMinorUnits, feeMinorUnits, currencyCode, recipientName, createdAt`; `WalletLookupResult({walletNumber, ownerName, currencyCode})`; `TransferPreview({amountMinorUnits, feeMinorUnits, netDebitMinorUnits, currencyCode, recipientName})`; `TransferStatus` enum
- [X] T087 [P] [US12] Create `lib/modules/transfer/data/models/transfer_request_dto.dart` â€” `TransferRequestDto({idempotencyKey, recipientWalletNumber, amountMinorUnits, currencyCode, note?}).toJson()`
- [X] T088 [P] [US12] Create `lib/modules/transfer/domain/repositories/transfer_repository.dart` â€” abstract: `Future<WalletLookupResult> lookupWallet(String walletNumber)`, `Future<TransferPreview> previewTransfer(String recipientWalletNumber, int amountMinorUnits, String currencyCode)`, `Future<TransferResult> submitTransfer(TransferRequestDto dto)`
- [X] T089 [US12] Create `lib/modules/transfer/data/repositories/transfer_repository_impl.dart` â€” `GET /v1/customer/wallets/lookup?wallet_number=`; `POST /v1/customer/transfers/preview`; `POST /v1/customer/transfers` with `Idempotency-Key`; handle `409` idempotent replay; handle `422` for insufficient balance (FR-021)
- [X] T090 [US12] Create `lib/modules/transfer/presentation/transfer_providers.dart` â€” `@riverpod class TransferNotifier extends AsyncNotifier<TransferResult?>` with `lookup(walletNumber)`, `preview(amount)`, `submit(dto)` methods; `@riverpod AsyncValue<WalletLookupResult?> recipientLookup(...)` 
- [X] T091 [US12] Create `lib/modules/transfer/presentation/screens/send_money_screen.dart` â€” wallet number `TextField` with `IconButton` launching `MobileScannerController` QR scan (overlays camera in a `BottomSheet`); recipient name shown below field after successful lookup; amount entry; daily-limit remaining display (FR-021); insufficient-funds check; "Review" `PrimaryButton`; screenshot guard
- [X] T092 [US12] Create `lib/modules/transfer/presentation/screens/transfer_confirmation_screen.dart` â€” shows recipient name, amount (`AmountDisplay`), fee (`AmountDisplay`), net debit; `PinPadWidget` authorization; generates `idempotencyKey = const Uuid().v4()` before submit; calls `TransferNotifier.submit`; `PrimaryButton("Confirm Transfer")`
- [X] T093 [US12] Create `lib/modules/transfer/presentation/screens/transfer_result_screen.dart` â€” success: `SuccessView` with reference + new balance note + "Done" that calls `ref.invalidate(walletProvider)` and `ref.invalidate(transactionListProvider(1))`; failure: `ErrorView` with retry

---

## Phase 8: Withdraw Money â€” US-CUST-014 (Priority: P0)

**Goal**: Customer withdraws to agent cash-out with pickup code.

**Independent Test**: Withdrawal confirmation shows fee, net, settlement time; PIN authorized; wallet balance debits; success screen shows pickup code prominently.

- [X] T094 [P] [US14] Create `lib/modules/withdrawal/domain/entities/withdrawal_result.dart` â€” `WithdrawalResult` entity: `transactionId, referenceNumber, status, pickupCode?, amountMinorUnits, feeMinorUnits, currencyCode, agentName, createdAt`; `WithdrawalPreview({amountMinorUnits, feeMinorUnits, netDebitMinorUnits, currencyCode, agentName})`; `WithdrawalStatus` enum
- [X] T095 [P] [US14] Create `lib/modules/withdrawal/data/models/withdraw_request_dto.dart` â€” `WithdrawRequestDto({idempotencyKey, agentWalletNumber, amountMinorUnits, currencyCode}).toJson()`
- [X] T096 [P] [US14] Create `lib/modules/withdrawal/domain/repositories/withdrawal_repository.dart` â€” abstract: `Future<WithdrawalPreview> previewWithdrawal(String agentWalletNumber, int amountMinorUnits, String currencyCode)`, `Future<WithdrawalResult> submitWithdrawal(WithdrawRequestDto dto)`
- [X] T097 [US14] Create `lib/modules/withdrawal/data/repositories/withdrawal_repository_impl.dart` â€” `POST /v1/customer/withdrawals/preview`; `POST /v1/customer/withdrawals` with `Idempotency-Key`; handle `409` idempotent replay; handle minimum-balance `422`
- [X] T098 [US14] Create `lib/modules/withdrawal/presentation/withdrawal_providers.dart` â€” `@riverpod class WithdrawalNotifier extends AsyncNotifier<WithdrawalResult?>` with `preview(agentWalletNumber, amount)` and `submit(dto)` methods
- [X] T099 [US14] Create `lib/modules/withdrawal/presentation/screens/withdraw_screen.dart` â€” agent wallet number `TextField` (with QR scan option via `mobile_scanner`); amount entry; minimum-balance validation (FR-021); "Review" `PrimaryButton`; screenshot guard
- [X] T100 [US14] Create `lib/modules/withdrawal/presentation/screens/withdrawal_confirmation_screen.dart` â€” agent name, amount (`AmountDisplay`), fee, net proceeds, estimated settlement time string; `PinPadWidget` authorization; `idempotencyKey = const Uuid().v4()` before submit
- [X] T101 [US14] Create `lib/modules/withdrawal/presentation/screens/withdrawal_result_screen.dart` â€” success: prominent `Text(result.pickupCode)` in large styled `Container`, `SuccessView` reference, agent name, "Done" invalidates `walletProvider`; failure: `ErrorView` with retry

---

## Phase 9: Profile & Security â€” US-CUST-005, US-CUST-018 to US-CUST-020 (Priority: P0)

**Goal**: Profile view/edit, security settings with PIN change and biometric toggle, and device management.

**Independent Test**: Profile shows all fields accurately; edit saves and reflects immediately; Change PIN invalidates sessions; biometric toggle persists; device list shows current device with revoke.

- [X] T102 [P] [US18] Create `lib/modules/profile/domain/entities/profile.dart` â€” `Profile` entity: `id, phone, email?, firstName, lastName, avatarUrl?, kycStatus, locale, notifications(ProfileNotificationPreferences)`
- [X] T103 [P] [US18] Create `lib/modules/profile/data/models/profile_dto.dart` â€” `ProfileDto.fromJson()`; `Profile toEntity()`
- [X] T104 [P] [US18] Create `lib/modules/profile/domain/repositories/profile_repository.dart` â€” abstract: `Future<Profile> getProfile()`, `Future<Profile> updateProfile(Map<String,dynamic> updates)`
- [X] T105 [US18] Create `lib/modules/profile/data/repositories/profile_repository_impl.dart` â€” `GET /v1/customer/profile`; `PATCH /v1/customer/profile`
- [X] T106 [US18] Create `lib/modules/profile/presentation/profile_providers.dart` â€” `@riverpod Future<Profile> profile(ProfileRef ref)`; `@riverpod class ProfileNotifier extends AsyncNotifier<Profile?>` with `update(Map<String,dynamic> fields)` method
- [X] T107 [US18] Create `lib/modules/profile/presentation/screens/profile_screen.dart` â€” displays full name, partially-masked phone (`+254***${last4}`), email (or "Not set"), KYC badge (approved=green check / pending=amber / none=link to KYC), account `createdAt` date; screenshot guard; edit `IconButton` â†’ `/profile/edit`; logout `TextButton` â†’ calls `AuthNotifier.logout()` with confirmation `AlertDialog`
- [X] T108 [US19] Create `lib/modules/profile/presentation/screens/edit_profile_screen.dart` â€” `TextFormField` for `firstName`, `lastName`, `email` pre-filled; phone `TextFormField` with `readOnly: true` and lock `suffixIcon` (FR-027); `PrimaryButton("Save")`; calls `ProfileNotifier.update` via `PATCH`; on success shows `SnackBar("Profile updated")`; on email-conflict `422` shows inline field error
- [X] T109 [US20] Create `lib/modules/security_settings/presentation/security_providers.dart` â€” `@riverpod class SecuritySettingsNotifier extends Notifier<SecuritySettings>` where `SecuritySettings({bool biometricEnabled})`; persistence via `SharedPreferences` key `biometric_enabled`; `void toggleBiometric(bool enabled)` method
- [X] T110 [US20] Create `lib/modules/security_settings/presentation/screens/security_screen.dart` â€” `ListTile("Change PIN")` â†’ `/security/change-pin`; `SwitchListTile("Biometric Login")` reading `SecuritySettingsNotifier.biometricEnabled` and calling `toggleBiometric`; `ListTile("My Devices")` â†’ `/security/devices`; grays out biometric toggle when `BiometricService.isAvailable()` returns false
- [X] T111 [US20] Create `lib/modules/security_settings/presentation/screens/change_pin_screen.dart` â€” current PIN (`PinPadWidget`) then new PIN + confirm; calls `POST /v1/customer/auth/change-pin` with `{current_pin_hash, new_pin_hash}`; on `204` shows `SnackBar("PIN changed")` and `ref.invalidate(authProvider)`; on `401` shows "Current PIN incorrect" inline error per FR-028
- [X] T112 [P] [US5] Confirm `lib/modules/security_settings/presentation/screens/security_screen.dart` "My Devices" tile (added in T110) routes to `/security/devices` â†’ existing `DeviceListScreen`; wire `Semantics(label: 'My Devices')` on the tile
- [X] T113 [US5] Update `lib/modules/device/presentation/screens/device_list_screen.dart` â€” identify current device by matching `deviceId` from `SecureStorageService`; show "(This device)" label; on revoke of current device, show `AlertDialog("This will log you out. Continue?")` before `DELETE /v1/customer/devices/{id}`; on success call `AuthNotifier.logout()`

---

## Phase 10: Services â€” US-CUST-015 to US-CUST-017 (Priority: P1)

**Goal**: Service catalog, purchase flow with dynamic fields, and service payment history.

**Independent Test**: Services tab shows categories; selecting Airtime shows providers; purchasing airtime renders phone field, shows confirmation with summary, PIN authorizes, success shows confirmation token; history entry appears.

- [X] T114 [P] [US15] Create `lib/modules/services/domain/entities/service_category.dart` â€” `ServiceCategory`: `id, name, iconUrl, description, providerCount`
- [X] T115 [P] [US15] Create `lib/modules/services/domain/entities/service_provider.dart` â€” `ServiceProvider`: `id, categoryId, name, logoUrl, description, requiredFields, feeMinorUnits, currencyCode, isActive`; `ServiceField({key, label, type, required, hint?, options?})`; `ServiceFieldType` enum: `text, numeric, phone, select`
- [X] T116 [P] [US15] Create `lib/modules/services/domain/repositories/services_repository.dart` â€” abstract: `getCategories()`, `getProviders(String categoryId)`, `previewServicePayment(String providerId, int amountMinorUnits, Map<String,String> fieldValues)`, `submitServicePayment(ServicePurchaseRequestDto dto)`
- [X] T117 [P] [US15] Create `lib/modules/services/data/models/service_dto.dart` â€” `ServiceCategoryDto.fromJson()`, `ServiceProviderDto.fromJson()` (with `ServiceField` list parsing), `ServicePaymentDto.fromJson()`
- [X] T118 [US15] Create `lib/modules/services/data/repositories/services_repository_impl.dart` â€” `GET /v1/customer/services/categories`; `GET /v1/customer/services/categories/{categoryId}/providers`; `POST /v1/customer/payments/service/preview`; `POST /v1/customer/payments/service` with `Idempotency-Key`; `GET /v1/customer/payments/service` (history)
- [X] T119 [US15] Create `lib/modules/services/presentation/services_providers.dart` â€” `@riverpod Future<List<ServiceCategory>> serviceCategories(...)`, `@riverpod Future<List<ServiceProvider>> serviceProviders(String categoryId)`, `@riverpod Future<PaginatedResult<ServicePayment>> servicePaymentHistory(int page)`
- [X] T120 [US15] Create `lib/modules/services/presentation/screens/services_home_screen.dart` â€” `GridView.count(crossAxisCount: 2)` of `ServiceCategory` cards with icon + name; client-side `TextField` search bar filtering by name; `ShimmerList` on load; `ErrorView` on error
- [X] T121 [US15] Create `lib/modules/services/presentation/screens/service_providers_screen.dart` â€” `ListView` of `ServiceProvider` tiles with `CachedNetworkImage(imageUrl: logoUrl)` and name; inactive providers grayed out; tap active provider â†’ `/services/purchase/{id}`
- [X] T122 [P] [US16] Create `lib/modules/services/domain/entities/service_payment.dart` â€” `ServicePayment` entity: `id, referenceNumber, providerId, providerName, amountMinorUnits, feeMinorUnits, currencyCode, status, fieldValues, createdAt`; `ServicePaymentStatus` enum
- [X] T123 [P] [US16] Add `ServicePurchaseRequestDto({idempotencyKey, providerId, amountMinorUnits, fieldValues: Map<String,String>}).toJson()` to `lib/modules/services/data/models/service_dto.dart`
- [X] T124 [US16] Create `lib/modules/services/presentation/screens/service_purchase_screen.dart` â€” dynamically build form from `ServiceProvider.requiredFields`: `ServiceFieldType.phone` â†’ `keyboardType: TextInputType.phone`; `ServiceFieldType.numeric` â†’ `TextInputType.number`; `ServiceFieldType.select` â†’ `DropdownButton`; amount `TextField`; client-side required field validation; "Review" `PrimaryButton`
- [X] T125 [US16] Create `lib/modules/services/presentation/screens/service_confirmation_screen.dart` â€” calls `ServicesRepository.previewServicePayment` on mount; displays: provider name, summary string from preview, amount, fee, net; `PinPadWidget` authorization; `idempotencyKey = const Uuid().v4()` before submit; calls `ServicesRepository.submitServicePayment`
- [X] T126 [US16] Create `lib/modules/services/presentation/screens/service_result_screen.dart` â€” success: `SuccessView` with `Text("Token: ${result.fieldValues['token'] ?? result.referenceNumber}")` styled prominently + reference + "Done" invalidating `walletProvider` + `servicePaymentHistoryProvider(1)`; failure: `ErrorView` with retry
- [X] T127 [US17] Create `lib/modules/services/presentation/screens/service_history_screen.dart` â€” infinite-scroll `ListView` from `servicePaymentHistoryProvider`; each tile: provider name, amount, date, status chip; tap opens `BottomSheet` detail: confirmation token, reference, fee breakdown, status; date + type filter `DropdownButton`s; `ShimmerList` on first load

---

## Phase 11: Support & Disputes â€” US-CUST-021 to US-CUST-023 (Priority: P1)

**Goal**: General support inquiry creation/tracking and transaction dispute raise/track.

**Independent Test**: Support inquiry submitted â†’ ticket reference shown â†’ appears in list; dispute raised from transaction detail â†’ reference returned; dispute list shows status.

- [X] T128 [P] [US21] Create `lib/modules/support/domain/entities/support_ticket.dart` â€” `SupportTicket`, `TicketMessage`, `TicketCategory` enum, `TicketStatus` enum, `TicketMessageSender` enum
- [X] T129 [P] [US21] Create `lib/modules/support/data/models/support_dto.dart` â€” `SupportTicketDto.fromJson()`, `TicketMessageDto.fromJson()`; `toEntity()` methods for both
- [X] T130 [P] [US21] Create `lib/modules/support/domain/repositories/support_repository.dart` â€” abstract: `getTickets(int page)`, `getTicket(String id)`, `createTicket({category, body, List<File> attachments})`, `replyToTicket(String id, {body, List<File> attachments})`, `getDisputes(int page)`, `raiseDispute({transactionId, reason, description})`, `getDispute(String id)`
- [X] T131 [US21] Create `lib/modules/support/data/repositories/support_repository_impl.dart` â€” `GET/POST /v1/customer/support/tickets`; `GET /v1/customer/support/tickets/{id}`; `POST /v1/customer/support/tickets/{id}/messages`; `GET/POST /v1/customer/support/disputes`; `GET /v1/customer/support/disputes/{id}`; use `FormData` + `MultipartFile.fromFile` for attachment uploads
- [X] T132 [US21] Create `lib/modules/support/presentation/support_providers.dart` â€” `ticketListProvider(int page)`, `ticketDetailProvider(String id)`, `disputeListProvider(int page)`, `disputeDetailProvider(String id)`
- [X] T133 [US21] Create `lib/modules/support/presentation/screens/support_home_screen.dart` â€” `ListView` of open tickets (reference, subject, status chip); `FloatingActionButton` â†’ `/support/new`; empty state "No support tickets yet"; `ShimmerList` on load
- [X] T134 [US21] Create `lib/modules/support/presentation/screens/new_inquiry_screen.dart` â€” `DropdownButton` for category; `TextFormField` description (min 20 chars, max 2000); `IconButton` attachment via `image_picker.pickMultiImage()` (max 3); attachment preview chips; `PrimaryButton("Submit")`; multipart `POST`; on `201` shows `SuccessView(referenceNumber: ticket.referenceNumber)` with "View Ticket" button
- [X] T135 [US21] Create `lib/modules/support/presentation/screens/ticket_detail_screen.dart` â€” scrollable `ListView` of `TicketMessage` bubbles (customer right-aligned / support left-aligned, distinct background colors); reply `TextField` pinned at bottom with send `IconButton` + attachment button; auto-scroll to latest on load; `ref.invalidate(ticketDetailProvider(id))` on push notification `support_reply` type
- [X] T136 [P] [US22] Create `lib/modules/support/domain/entities/dispute.dart` â€” `Dispute` entity: `id, referenceNumber, transactionId, transactionReference, reason, status, resolutionNotes?, createdAt, updatedAt`; `DisputeReason` enum, `DisputeStatus` enum
- [X] T137 [US22] Create `lib/modules/support/presentation/screens/raise_dispute_screen.dart` â€” pre-fills `transactionId` and `transactionReference` from `extra` route params; `DropdownButton<DisputeReason>` for reason; `TextFormField` description (min 20 chars); `PrimaryButton("Raise Dispute")`; calls `SupportRepository.raiseDispute`; on `201` shows `SuccessView(referenceNumber: dispute.referenceNumber)`; calls `ref.invalidate(transactionDetailProvider(txId))`
- [X] T138 [US22] Update `lib/modules/transactions/presentation/screens/transaction_detail_screen.dart` â€” add "Dispute this transaction" `OutlinedButton` at bottom; before navigating to `/disputes/new/:txId`, check if an open dispute already exists by watching `disputeListProvider` for matching `transactionId`; if found, show `AlertDialog("Dispute already open: ${existingDispute.referenceNumber}")` instead of navigating (per US-CUST-022 scenario 3)
- [X] T139 [US23] Create `lib/modules/support/presentation/screens/dispute_list_screen.dart` â€” paginated `ListView` of `Dispute` tiles: reference number, linked transaction reference (truncated), status chip (submitted=grey, under_review=amber, resolved=green, rejected=red), date raised; tap â†’ `/disputes/{id}`; `ShimmerList` on load
- [X] T140 [US23] Create `lib/modules/support/presentation/screens/dispute_detail_screen.dart` â€” reference, linked transaction reference, reason label, status badge, `createdAt`; when `status == resolved || rejected` show resolution section with heading "Resolution" and `resolutionNotes` text

---

## Phase 12: Polish & Cross-Cutting Concerns

**Purpose**: Accessibility, screenshot guard audit, offline handling, logging hygiene, widget tests, integration tests, `dart analyze` gate.

- [X] T141 Add `Semantics(label: '...')` wrapper to all interactive elements (buttons, form fields, icons, tiles) on all screens per FR-035; verify with TalkBack/VoiceOver; ensure minimum touch target 48Ã—48dp
- [X] T142 Audit WCAG 2.1 AA (4.5:1 contrast ratio) for all text/background pairs across `design_tokens.dart`; fix any failing combinations per FR-035; run `flutter_test` accessibility checks
- [X] T143 [P] Add `ShimmerList` loading states to all list screens missing them: `transaction_list_screen.dart`, `notifications_screen.dart`, `services_home_screen.dart`, `service_providers_screen.dart`, `service_history_screen.dart`, `support_home_screen.dart`, `dispute_list_screen.dart`
- [X] T144 [P] Verify `OfflineBanner` renders on home, wallet, transactions, notifications, services, profile, support screens; add to any screen currently missing it; verify financial write screens (`add_money_screen`, `send_money_screen`, `withdraw_screen`, `service_purchase_screen`) show blocking `AlertDialog("You are offline. Please reconnect to perform transactions.")` when `isOfflineProvider == true` before allowing form submission
- [X] T145 [P] Audit screenshot guard â€” confirm `ScreenshotGuard.enable()` in `initState` and `ScreenshotGuard.disable()` in `dispose` for: `wallet_detail_screen.dart`, `receive_money_screen.dart`, `transaction_detail_screen.dart`, `transfer_confirmation_screen.dart`, `withdrawal_confirmation_screen.dart`, `add_money_screen.dart`, `funding_method_screen.dart`, `profile_screen.dart`, `change_pin_screen.dart`; add any missing calls per FR-034
- [X] T146 [P] Audit all providers, repository implementations, and services for `print`, `log`, `debugPrint`, or logger calls containing PIN, OTP, token, password, or hash values; remove all violations per FR-036
- [X] T147 [P] Populate `test/fixtures/` JSON files with realistic test data matching `contracts/customer-api.md` shapes: `wallet.json`, `transactions.json`, `notifications.json`, `profile.json`, `service_categories.json`, `service_providers.json`
- [X] T148 Write widget tests in `test/modules/auth/` â€” `register_screen_test.dart`: form validation, inline error display, loading state; `login_screen_test.dart`: PIN lockout countdown display, biometric prompt logic; `otp_verification_screen_test.dart`: 60s countdown, resend link appearance
- [X] T149 Write widget tests â€” `test/modules/home/home_screen_test.dart`: balance display from provider, 4 shortcut tiles present, offline banner; `test/modules/transactions/transaction_list_screen_test.dart`: ShimmerList on loading, empty state, TransactionListTile render
- [X] T150 Write widget tests â€” `test/modules/funding/funding_method_screen_test.dart`: correct summary amounts; `test/modules/transfer/transfer_confirmation_screen_test.dart`: fee/net accuracy; `test/modules/withdrawal/withdrawal_result_screen_test.dart`: pickup code prominence
- [X] T151 Write widget tests â€” `test/modules/services/service_purchase_screen_test.dart`: dynamic field types render correctly (phone/numeric/select); `test/modules/support/new_inquiry_screen_test.dart`: description min-length validation, attachment count limit
- [X] T152 Implement `integration_test/auth_flow_test.dart` â€” SC-01: new phone registers end-to-end hitting local Docker backend and home screen reached within 3 minutes; SC-02: same credentials login successfully and home screen appears within 3 seconds; assert timing with `Stopwatch` per quickstart.md timing thresholds
- [X] T153 Implement `integration_test/wallet_test.dart` â€” SC-03: balance card visible and formatted on home; SC-04: transaction list loads, tap shows detail with reference number
- [X] T154 Implement `integration_test/financial_flow_test.dart` â€” SC-05: add money confirmation + result screen shows reference; SC-06: send money to known wallet with QR scan; SC-07: withdrawal with agent wallet number and pickup code displayed
- [X] T155 Implement `integration_test/services_test.dart` â€” SC-08: airtime purchase from category browse through confirmation PIN through result screen with token
- [X] T156 Implement `integration_test/security_test.dart` â€” SC-10: PIN change flow completes; SC-11: offline detected, banner shows, financial submit blocked
- [X] T157 Run `flutter analyze --fatal-infos` in `wallet-ecosystem/apps/customer/` and fix all issues until output is "No issues found! (0 issues)"
- [ ] T158 Run quickstart.md SC-01 through SC-12 validation scenarios against local Docker stack; confirm all pass; document any new troubleshooting findings in `quickstart.md`
- [X] T165 Write `test/modules/financial/double_submit_test.dart` â€” unit test concurrency safety for funding, transfer, and withdrawal flows: (1) call the financial repository method twice simultaneously with the same idempotency key using `Future.wait([submit(), submit()])`; assert only one transaction entity is created and the second resolves idempotently with `409` replay; (2) mock the API to return `409 {idempotent_replayed: true, data: {...}}` and assert the repository returns the same result object without error â€” implements Principle 4 concurrency requirement (C4)

---

## Dependencies & Execution Order

### Phase Dependencies

- **Phase 1 (Setup)**: No dependencies â€” start immediately
- **Phase 2 (Foundational)**: Requires Phase 1 T001â€“T002 (packages resolved) â€” **blocks all story phases**
- **Phases 3â€“11 (User Stories)**: Require Phase 2 completion; can proceed in priority order
- **Phase 12 (Polish)**: Requires Phases 3â€“11 completion

### User Story Dependencies

| Phase | Stories | Depends On |
|-------|---------|------------|
| 3 | US-CUST-001â€“004 (Auth) | Phase 2 |
| 4 | US-CUST-006â€“009 (Home) | Phase 3 (auth tokens) |
| 5 | US-CUST-010, 013 (Wallet/QR) | Phase 4 (`Wallet` entity) |
| 6 | US-CUST-011 (Add Money) | Phase 4 (wallet balance refresh) |
| 7 | US-CUST-012 (Send Money) | Phase 4 (wallet balance), Phase 5 (wallet lookup) |
| 8 | US-CUST-014 (Withdraw) | Phase 4 (wallet balance) |
| 9 | US-CUST-005, 018â€“020 (Profile/Security) | Phase 3 (auth repository) |
| 10 | US-CUST-015â€“017 (Services) | Phase 4 (wallet balance for payment) |
| 11 | US-CUST-021â€“023 (Support) | Phase 7 (transaction detail for dispute link T138) |

### Parallel Opportunities Within Phases

```
# Phase 2 (all safe to run concurrently after T002):
T013 design_tokens    T021 amount_display    T028 AuthInterceptor
T014 app_theme        T022 pin_pad_widget    T029 push_notification_service
T015 secure_storage   T023 loading_shimmer   T030 deep_link_handler
T016 pin_service      T024 primary_button
T017 biometric        T025 error_view
T018 screenshot_guard T026 success_view
T019 connectivity     T027 transaction_list_tile

# Phase 3 entities (run concurrently):
T033 customer.dart  T034 auth_token.dart  T035 auth_repository.dart

# Phase 4 entities (run concurrently):
T053 wallet.dart  T058 transaction.dart  T065 home_summary.dart  T068 notification.dart
```

---

## Implementation Strategy

### MVP (US-CUST-001 Registration Only)

1. Complete Phase 1: Setup (T001â€“T012)
2. Complete Phase 2: Foundational (T013â€“T032)
3. Complete Phase 3 T033â€“T044 (US-CUST-001 Register only)
4. **STOP and validate**: New user registers and reaches home screen (T040 splash redirect)
5. Demo milestone: end-to-end registration works

### Incremental Delivery

| Sprint | Deliverable | Tasks |
|--------|-------------|-------|
| 1 | Setup + Foundational | T001â€“T032 |
| 2 | Auth (register, login, logout, PIN reset) | T033â€“T052 |
| 3 | Home + Transactions + Notifications | T053â€“T074 |
| 4 | Wallet + Add Money + Send Money | T075â€“T093 |
| 5 | Withdraw + Profile + Security + Devices | T094â€“T113 |
| 6 | Services + Support + Disputes | T114â€“T140 |
| 7 | Polish + Tests + dart analyze | T141â€“T158 |

---

## Notes

- `[P]` tasks write to different files with no mutual dependency â€” safe to run concurrently
- `[USx]` maps to `US-CUST-00x` from spec.md (e.g. `[US12]` = `US-CUST-012 Send Money`)
- All financial screens require screenshot guard (FR-034) and idempotency key on every submit (FR-020)
- PIN must never appear in logs, crash reports, or analytics events (FR-036); `PinPadWidget` clears value after `onCompleted`
- Biometric is additive â€” app degrades gracefully when unavailable (FR-002); always show PIN fallback
- Offline: read-only screens serve cached Riverpod state; write operations show `AlertDialog` blocking submission (FR-033)
- Multi-currency: handled by existing `lib/modules/multi_currency/` module (E29); wallet selector already implemented
- `PaginatedResult<T>` is a simple generic: `{List<T> data, int currentPage, int total, int lastPage}`

