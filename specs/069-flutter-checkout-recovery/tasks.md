# Tasks: P22 — Shared Flutter Checkout and Recovery

**Input**: Design documents from `specs/069-flutter-checkout-recovery/`

**Prerequisites**: plan.md ✓ | spec.md ✓ | research.md ✓ | data-model.md ✓ | contracts/ ✓ | quickstart.md ✓

**Organization**: Phases 1–2 are foundational. Phases 3–6 are one phase per user story (all P1). Phase 7 is polish.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies on incomplete sibling tasks)
- **[Story]**: Which user story this task belongs to
- Tests are included for financial-integrity-critical and security paths per constitution Principle 12

---

## Phase 1: Setup — Package Scaffold

**Purpose**: Create `packages/shared-payment/` Flutter library following `packages/design-system` convention.

- [X] T001 Create packages/shared-payment/ directory scaffold: pubspec.yaml, lib/shared_payment.dart, lib/src/ tree (domain/models/, domain/, application/, data/, presentation/widgets/), test/ tree (domain/, application/, presentation/)
- [X] T002 [P] Write packages/shared-payment/pubspec.yaml (name: shared_payment; sdk >=3.3.0 <4.0.0; deps: flutter_riverpod ^3.1.0, dio ^5.4.3, shared_preferences ^2.3.0, uuid ^4.4.0, url_launcher ^6.3.2, design_system path; dev_deps: flutter_test, mocktail ^1.0.4)
- [X] T003 [P] Upgrade wallet-ecosystem/apps/agent/pubspec.yaml flutter_riverpod from ^2.5.1 to ^3.1.0 and riverpod_annotation from ^2.3.5 to ^4.0.0 and riverpod_lint to ^3.1.0 (required by shared package)
- [X] T004 [P] Create packages/shared-payment/lib/shared_payment.dart as empty barrel (placeholder — finalized in T018)
- [X] T005 [P] Create packages/shared-payment/.gitignore following packages/design-system pattern (build/, .dart_tool/, pubspec.lock)

**Checkpoint**: Package directory ready; `flutter pub get` succeeds from packages/shared-payment/

---

## Phase 2: Foundational — Domain Models + Data Layer

**Purpose**: Core types consumed by all user stories. Must complete before any US phase begins.

- [X] T006 Create packages/shared-payment/lib/src/domain/models/payment_status.dart — PaymentIntentStatus enum with values: pending, processing, awaitingAction, requiresConfirmation, fulfilling (NEW), succeeded, failed, cancelled, expired, refunding, partiallyRefunded, refunded; fromJson factory; isTerminal getter (succeeded/failed/cancelled/expired/refunded)
- [X] T007 [P] Create packages/shared-payment/lib/src/domain/models/payment_intent.dart — PaymentIntent class with all fields from data-model.md; fromJson factory; imports payment_status.dart
- [X] T008 [P] Create packages/shared-payment/lib/src/domain/models/payment_option.dart — FeeDisclosure class (serviceFeeMinor, providerFeeMinor, totalFeeMinor, totalPayableMinor; fromJson); PaymentOption class with all fields including supportedNextAction, restrictions, hasSufficientBalance, and fallbackMessage: String? (human-readable bank contact/fallback shown when redirect URL cannot be opened; sourced from options response field 'fallback_message'; null if server does not supply it); effectiveLabel and isRestricted getters; fromJson factory
- [X] T009 [P] Create packages/shared-payment/lib/src/domain/models/checkout_result.dart — CheckoutResult class (succeeded: bool, paymentIntentId: String?, errorCode: String?); const constructor; no provider references
- [X] T010 [P] Create packages/shared-payment/lib/src/domain/models/recovery_record.dart — RecoveryRecord value type (intentId: String); static const kPrefsKey = 'payment_pending_intent_id'; static Future<RecoveryRecord?> read(); static Future<void> write(String intentId); static Future<void> clear()
- [X] T011 Create packages/shared-payment/lib/src/data/payment_api_client.dart — PaymentApiClient class wrapping Dio; getOptions (GET /api/v1/payment/options with params currency_code + estimated_amount_minor); createIntent (POST /api/v1/payment/intents with Idempotency-Key header); initiateAttempt (POST /api/v1/payment/intents/{id}/attempts with Idempotency-Key); getStatus (GET /api/v1/payment/intents/{id}/status)
- [X] T012 Create packages/shared-payment/lib/src/data/payment_repository.dart — PaymentRepository abstract interface; PaymentRepositoryImpl; define `final httpClientProvider = Provider<Dio>((ref) => throw UnimplementedError('Override httpClientProvider in ProviderScope'))` so consuming apps supply their Dio instance; paymentApiClientProvider watches httpClientProvider; paymentRepositoryProvider wraps api client; consuming apps override httpClientProvider in their ProviderScope with their existing `ref.watch(apiClientProvider).dio`
- [X] T013 [P] Create packages/shared-payment/lib/src/application/step_up_service.dart — abstract interface class StepUpService { Future<String> acquireToken({required String scope}); }; stepUpServiceProvider Provider throwing UnimplementedError if not overridden

**Checkpoint**: `flutter analyze packages/shared-payment/lib/src/domain/` passes; `flutter analyze packages/shared-payment/lib/src/data/` passes

---

## Phase 3: US1 — Single Shared Checkout Entry Point

**Goal**: One entry point (`showPaymentCheckout` / `PaymentCheckoutSheet`) that a business feature calls with a trusted reference and receives a typed `CheckoutResult`. Both apps import the shared library; neither maintains its own checkout.

**Independent Test**: Launch `PaymentCheckoutSheet` in a test `ProviderScope` with a mock `paymentRepositoryProvider` returning one option; verify `OptionsLoaded` state renders; confirm payment; verify `PaymentSucceeded` state returned as `CheckoutResult(succeeded: true)`.

- [X] T014 [US1] Create packages/shared-payment/lib/src/domain/checkout_state.dart — sealed class PaymentCheckoutState with all 14 states: LoadingOptions, EmptyOptions (no payment methods available; user can close), OptionsLoaded (options, intent?, selectedOption?), ConfirmingPayment (selectedOption, intent), AwaitingRedirect (redirectUrl: String, paymentIntentId: String, allowedReturnSchemes: List<String>; covers redirect/sdk_launch/app_switch flows — all park in this state), PollingStatus (paymentIntentId, lastKnownStatus), InsufficientFunds (requiredMinor, availableMinor), StepUpRequired (paymentIntentId), RecoveringUnknown (paymentIntentId, supportReference), RecoveringPending (paymentIntentId), RecoveringFulfilling (paymentIntentId), PaymentSucceeded (paymentIntentId, amountMinor, currencyCode), PaymentFailed (errorCode, message, canRetry), PaymentCancelled, PaymentExpired
- [X] T015 [US1] Create packages/shared-payment/lib/src/application/payment_notifier.dart — typedef PaymentCheckoutRequest = ({String purposeType, String purposeReference, String currencyCode, int estimatedAmountMinor, String? returnRoute}); class PaymentNotifier extends AsyncNotifier.autoDispose.family<PaymentCheckoutState, PaymentCheckoutRequest>; build() checks RecoveryRecord first, else fetches options — if options list is empty return EmptyOptions() (edge case: no payment methods); selectOption(); confirmPayment({String? stepUpToken}) creates intent + initiates attempt (idempotency keys as instance fields: _intentKey + _attemptKey, reused on retry; new _attemptKey only after definitive STEP_UP_REQUIRED); pollStatus() with 5×3s then up to 25×10s; cancelPayment(); _terminalState(); _extractErrorCode(); _disposed + _pollGeneration guards; _attemptSubmitted bool (blocks provider change); _intent cache (prevents duplicate createIntent)
- [X] T016 [P] [US1] Create packages/shared-payment/lib/src/presentation/payment_status_screen.dart — PaymentStatusScreen ConsumerWidget taking (state: PaymentCheckoutState, onClose: VoidCallback, onRetry: VoidCallback?); switch on PaymentSucceeded / PaymentFailed / PaymentCancelled / PaymentExpired; displays intent ID, amount, error message; retry button only when state.canRetry
- [X] T017 [US1] Create packages/shared-payment/lib/src/presentation/payment_checkout_sheet.dart — PaymentCheckoutSheet ConsumerStatefulWidget; PaymentCheckoutMode { sheet, page }; initState checks RecoveryRecord.read() and triggers recovery or options load; WidgetsBindingObserver for lifecycle; _buildState switch routing to sub-views for all 15 states including EmptyOptions; _openRedirect using url_launcher; _resultFromState returning CheckoutResult; after PaymentSucceeded: if request.returnRoute != null call GoRouter.of(context).go(returnRoute) instead of Navigator.pop() so the calling feature is navigated back correctly; showPaymentCheckout() convenience function using showModalBottomSheet; all sub-views (_LoadingView, _ErrorView, _OptionsView, _EmptyOptionsView, _RedirectView, _InsufficientFundsView, _StepUpView) as private classes; NEVER parse redirect URL params as payment proof; _EmptyOptionsView: title "No Payment Methods Available", body "No payment options are available for this transaction right now.", close button
- [X] T018 [US1] Update packages/shared-payment/lib/shared_payment.dart — export presentation/payment_checkout_sheet.dart (PaymentCheckoutSheet, PaymentCheckoutMode, showPaymentCheckout); export domain/models/checkout_result.dart (CheckoutResult); export application/payment_notifier.dart (PaymentCheckoutRequest); export application/step_up_service.dart (StepUpService, stepUpServiceProvider)
- [X] T019 [US1] Update wallet-ecosystem/apps/customer/pubspec.yaml — add shared_payment: {path: ../../packages/shared-payment}; run flutter pub get
- [X] T020 [P] [US1] Update wallet-ecosystem/apps/agent/pubspec.yaml — add shared_payment: {path: ../../packages/shared-payment}; run flutter pub get
- [X] T021 [US1] Update wallet-ecosystem/apps/customer/lib/ — replace all imports of local payment module (payment_notifier, payment_checkout_sheet, payment_status_screen, payment_option, payment_intent, payment_status) with import 'package:shared_payment/shared_payment.dart'; delete duplicate files: apps/customer/lib/modules/payment/domain/payment_notifier.dart, payment_intent.dart, payment_status.dart, payment_option.dart, presentation/payment_checkout_sheet.dart, data/payment_repository.dart, data/payment_api_client.dart; add httpClientProvider override to ProviderScope in apps/customer/lib/app.dart: `httpClientProvider.overrideWithValue(ref.watch(apiClientProvider).dio)` (resolves shared package DI)
- [X] T022 [US1] Update wallet-ecosystem/apps/agent/lib/ — replace all imports of local payment module with import 'package:shared_payment/shared_payment.dart'; delete duplicate files: apps/agent/lib/modules/payment/domain/payment_notifier.dart, payment_intent.dart, payment_status.dart, payment_option.dart, presentation/payment_checkout_sheet.dart, data/payment_repository.dart, data/payment_api_client.dart (verify no other agent module imports these before deleting); agent's AutoDisposeAsyncNotifier with init() pattern replaced by shared package's family pattern; add httpClientProvider override to ProviderScope in apps/agent/lib/app.dart: `httpClientProvider.overrideWithValue(ref.watch(apiClientProvider).dio)`
- [X] T023 [US1] Create wallet-ecosystem/apps/customer/lib/modules/payment/customer_step_up_service.dart — CustomerStepUpService implements StepUpService; acquireToken({required String scope}) calls POST /v1/auth/step-up/initiate then POST /v1/auth/step-up/verify; returns step_up_access_token — token is never stored or logged (// never persisted; discarded after forwarding to notifier); wire into customer ProviderScope in apps/customer/lib/app.dart: `stepUpServiceProvider.overrideWith((ref) => CustomerStepUpService(ref))`

**Checkpoint**: Both apps compile and `flutter analyze` shows 0 errors; `PaymentCheckoutSheet` can be launched from customer bill-payment flow

---

## Phase 4: US2 — Server-Driven Option Display

**Goal**: Payment options, fees, Wallet balance, and restrictions are displayed exactly as returned by the server. Restricted options are shown but cannot be selected. Fee breakdown visible before confirm.

**Independent Test**: Mock `getOptions` returning one Wallet option (balance: 50000, hasSufficientBalance: true), one redirect option with fee disclosure, and one option with a non-empty restrictions list. Verify all three render correctly; verify restricted option is disabled; verify fee breakdown shows when redirect option is selected.

- [X] T024 [P] [US2] Create packages/shared-payment/lib/src/presentation/widgets/wallet_balance_tile.dart — WalletBalanceTile ConsumerWidget taking (option: PaymentOption); displays wallet balance formatted in currency units; if hasSufficientBalance==false: show shortfall amount, disable selection styling, show "Insufficient balance" label; uses FeeDisclosure.totalPayableMinor for required amount
- [X] T025 [P] [US2] Create packages/shared-payment/lib/src/presentation/widgets/payment_option_tile.dart — PaymentOptionTile StatelessWidget taking (option: PaymentOption, isSelected: bool, onTap: VoidCallback?); shows provider icon, effectiveLabel, fee summary line; if option.isRestricted: show restriction reasons, disable onTap, grey out tile; if isWallet: delegates to WalletBalanceTile for balance row
- [X] T026 [P] [US2] Create packages/shared-payment/lib/src/presentation/widgets/payment_amount_summary.dart — PaymentAmountSummary StatelessWidget taking (option: PaymentOption, amountMinor: int, currencyCode: String); shows base amount, service fee, provider fee, total payable from option.feeDisclosure; total payable sourced from server (FeeDisclosure.totalPayableMinor), never computed locally
- [X] T027 [US2] Update _OptionsView in packages/shared-payment/lib/src/presentation/payment_checkout_sheet.dart — integrate PaymentOptionTile (replacing bare list); integrate WalletBalanceTile for Wallet option above the list; when an option is selected show PaymentAmountSummary below the list before the "Pay Now" button
- [X] T028 [US2] Update _InsufficientFundsView in packages/shared-payment/lib/src/presentation/payment_checkout_sheet.dart — display requiredMinor from PaymentOption.feeDisclosure.totalPayableMinor (server-computed); display availableMinor from PaymentOption.walletBalanceMinor; format amounts using option.currencyCode
- [X] T029 [P] [US2] Create wallet-ecosystem/apps/agent/lib/modules/payment/agent_step_up_service.dart — AgentStepUpService implements StepUpService; acquireToken({required String scope}) returns a stub mock token '// DEV_MOCK: pending E32 agent auth module wiring' (token is never stored or logged; // never persisted; discarded after forwarding to notifier); wire into agent ProviderScope in apps/agent/lib/app.dart: `stepUpServiceProvider.overrideWith((ref) => AgentStepUpService(ref))`
- [X] T030 [US2] Write packages/shared-payment/test/presentation/payment_checkout_sheet_test.dart — US2 tests: (a) mock options with wallet+bank+restricted; verify restricted option renders with reason and cannot be tapped; (b) verify Wallet balance shown; (c) verify fee breakdown visible when bank option selected; (d) verify InsufficientFunds state renders correct amounts from server values

**Checkpoint**: Options screen renders correctly for all option types; restricted options cannot be selected; fee breakdown visible before confirm

---

## Phase 5: US3 — Payment Execution via Typed Next Actions

**Goal**: Server-returned next action (wallet_pin, redirect, sdk_launch, app_switch) is handled correctly. Return from redirect triggers polling only — URL params are discarded. Provider replacement is blocked once attempt submitted.

**Independent Test**: Mock server returning `redirect` next action with `allowed_return_schemes: ['olekard']`; verify app opens URL; simulate app resume; verify `pollStatus` called and redirect URL params are NOT parsed; verify `CheckoutResult.succeeded` is only set after poll confirms `succeeded`.

- [X] T031 [US3] Create packages/shared-payment/lib/src/domain/models/next_action.dart — sealed class NextAction; WalletPinAction (const, no fields); RedirectAction (redirectUrl: String, allowedReturnSchemes: List<String>); SdkLaunchAction (sdkId: String, sdkParams: Map<String,String>); AppSwitchAction (deepLinkUrl: String, fallbackUrl: String?); ProviderUnavailableAction (reason: String); static NextAction fromJson(Map<String,dynamic> json) factory using switch on next_action_type
- [X] T032 [US3] Update PaymentNotifier.confirmPayment() in packages/shared-payment/lib/src/application/payment_notifier.dart — replace raw string `next_action_type` parsing with NextAction.fromJson(attemptResult); switch on NextAction type: WalletPinAction → StepUpRequired state; RedirectAction → persist RecoveryRecord, set AwaitingRedirect(redirectUrl, paymentIntentId, allowedReturnSchemes: action.allowedReturnSchemes); SdkLaunchAction → set AwaitingRedirect(redirectUrl: action.sdkId, paymentIntentId, allowedReturnSchemes: []); AppSwitchAction → set AwaitingRedirect(redirectUrl: action.deepLinkUrl, paymentIntentId, allowedReturnSchemes: []); ProviderUnavailableAction → PaymentFailed(canRetry:false) + return to options; set _attemptSubmitted=true after successful attempt initiation
- [X] T033 [US3] Update PaymentNotifier.selectOption() in packages/shared-payment/lib/src/application/payment_notifier.dart — add guard: if (_attemptSubmitted) return; — prevents provider replacement after attempt submitted per FR-011
- [X] T034 [US3] Update AwaitingRedirect state handling in packages/shared-payment/lib/src/presentation/payment_checkout_sheet.dart — in didChangeAppLifecycleState(resumed): read current AwaitingRedirect state; check if incoming deep-link scheme (from app-link or returnRoute) is in state.allowedReturnSchemes (Flutter has no "return URL" — the allowlist validates the incoming app deep-link scheme, not the bank-side URL itself; this is the Flutter-specific interpretation of FR-006); if scheme present in allowlist OR allowedReturnSchemes is empty (sdk_launch/app_switch flows): trigger _notifier.pollStatus() — NOTE: scheme match is only a trigger for polling, NOT payment proof (FR-007 still applies; // dual protection: scheme-check gates polling start; poll result is the only payment proof); if scheme not in allowlist: show warning and do not poll; NEVER parse any URL query parameters as payment confirmation
- [X] T035 [US3] Update _StepUpView in packages/shared-payment/lib/src/presentation/payment_checkout_sheet.dart — replace stub button with call to ref.read(stepUpServiceProvider).acquireToken(scope: 'payment.initiate'); on success: call _notifier.confirmPayment(stepUpToken: token); on error: show error message; _busy guard prevents double-tap; error displayed inline
- [X] T036 [P] [US3] Add SdkLaunchAction + AppSwitchAction views in packages/shared-payment/lib/src/presentation/payment_checkout_sheet.dart — both flow through AwaitingRedirect state (see T032); SdkLaunchAction: _LoadingView(message: 'Launching bank app…'), attempt to launch via url_launcher (stub for now), if unavailable show fallback; AppSwitchAction: try url_launcher with deepLinkUrl, if canLaunchUrl returns false show _FallbackView using option.fallbackMessage (from PaymentOption field added in T008) if non-null, else generic "Please open your bank app manually"; both resume with pollStatus on foreground lifecycle event
- [X] T037 [US3] Add ProviderUnavailableAction handling in packages/shared-payment/lib/src/presentation/payment_checkout_sheet.dart — when notifier emits PaymentFailed from ProviderUnavailableAction: show provider-unavailable error variant with "Try another payment method" button that invalidates provider and returns to OptionsLoaded (not full reset)
- [X] T038 [P] [US3] Write packages/shared-payment/test/domain/next_action_test.dart — unit tests: fromJson correctly parses all 5 types; unknown type maps to ProviderUnavailableAction; RedirectAction.allowedReturnSchemes populated from allowed_return_schemes field; missing fields handled gracefully
- [X] T039 [US3] Write payment_notifier_test.dart US3 section in packages/shared-payment/test/application/payment_notifier_test.dart — (a) confirmPayment with redirect action → RecoveryRecord written to SharedPreferences and AwaitingRedirect state has allowedReturnSchemes populated; (b) after attempt submitted, selectOption with different providerCode → state unchanged (provider replacement blocked per FR-011); (c) STEP_UP_REQUIRED → _attemptKey rotated (new UUID); (d) ProviderUnavailableAction → options state restored; (e) SC-03 security invariant: verify PaymentApiClient.createIntent() POST body contains ONLY purpose_type + purpose_reference + currency_code — assert amount_minor is NOT present in request body (tampered total cannot charge); (f) C5 edge case: first confirmPayment() call throws network timeout → second call reuses same _intentKey (idempotency preserved on retry)

**Checkpoint**: All 4 next action types handled; redirect URL params never treated as proof; provider locked after submission; step-up token never stored

---

## Phase 6: US4 — Restart and Network-Loss Recovery

**Goal**: App restart or network loss mid-payment shows the correct recovery state — never a false success, never options. Three distinct recovery states: unknown / pending / fulfilling.

**Independent Test**: Inject `payment_pending_intent_id` into SharedPreferences; create `PaymentCheckoutSheet` in test; verify initial state is `RecoveringPending` (not `LoadingOptions`); mock poll returning `processing` status; verify state stays in `RecoveringPending`; mock poll returning `succeeded`; verify `PaymentSucceeded` state and SharedPreferences key cleared.

- [X] T040 [US4] Update PaymentNotifier.build() in packages/shared-payment/lib/src/application/payment_notifier.dart — before fetching options: call RecoveryRecord.read(); if pending intentId found: call _startRecovery(intentId) and return early; otherwise: fetch options normally
- [X] T041 [US4] Implement PaymentNotifier._startRecovery() in packages/shared-payment/lib/src/application/payment_notifier.dart — immediately set state to RecoveringPending(intentId); call _pollForRecovery(intentId) which is pollStatus with recovery context flag; _pollForRecovery maps non-terminal status → recovery state (pending/processing → RecoveringPending; awaitingAction/requiresConfirmation → RecoveringUnknown; fulfilling → RecoveringFulfilling); terminal → terminal state + clear RecoveryRecord
- [X] T042 [US4] Update PaymentNotifier.pollStatus() in packages/shared-payment/lib/src/application/payment_notifier.dart — add isRecovery: bool = false parameter; when isRecovery=true: update state using _statusToRecoveryState() instead of PollingStatus; on poll timeout (30 polls): if recovery → stay in last recovery state; if normal → stay in PollingStatus(processing); network error during poll → continue loop silently (NFR connectivity resilience)
- [X] T043 [US4] Implement _statusToRecoveryState() in packages/shared-payment/lib/src/application/payment_notifier.dart — maps PaymentIntentStatus → RecoveringUnknown(paymentIntentId, supportReference: 'REF-${intentId.substring(0,8)}')/RecoveringPending/RecoveringFulfilling; terminal statuses call _terminalState() directly
- [X] T044 [US4] Update PaymentCheckoutSheet initState in packages/shared-payment/lib/src/presentation/payment_checkout_sheet.dart — on cold start (RecoveringUnknown/RecoveringPending/RecoveringFulfilling): render correct recovery sub-view immediately without showing options; lifecycle observer still active for foreground resume
- [X] T045 [US4] Add recovery state sub-views in packages/shared-payment/lib/src/presentation/payment_checkout_sheet.dart — _RecoveringUnknownView: title "Outcome Unknown", body "Do not retry — we are checking with your bank", NO retry button, support reference displayed; _RecoveringPendingView: title "Payment Processing", body "Your payment is being processed. This may take a moment."; _RecoveringFulfilling View: title "Payment Confirmed", body "Crediting your wallet — this usually takes under a minute."
- [X] T046 [P] [US4] Write payment_notifier_test.dart US4 section in packages/shared-payment/test/application/payment_notifier_test.dart — (a) cold start with pending intent → RecoveringPending initial state; (b) poll 30× without terminal → stays in RecoveringPending not PaymentExpired; (c) poll returns succeeded → PaymentSucceeded + RecoveryRecord cleared; (d) poll network error × 3 then success → recovers and resolves; (e) awaitingAction status → RecoveringUnknown not RecoveringPending
- [X] T047 [US4] Write payment_checkout_sheet_test.dart US4 section in packages/shared-payment/test/presentation/payment_checkout_sheet_test.dart — (a) SharedPreferences has pending intent → renders RecoveringPending not OptionsLoaded; (b) RecoveringUnknown view has NO retry button; (c) RecoveringFulfilling view shows "crediting your wallet" message; (d) terminal poll clears SharedPreferences key

**Checkpoint**: Cold start shows recovery state; 3 recovery states display distinct messages; RecoveryRecord cleared on terminal; network loss during poll does not crash

---

## Phase 7: Polish & Cross-Cutting Concerns

- [ ] T048 [P] Run flutter analyze --no-fatal-infos on packages/shared-payment/ — must report 0 errors
- [ ] T049 [P] Run flutter analyze --no-fatal-infos on wallet-ecosystem/apps/customer/ — must report 0 errors after migration
- [ ] T050 [P] Run flutter analyze --no-fatal-infos on wallet-ecosystem/apps/agent/ — must report 0 errors after migration
- [ ] T051 Run flutter test test/ from packages/shared-payment/ — all unit and widget tests pass
- [X] T052 [P] Write packages/shared-payment/test/domain/checkout_state_test.dart — verify CheckoutResult has no provider_reference_id field; verify CheckoutResult.succeeded is false for PaymentFailed/Cancelled/Expired; verify PaymentSucceeded.amountMinor matches server value (not local estimate); C4 page-mode: mount PaymentCheckoutSheet(mode: PaymentCheckoutMode.page) and verify it renders inside a Scaffold rather than a bottom sheet container (covers FR-015)
- [X] T053 [P] Write redirect non-proof test in packages/shared-payment/test/presentation/payment_checkout_sheet_test.dart — mock AwaitingRedirect state + simulate app lifecycle resume; verify notifier.pollStatus() called; verify no state transition to PaymentSucceeded until poll returns succeeded; verify EmptyOptions state renders _EmptyOptionsView with close button (edge case coverage)
- [ ] T054 Run quickstart.md SC-01–SC-08 validation scenarios in Docker environment — record timestamps for SC-01 (wallet payment end-to-end) and SC-02 (post-redirect outcome); assert SC-01 completes in <60s and SC-02 in <30s of app return; log wall-clock times in test output (Docker-gated)
- [X] T055 [P] Add structured logging to packages/shared-payment/lib/src/application/payment_notifier.dart — emit a structured log line at each state transition using debugPrint or a logger abstraction: log intentId (when known) + fromState + toState + timestamp (ISO 8601 UTC); financial event checkpoints — intent-created, attempt-submitted, outcome-confirmed — must include intentId, providerCode (attempt only), and status; PIN/step-up token MUST NOT appear in any log line (NFR-004 + constitution P13)

---

## Dependencies & Execution Order

### Phase Dependencies

- **Phase 1 (Setup)**: No dependencies — start immediately
- **Phase 2 (Foundational)**: Depends on Phase 1 — BLOCKS all user story phases
- **Phase 3 (US1)**: Depends on Phase 2; T014 → T015 → T016+T017 → T018 → T019+T020 → T021+T022 → T023
- **Phase 4 (US2)**: Depends on Phase 3 (checkout sheet must exist); T024–T026 parallel → T027+T028 → T029+T030
- **Phase 5 (US3)**: Depends on Phase 3; T031 → T032+T033 → T034+T035+T036+T037 → T038+T039
- **Phase 6 (US4)**: Depends on Phase 3; T040+T041 → T042+T043 → T044+T045 → T046+T047
- **Phase 7 (Polish)**: Depends on Phases 3–6; T048–T051 → T052+T053+T055 → T054

### User Story Dependencies

- **US1 (P1)**: Can start after Phase 2 — MVP entry point
- **US2 (P1)**: Can start after US1 checkout sheet scaffolding (T017) — display enhancements
- **US3 (P1)**: Can start after US1 notifier (T015) — next action routing
- **US4 (P1)**: Can start after US1 notifier (T015) — recovery state additions

US2, US3, US4 can proceed in parallel once T015 and T017 are complete (different files).

### Parallel Opportunities Per Story

**US1**: T016 || T019+T020 (after T018) || T021 || T022

**US2**: T024 || T025 || T026 (three widget files)

**US3**: T036 || T038 (separate files while T034+T035 in progress)

**US4**: T046 || T047 (test files can be written in parallel once T044+T045 done)

---

## Parallel Execution Examples

```bash
# Phase 2 — domain models in parallel:
# Worker A: T006 payment_status.dart
# Worker B: T007 payment_intent.dart  [P]
# Worker C: T008 payment_option.dart  [P]
# Worker D: T009 checkout_result.dart [P]
# Worker E: T010 recovery_record.dart [P]
# → T011 api_client (after T006-T008), T012 repository (after T011)

# Phase 5 (US3) — after T031 (NextAction created):
# Worker A: T032+T033 notifier updates
# Worker B: T038 next_action_test.dart  [P]

# Phase 6 (US4) — after T040+T041:
# Worker A: T042+T043 notifier recovery logic
# Worker B: T046+T047 tests  [P]
```

---

## Implementation Strategy

### MVP (US1 Only — T001–T023)

1. Complete Phase 1 (scaffold)
2. Complete Phase 2 (domain models + data layer)
3. Complete Phase 3 US1 (entry point, notifier, checkout sheet, both apps migrated)
4. **STOP and VALIDATE**: Run `flutter analyze` on all three projects; test basic wallet payment end-to-end

### Incremental Delivery

1. Setup + Foundational → library compiles
2. US1 → both apps use shared library; basic wallet flow works
3. US2 → fee breakdown, restrictions, wallet balance visible
4. US3 → redirect, step-up, sdk, app-switch all handled; provider lock active
5. US4 → recovery works on cold start; network resilience confirmed
6. Polish → 0 analyzer errors; all tests pass; quickstart SC-01–SC-08 verified

---

## Notes

- T021 and T022 (migration tasks) require deleting existing local payment module files — verify no other module in each app imports those files before deleting
- T023 and T029 (StepUpService implementations) are app-specific; the shared package remains agnostic
- T054 is Docker-gated; T055 structured logging is implementation work (not Docker-gated); all other tasks can complete without Docker
- The agent app's `AutoDisposeAsyncNotifier` with `init()` pattern is replaced by the family pattern in T022 — this is a breaking change within the agent module but does not affect the agent app's feature behaviour
- `_kPendingPaymentIntentId` constant is unified through `RecoveryRecord.kPrefsKey` — the same key value ('payment_pending_intent_id') ensures existing recovery records survive the migration
