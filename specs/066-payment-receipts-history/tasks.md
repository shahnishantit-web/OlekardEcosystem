# Tasks: P19 — Receipts, Communication and Payment History APIs

**Input**: Design documents from `specs/066-payment-receipts-history/`

**Branch**: `066-payment-receipts-history`

**Prerequisites**: plan.md ✓, spec.md ✓, research.md ✓, data-model.md ✓, contracts/payment-history-api.yaml ✓, quickstart.md ✓

**Base API path**: `wallet-ecosystem/services/api/`

**Organization**: Tasks are grouped by user story — each story is independently testable.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no shared dependencies on incomplete tasks)
- **[Story]**: Which user story this task belongs to
- All paths are relative to `wallet-ecosystem/services/api/`

---

## Phase 1: Setup (Config)

**Purpose**: Add P19 config keys; no new framework setup needed — Payment module already exists.

- [X] T001 Add P19 config keys to `wallet-ecosystem/services/api/config/payment.php` (create file if absent): `payment.receipt.download_token_ttl_minutes = 15`, `payment.export.token_ttl_minutes = 60`, `payment.deep_link.retired_purposes = []`

---

## Phase 2: Foundational (Migrations + Models + Enums + Routes)

**Purpose**: DB tables, models and enums that ALL user stories depend on. Routes registration for all P19 endpoints.

**⚠️ CRITICAL**: No user story work can begin until this phase is complete.

- [X] T002 Create migration `app/Modules/Payment/Infrastructure/Migrations/2026_10_25_000024_create_receipt_download_tokens_table.php` — `payment.receipt_download_tokens` table with columns: id uuid PK, payment_intent_id uuid NOT NULL FK→payment.payment_intents, requestor_id varchar(100) NOT NULL, token char(64) UNIQUE NOT NULL, used_at timestamptz NULL, expires_at timestamptz NOT NULL, timestamps; INDEX(payment_intent_id), INDEX(requestor_id, created_at)
- [X] T003 Create migration `app/Modules/Payment/Infrastructure/Migrations/2026_10_25_000025_create_payment_export_requests_table.php` — `payment.payment_export_requests` table with columns: id uuid PK, requestor_id varchar(100) NOT NULL, scope_type varchar(30) NOT NULL, organisation_id varchar(100) NULL, filters jsonb NOT NULL DEFAULT '{}', status varchar(30) NOT NULL DEFAULT 'queued', token char(64) UNIQUE NULL, row_count int NULL, generated_at timestamptz NULL, token_expires_at timestamptz NULL, downloaded_at timestamptz NULL, failure_reason text NULL, timestamps; INDEX(requestor_id, created_at), INDEX(organisation_id, created_at)
- [X] T004 Create migration `app/Modules/Payment/Infrastructure/Migrations/2026_10_25_000026_create_receipt_notification_deliveries_table.php` — `payment.receipt_notification_deliveries` table with columns: id uuid PK, event_idempotency_key varchar(200) UNIQUE NOT NULL, event_type varchar(100) NOT NULL, payment_intent_id uuid NOT NULL, notification_id uuid NULL, status varchar(30) NOT NULL, template_slug varchar(200) NULL, template_version varchar(50) NULL, timestamps; INDEX(payment_intent_id), INDEX(event_type, created_at); also seed 5 permissions into `identity.permissions`: `payment.history.read`, `payment.history.read.organisation`, `payment.history.read.platform`, `payment.export.read.organisation`, `payment.export.read.platform`
- [X] T005 [P] Create enum `app/Modules/Payment/Domain/Enums/ReceiptDownloadTokenStatus.php` — cases: `Pending = 'pending'`, `Used = 'used'`, `Expired = 'expired'`; add `isDepleted(): bool` method returning true for Used and Expired
- [X] T006 [P] Create enum `app/Modules/Payment/Domain/Enums/PaymentExportRequestStatus.php` — cases: `Queued = 'queued'`, `Generating = 'generating'`, `Ready = 'ready'`, `Failed = 'failed'`, `Expired = 'expired'`; add `isTerminal(): bool` and `isDownloadable(): bool` helper methods
- [X] T007 [P] Create enum `app/Modules/Payment/Domain/Enums/ReceiptNotificationDeliveryStatus.php` — cases: `Dispatched = 'dispatched'`, `Failed = 'failed'`, `Skipped = 'skipped'`
- [X] T008 [P] Create model `app/Modules/Payment/Infrastructure/Models/ReceiptDownloadTokenModel.php` — `protected $table = 'payment.receipt_download_tokens'`; UUID v7 primary key; casts: `expires_at => 'immutable_datetime'`, `used_at => 'immutable_datetime'`; `isExpired(): bool`, `isUsed(): bool`, `status(): ReceiptDownloadTokenStatus` computed methods
- [X] T009 [P] Create model `app/Modules/Payment/Infrastructure/Models/PaymentExportRequestModel.php` — `protected $table = 'payment.payment_export_requests'`; UUID v7 PK; casts: `filters => 'array'`, `status => PaymentExportRequestStatus::class`, `generated_at/token_expires_at/downloaded_at => 'immutable_datetime'`
- [X] T010 [P] Create model `app/Modules/Payment/Infrastructure/Models/ReceiptNotificationDeliveryModel.php` — `protected $table = 'payment.receipt_notification_deliveries'`; UUID v7 PK; casts: `status => ReceiptNotificationDeliveryStatus::class`
- [X] T011 Add P19 routes to `routes/modules/payment.php`: (a) **Inside** the existing authenticated consumer route group: add `GET /history`, `GET /receipts/{intentId}`, `POST /receipts/{intentId}/download-token`; (b) **Inside** the existing authenticated admin route group: add `GET /history`, `POST /exports`, `GET /exports/{exportId}`; (c) **Outside ALL auth groups** (at top-level or in a separate unauthenticated block): add `GET /receipts/download/{token}` and `GET /exports/download/{token}` — these two routes MUST NOT inherit the group's auth middleware because the token IS the credential. Use `->withoutMiddleware(['auth:api', 'auth:sanctum'])` if defined inside a group, or define them in an un-grouped block before the auth groups. Reference: the existing bank callback routes (`POST /payment/bank/callback`) follow the same unauthenticated pattern. Import all P19 controller classes at the top of the file.

**Checkpoint**: Run `php artisan migrate --pretend` — should show 3 new migration files with correct column definitions.

---

## Phase 3: User Story 1 — Scoped Payment History Retrieval (P1) 🎯 MVP

**Goal**: Paginated, payer-scoped payment history API with filters and safe status mapping.

**Independent Test**: `GET /v1/payment/history` as authenticated consumer returns only that payer's records; admin query with `organisation_id` filter returns only that org's records; `purpose_type` filter returns only matching records.

### Implementation for User Story 1

- [X] T012 [P] [US1] Create `PaymentHistoryRecord` value object in `app/Modules/Payment/Domain/ValueObjects/PaymentHistoryRecord.php` — readonly class with all fields from data-model.md: `paymentReference`, `purposeType`, `purposeReference`, `domainDeepLink`, `providerLabel`, `originalAmountMinor`, `currencyCode`, `feeAmountMinor`, `totalChargedMinor` (= original + fee; NOT "net"), `paymentStatus`, `refundStatus`, `refundAmountMinor`, `refundEligible`, `paymentDate`, `organisationId`
- [X] T013 [P] [US1] Create form request `app/Modules/Payment/Http/Requests/GetPaymentHistoryRequest.php` — validate: `purpose_type?` string, `status?` enum, `date_from?` date, `date_to?` date, `currency_code?` regex `[A-Z]{3}`, `page?` int min:1, `per_page?` int 1–100 default 20
- [X] T014 [US1] Implement `PaymentHistoryService` in `app/Modules/Payment/Application/Services/PaymentHistoryService.php` — `getForPayer(string $payerActorId, array $filters): LengthAwarePaginator`, `getForOrganisation(string $orgId, array $filters, string $payerFilter = null): LengthAwarePaginator`, `getForPlatform(array $filters): LengthAwarePaginator`; each method: joins `payment_intents` LEFT JOIN latest confirmed `payment_attempts` LEFT JOIN latest non-failed `payment_refunds`; maps `PaymentIntentModel.status` to safe status set per data-model.md; computes `domain_deep_link` as `"{purpose_type}://{purpose_reference}"` or unavailable object if in retired list; `fee_amount_minor` = confirmed `PA.fee_minor` if available else `PI.fee_quote_minor`
- [X] T015 [US1] Create customer controller `app/Modules/Payment/Http/Controllers/Customer/PaymentHistoryController.php` — thin: inject `PaymentHistoryService`; `index(GetPaymentHistoryRequest $request)` reads `auth()->user()->actor_id` for scoping, delegates to service, returns paginated JSON; never exposes another payer's records
- [X] T016 [US1] Create admin history controller `app/Modules/Payment/Http/Controllers/Admin/PaymentHistoryAdminController.php` — `index(GetPaymentHistoryRequest $request)`: checks permission `payment.history.read.organisation` or `payment.history.read.platform`; delegates to appropriate `PaymentHistoryService` method based on permission and `organisation_id` query param
- [X] T017 [US1] Write unit test `tests/Unit/Payment/PaymentHistoryServiceTest.php` — tests: `test_returns_only_payer_scoped_records`, `test_safe_status_mapping_pending_returns_processing`, `test_safe_status_mapping_confirmed_with_pending_refund_returns_refund_pending`, `test_fee_falls_back_to_quoted_when_no_confirmed_attempt`, `test_purpose_type_filter_applied`, `test_domain_deep_link_constructed_from_validated_purpose_type_and_reference`, `test_retired_purpose_returns_unavailable_deep_link`
- [X] T018 [US1] Write feature test `tests/Feature/Modules/Payment/PaymentHistoryApiTest.php` — tests: `test_consumer_cannot_see_other_payers_records`, `test_admin_history_requires_permission`, `test_admin_can_filter_by_organisation`, `test_platform_admin_can_query_cross_organisation`, `test_pagination_defaults_to_20_per_page`, `test_purpose_type_filter_returns_correct_subset`

**Checkpoint**: US1 independently functional — history endpoint returns scoped, paginated records with safe status mapping.

---

## Phase 4: User Story 2 — Receipt Retrieval and Authorised Download (P1)

**Goal**: Receipt endpoint returning immutable financial data; single-use expiring download tokens.

**Independent Test**: `GET /v1/payment/receipts/{intentId}` as payer returns receipt; same call as different user returns 404; download token redeemed once, second use returns 410.

### Implementation for User Story 2

- [X] T019 [P] [US2] Create `ReceiptRecord` value object in `app/Modules/Payment/Domain/ValueObjects/ReceiptRecord.php` — readonly class: `paymentReference`, `providerLabel`, `originalAmountMinor`, `currencyCode`, `feeAmountMinor` (from confirmed attempt only), `totalChargedMinor`, `paymentDate`, `purposeSummary`, `receiptStatus = 'confirmed'`, `refundSummary: array`; add `fromModels(PaymentIntentModel $intent, PaymentAttemptModel $attempt, array $refunds): self` factory
- [X] T020 [P] [US2] Implement `ReceiptService` in `app/Modules/Payment/Application/Services/ReceiptService.php` — `compile(string $intentId, string $requestorId): ReceiptRecord`; throws `NotFoundException` if intent not found; throws `ForbiddenException` if `intent.payer_actor_id !== $requestorId` (admin bypass via separate method); throws `NotFoundException` if intent not confirmed (FR-008: failed intents return 404, not 403, to avoid leakage); sources `feeAmountMinor` exclusively from confirmed `PaymentAttemptModel.fee_minor` — never from `PaymentIntentModel.fee_quote_minor` for receipts (FR-009)
- [X] T021 [US2] Implement `ReceiptDownloadTokenService` in `app/Modules/Payment/Application/Services/ReceiptDownloadTokenService.php` — `issue(string $intentId, string $requestorId): ReceiptDownloadTokenModel`: verifies ownership via ReceiptService, generates 64-char hex token via `bin2hex(random_bytes(32))`, TTL from `config('payment.receipt.download_token_ttl_minutes', 15)`, persists and returns; `redeem(string $token): ReceiptDownloadTokenModel`: loads token, checks `expires_at > now()` (throws `GoneException` if expired), checks `used_at IS NULL` (throws `GoneException` if already used), sets `used_at = now()`, saves and returns
- [X] T022 [US2] Create receipt controller `app/Modules/Payment/Http/Controllers/Customer/ReceiptController.php` — `show(string $intentId)`: delegates to `ReceiptService::compile()`; returns JSON receipt; `issueDownloadToken(string $intentId)`: delegates to `ReceiptDownloadTokenService::issue()`, returns token + expires_at + download_url (201)
- [X] T023 [US2] Create download controller `app/Modules/Payment/Http/Controllers/ReceiptDownloadController.php` — `download(string $token, Request $request)`: delegates to `ReceiptDownloadTokenService::redeem()`; then compiles receipt via `ReceiptService`; returns JSON (default) or CSV (if `?format=csv`); no auth middleware (token IS the credential)
- [X] T024 [US2] Write unit test `tests/Unit/Payment/ReceiptServiceTest.php` — tests: `test_compile_returns_fee_from_confirmed_attempt_not_quoted_fee`, `test_compile_throws_not_found_for_missing_intent`, `test_compile_throws_forbidden_when_requestor_is_not_payer`, `test_compile_throws_not_found_for_failed_intent` (no receipt for failed payments), `test_refund_summary_shows_pending_not_confirmed_for_in_flight_refund`, `test_receipt_does_not_expose_raw_provider_secrets` (assert ReceiptRecord has no `provider_credentials`, `api_key`, `account_number` fields; assert `providerLabel` is a display string, not a raw bank account reference — FR-012)
- [X] T025 [US2] Write unit test `tests/Unit/Payment/ReceiptDownloadTokenServiceTest.php` — tests: `test_issue_generates_64_char_hex_token`, `test_issue_sets_expiry_from_config`, `test_redeem_sets_used_at_on_first_use`, `test_redeem_throws_gone_on_second_use`, `test_redeem_throws_gone_when_expired`
- [X] T026 [US2] Write feature test `tests/Feature/Modules/Payment/ReceiptApiTest.php` — tests: `test_non_payer_cannot_access_receipt_returns_404`, `test_receipt_returns_confirmed_payment_data`, `test_receipt_shows_pending_refund_as_pending`, `test_download_token_is_single_use`, `test_expired_download_token_returns_410`

**Checkpoint**: US2 independently functional — receipt endpoint enforces ownership; download tokens expire and are single-use.

---

## Phase 5: User Story 3 — Transactional Receipt Notifications (P1)

**Goal**: Notification listeners for `payment.confirmed` and `refund.confirmed` events; idempotent deduplication via `receipt_notification_deliveries`.

**Independent Test**: Trigger `payment.confirmed` twice with same idempotency key; assert exactly one row in `receipt_notification_deliveries` with `status = dispatched`. Simulate delivery failure; assert payment status unchanged.

### Implementation for User Story 3

- [X] T027 [P] [US3] Create `PaymentConfirmedReceiptListener` in `app/Modules/Notification/Application/Listeners/PaymentConfirmedReceiptListener.php` — `implements ShouldQueue`, `use Queueable`, queue `'notifications-critical'`; constructor: `NotificationService`, `ReceiptNotificationDeliveryModel` (or via Eloquent); `handle(PaymentConfirmedEvent $event)`: build idempotency key `"{event->eventName()}.{$event->correlationId()}"`, attempt INSERT into `receipt_notification_deliveries` (ignore on unique violation — deduplication), if new row: call `$this->notificationService->createFromEvent(new CreateNotificationCommand(sourceEventId: $event->paymentIntentId, sourceEventType: PaymentConfirmedEvent::class, notificationType: 'payment.receipt.payment_confirmed', category: NotificationCategory::Financial, mandatory: true, recipientId: $event->payerActorId, variables: [...], correlationId: $event->correlationId()))`, update row `status = 'dispatched'`; if duplicate (caught unique violation): log skip and return without notification
- [X] T028 [P] [US3] Create `RefundConfirmedReceiptListener` in `app/Modules/Notification/Application/Listeners/RefundConfirmedReceiptListener.php` — same pattern as T027 but for `RefundConfirmedEvent`; resolves `payer_actor_id` by loading `PaymentIntentModel::find($event->paymentIntentId)?->payer_actor_id` (not on event directly); notificationType: `'payment.receipt.refund_confirmed'`; if `payer_actor_id` is null: log warning + return (do not throw; delivery failure must NOT reverse the refund per FR-015)
- [X] T029 [US3] Register both listeners in `app/Modules/Notification/Providers/NotificationServiceProvider.php` — add `use` imports for `PaymentConfirmedEvent`, `RefundConfirmedEvent`, `PaymentConfirmedReceiptListener`, `RefundConfirmedReceiptListener`; add two `Event::listen(...)` calls inside `registerEventListeners()`
- [X] T030 [US3] Write unit test `tests/Unit/Payment/PaymentConfirmedReceiptListenerTest.php` — tests: `test_handle_dispatches_notification_on_first_event`, `test_handle_skips_notification_on_duplicate_idempotency_key`, `test_handle_does_not_throw_when_notification_service_fails` (wraps in try/catch to verify payment status unchanged), `test_idempotency_key_includes_event_name_and_correlation_id`, `test_notification_is_sent_when_payer_has_opted_out_of_marketing` (mock `CreateNotificationCommand` with `mandatory: true`; assert notification is dispatched regardless of any marketing consent flag — FR-016)
- [X] T031 [US3] Write unit test `tests/Unit/Payment/RefundConfirmedReceiptListenerTest.php` — tests: `test_handle_resolves_payer_from_payment_intent`, `test_handle_logs_and_returns_when_payer_not_found`, `test_handle_does_not_reverse_refund_on_delivery_failure`, `test_duplicate_refund_event_produces_no_second_notification`

**Checkpoint**: US3 independently functional — duplicate event replay does not produce duplicate notification; delivery failure does not alter payment state.

---

## Phase 6: User Story 4 — Sanitised Scoped Exports (P2)

**Goal**: Finance operators can request a scoped CSV export; async generation; single-use expiring download token; formula-injection-safe output; provisional labelling for unreconciled rows.

**Independent Test**: Request export as org finance role; download CSV; assert no formula-injectable values; assert `provisional` label on rows with unresolved reconciliation exceptions; assert second download with same token returns 410.

### Implementation for User Story 4

- [X] T032 [P] [US4] Create `CsvFormulaSanitiser` value object in `app/Modules/Payment/Domain/ValueObjects/CsvFormulaSanitiser.php` — `public static function sanitise(string $value): string`: if `$value` starts with `=`, `+`, `-`, `@`, `\t`, or `\r` → return `"'" . $value`; else return `$value`; apply to every non-numeric text column at write time
- [X] T033 [P] [US4] Implement `PaymentExportService` in `app/Modules/Payment/Application/Services/PaymentExportService.php` — `request(string $requestorId, string $scopeType, ?string $orgId, array $filters): PaymentExportRequestModel`: validates scope permission (`payment.export.read.organisation` / `payment.export.read.platform`), creates `PaymentExportRequestModel` with `status = queued`, dispatches `GeneratePaymentExportJob::dispatch($model->id)->onQueue('payment')`, returns model; `getStatus(string $exportId, string $requestorId): PaymentExportRequestModel`: loads model, asserts ownership (requester OR platform admin); `redeemToken(string $token): PaymentExportRequestModel`: loads by token, checks `token_expires_at > now()` (GoneException if expired), checks `downloaded_at IS NULL` (GoneException if already downloaded), sets `downloaded_at = now()`, saves
- [X] T034 [US4] Implement `GeneratePaymentExportJob` in `app/Modules/Payment/Application/Jobs/GeneratePaymentExportJob.php` — `implements ShouldQueue`, `$queue = 'payment'`; `handle()`: load `PaymentExportRequestModel`, set `status = generating`, build filtered query on `payment_intents` scoped by `requestor_id/scope_type/organisation_id` + filters; for each row: apply `CsvFormulaSanitiser::sanitise()` to all text columns; add `provisional` label for rows whose `payment_intent_id` has an open `reconciliation_exception`; write to `Storage::put("exports/payment/{$id}.csv", $csv)`, generate download token `bin2hex(random_bytes(32))`, set `token`, `row_count`, `generated_at`, `token_expires_at`, `status = ready`, save; on exception: set `status = failed`, `failure_reason`, save
- [X] T035 [US4] Create form request `app/Modules/Payment/Http/Requests/CreatePaymentExportRequest.php` — validate: `scope_type` required enum (`organisation`, `platform`), `organisation_id?` string required-if `scope_type=organisation`, `filters.purpose_type?` string, `filters.currency_code?` regex, `filters.date_from?` date, `filters.date_to?` date, `filters.status?` enum
- [X] T036 [US4] Create export controller `app/Modules/Payment/Http/Controllers/Admin/PaymentExportAdminController.php` — `request(CreatePaymentExportRequest $r)`: delegates to `PaymentExportService::request()`, returns 202; `status(string $exportId)`: delegates to `PaymentExportService::getStatus()`, returns JSON with download_url when ready; `download(string $token)`: delegates to `PaymentExportService::redeemToken()`, streams CSV file, calls `AuditServiceInterface::log()` for FR-020 access audit
- [X] T037 [US4] Write unit test `tests/Unit/Payment/CsvFormulaSanitiserTest.php` — tests: `test_equals_prefix_is_sanitised`, `test_plus_prefix_is_sanitised`, `test_minus_prefix_is_sanitised`, `test_at_sign_prefix_is_sanitised`, `test_tab_prefix_is_sanitised`, `test_safe_value_is_unchanged`, `test_empty_string_is_unchanged`, `test_numeric_string_is_unchanged`
- [X] T038 [US4] Write unit test `tests/Unit/Payment/PaymentExportServiceTest.php` — tests: `test_request_requires_organisation_permission_for_org_scope`, `test_request_requires_platform_permission_for_platform_scope`, `test_redeem_token_throws_gone_when_expired`, `test_redeem_token_throws_gone_when_already_downloaded`, `test_status_throws_forbidden_when_requestor_is_not_owner`
- [X] T039 [US4] Write feature test `tests/Feature/Modules/Payment/PaymentExportApiTest.php` — tests: `test_org_export_only_contains_org_records`, `test_export_token_is_single_use`, `test_expired_export_token_returns_410`, `test_export_access_is_audited`, `test_csv_has_no_formula_injectable_values`, `test_export_does_not_contain_full_account_numbers` (seed a payment whose intent metadata contains a bank account reference; assert no column in the CSV matches the pattern `\d{8,}` or contains the raw account string — FR-012)

**Checkpoint**: US4 independently functional — export generates safely, is scoped correctly, and download token enforces single-use expiry with audit.

---

## Phase 7: User Story 5 — Domain Deep Links and Mobile Activity (P2)

**Goal**: Add `domain_deep_link` and `refund_eligible` fields to history API response; `purpose_type` filter (already implemented in US1).

**Independent Test**: `GET /v1/payment/history?purpose_type=funeral_assistance` returns only records with that purpose type. Each record contains `domain_deep_link` as a server-derived string. Retired domain returns `{"status":"unavailable","reason":"domain_retired"}`.

### Implementation for User Story 5

- [X] T040 [P] [US5] Extend `PaymentHistoryService` in `app/Modules/Payment/Application/Services/PaymentHistoryService.php` to populate `domain_deep_link` and `refund_eligible` on all existing methods — `domain_deep_link`: check `config('payment.deep_link.retired_purposes', [])` array; if `purpose_type` is in the list, return `['status' => 'unavailable', 'reason' => 'domain_retired']`; otherwise return `"{$purposeType}://{$purposeReference}"`; `refund_eligible`: set `true` if payment confirmed and no existing refund in confirmed/processing state; `false` otherwise (domain screens determine actual eligibility — P19 provides safe default)
- [X] T041 [US5] Write unit test `tests/Unit/Payment/PaymentHistoryDeepLinkTest.php` — tests: `test_deep_link_format_is_purpose_type_colon_slash_slash_reference`, `test_retired_purpose_returns_unavailable_object`, `test_refund_eligible_true_when_confirmed_with_no_active_refund`, `test_refund_eligible_false_when_refund_in_progress`

**Checkpoint**: US1–US5 all functional. History API includes `domain_deep_link` and `refund_eligible` on all responses.

---

## Phase 8: Polish & Cross-Cutting Concerns

**Purpose**: OpenAPI documentation, PHPStan compliance, concurrency guard, quickstart validation.

- [X] T042 [P] Append P19 schemas and paths to `openapi/v1/payment.yaml` — add 7 new schemas: `PaymentHistoryItem`, `PaymentHistoryResponse`, `ReceiptResponse`, `ReceiptDownloadTokenResponse`, `PaymentExportRequest`, `PaymentExportStatusResponse`; add 8 new paths: all routes from `contracts/payment-history-api.yaml`; place after last P18 path entry
- [X] T043 [P] Create concurrency test `tests/Concurrency/Modules/Payment/ReceiptDownloadTokenConcurrentTest.php` — `markTestSkipped('Docker-gated: requires running PostgreSQL')` on all methods; test stubs: `test_concurrent_token_redemption_only_one_succeeds` (UNIQUE constraint on token), `test_concurrent_export_token_download_only_first_succeeds`
- [X] T044 Run PHPStan level-8 analysis on all P19 Payment module files — `./vendor/bin/phpstan analyse app/Modules/Payment/Application/Services/PaymentHistoryService.php app/Modules/Payment/Application/Services/ReceiptService.php app/Modules/Payment/Application/Services/ReceiptDownloadTokenService.php app/Modules/Payment/Application/Services/PaymentExportService.php app/Modules/Payment/Application/Jobs/GeneratePaymentExportJob.php app/Modules/Payment/Http/Controllers/Customer/PaymentHistoryController.php app/Modules/Payment/Http/Controllers/Customer/ReceiptController.php app/Modules/Payment/Http/Controllers/ReceiptDownloadController.php app/Modules/Payment/Http/Controllers/Admin/PaymentHistoryAdminController.php app/Modules/Payment/Http/Controllers/Admin/PaymentExportAdminController.php app/Modules/Notification/Application/Listeners/PaymentConfirmedReceiptListener.php app/Modules/Notification/Application/Listeners/RefundConfirmedReceiptListener.php --level=8`; resolve all errors; mark DONE when 0 errors; **Docker-gated** (requires running environment with dependencies)
- [X] T045 Validate quickstart scenarios SC-001 through SC-009 in running Docker stack using `quickstart.md` as guide; record pass/fail for each scenario in this task's notes; mark DONE when all 9 scenarios pass; **Docker-gated**

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: No dependencies — start immediately
- **Foundational (Phase 2)**: Depends on Phase 1 — BLOCKS all user stories
- **US1–US3 (Phases 3–5)**: All depend on Phase 2 completion; US1/US2/US3 can run in parallel once Phase 2 is done
- **US4 (Phase 6)**: Depends on Phase 2; independently parallelizable with US1–US3
- **US5 (Phase 7)**: Depends on US1 (Phase 3) — extends PaymentHistoryService
- **Polish (Phase 8)**: Depends on all desired user stories complete

### User Story Dependencies

- **US1 (P1)**: After Phase 2 — no dependency on other stories
- **US2 (P1)**: After Phase 2 — no dependency on other stories
- **US3 (P1)**: After Phase 2 — no dependency on other stories
- **US4 (P2)**: After Phase 2 — no dependency on other stories
- **US5 (P2)**: After US1 — extends existing PaymentHistoryService

### Within Each User Story

- Value objects/enums before services
- Services before controllers
- Unit tests can be written before or alongside services (TDD or alongside)
- Controllers depend on services

---

## Parallel Example: P1 Stories (Phases 3–5)

```
# After Phase 2 completes, all three P1 stories can be worked in parallel:
Developer A: US1 (T012–T018) — History API
Developer B: US2 (T019–T026) — Receipt + Download Tokens
Developer C: US3 (T027–T031) — Notification Listeners
```

## Parallel Example: Within US1

```
# These P-marked tasks can run simultaneously:
T012 [P] — PaymentHistoryRecord value object
T013 [P] — GetPaymentHistoryRequest form request
# Then sequentially:
T014 — PaymentHistoryService (depends on T012)
T015 — Customer Controller (depends on T014)
T016 — Admin Controller (depends on T014)
T017 — Unit tests (can run alongside T014)
T018 — Feature tests (depends on T015, T016)
```

---

## Implementation Strategy

### MVP (P1 Stories — implement first)

1. Complete Phase 1 (Setup) → Phase 2 (Foundational)
2. Implement Phase 3 (US1) — History API
3. Implement Phase 4 (US2) — Receipts + Downloads
4. Implement Phase 5 (US3) — Notifications
5. **STOP and VALIDATE**: Run unit + feature tests; verify SC-001 to SC-006 in quickstart

### Full Delivery (P2 Stories)

6. Implement Phase 6 (US4) — Exports
7. Implement Phase 7 (US5) — Deep Links (lightweight extension of US1)
8. Complete Phase 8 (Polish) — OpenAPI, PHPStan, quickstart SC-007 to SC-009

### Notes

- `[P]` tasks within a phase touch different files — safe to parallelize
- Routes (T011) must be done before feature tests (T018, T026, T039)
- PHPStan (T044) and quickstart (T045) are Docker-gated — skip with `markTestSkipped` or run in Docker
- Export token uses same 64-char hex pattern as receipt download token
- Receipt `fee_amount_minor` MUST come from confirmed `payment_attempts.fee_minor`, never from `payment_intents.fee_quote_minor` (FR-009 financial integrity)
- Notification listeners MUST NOT throw after deduplication record is written — wrap downstream calls in try/catch that logs failures without re-throwing (FR-015)
