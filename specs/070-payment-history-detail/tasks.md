# Tasks: P23 — Payment History, Detail and Transaction Management

**Input**: Design documents from `specs/070-payment-history-detail/`

**Prerequisites**: plan.md ✓ spec.md ✓ research.md ✓ data-model.md ✓ contracts/ ✓ quickstart.md ✓

**Format**: `[ID] [P?] [Story] Description with file path`
- **[P]**: Parallelisable (different files, no unresolved dependencies)
- **[USn]**: User story label (US1–US7; absent in Setup/Foundational/Polish phases)

---

## Phase 1: Setup

**Purpose**: Add missing dependency; register route path constants in both apps.

- [X] T001 Add `flutter_windowmanager: ^0.2.0` to `apps/customer/pubspec.yaml` (screenshot guard for receipt screen — R-008)
- [X] T002 [P] Add `flutter_windowmanager: ^0.2.0` to `apps/agent/pubspec.yaml`
- [X] T003 Create route path constants class `PaymentRoutes` in `apps/customer/lib/router/payment_routes.dart` (exports `historyPath`, `detailPath`, `receiptPath` string constants — R-009)
- [X] T004 [P] Create route path constants class `PaymentRoutes` in `apps/agent/lib/router/payment_routes.dart`
- [X] T005 Register `PaymentRoutes.historyPath`, `detailPath`, `receiptPath` as GoRouter shell routes in `apps/customer/lib/router/app_router.dart`
- [X] T006 [P] Register `PaymentRoutes.historyPath`, `detailPath`, `receiptPath` as GoRouter shell routes in `apps/agent/lib/router/app_router.dart`

**Checkpoint**: Both apps build with `flutter_windowmanager` added and routes registered.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Upgrade domain entities, repository interfaces, DTOs, and shared cache infrastructure. Must complete before any user story work begins.

**⚠ CRITICAL**: No user story work can begin until this phase is complete.

### Domain Entities

- [X] T007 Upgrade `Transaction` entity to `PaymentHistoryItem` in `apps/customer/lib/modules/transactions/domain/entities/transaction.dart` — add all P19 fields: `captureStatus`, `accountingStatus`, `serviceStatus`, `refundStatus`, `refundEligible`, `domainDeepLink`, `receiptAvailable`, `providerName`, `direction` (see data-model.md §1)
- [X] T008 [P] Upgrade `AgentTransaction` entity to `PaymentHistoryItem` in `apps/agent/lib/modules/transactions/domain/entities/agent_transaction.dart` — same fields as T007; agent-specific label mapping noted
- [X] T009 Create `PaymentHistoryFilter` value object in `apps/customer/lib/modules/transactions/domain/entities/payment_history_filter.dart` (fields: `purposeType?`, `status?`, `dateFrom?`, `dateTo?`, `currencyCode?`, `page`, `perPage`; equality by fields; `page` resets to 1 on filter change)
- [X] T010 [P] Create `PaymentHistoryFilter` value object in `apps/agent/lib/modules/transactions/domain/entities/payment_history_filter.dart`
- [X] T011 Create `PaymentReceipt`, `ReceiptLineItem`, and `ReceiptDownloadToken` entities in `apps/customer/lib/modules/transactions/domain/entities/payment_receipt.dart` (see data-model.md §4–6; `ReceiptDownloadToken` must not be serialised to storage)
- [X] T012 [P] Create same receipt entities in `apps/agent/lib/modules/transactions/domain/entities/payment_receipt.dart`
- [X] T013 Create `DomainDeepLink` sealed class in `apps/customer/lib/modules/transactions/domain/entities/domain_deep_link.dart` (sealed: `AvailableLink(url, label?)` / `UnavailableLink(reason)`)
- [X] T014 [P] Create `DomainDeepLink` sealed class in `apps/agent/lib/modules/transactions/domain/entities/domain_deep_link.dart`

### Repository Interface Upgrades

- [X] T015 Upgrade `TransactionRepository` abstract class in `apps/customer/lib/modules/transactions/domain/repositories/transaction_repository.dart` — add: `fetchHistory(PaymentHistoryFilter)`, `fetchDetail(String intentId)`, `fetchReceipt(String intentId)`, `requestDownloadToken(String intentId)`, `submitRefund(RefundRequest)`, `createSupportTicket(SupportTicketDraft)`
- [X] T016 [P] Upgrade `TransactionRepository` abstract class in `apps/agent/lib/modules/transactions/domain/repositories/transaction_repository.dart` (same methods)

### Data Transfer Objects

- [X] T017 Upgrade `TransactionDto` in `apps/customer/lib/modules/transactions/data/models/transaction_dto.dart` — map all `PaymentHistoryItem` fields from P19 JSON; add `fromJson` factory for `captureStatus`, `accountingStatus`, `serviceStatus`, `refundStatus`, `refundEligible`, `domainDeepLink` (handle string vs `{status, reason}` object form)
- [X] T018 [P] Upgrade `TransactionDto` in `apps/agent/lib/modules/transactions/data/models/transaction_dto.dart`
- [X] T019 Create `PaymentHistoryFilterDto` (query param serialiser) in `apps/customer/lib/modules/transactions/data/models/payment_history_filter_dto.dart`
- [X] T020 [P] Create `PaymentHistoryFilterDto` in `apps/agent/lib/modules/transactions/data/models/payment_history_filter_dto.dart`
- [X] T021 Create `ReceiptDto` in `apps/customer/lib/modules/transactions/data/models/receipt_dto.dart` — maps `PaymentReceipt` + `ReceiptLineItem` from P19 receipt JSON
- [X] T022 [P] Create `ReceiptDto` in `apps/agent/lib/modules/transactions/data/models/receipt_dto.dart`

### Cache Infrastructure

- [X] T023 Create `PaymentCacheStore` in `apps/customer/lib/modules/transactions/infrastructure/payment_cache_store.dart` — wraps `flutter_secure_storage`; methods: `writeHistoryPage(userId, page)`, `readHistoryPage(userId)`, `writeDetail(intentId, item)`, `readDetail(intentId)`, `clearAll()` (deletes all `payment_` keys); stores `PaymentCacheEntry` JSON (includes `cachedAt`, `staleAfterSeconds`)
- [X] T024 [P] Create `PaymentCacheStore` in `apps/agent/lib/modules/transactions/infrastructure/payment_cache_store.dart`

### Repository Implementation Upgrades

- [X] T025 Upgrade `TransactionRepositoryImpl` in `apps/customer/lib/modules/transactions/data/repositories/transaction_repository_impl.dart` — implement `fetchHistory()` calling `GET /v1/payment/history` with `PaymentHistoryFilter` query params; write result to `PaymentCacheStore`; on `DioException` network failure, read stale cache and return with `isStale: true` flag; implement `fetchDetail(intentId)`
- [X] T026 [P] Upgrade `TransactionRepositoryImpl` in `apps/agent/lib/modules/transactions/data/repositories/transaction_repository_impl.dart` (same pattern)

**Checkpoint**: Domain entities, repository interfaces, DTOs, and cache store all compile. No user story UI work yet.

---

## Phase 3: User Story 1 — Filterable Payment Activity Feed (P1) ★ MVP

**Goal**: Replace old transaction list with P19-backed filterable, paginated activity feed; offline stale cache with banner.

**Independent Test**: SC-01 (list loads), SC-02 (filter applied), SC-03 (offline stale served with banner).

### Application Layer

- [X] T027 [US1] Create `PaymentHistoryNotifier` (Riverpod `FamilyAsyncNotifier<PaymentHistoryState, String>`) in `apps/customer/lib/modules/transactions/application/payment_history_notifier.dart` — implement `build(userId)`, `applyFilter(filter)`, `loadNextPage()`, `refresh()`; read from `PaymentCacheStore` on build; serve stale on network failure; state sealed class: `PaymentHistoryLoading`, `PaymentHistoryLoaded(items, filter, pagination, isStale)`, `PaymentHistoryEmpty`, `PaymentHistoryRefreshing`, `PaymentHistoryError(isOffline, staleItems?)`
- [X] T028 [P] [US1] Create `PaymentHistoryNotifier` in `apps/agent/lib/modules/transactions/application/payment_history_notifier.dart`

### Presentation Layer

- [X] T029 [US1] Create `HistoryFilterBar` stateless widget in `apps/customer/lib/modules/transactions/presentation/widgets/history_filter_bar.dart` — renders status chip row, date picker, purpose-type selector; callback `onFilterChanged(PaymentHistoryFilter)`; each chip min 44×44 dp; `Semantics` labels on each chip
- [X] T030 [US1] Upgrade `TransactionListScreen` in `apps/customer/lib/modules/transactions/presentation/screens/transaction_list_screen.dart` — replace old list with P19-backed `PaymentHistoryLoaded` rendering; add `HistoryFilterBar`; add pagination (`loadNextPage` on scroll-to-end); add stale banner with `Semantics(liveRegion: true)` when `isStale: true`; add offline empty state (no spinner for offline+no-cache)
- [X] T031 [P] [US1] Upgrade `TransactionListScreen` in `apps/agent/lib/modules/transactions/presentation/screens/transaction_list_screen.dart`
- [X] T032 [US1] Update `transaction_providers.dart` in `apps/customer/lib/modules/transactions/presentation/transaction_providers.dart` — expose `paymentHistoryProvider` family; update existing provider references
- [X] T033 [P] [US1] Update `transaction_providers.dart` in `apps/agent/lib/modules/transactions/presentation/transaction_providers.dart`

### Tests (US1)

- [X] T034 [US1] Write unit tests for `PaymentHistoryNotifier` in `apps/customer/test/modules/transactions/application/payment_history_notifier_test.dart` — covers SC-01 (3 items loaded, `isStale: false`), SC-02 (applyFilter transitions to Loading then Loaded with filtered items), SC-03 (network failure + cached data → `PaymentHistoryError(isOffline: true, staleItems: [...])`)
- [X] T035 [US1] Write widget tests for `TransactionListScreen` in `apps/customer/test/modules/transactions/presentation/payment_history_screen_test.dart` — verify 3 tiles render; stale banner appears when `isStale: true`; filter chips call `applyFilter`; each tile touch target ≥ 44dp
- [X] T084 [P] [US1] Write unit tests for agent `PaymentHistoryNotifier` in `apps/agent/test/modules/transactions/application/payment_history_notifier_test.dart` — same scenarios as T034; verify agent-scoped JWT produces agent-scoped items (mock repository returns agent items); stale cache eviction mirrors customer behaviour

**Checkpoint**: Customer activity feed renders and filters; offline stale banner works. Agent mirrors customer.

---

## Phase 4: User Story 2 — Detailed Transaction View with Status Distinction (P1)

**Goal**: Transaction detail shows 4 independent status rows (capture, accounting, service, refund), domain deep-link tile, receipt button; all accessible.

**Independent Test**: SC-04 (4 status rows shown), SC-05 (available deep link tappable; unavailable greyed).

### Domain Objects

- [X] T036 [US2] Create `PaymentStatusRows` value object in `apps/customer/lib/modules/transactions/domain/entities/payment_status_rows.dart` (fields: `captureStatus`, `accountingStatus`, `serviceStatus`, `refundStatus?`)
- [X] T037 [P] [US2] Create `PaymentStatusRows` in `apps/agent/lib/modules/transactions/domain/entities/payment_status_rows.dart`

### Application Layer

- [X] T038 [US2] Create `PaymentDetailNotifier` (`FamilyAsyncNotifier<PaymentDetailState, String intentId>`) in `apps/customer/lib/modules/transactions/application/payment_detail_notifier.dart` — implement `build(intentId)`, `initiateRefund()`, `openSupportDraft()`, `cancel()`; state sealed class: `PaymentDetailLoading`, `PaymentDetailLoaded(item, statusRows, deepLink?)`, `PaymentDetailRefundConfirm`, `PaymentDetailRefundSubmitting`, `PaymentDetailRefundSubmitted`, `PaymentDetailSupportDraft`, `PaymentDetailError` (see contracts/payment-detail-notifier.md)
- [X] T039 [P] [US2] Create `PaymentDetailNotifier` in `apps/agent/lib/modules/transactions/application/payment_detail_notifier.dart`

### Widgets

- [X] T040 [US2] Create `PaymentStatusRow` widget in `apps/customer/lib/modules/transactions/presentation/widgets/payment_status_row.dart` — renders a single labelled status row; `Semantics(label: "${label}: ${value}")` node; min 44dp row height; supports 2× font scale (no fixed height on label text)
- [X] T041 [US2] Create `DomainDeepLinkTile` widget in `apps/customer/lib/modules/transactions/presentation/widgets/domain_deep_link_tile.dart` — renders tappable tile when `deepLink is AvailableLink` (calls `context.go(url)` for in-app path; catches `GoException` if the route is unregistered and falls back to "Domain unavailable" inline message; calls `launchUrl` for external https URLs); greyed non-interactive tile when `deepLink is UnavailableLink`; accessible: `Semantics(button: true)` when tappable; `Semantics(button: false)` + disabled label when unavailable or unregistered

### Screen Upgrade

- [X] T042 [US2] Upgrade `TransactionDetailScreen` in `apps/customer/lib/modules/transactions/presentation/screens/transaction_detail_screen.dart` — consume `paymentDetailProvider(intentId)`; render 4 `PaymentStatusRow` tiles (capture, accounting, service, refund — hide refund row when `refundStatus == null`); render `DomainDeepLinkTile` when `deepLink != null`; render "View Receipt" button when `item.receiptAvailable`; mask `providerReference` (last 4 chars + asterisks); `refundEligible` drives refund button visibility (see US4); all rows reflow at 2× font scale
- [X] T043 [P] [US2] Upgrade `TransactionDetailScreen` in `apps/agent/lib/modules/transactions/presentation/screens/transaction_detail_screen.dart`
- [X] T044 [US2] Update `transaction_providers.dart` in `apps/customer/lib/modules/transactions/presentation/transaction_providers.dart` — expose `paymentDetailProvider` family

### Tests (US2)

- [X] T045 [US2] Write unit tests for `PaymentDetailNotifier` in `apps/customer/test/modules/transactions/application/payment_detail_notifier_test.dart` — covers: `build()` → `PaymentDetailLoaded` with 4 status rows; 403 response → `PaymentDetailError`; `initiateRefund()` on `refundEligible=false` stays in Loaded; deep link both cases
- [X] T046 [US2] Write widget tests for `TransactionDetailScreen` in `apps/customer/test/modules/transactions/presentation/payment_detail_screen_test.dart` — covers SC-04 (4 rows present), SC-05 (tappable vs greyed deep link), `providerReference` masked, receipt button present when `receiptAvailable=true`
- [X] T085 [P] [US2] Write unit tests for agent `PaymentDetailNotifier` in `apps/agent/test/modules/transactions/application/payment_detail_notifier_test.dart` — same scenarios as T045; verify 403 response → `PaymentDetailError`; unregistered deep link → `UnavailableLink` fallback; `GoException` caught and shown as "Domain unavailable"

**Checkpoint**: Detail screen shows 4 status rows and deep links independently of US1.

---

## Phase 5: User Story 3 — Safe Receipt Access and Download (P1)

**Goal**: Receipt screen with screenshot guard; single-use download token opened immediately; cross-account 403 → denied state.

**Independent Test**: SC-06 (download token used once then discarded), SC-07 (cross-account 403 → denied).

### Repository Implementation

- [X] T047 [US3] Add `fetchReceipt(String intentId)` and `requestDownloadToken(String intentId)` implementations to `TransactionRepositoryImpl` in `apps/customer/lib/modules/transactions/data/repositories/transaction_repository_impl.dart` — map `GET /v1/payment/receipts/{intentId}` → `PaymentReceipt`; map `POST /v1/payment/receipts/{intentId}/download-token` → `ReceiptDownloadToken`; 403 → rethrow as `AccessDeniedException`
- [X] T048 [P] [US3] Same additions to `TransactionRepositoryImpl` in `apps/agent/lib/modules/transactions/data/repositories/transaction_repository_impl.dart`

### Application Layer

- [X] T049 [US3] Create `ReceiptAccessNotifier` (`FamilyAsyncNotifier<ReceiptAccessState, String intentId>`) in `apps/customer/lib/modules/transactions/application/receipt_access_notifier.dart` — implement `build(intentId)`, `requestDownload()`, `onDownloadLaunched()`; state sealed class: `ReceiptAccessLoading`, `ReceiptReady(receipt)`, `ReceiptDownloadRequested`, `ReceiptDownloadReady(receipt, downloadUrl, expiresAt)` — `downloadUrl` MUST NOT be written to any storage; `ReceiptDownloadError`, `ReceiptAccessDenied` (see contracts/receipt-access-notifier.md)
- [X] T050 [P] [US3] Create `ReceiptAccessNotifier` in `apps/agent/lib/modules/transactions/application/receipt_access_notifier.dart`

### Screen

- [X] T051 [US3] Create `ReceiptScreen` in `apps/customer/lib/modules/transactions/presentation/screens/receipt_screen.dart` — calls `WindowManager.instance.setPreventCapture(true)` on `initState` and `false` on `dispose`; renders `ReceiptReady` receipt fields (reference, issue date, line items with `amountMinor` formatted, refund summary); "Download" button calls `notifier.requestDownload()`; on `ReceiptDownloadReady`: calls `url_launcher.launchUrl(Uri.parse(downloadUrl))` — if `launchUrl()` returns false (no PDF viewer installed), show "No PDF viewer installed — download link copied to clipboard" SnackBar and copy the URL via `Clipboard.setData`; then calls `notifier.onDownloadLaunched()`; `ReceiptAccessDenied`: renders "Access Denied" with reason text; `providerReference` masked (last 4 chars); all text reflows at 2× font scale; min 44dp touch target on Download
- [X] T052 [P] [US3] Create `ReceiptScreen` in `apps/agent/lib/modules/transactions/presentation/screens/receipt_screen.dart`
- [X] T053 [US3] Update `transaction_providers.dart` in `apps/customer/lib/modules/transactions/presentation/transaction_providers.dart` — expose `receiptAccessProvider` family; add `ReceiptScreen` route wiring

### Tests (US3)

- [X] T054 [US3] Write unit tests for `ReceiptAccessNotifier` in `apps/customer/test/modules/transactions/application/receipt_access_notifier_test.dart` — covers: SC-06 (`requestDownload()` → `ReceiptDownloadReady`; `onDownloadLaunched()` resets to `ReceiptReady`; verify `downloadUrl` not in `PaymentCacheStore`; assert that no captured log line contains the `downloadUrl` string — mock the Dio response logger if needed), SC-07 (403 → `ReceiptAccessDenied`), expired token 410 → `ReceiptDownloadError`
- [X] T055 [US3] Write widget tests for `ReceiptScreen` in `apps/customer/test/modules/transactions/presentation/receipt_screen_test.dart` — verify receipt fields render; download button calls `requestDownload()`; `ReceiptAccessDenied` renders error message; `providerReference` is masked
- [X] T086 [P] [US3] Write unit tests for agent `ReceiptAccessNotifier` in `apps/agent/test/modules/transactions/application/receipt_access_notifier_test.dart` — same scenarios as T054; verify 403 → `ReceiptAccessDenied`; token not cached; no `downloadUrl` in log output
- [X] T087 [US3] Implement account-switch cancellation in `ReceiptAccessNotifier` in `apps/customer/lib/modules/transactions/application/receipt_access_notifier.dart` and `apps/agent/lib/modules/transactions/application/receipt_access_notifier.dart` — hold a `CancelToken` for any in-flight `requestDownloadToken` Dio call; on E03 account-switch event (via `SessionEventBus.onAccountSwitch`), cancel the token, discard any downloaded bytes in state, and transition to `ReceiptAccessLoading`; `PaymentCacheStore.clearAll()` is called separately by US6 wiring

**Checkpoint**: Receipt screen with screenshot guard works independently; cross-account denial handled; in-flight download cancelled on account switch.

---

## Phase 6: User Story 6 — Private Cache Security and Account-Switch Eviction (P1)

**Goal**: Full cache eviction (`payment_` prefix) on logout and account switch; encrypted at rest via `flutter_secure_storage`.

**Independent Test**: SC-10 (logout → all `payment_` keys cleared from secure storage).

### Cache Eviction

- [X] T056 [US6] Implement `clearAll()` in `PaymentCacheStore` in `apps/customer/lib/modules/transactions/infrastructure/payment_cache_store.dart` — reads all keys from `flutter_secure_storage`, filters keys starting with `payment_`, deletes each; verify no non-payment keys affected
- [X] T057 [P] [US6] Same `clearAll()` in `apps/agent/lib/modules/transactions/infrastructure/payment_cache_store.dart`

### Logout Event Wiring

- [X] T058 [US6] Subscribe `PaymentCacheStore.clearAll()` to the E03 logout event bus in `apps/customer/lib/modules/transactions/infrastructure/payment_cache_store.dart` — listen to `SessionEventBus.onLogout` stream; call `clearAll()` synchronously within the session-clear operation; also call on account-switch event
- [X] T059 [P] [US6] Same event bus wiring in `apps/agent/lib/modules/transactions/infrastructure/payment_cache_store.dart`

### Tests (US6)

- [X] T060 [US6] Write unit tests for `PaymentCacheStore` eviction in `apps/customer/test/modules/transactions/infrastructure/payment_cache_store_test.dart` — covers SC-10: seed keys `payment_history_user1`, `payment_detail_intent-abc`, `payment_detail_intent-def` + a non-payment key; fire logout event; verify all `payment_` keys absent; verify non-payment key unaffected
- [X] T061 [P] [US6] Mirror eviction tests in `apps/agent/test/modules/transactions/infrastructure/payment_cache_store_test.dart`

**Checkpoint**: Logout clears all payment cache; verified by unit test. Encryption-at-rest provided by `flutter_secure_storage` platform default (no extra code needed beyond using the library).

---

## Phase 7: User Story 4 — Refund Request via Eligibility Flow (P2)

**Goal**: Refund button conditional on server-returned `refundEligible`; request routes to E62; pending vs confirmed distinction enforced.

**Independent Test**: SC-08 (`refundEligible=true` → button shown; `=false` → button absent; submit → `refundStatus=pending`).

### Repository

- [X] T062 [US4] Add `submitRefund(RefundRequest)` implementation to `TransactionRepositoryImpl` in `apps/customer/lib/modules/transactions/data/repositories/transaction_repository_impl.dart` — POST to E62 `POST /v1/payment/refunds`; body: `{payment_intent_id, reason, amount_minor?}`; idempotency key generated per request; map E62 response refund state to `refundStatus`
- [X] T063 [P] [US4] Same in `apps/agent/lib/modules/transactions/data/repositories/transaction_repository_impl.dart`

### Notifier Extension

- [X] T064 [US4] Implement `initiateRefund()` and `submitRefund({reason, amountMinor?})` on `PaymentDetailNotifier` in `apps/customer/lib/modules/transactions/application/payment_detail_notifier.dart` — `initiateRefund()` transitions `PaymentDetailLoaded` → `PaymentDetailRefundConfirm` only if `item.refundEligible == true`; `submitRefund()` → `PaymentDetailRefundSubmitting` → on success `PaymentDetailRefundSubmitted(refundRequestId)`; on error → `PaymentDetailError` with retry option; duplicate-submission guard: `refundStatus == pending` → `initiateRefund()` is a no-op
- [X] T065 [P] [US4] Same on `PaymentDetailNotifier` in `apps/agent/lib/modules/transactions/application/payment_detail_notifier.dart`

### Screen Updates

- [X] T066 [US4] Update `TransactionDetailScreen` in `apps/customer/lib/modules/transactions/presentation/screens/transaction_detail_screen.dart` — show "Request Refund" button only when `item.refundEligible == true`; show ineligibility reason when `refundEligible == false` and reason provided; show `PaymentDetailRefundConfirm` confirmation dialog with reason selector; show "Refund in progress" when `refundStatus == pending`; prevent duplicate tap
- [X] T067 [P] [US4] Update `TransactionDetailScreen` in `apps/agent/lib/modules/transactions/presentation/screens/transaction_detail_screen.dart`

### Tests (US4)

- [X] T068 [US4] Write unit tests for refund flow in `apps/customer/test/modules/transactions/application/payment_detail_notifier_test.dart` — covers: `refundEligible=true` → `initiateRefund()` → `PaymentDetailRefundConfirm`; `refundEligible=false` → `initiateRefund()` → state unchanged; `submitRefund()` → `Submitting` → `Submitted`; `refundStatus=pending` → `initiateRefund()` no-op; E62 API error → `PaymentDetailError`
- [X] T069 [US4] Write widget tests for refund UI in `apps/customer/test/modules/transactions/presentation/payment_detail_screen_test.dart` — covers SC-08: refund button present/absent; confirmation dialog renders; "Refund in progress" label when `refundStatus == pending`
- [X] T088 [P] [US4] Write unit tests for refund flow on agent `PaymentDetailNotifier` in `apps/agent/test/modules/transactions/application/payment_detail_notifier_test.dart` — same scenarios as T068; verify duplicate-submission guard fires; E62 error transitions correctly

**Checkpoint**: Refund button conditional on `refundEligible`; pending distinct from confirmed. US4 independently testable.

---

## Phase 8: User Story 5 — Support Ticket from Transaction (P2)

**Goal**: Pre-filled support form with stable payment reference; safe attachments (PDF/PNG/JPEG); E22 API submission.

**Independent Test**: SC-09 (form opens with `paymentIntentId` + pre-filled subject; attachment picker filters; ticket submitted with stable reference).

### Repository

- [X] T070 [US5] Add `createSupportTicket(SupportTicketDraft)` implementation to `TransactionRepositoryImpl` in `apps/customer/lib/modules/transactions/data/repositories/transaction_repository_impl.dart` — POST to E22 `POST /v1/support/tickets`; body: `{payment_intent_id, subject, description, attachment_references}`; for each attachment: get signed URL via E22 `attachEvidence` then upload bytes; return stable support reference from response
- [X] T071 [P] [US5] Same in `apps/agent/lib/modules/transactions/data/repositories/transaction_repository_impl.dart`

### Widget

- [X] T072 [US5] Create `SupportTicketDraftSheet` stateful widget in `apps/customer/lib/modules/transactions/presentation/screens/support_ticket_sheet.dart` — constructor params: `paymentIntentId` (displayed as read-only reference field), `prefilledSubject`; editable `subject` field (pre-filled from `PaymentHistoryItem.displayTitle`); text `description` field; "Attach file" button: opens file picker filtered to PDF/PNG/JPEG; client-side MIME check + user-facing error for unsupported type; max attachment size read from E28 `ConfigService` key `support.attachment.max_bytes` (fall back to 10 485 760 bytes / 10 MiB if key absent — do NOT hard-code); client-side size check before upload with inline error; per-attachment upload progress indicator; "Submit" calls `repository.createSupportTicket(...)`; on success: shows stable reference; navigate-away on back discards draft (no accidental submission)
- [X] T073 [P] [US5] Create `SupportTicketDraftSheet` in `apps/agent/lib/modules/transactions/presentation/screens/support_ticket_sheet.dart`
- [X] T074 [US5] Update `TransactionDetailScreen` in `apps/customer/lib/modules/transactions/presentation/screens/transaction_detail_screen.dart` — add "Get Help" button that calls `notifier.openSupportDraft()`; observe `PaymentDetailSupportDraft` state to show `SupportTicketDraftSheet` as a bottom sheet

### Tests (US5)

- [X] T075 [US5] Write widget tests for `SupportTicketDraftSheet` in `apps/customer/test/modules/transactions/presentation/support_ticket_sheet_test.dart` — covers SC-09: `paymentIntentId` rendered as read-only reference; subject pre-filled; file picker only accepts PDF/PNG/JPEG; unsupported type shows inline error; oversize file (> configured max) shows inline error before upload; submit calls `createSupportTicket` with `paymentIntentId`; stable reference shown on success
- [X] T089 [P] [US5] Write widget tests for agent `SupportTicketDraftSheet` in `apps/agent/test/modules/transactions/presentation/support_ticket_sheet_test.dart` — same scenarios as T075; verify agent payment reference is stable and pre-filled correctly

**Checkpoint**: Support form opens pre-filled, validates attachments, submits to E22. US5 independently testable.

---

## Phase 9: Polish — Accessibility, Flutter Analyze, Quickstart (US7)

**Purpose**: Accessibility audit across all P23 screens; static analysis; quickstart validation.

- [X] T076 [P] Audit all P23 screens in `apps/customer` for accessibility: add `Semantics(liveRegion: true)` to stale banner; verify each `PaymentStatusRow` has `Semantics(label: "${row.label}: ${row.value}")` node; verify loading state has `Semantics(label: "Loading...")` live region; verify offline state announces "Showing offline data, last updated [time]"; verify access-denied state has accessible explanation — in `apps/customer/lib/modules/transactions/presentation/` (all screen files)
- [X] T077 [P] Mirror accessibility audit for agent app in `apps/agent/lib/modules/transactions/presentation/` (all screen files)
- [X] T078 Verify all P23 interactive touch targets are ≥ 44×44 dp in customer app screens (use `debugPrint` + Flutter DevTools size overlay; fix any undersize buttons) — `apps/customer/lib/modules/transactions/presentation/screens/`
- [X] T079 [P] Same touch target audit for agent app — `apps/agent/lib/modules/transactions/presentation/screens/`
- [X] T080 Verify all P23 screen layouts support 2× font scale without text overflow: test `HistoryFilterBar`, list tiles, `PaymentStatusRow`, `ReceiptScreen` line items using `MediaQuery.textScaleFactor = 2.0` in widget tests — `apps/customer/test/modules/transactions/presentation/`
- [X] T081 Run `flutter analyze apps/customer/ --no-fatal-infos` from `wallet-ecosystem/` and resolve any errors (warnings acceptable if informational)
- [X] T082 [P] Run `flutter analyze apps/agent/ --no-fatal-infos` from `wallet-ecosystem/`
- [ ] T083 Run quickstart validation SC-01–SC-10 from `specs/070-payment-history-detail/quickstart.md` against live Docker environment (`docker compose up -d` from `wallet-ecosystem/`) — verify each scenario passes; record PASS/FAIL per SC
- [ ] T090 Write Flutter driver performance test in `apps/customer/test_driver/payment_history_perf_test.dart` — measures p95 latency for: (a) cold open of history list (target ≤ 800 ms per quickstart.md), (b) filter application round-trip (target ≤ 1 500 ms per SC-002); uses `flutter_driver` `Stopwatch` pattern; asserts both p95 thresholds; marked Docker-gated (requires live backend)
- [ ] T091 [P] Write Flutter driver performance test in `apps/customer/test_driver/receipt_token_perf_test.dart` — measures p95 latency for receipt download token fetch (target ≤ 600 ms per quickstart.md; aligns with SC-004 <2s download initiation); asserts p95 threshold; marked Docker-gated

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: No dependencies — can start immediately
- **Foundational (Phase 2)**: Depends on Phase 1 (pubspec + route setup) — **BLOCKS all user stories**
- **US1 (Phase 3)**: Depends on Phase 2 — MVP deliverable; start here after Phase 2
- **US2 (Phase 4)**: Depends on Phase 2; independent of US1 — can run in parallel with US1
- **US3 (Phase 5)**: Depends on Phase 2 and US2 (needs `PaymentDetailLoaded` with receipt button); sequential after US2
- **US6 (Phase 6)**: Depends on Phase 2 (`PaymentCacheStore` exists); independent of US1–US3 screen work
- **US4 (Phase 7)**: Depends on US2 (`PaymentDetailNotifier` and `TransactionDetailScreen` exist)
- **US5 (Phase 8)**: Depends on US2 (`TransactionDetailScreen` "Get Help" button); independent of US4
- **US3 account-switch (T087)**: Depends on Phase 5 (US3 notifier exists); can run in parallel with T086
- **Polish/US7 (Phase 9)**: Depends on all P1 stories (US1, US2, US3, US6) being complete; T090/T091 are Docker-gated

### User Story Dependencies (Strict Order)

```
Phase 1: Setup
  → Phase 2: Foundational
    → Phase 3: US1 (independent)
    → Phase 4: US2 (independent)
      → Phase 5: US3 (needs US2 detail screen)
      → Phase 7: US4 (needs US2 detail screen)
      → Phase 8: US5 (needs US2 detail screen)
    → Phase 6: US6 (independent — only needs cache store from Phase 2)
  → Phase 9: Polish (needs all P1 stories)
```

### Parallel Opportunities

All `[P]`-marked tasks within the same phase can run concurrently (they touch different files).

**Within Phase 2** (maximum parallelism):
```
T007 (customer entities) ‖ T008 (agent entities) ‖ T009 (customer filter) ‖ T010 (agent filter) ‖ T011 (customer receipt) ‖ T012 (agent receipt) ‖ T013 (customer deep link) ‖ T014 (agent deep link)
T015 (customer repo interface) ‖ T016 (agent repo interface)
T017 (customer DTOs) ‖ T018 (agent DTOs) ‖ T019 (customer filter DTO) ‖ T020 (agent filter DTO) ‖ T021 (customer receipt DTO) ‖ T022 (agent receipt DTO)
T023 (customer cache store) ‖ T024 (agent cache store)
T025 (customer repo impl) ‖ T026 (agent repo impl)
```

**Within each user story phase**: customer app tasks ‖ agent app tasks (all `[P]` pairs).

---

## Parallel Execution Examples

### Phase 2 (Foundational) — max parallelism
```
Task: "Upgrade customer Transaction entity T007"
Task: "Upgrade agent AgentTransaction entity T008"
Task: "Create customer PaymentHistoryFilter T009"
Task: "Create agent PaymentHistoryFilter T010"
  [Wait for T007–T010]
Task: "Upgrade customer TransactionRepository interface T015"
Task: "Upgrade agent TransactionRepository interface T016"
```

### Phase 3 (US1) — customer+agent parallel
```
Task: "Create customer PaymentHistoryNotifier T027"
Task: "Create agent PaymentHistoryNotifier T028"  [parallel with T027]
  [Wait for T027, T028]
Task: "Create HistoryFilterBar T029"
Task: "Upgrade customer TransactionListScreen T030"
  [After T030]
Task: "Run customer US1 tests T034"
```

---

## Implementation Strategy

### MVP Scope (US1 only — Phase 1 + 2 + 3)

1. Complete Phase 1: Setup (pubspec + routes)
2. Complete Phase 2: Foundational (entities + interfaces + DTOs + cache store + repo impl)
3. Complete Phase 3: US1 (notifier + list screen + filter bar)
4. **VALIDATE**: Run SC-01, SC-02, SC-03 from quickstart.md
5. MVP deliverable: P19-backed filterable activity feed with offline stale cache

### Incremental Delivery

1. Phase 1 + 2 + 3 (US1) → filterable activity feed ✓
2. + Phase 4 (US2) → detail screen with status rows + deep links ✓
3. + Phase 5 (US3) → receipt screen with screenshot guard ✓
4. + Phase 6 (US6) → cache eviction on logout ✓
5. + Phase 7 (US4) → refund flow (P2) ✓
6. + Phase 8 (US5) → support ticket (P2) ✓
7. Phase 9 (Polish/US7) → accessibility audit + analyze + quickstart ✓

---

## Notes

- All tasks target `wallet-ecosystem/apps/customer/` or `wallet-ecosystem/apps/agent/` (both apps required)
- `[P]` marks tasks operating on different files with no dependency on an incomplete predecessor
- `[USn]` label maps each task to its user story for traceability
- `ReceiptDownloadToken.downloadUrl` must never be serialised to storage — enforced in `ReceiptAccessNotifier` and verified in T054
- `refundEligible` is server-authoritative — never computed locally; enforced in T064
- T083 (quickstart) is Docker-gated; all preceding tasks can complete without Docker
