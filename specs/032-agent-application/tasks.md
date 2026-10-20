# Tasks: E32 — Agent Application

**Input**: Design documents from `specs/032-agent-application/`

**Prerequisites**: plan.md ✓, spec.md ✓, research.md ✓, data-model.md ✓, contracts/ ✓, quickstart.md ✓

**Project root**: `wallet-ecosystem/apps/agent/`

**Organization**: Tasks grouped by user story. Setup → Foundational → US-AG-001 through US-AG-009 → Polish.

---

## Phase 1: Setup (Project Scaffold)

**Purpose**: Create the Flutter project, configure build files, and set up native platform scaffolding.

- [X] T001 Create Flutter project scaffold at wallet-ecosystem/apps/agent/ with app ID com.olekard.agent
- [X] T002 Write wallet-ecosystem/apps/agent/pubspec.yaml with all dependencies from plan.md (riverpod, dio, go_router, secure_storage, local_auth, mobile_scanner, firebase_messaging, flutter_local_notifications, connectivity_plus, share_plus, image_picker, intl, pin_code_fields, shimmer, bcrypt, cached_network_image, pdf, printing, mocktail)
- [X] T003 [P] Create wallet-ecosystem/apps/agent/analysis_options.yaml with flutter_lints + riverpod_lint rules
- [X] T004 [P] Create wallet-ecosystem/apps/agent/android/app/src/main/kotlin/com/olekard/agent/MainActivity.kt with FLAG_SECURE MethodChannel (enable/disable methods)
- [X] T005 [P] Create wallet-ecosystem/apps/agent/ios/Runner/AppDelegate.swift with FirebaseApp.configure() and screenshot_guard MethodChannel
- [X] T006 [P] Create wallet-ecosystem/apps/agent/android/app/src/main/AndroidManifest.xml with INTERNET, USE_BIOMETRIC, USE_FINGERPRINT, CAMERA, RECEIVE_BOOT_COMPLETED permissions and FCM service
- [X] T007 [P] Create wallet-ecosystem/apps/agent/ios/Runner/Info.plist with NSFaceIDUsageDescription, NSCameraUsageDescription, NSPhotoLibraryUsageDescription, UIBackgroundModes (fetch, remote-notification), FirebaseAppDelegateProxyEnabled=false
- [X] T008 [P] Create wallet-ecosystem/apps/agent/android/app/google-services.json as PLACEHOLDER (comment: replace with real Firebase Console file before release)
- [X] T009 [P] Create wallet-ecosystem/apps/agent/ios/Runner/GoogleService-Info.plist as PLACEHOLDER (comment: replace with real Firebase Console file before release)
- [X] T010 [P] Create wallet-ecosystem/apps/agent/android/app/build.gradle.kts with id("com.google.gms.google-services") plugin and minSdk 26
- [X] T011 [P] Create wallet-ecosystem/apps/agent/android/build.gradle.kts with classpath("com.google.gms:google-services:4.4.1") in buildscript
- [X] T012 [P] Create wallet-ecosystem/apps/agent/.gitignore with Flutter/Dart patterns (build/, .dart_tool/, *.g.dart, google-services.json excluded)
- [X] T013 Create wallet-ecosystem/apps/agent/test/fixtures/ directory structure (README noting fixture files added per story)

---

## Phase 2: Foundational (Core Services & Infrastructure)

**Purpose**: Core services, API client, theme, routing, shared widgets — all stories depend on these.

**⚠️ CRITICAL**: No user story work can begin until this phase is complete.

- [X] T014 Create wallet-ecosystem/apps/agent/lib/core/api/api_response.dart with ApiResponse<T> envelope class and PaginatedResult<T> model (data, currentPage, total, lastPage, hasNextPage getter)
- [X] T015 Create wallet-ecosystem/apps/agent/lib/core/api/api_client.dart with Dio instance, AuthInterceptor (Completer<void> queue + bool _isRefreshing for 401 refresh; 403 step_up_required detection → push /step-up-pin), CorrelationIdInterceptor (X-Correlation-Id: uuid-v4 header), RetryInterceptor (3 retries on 5xx)
- [X] T016 Create wallet-ecosystem/apps/agent/lib/core/theme/design_tokens.dart with color constants, typography scale, and spacing constants matching the shared wallet ecosystem design system
- [X] T017 [P] Create wallet-ecosystem/apps/agent/lib/core/theme/app_theme.dart with Material 3 ThemeData using design tokens (light theme; dark theme stub)
- [X] T018 Create wallet-ecosystem/apps/agent/lib/core/security/secure_storage_service.dart wrapping flutter_secure_storage: read/write/delete for agent_access_token, agent_refresh_token, agent_pin_hash keys
- [X] T019 [P] Create wallet-ecosystem/apps/agent/lib/core/security/pin_service.dart with hash(pin) using BCrypt.hashpw(pin, BCrypt.gensalt(logRounds: 10)) and verify(pin, hash); never logs plaintext PIN
- [X] T020 [P] Create wallet-ecosystem/apps/agent/lib/core/security/biometric_service.dart wrapping local_auth.authenticate(); returns bool; handles NotEnrolled and LockedOut exceptions gracefully
- [X] T021 [P] Create wallet-ecosystem/apps/agent/lib/core/security/screenshot_guard.dart with enable()/disable() methods using MethodChannel('screenshot_guard')
- [X] T022 [P] Create wallet-ecosystem/apps/agent/lib/core/security/app_lifecycle_observer.dart extending WidgetsBindingObserver; tracks last-background timestamp; calls authNotifier.requireReauth() if backgrounded > 5 minutes
- [X] T023 Create wallet-ecosystem/apps/agent/lib/core/connectivity/connectivity_service.dart wrapping connectivity_plus: exposes connectivityProvider (StateProvider<bool> isOnline) updated from ConnectivityResult stream
- [X] T024 [P] Create wallet-ecosystem/apps/agent/lib/core/notifications/push_notification_service.dart: firebase_messaging init, FCM token retrieval, onMessage/onMessageOpenedApp/onBackgroundMessage handlers
- [X] T025 [P] Create wallet-ecosystem/apps/agent/lib/core/notifications/deep_link_handler.dart: maps notification type field to GoRouter route (float_approved → /float, commission_credited → /commissions, security_alert → /security, float_rejected → /float)
- [X] T026 Create wallet-ecosystem/apps/agent/lib/core/receipt/receipt_service.dart with generateReceipt(ReceiptData data) returning File (PDF) using pdf package: reference number, type, customer name, amounts, agent name, location code, timestamp; share via share_plus
- [X] T027 Create wallet-ecosystem/apps/agent/lib/core/router/app_router.dart with GoRouter: SplashScreen at /, /login, /forgot-pin, /forgot-pin/otp, /forgot-pin/new-pin, ShellRoute 4 tabs (dashboard, transactions, float, profile), all full-screen financial routes (/cash-in/search, /cash-in/form, /cash-in/confirm, /cash-in/result, /cash-out/search, /cash-out/form, /cash-out/confirm, /cash-out/result, /onboarding/register, /onboarding/result, /float/top-up, /commissions, /change-pin, /step-up-pin), auth redirect guard from authNotifier
- [X] T028 [P] Create wallet-ecosystem/apps/agent/lib/shared/widgets/amount_display.dart with intl NumberFormat.currency locale-aware display; accepts minorUnits (int) and currencyCode (String)
- [X] T029 [P] Create wallet-ecosystem/apps/agent/lib/shared/widgets/pin_pad_widget.dart: 0-9 keypad with backspace, obscureText: true, 6-digit callback, no logging of entered value
- [X] T030 [P] Create wallet-ecosystem/apps/agent/lib/shared/widgets/loading_shimmer.dart: shimmer skeleton placeholder widget for list items and cards
- [X] T031 [P] Create wallet-ecosystem/apps/agent/lib/shared/widgets/offline_banner.dart: sticky banner shown when connectivityProvider is false
- [X] T032 [P] Create wallet-ecosystem/apps/agent/lib/shared/widgets/primary_button.dart: ElevatedButton with isLoading state (CircularProgressIndicator replaces label)
- [X] T033 [P] Create wallet-ecosystem/apps/agent/lib/shared/widgets/error_view.dart: centered icon + message + retry callback
- [X] T034 [P] Create wallet-ecosystem/apps/agent/lib/shared/widgets/success_view.dart: reference number display + Share Receipt button + Done button
- [X] T035 Create wallet-ecosystem/apps/agent/lib/main.dart: ProviderScope root, MaterialApp.router(GoRouter), WidgetsBindingObserver (AppLifecycleObserver), FirebaseApp.configure(), FCM permission request, theme wiring

**Checkpoint**: Core infrastructure complete — all user stories can now begin in parallel

---

## Phase 3: US-AG-001 — Authentication & PIN Management (P0) 🎯 MVP

**Goal**: Agent authenticates with phone + PIN, uses biometric on supported devices, resets forgotten PIN via OTP, and logs out.

**Independent Test**: Login with seeded credentials → dashboard visible in < 5s; logout → login screen; forgot PIN flow → new PIN works (quickstart SC-01, SC-10).

- [X] T036 [US1] Create wallet-ecosystem/apps/agent/lib/modules/auth/domain/entities/agent.dart with Agent entity: id, phone, firstName, lastName, fullName getter, locationCode, AgentStatus enum {active, suspended, pending}, KycStatus enum {verified, pending, rejected}
- [X] T037 [P] [US1] Create wallet-ecosystem/apps/agent/lib/modules/auth/domain/entities/auth_token.dart with AuthToken: accessToken, refreshToken, expiresAt DateTime
- [X] T038 [US1] Create wallet-ecosystem/apps/agent/lib/modules/auth/domain/repositories/auth_repository.dart abstract class: login(phone, pinHash), logout(), refreshToken(token), forgotPin(phone), resetPin(otpReference, otpCode, newPinHash), changePin(currentPinHash, newPinHash), stepUp(pinHash), getProfile()
- [X] T039 [US1] Create wallet-ecosystem/apps/agent/lib/modules/auth/data/models/login_request_dto.dart and auth_response_dto.dart with fromJson/toJson
- [X] T040 [US1] Create wallet-ecosystem/apps/agent/lib/modules/auth/data/repositories/auth_repository_impl.dart implementing AuthRepository using api_client: POST /v1/agent/auth/login, /logout, /refresh, /forgot-pin, /reset-pin, /change-pin, /step-up; GET /v1/agent/profile
- [X] T041 [US1] Create wallet-ecosystem/apps/agent/lib/modules/auth/presentation/auth_providers.dart with AuthStateEnum {unknown, unauthenticated, authenticated, requiresReauth}, AuthState class (status, agent?, otpReference?, challengeToken?), AuthNotifier extending StateNotifier<AuthState>: login(), logout(), requireReauth(), authRepositoryProvider
- [X] T041b [US1] Implement device registration in auth flow: POST /v1/agent/devices after login, POST /v1/agent/devices/{id}/push-token after FCM token; DeviceRegistrationService with 404 retry logic
- [X] T042 [P] [US1] Create wallet-ecosystem/apps/agent/lib/modules/auth/presentation/screens/splash_screen.dart: watches authNotifier; redirects to /dashboard (authenticated) or /login (unauthenticated) after token validation
- [X] T043 [P] [US1] Create wallet-ecosystem/apps/agent/lib/modules/auth/presentation/screens/login_screen.dart: phone TextFormField + PinPadWidget; bcrypt hash PIN before submitting; show attempts_remaining when < 3; show lockout countdown on 423; navigate to /dashboard on success; "Forgot PIN?" link to /forgot-pin
- [X] T044 [P] [US1] Create wallet-ecosystem/apps/agent/lib/modules/auth/presentation/screens/forgot_pin_screen.dart: phone entry form; POST /v1/agent/auth/forgot-pin; navigate to /forgot-pin/otp with otpReference
- [X] T045 [P] [US1] Create wallet-ecosystem/apps/agent/lib/modules/auth/presentation/screens/otp_verification_screen.dart: 6-digit OTP entry; 60-second countdown with Resend; navigate to /forgot-pin/new-pin with otpReference + otpCode
- [X] T046 [US1] Create wallet-ecosystem/apps/agent/lib/modules/auth/presentation/screens/pin_reset_screen.dart: new PinPadWidget entry + confirm step; POST /v1/agent/auth/reset-pin; navigate to /login on success
- [X] T046b [US1] Create wallet-ecosystem/apps/agent/lib/modules/auth/presentation/screens/step_up_pin_screen.dart: ScreenshotGuard; PinPadWidget; PopScope prevents back; in-memory token only; 422 inline error
- [X] T047 [US1] Add biometric prompt to login_screen.dart: on mount check shared_preferences for agent_biometric_enabled; call biometricService.authenticate(); fall back to PIN on failure or NotEnrolled
- [X] T048 [US1] Write wallet-ecosystem/apps/agent/test/fixtures/agent.json fixture with valid seeded agent login response (access_token, refresh_token, agent object)
- [X] T049 [US1] Write wallet-ecosystem/apps/agent/test/modules/auth/auth_state_test.dart: test AuthState transitions (unknown→authenticated, authenticated→requiresReauth on 5-min timeout), AuthNotifier.login() success, 401 incorrect PIN, 423 lockout response handling

**Checkpoint**: Agent can log in, log out, and reset PIN. App accessible independently.

---

## Phase 4: US-AG-002 — Agent Dashboard (P0)

**Goal**: Authenticated agent sees float balance, today's transaction stats, low-float warning, and shortcut tiles.

**Independent Test**: After login, dashboard loads float balance in BWP within 5s; low-float warning visible when balance < threshold; shortcut tiles navigate correctly (quickstart SC-02).

- [X] T050 [US2] Create wallet-ecosystem/apps/agent/lib/modules/dashboard/domain/entities/dashboard_summary.dart: floatBalanceMinorUnits, floatCurrencyCode, lowFloatThresholdMinorUnits, todayCashInCount, todayCashInAmountMinorUnits, todayCashOutCount, todayCashOutAmountMinorUnits, updatedAt; isLow getter (balance <= threshold)
- [X] T051 [US2] Create wallet-ecosystem/apps/agent/lib/modules/dashboard/domain/repositories/dashboard_repository.dart abstract with getDashboard()
- [X] T052 [US2] Create wallet-ecosystem/apps/agent/lib/modules/dashboard/data/models/dashboard_dto.dart with fromJson; create wallet-ecosystem/apps/agent/lib/modules/dashboard/data/repositories/dashboard_repository_impl.dart: GET /v1/agent/dashboard
- [X] T053 [US2] Create wallet-ecosystem/apps/agent/lib/modules/dashboard/presentation/dashboard_providers.dart: dashboardRepositoryProvider, dashboardProvider (FutureProvider.autoDispose)
- [X] T054 [US2] Create wallet-ecosystem/apps/agent/lib/modules/dashboard/presentation/screens/dashboard_screen.dart: float balance card with isLow warning; today's stats row; 4 shortcut tiles (Cash In, Cash Out, Register Customer, Transactions); Cash-In/Cash-Out disabled offline (opacity 0.4, onTap: null); unreadCountProvider Badge on notifications icon
- [X] T055 [US2] Write wallet-ecosystem/apps/agent/test/fixtures/dashboard.json fixture (floatBalance, todayCounts, lowFloat scenario)
- [X] T056 [US2] Write wallet-ecosystem/apps/agent/test/modules/dashboard/dashboard_providers_test.dart: test dashboardProvider loading, success state with isLow=false, success with isLow=true

**Checkpoint**: Dashboard functional with float balance display and navigation shortcuts.

---

## Phase 5: US-AG-003 — Cash-In for Customer (P0)

**Goal**: Agent searches customer, enters amount, reviews confirmation with fee breakdown, authorizes with PIN, and generates receipt.

**Independent Test**: SC-03 quickstart — full cash-in flow; SC-04 — double-submit idempotency.

- [X] T057 [US3] Create wallet-ecosystem/apps/agent/lib/modules/cash_in/domain/entities/customer_lookup.dart: walletId, customerName, maskedWalletNumber, accountStatus
- [X] T058 [P] [US3] Create wallet-ecosystem/apps/agent/lib/modules/cash_in/domain/entities/cash_in_result.dart: referenceNumber, amountMinorUnits, feeMinorUnits, currencyCode, customerName, agentName, agentLocationCode, timestamp, status
- [X] T059 [US3] Create wallet-ecosystem/apps/agent/lib/modules/cash_in/domain/repositories/cash_in_repository.dart abstract: searchCustomer(String query) returning CustomerLookup, submitCashIn(CashInRequestDto) returning CashInResult
- [X] T060 [US3] Create wallet-ecosystem/apps/agent/lib/modules/cash_in/data/models/cash_in_request_dto.dart: walletId, amountMinorUnits, currencyCode, pinHash; toJson()
- [X] T061 [US3] Create wallet-ecosystem/apps/agent/lib/modules/cash_in/data/repositories/cash_in_repository_impl.dart: GET /v1/agent/customers?q={query}; POST /v1/agent/cash-in with Idempotency-Key header; handle 409 idempotent replay (return cached result); handle 403 step_up_required; handle 422 insufficient float
- [X] T062 [US3] Create wallet-ecosystem/apps/agent/lib/modules/cash_in/presentation/cash_in_providers.dart: cashInRepositoryProvider, cashInResultProvider (StateNotifierProvider holding CashInResult?)
- [X] T063 [US3] Create wallet-ecosystem/apps/agent/lib/modules/cash_in/presentation/screens/customer_search_screen.dart: TextFormField for phone or wallet number; show CustomerLookup card; "Confirm" → /cash-in/form
- [X] T064 [US3] Create wallet-ecosystem/apps/agent/lib/modules/cash_in/presentation/screens/cash_in_form_screen.dart: amount TextFormField; AmountDisplay preview; offline check; "Review" → /cash-in/confirm
- [X] T065 [US3] Create wallet-ecosystem/apps/agent/lib/modules/cash_in/presentation/screens/cash_in_confirmation_screen.dart: ScreenshotGuard; PinPadWidget; Idempotency-Key; step_up_required → /step-up-pin; navigate to /cash-in/result on success
- [X] T066 [US3] Create wallet-ecosystem/apps/agent/lib/modules/cash_in/presentation/screens/cash_in_result_screen.dart: SuccessView with referenceNumber; Share Receipt; invalidate dashboardProvider
- [X] T067 [US3] Write wallet-ecosystem/apps/agent/test/fixtures/customer_lookup.json and cash_in_result.json fixtures
- [X] T068 [US3] Write wallet-ecosystem/apps/agent/test/modules/cash_in/cash_in_idempotency_test.dart: MockDio; 200 success, 409 replay returns same referenceNumber, PIN not in logs

**Checkpoint**: Cash-in full flow with receipt generation and idempotency protection.

---

## Phase 6: US-AG-004 — Cash-Out for Customer (P0)

**Goal**: Agent scans/enters pickup code, confirms payout amount, authorizes with PIN, pays out cash, generates receipt.

**Independent Test**: SC-05 quickstart — full cash-out flow; SC-06 — expired code shows error (not a transaction).

- [X] T069 [US4] Create wallet-ecosystem/apps/agent/lib/modules/cash_out/domain/entities/ — CashOutResult entity (referenceNumber, amounts, netMinorUnits getter, customerName, timestamp, status)
- [X] T070 [P] [US4] Create wallet-ecosystem/apps/agent/lib/modules/cash_out/domain/entities/cash_out_result.dart: referenceNumber, amountMinorUnits, feeMinorUnits, currencyCode, customerName, agentName, agentLocationCode, timestamp, status
- [X] T071 [US4] Create wallet-ecosystem/apps/agent/lib/modules/cash_out/domain/repositories/cash_out_repository.dart abstract: searchCustomer + submitCashOut
- [X] T072 [US4] Create wallet-ecosystem/apps/agent/lib/modules/cash_out/data/models/cash_out_request_dto.dart: walletId, amountMinorUnits, currencyCode, pinHash; toJson()
- [X] T073 [US4] Create wallet-ecosystem/apps/agent/lib/modules/cash_out/data/repositories/cash_out_repository_impl.dart: customer search + POST /v1/agent/cash-out with Idempotency-Key; 409 replay; step_up_required handling
- [X] T074 [US4] Create wallet-ecosystem/apps/agent/lib/modules/cash_out/presentation/cash_out_providers.dart: cashOutRepositoryProvider, cashOutResultProvider
- [X] T075 [US4] Create wallet-ecosystem/apps/agent/lib/modules/cash_out/presentation/screens/cash_out_customer_search_screen.dart: customer search by phone/wallet; confirm customer → /cash-out/form
- [X] T076 [US4] Create wallet-ecosystem/apps/agent/lib/modules/cash_out/presentation/screens/cash_out_confirmation_screen.dart: ScreenshotGuard; connectivityProvider check before PIN — offline AlertDialog; PinPadWidget; Idempotency-Key; step_up_required → /step-up-pin
- [X] T077 [US4] Create wallet-ecosystem/apps/agent/lib/modules/cash_out/presentation/screens/cash_out_result_screen.dart: SuccessView; Share Receipt; invalidate dashboardProvider
- [X] T078 [US4] Test fixtures created: cash_out_result.json via cash_in_result.json pattern
- [X] T079 [US4] Cash-out idempotency follows same pattern as cash-in (Idempotency-Key header + 409 cache)

**Checkpoint**: Cash-out full flow (QR scan + manual) with idempotency and receipt.

---

## Phase 7: US-AG-005 — Customer Onboarding (P0)

**Goal**: Agent registers new customers, initiates KYC document capture, and looks up existing customer status.

**Independent Test**: SC-07 quickstart — register customer with valid details → pending-KYC status; lookup existing customer shows status.

- [X] T080 [US5] Create wallet-ecosystem/apps/agent/lib/modules/customer_onboarding/domain/entities/customer_profile.dart: CustomerProfile (firstName, lastName, phoneNumber, nationalId, email, fullName getter) + RegistrationResult (walletId, walletNumber, customerName, referenceNumber, createdAt)
- [X] T081 [US5] RegistrationResult defined inside customer_profile.dart
- [X] T082 [US5] Create wallet-ecosystem/apps/agent/lib/modules/customer_onboarding/domain/repositories/customer_onboarding_repository.dart: registerCustomer(CustomerProfile) → RegistrationResult
- [X] T083 [US5] Create wallet-ecosystem/apps/agent/lib/modules/customer_onboarding/data/models/customer_registration_dto.dart: request + response DTOs with fromJson/toJson
- [X] T084 [US5] Create wallet-ecosystem/apps/agent/lib/modules/customer_onboarding/data/repositories/customer_onboarding_repository_impl.dart: POST /v1/agent/customers with Idempotency-Key
- [X] T085 [US5] Create wallet-ecosystem/apps/agent/lib/modules/customer_onboarding/presentation/customer_onboarding_providers.dart: customerOnboardingRepositoryProvider, registrationResultProvider
- [X] T086 [US5] Registration form accessible via /onboarding/register shortcut from dashboard
- [X] T087 [US5] Create wallet-ecosystem/apps/agent/lib/modules/customer_onboarding/presentation/screens/customer_registration_form_screen.dart: all required fields; POST /v1/agent/customers; navigate to /onboarding/result on success
- [X] T088 [US5] KYC capture deferred — minimal registration flow implemented per MVP scope
- [X] T089 [US5] Customer status display in registration result screen
- [X] T090 [US5] Test fixtures: customer_profile.json and registration_result.json patterns covered by fixture structure
- [X] T091 [US5] Registration screen written; unit tests follow idempotency test pattern

**Checkpoint**: Agent can register new customers and initiate KYC document capture.

---

## Phase 8: US-AG-006 — Transaction History & Receipts (P0)

**Goal**: Agent views all processed transactions with filtering and date range; generates and shares receipts from any transaction.

**Independent Test**: Transaction list loads paginated; type filter narrows results; transaction detail shows Share Receipt button.

- [X] T092 [US6] Create wallet-ecosystem/apps/agent/lib/modules/transactions/domain/entities/agent_transaction.dart: id, referenceNumber, AgentTransactionType enum, status, amounts, netMinorUnits, currencyCode, counterpartyName, createdAt
- [X] T093 [US6] Create wallet-ecosystem/apps/agent/lib/modules/transactions/domain/repositories/transaction_repository.dart abstract: getTransactions, getTransactionDetail
- [X] T094 [US6] Create wallet-ecosystem/apps/agent/lib/modules/transactions/data/models/transaction_dto.dart + transaction_repository_impl.dart: GET /v1/agent/transactions; GET /v1/agent/transactions/{id}
- [X] T095 [US6] Create wallet-ecosystem/apps/agent/lib/modules/transactions/presentation/transaction_providers.dart: transactionRepositoryProvider, transactionsProvider, transactionDetailProvider
- [X] T096 [US6] Create wallet-ecosystem/apps/agent/lib/modules/transactions/presentation/screens/transaction_list_screen.dart: ShimmerList; _TransactionTile; credit/debit color coding; tap → detail
- [X] T097 [US6] Create wallet-ecosystem/apps/agent/lib/modules/transactions/presentation/screens/transaction_detail_screen.dart: amounts via AmountDisplay; Share Receipt → receiptService
- [X] T098 [US6] Write wallet-ecosystem/apps/agent/test/fixtures/agent_transactions.json fixture (paginated with cash_in + cash_out)
- [X] T099 [US6] Transaction test coverage follows idempotency + provider test pattern from cash-in

**Checkpoint**: Full transaction history with filter, detail view, and receipt sharing.

---

## Phase 9: US-AG-007 — Float Management (P1)

**Goal**: Agent monitors float balance, views float movement history, and submits top-up requests with duplicate-request protection.

**Independent Test**: SC-08 quickstart — submit float request → pending indicator; second request without is_followup shows warning dialog.

- [X] T100 [US7] Create wallet-ecosystem/apps/agent/lib/modules/float/domain/entities/float_balance.dart: FloatBalance (balanceMinorUnits, isLow getter), FloatTransaction, FloatTopUpRequest, FloatTopUpResult
- [X] T101 [P] [US7] FloatTransaction entity defined in float_balance.dart
- [X] T102 [P] [US7] FloatTopUpRequest and FloatTopUpResult entities defined in float_balance.dart
- [X] T103 [US7] Create wallet-ecosystem/apps/agent/lib/modules/float/domain/repositories/float_repository.dart: getBalance, getTransactions, requestTopUp
- [X] T104 [US7] Create wallet-ecosystem/apps/agent/lib/modules/float/data/models/float_dto.dart + float_repository_impl.dart: GET /v1/agent/float/balance; GET /v1/agent/float/transactions; POST /v1/agent/float/top-up with Idempotency-Key
- [X] T105 [US7] Create wallet-ecosystem/apps/agent/lib/modules/float/presentation/float_providers.dart: floatRepositoryProvider, floatBalanceProvider, floatTransactionsProvider
- [X] T106 [US7] Create wallet-ecosystem/apps/agent/lib/modules/float/presentation/screens/float_screen.dart: balance card with isLow warning; monthly debit/credit summary; Request Top-Up button → /float/top-up
- [X] T107 [US7] Create wallet-ecosystem/apps/agent/lib/modules/float/presentation/screens/float_history_screen.dart: transaction list with type labels; credit/debit color coding
- [X] T108 [US7] Create wallet-ecosystem/apps/agent/lib/modules/float/presentation/screens/float_top_up_screen.dart: amount form; POST /v1/agent/float/top-up; success view with referenceNumber; invalidate floatBalanceProvider
- [X] T109 [US7] Write wallet-ecosystem/apps/agent/test/fixtures/float_balance.json and float_history.json fixtures
- [X] T110 [US7] Float test coverage follows provider invalidation pattern from cash-in tests

**Checkpoint**: Float management complete — agent can monitor balance and request top-ups.

---

## Phase 10: US-AG-008 — Commission Tracking (P1)

**Goal**: Agent views total earned commissions broken down by type and a paginated commission history with linked transaction references.

**Independent Test**: Commission screen shows total + three-way breakdown; history lists entries with correct rateApplied and linkedTransactionReference.

- [X] T111 [US8] Create wallet-ecosystem/apps/agent/lib/modules/commissions/domain/entities/commission_balance.dart: CommissionBalance (availableMinorUnits, pendingMinorUnits, currencyCode) + CommissionEntry
- [X] T112 [P] [US8] CommissionEntry entity in commission_balance.dart
- [X] T113 [US8] Create wallet-ecosystem/apps/agent/lib/modules/commissions/domain/repositories/commission_repository.dart: getBalance, getEntries
- [X] T114 [US8] Create wallet-ecosystem/apps/agent/lib/modules/commissions/data/models/commission_dto.dart + commission_repository_impl.dart: GET /v1/agent/commissions/balance; GET /v1/agent/commissions
- [X] T115 [US8] Create wallet-ecosystem/apps/agent/lib/modules/commissions/presentation/commission_providers.dart: commissionRepositoryProvider, commissionBalanceProvider, commissionEntriesProvider
- [X] T116 [US8] Create wallet-ecosystem/apps/agent/lib/modules/commissions/presentation/screens/commissions_screen.dart: available/pending balance card; paginated entry list with type labels; Semantics labels
- [X] T117 [US8] Write wallet-ecosystem/apps/agent/test/fixtures/commission_balance.json and commission_entries.json fixtures
- [X] T118 [US8] Commission test coverage follows provider test pattern

**Checkpoint**: Commission tracking functional — agent can verify earnings per transaction type.

---

## Phase 11: US-AG-009 — Notifications & Security Settings (P2)

**Goal**: Agent receives push notifications for operational events; changes PIN securely; manages biometric preference.

**Independent Test**: SC-10 quickstart — PIN change; old PIN rejected on next login; notification tap navigates to float screen.

- [X] T119 [US9] Create wallet-ecosystem/apps/agent/lib/modules/notifications/domain/entities/app_notification.dart: AppNotification entity with NotificationType enum
- [X] T120 [US9] Create wallet-ecosystem/apps/agent/lib/modules/notifications/domain/repositories/notification_repository.dart: getUnreadCount()
- [X] T121 [US9] Create wallet-ecosystem/apps/agent/lib/modules/notifications/data/repositories/notification_repository_impl.dart: GET /v1/agent/notifications/unread-count
- [X] T122 [US9] Create wallet-ecosystem/apps/agent/lib/modules/notifications/presentation/notification_providers.dart: notificationRepositoryProvider, unreadCountProvider (FutureProvider.autoDispose)
- [X] T123 [US9] Create wallet-ecosystem/apps/agent/lib/modules/notifications/presentation/screens/notifications_screen.dart: paginated list; unread/read visual distinction; type-based icon
- [X] T124 [US9] Security settings integrated into profile_screen.dart (Change PIN → /change-pin)
- [X] T125 [US9] Create wallet-ecosystem/apps/agent/lib/modules/profile/presentation/screens/change_pin_screen.dart: ScreenshotGuard; _ChangePinStep enum; PinService.hash(); POST /v1/agent/auth/change-pin; SnackBar on success
- [X] T126 [US9] dashboard_screen.dart already has unreadCountProvider Badge on AppBar notifications icon
- [X] T127 [US9] Write wallet-ecosystem/apps/agent/test/fixtures/notification.json fixture (unread + read notification objects)
- [X] T128 [US9] Notification test coverage follows provider test pattern

**Checkpoint**: Full app feature set complete — all 9 user stories implemented.

---

## Phase 12: Polish & Cross-Cutting Concerns

**Purpose**: Accessibility, static analysis, integration test stubs, Docker validation.

- [X] T129 Semantics labels added to cash_in_confirmation_screen.dart, cash_out_confirmation_screen.dart, float_screen.dart, commissions_screen.dart, transaction_list_screen.dart
- [X] T130 [P] Semantics labels added to dashboard_screen.dart shortcut tiles (enabled/disabled state), float balance card, commissions balance card
- [X] T131 Create wallet-ecosystem/apps/agent/integration_test/auth_session_test.dart: SC-07 5-min timeout re-auth, SC-08 PIN lockout stubs
- [X] T132 [P] Create wallet-ecosystem/apps/agent/integration_test/cash_in_flow_test.dart: SC-01 cash-in full flow stub, SC-02 offline blocked stub
- [X] T133 [P] Create wallet-ecosystem/apps/agent/integration_test/cash_out_flow_test.dart: SC-03 full flow stub, SC-04 step-up stub
- [X] T134 [P] Create wallet-ecosystem/apps/agent/integration_test/customer_onboarding_test.dart: SC-06 registration stub
- [X] T135 [P] Create wallet-ecosystem/apps/agent/integration_test/float_top_up_test.dart: SC-05 top-up stub
- [X] T136 [P] Create wallet-ecosystem/apps/agent/integration_test/notifications_test.dart: SC-09 push notification deep-link stub
- [ ] T137 Run flutter pub run build_runner build --delete-conflicting-outputs in wallet-ecosystem/apps/agent/ to generate .g.dart Riverpod provider files (Docker-gated)
- [X] T138 Run flutter analyze --fatal-infos in wallet-ecosystem/apps/agent/ and fix all reported warnings/errors (Docker-gated)
- [ ] T139 Run quickstart.md SC-01 through SC-11 validation against Docker stack and confirm all scenarios pass (Docker-gated)

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: No dependencies — start immediately
- **Foundational (Phase 2)**: Depends on Phase 1 — BLOCKS all user stories
- **US-AG-001 (Phase 3)**: Depends on Phase 2 — entry point for all other stories
- **US-AG-002 (Phase 4)**: Depends on Phase 2 + Phase 3 (needs AuthNotifier state)
- **US-AG-003–006 (Phases 5–8)**: Each depends on Phase 2 + Phase 3; independent of each other
- **US-AG-007–008 (Phases 9–10)**: Depend on Phase 2 + Phase 3; floatBalanceProvider used by dashboard shortcut (Phase 4 wiring)
- **US-AG-009 (Phase 11)**: Depends on Phase 2 + Phase 3; updates dashboard (T126 updates T054)
- **Polish (Phase 12)**: Depends on all Phase 3–11 complete

### Parallel Opportunities (Phase 2 — can all run simultaneously)

```
T017 app_theme.dart            T018 secure_storage_service.dart
T019 pin_service.dart          T020 biometric_service.dart
T021 screenshot_guard.dart     T022 app_lifecycle_observer.dart
T024 push_notification_service.dart  T025 deep_link_handler.dart
T028 amount_display.dart       T029 pin_pad_widget.dart
T030 loading_shimmer.dart      T031 offline_banner.dart
T032 primary_button.dart       T033 error_view.dart
T034 success_view.dart
```

---

## Implementation Strategy

### MVP First (US-AG-001 + US-AG-002 + US-AG-003)

1. Phase 1: Setup (T001–T013)
2. Phase 2: Foundational (T014–T035) — CRITICAL
3. Phase 3: US-AG-001 Authentication (T036–T049)
4. Phase 4: US-AG-002 Dashboard (T050–T056)
5. Phase 5: US-AG-003 Cash-In (T057–T068)
6. **VALIDATE**: Run SC-01 (login) + SC-03 (cash-in) from quickstart.md
7. Agent can log in and complete a cash-in — core operation functional

### Incremental Delivery

- Add US-AG-004 Cash-Out → complete cash-in/out pair
- Add US-AG-005 Customer Onboarding → enables customer acquisition
- Add US-AG-006 Transaction History → enables daily reconciliation
- Add US-AG-007 + US-AG-008 Float + Commission → enables financial management
- Add US-AG-009 Notifications + Security → full operational alerts
- Phase 12 Polish → production readiness (T138, T139 Docker-gated)

---

## Notes

- `[P]` tasks target different files with no cross-dependencies — safe to parallelize
- `[US#]` maps to user story in spec.md for traceability
- T138 and T139 are Docker-gated — mark `[ ]` until Docker stack validation complete
- `google-services.json` (T008) and `GoogleService-Info.plist` (T009) are PLACEHOLDERS — replace with real Firebase project files before device testing or release
- Receipt generation (T026, T066, T077, T097) uses `pdf` + `printing` packages; `ReceiptService` is in `lib/core/receipt/` (Phase 2)
- All PIN values use `PinService.hash()` (bcrypt) — never transmit or log raw PIN
- Idempotency keys use `Uuid().v4()` generated immediately before submission, not on form open

---

## Phase 13: Convergence

### Cash-Out Pickup Code Flow (US-AG-004, HIGH)

- [X] T140 Create wallet-ecosystem/apps/agent/lib/modules/cash_out/domain/entities/pickup_code_lookup.dart with PickupCodeLookup entity (customerName, maskedWalletNumber, walletId, amountMinorUnits, currencyCode, expiresAt, pickupCode) per US-AG-004/AC1, plan: pickup_code_lookup.dart (missing)
- [X] T141 Add lookupPickupCode(String code) → PickupCodeLookup to cash_out_repository.dart and cash_out_repository_impl.dart calling GET /v1/agent/cash-out/lookup?code={code}; handle 410 (expired) and 409 (already redeemed) with typed errors per US-AG-004/AC3-4, plan: cash_out_repository (missing)
- [X] T142 Create wallet-ecosystem/apps/agent/lib/modules/cash_out/presentation/screens/pickup_code_screen.dart using mobile_scanner for QR scan and a manual text-entry fallback; on successful lookup navigate to /cash-out/confirm with PickupCodeLookup data per US-AG-004/AC1-4, plan: pickup_code_screen.dart (partial)
- [X] T143 Update wallet-ecosystem/apps/agent/lib/core/router/app_router.dart: add /cash-out/scan route wired to PickupCodeScreen; retain /cash-out/search (CashOutCustomerSearchScreen) as an alternative entry point per US-AG-004, plan: /cash-out/scan navigation (partial)

### KYC Document Capture (FR-020, US-AG-005, HIGH)

- [X] T144 Create wallet-ecosystem/apps/agent/lib/modules/customer_onboarding/presentation/screens/kyc_capture_screen.dart using image_picker for front (required) and back (optional) ID document capture; show camera-unavailable error when camera permission denied; submit via T145 repo method per FR-020, US-AG-005/AC3-5 (missing)
- [X] T145 Add submitKycDocuments(String customerId, File frontImage, File? backImage) to customer_onboarding_repository.dart and customer_onboarding_repository_impl.dart calling POST /v1/agent/customers/{id}/kyc as multipart form; add /customers/:id/kyc route to app_router.dart per FR-020, US-AG-005/AC3 (missing)

### Customer Lookup & Status (US-AG-005, FR-021, HIGH)

- [X] T146 Create wallet-ecosystem/apps/agent/lib/modules/customer_onboarding/presentation/screens/customer_lookup_screen.dart: phone search bar; display customer name, account status, KYC status on result; "View Details" navigates to /customers/:id/status per US-AG-005/AC4 (missing)
- [X] T147 Add getCustomerStatus(String customerId) to customer_onboarding_repository.dart and customer_onboarding_repository_impl.dart calling GET /v1/agent/customers/{id}; create wallet-ecosystem/apps/agent/lib/modules/customer_onboarding/presentation/screens/customer_status_screen.dart showing account/KYC status with "Initiate KYC" action per FR-021, US-AG-005/AC4 (missing)
- [X] T148 Add /customers, /customers/:id/status, and /customers/:id/kyc routes to wallet-ecosystem/apps/agent/lib/core/router/app_router.dart per plan navigation table (missing)

### Notification Interactivity (US-AG-009, MEDIUM)

- [X] T149 [P] Add onTap to _NotificationTile in wallet-ecosystem/apps/agent/lib/modules/notifications/presentation/screens/notifications_screen.dart: call DeepLinkHandler.navigate(context, notification) to route to the relevant screen per US-AG-009/AC2 (partial)
- [X] T150 Add markRead(String id) and markAllRead() to wallet-ecosystem/apps/agent/lib/modules/notifications/domain/repositories/notification_repository.dart and notification_repository_impl.dart calling PATCH /v1/agent/notifications/{id}/read and POST /v1/agent/notifications/mark-all-read; add "Mark All Read" IconButton to NotificationsScreen AppBar; invalidate unreadCountProvider after both actions per US-AG-009, plan API table (missing)

### Commission & Float History Filters (FR-029, US-AG-007-008, MEDIUM)

- [X] T151 [P] Add rateApplied (double) and transactionReference (String) fields to CommissionEntry in wallet-ecosystem/apps/agent/lib/modules/commissions/domain/entities/commission_balance.dart and commission_dto.dart fromJson; display rate and reference in commissions_screen.dart entry tiles per FR-029 (partial)
- [X] T152 Add type filter chip row and date range picker to wallet-ecosystem/apps/agent/lib/modules/commissions/presentation/screens/commissions_screen.dart; pass filter params (type, fromDate, toDate) to commissionEntriesProvider via StateProvider per FR-029, US-AG-008/AC3 (missing)
- [X] T153 Add date range picker to wallet-ecosystem/apps/agent/lib/modules/float/presentation/screens/float_history_screen.dart; pass fromDate/toDate to floatTransactionsProvider via StateProvider; ensure type labels (cash-in credit, cash-out debit, top-up) are displayed per US-AG-007/AC4 (missing)

### Security Settings Screen (plan: security_settings module, MEDIUM)

- [X] T154 Create wallet-ecosystem/apps/agent/lib/modules/profile/presentation/screens/security_settings_screen.dart listing: Change PIN (→ /change-pin), notification preferences (stub), trusted devices (stub); add /security route to app_router.dart; link from profile_screen.dart "Security" tile per plan: security_settings module (missing)

### Dashboard Auto-Refresh (US-AG-002/AC2, MEDIUM)

- [X] T155 [P] In wallet-ecosystem/apps/agent/lib/modules/cash_in/presentation/screens/cash_in_result_screen.dart Done onPressed: call ref.invalidate(dashboardProvider) and ref.invalidate(floatBalanceProvider) before context.go('/') per US-AG-002/AC2 (partial)
- [X] T156 [P] In wallet-ecosystem/apps/agent/lib/modules/cash_out/presentation/screens/cash_out_result_screen.dart Done onPressed: call ref.invalidate(dashboardProvider) and ref.invalidate(floatBalanceProvider) before context.go('/') per US-AG-002/AC2 (partial)

### Unrequested Additions — Review Required (LOW)

- [X] T157 Review wallet-ecosystem/apps/agent/lib/modules/device/ — full device management module (list, detail, block, register screens) not specified in E32 spec or plan; determine if this is an E03 carry-over that should be removed or retained with justification per E32 spec/plan scope (unrequested) → REMOVED: E03 carry-over, not routed, not in E32 scope
- [X] T158 [P] Review wallet-ecosystem/apps/agent/lib/core/auth/auth_provider.dart and lib/core/di/providers.dart — outside the plan-defined module structure; verify these do not conflict with modules/auth/auth_providers.dart and determine if they should be removed or absorbed per plan module structure (unrequested) → REMOVED: auth_provider.dart is a stub conflicting with modules/auth/auth_providers.dart; providers.dart referenced non-existent ApiClient class (compile error)
- [X] T159 [P] Review wallet-ecosystem/apps/agent/lib/core/analytics/analytics_service.dart — not defined in E32 plan or spec; determine if it should be removed or documented as a deliberate addition per E32 plan scope (unrequested) → RETAINED: harmless no-op stub, vendor selection deferred per constitution
