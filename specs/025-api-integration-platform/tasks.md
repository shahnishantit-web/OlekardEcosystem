# Tasks: E25 â€” API & Integration Platform

**Input**: Design documents from `specs/025-api-integration-platform/`

**Prerequisites**: plan.md âœ“, spec.md âœ“, research.md âœ“, data-model.md âœ“, contracts/integration.yaml âœ“, quickstart.md âœ“

**Organization**: Tasks grouped by user story phase for independent implementation and testing.

## Format: `[ID] [P?] [Story?] Description with file path`

- **[P]**: parallelizable (different files, no shared dependency on an incomplete task)
- **[Story]**: user story label (US1, US2, US3)
- **No story label**: Setup, Foundational, or Polish phase

---

## Phase 1: Setup

**Purpose**: Module skeleton, schema migration, and shared infrastructure bootstrap.

- [x] T001 Create `integration` PostgreSQL schema migration in `app/Modules/Integration/Infrastructure/Migrations/2026_10_01_000001_create_integration_schema.php` (`CREATE SCHEMA IF NOT EXISTS integration`)
- [x] T002 Create `IntegrationServiceProvider` skeleton (registers module routes, middleware aliases, bindings) in `app/Modules/Integration/Providers/IntegrationServiceProvider.php` and add to `bootstrap/providers.php`
- [x] T003 [P] Create domain enum classes â€” `ApiClientStatus`, `IntegrationProviderType`, `IntegrationStatus`, `ProviderCallOutcome`, `WebhookEventStatus` â€” in `app/Modules/Integration/Domain/Enums/`
- [x] T004 [P] Create domain contract interfaces â€” `ApiClientRepositoryInterface`, `IntegrationRepositoryInterface`, `WebhookEventRepositoryInterface`, `IntegrationRegistryInterface` â€” in `app/Modules/Integration/Domain/Contracts/`
- [x] T005 [P] Create domain exception classes â€” `ApiClientNotFoundException`, `InvalidApiCredentialsException`, `WebhookProviderNotFoundException`, `WebhookNotDeadLetterException`, `IntegrationNotFoundException` â€” in `app/Modules/Integration/Domain/Exceptions/`; `WebhookSignatureException` reused from Platform module
- [x] T006 Create route file skeleton `routes/modules/integration.php` and register it in `IntegrationServiceProvider::boot()` (loaded via `loadRoutesFrom`)

**Checkpoint**: Module loads, enums/interfaces/exceptions exist, empty route file registered.

---

## Phase 2: Foundational (Middleware Stack)

**Purpose**: Gateway middleware chain that ALL requests pass through. MUST be complete before any user story work begins.

**âš ï¸ CRITICAL**: No user story work can begin until this phase is complete.

- [x] T007 `CorrelationIdMiddleware` already exists globally at `App\Http\Middleware\CorrelationIdMiddleware`; aliased as `integration.correlation-id` in IntegrationServiceProvider
- [x] T008 [P] `RequestLoggingMiddleware` + `ResponseLoggingMiddleware` already applied globally; aliased as `integration.request-log` in IntegrationServiceProvider
- [x] T009 Create `DualRateLimitMiddleware` â€” Redis Lua sorted-set dual sliding windows â€” in `app/Modules/Integration/Infrastructure/Http/Middleware/DualRateLimitMiddleware.php`
- [x] T010 [P] Create `ApiClientAuthMiddleware` â€” Bearer base64 decode, bcrypt password_verify, 401 on inactive â€” in `app/Modules/Integration/Infrastructure/Http/Middleware/ApiClientAuthMiddleware.php`
- [x] T011 All four middleware aliases registered in `IntegrationServiceProvider::registerMiddlewareAliases()`
- [x] T012 [P] Create `IntegrationTestCase` extending `LedgerTestCase` with `ensureApiClient()` / `ensureIntegration()` helpers in `tests/Feature/Modules/Integration/IntegrationTestCase.php`

**Checkpoint**: Middleware stack functional. A request with valid API key credentials passes all four middleware layers.

---

## Phase 3: User Story 1 â€” API Gateway, Cross-Cutting Concerns & Client Management (Priority: P1) ðŸŽ¯ MVP

**Goal**: A registered API client can authenticate, be rate-limited, have its payload validated, and receive a correlation-ID-tagged response. Administrators can create, list, view, rotate secrets for, and activate/deactivate API clients through the admin portal.

**Independent Test**: Submit API requests with valid, missing, and expired credentials; verify 401/403/429/422 responses and correlation ID header. Use admin portal to create a client, retrieve credentials, rotate secret, deactivate and reactivate. See quickstart.md SC-1 to SC-4.

- [x] T013 [P] [US1] Create `ApiClientModel` Eloquent model with UUID v7 boot hook, `integration.api_clients` table, `status`/`permissions`/`rate_limit_per_minute`/`last_used_at` fillable, and `ApiClientStatus` enum cast in `app/Modules/Integration/Infrastructure/Models/ApiClientModel.php`
- [x] T014 [P] [US1] Create migration `2026_10_01_000002_create_api_clients_table.php` â€” columns: `id UUID PK`, `name varchar(255)`, `api_key varchar(64) UNIQUE`, `secret_hash varchar(255)`, `status enum(active,inactive) DEFAULT active`, `permissions jsonb DEFAULT '[]'`, `rate_limit_per_minute int DEFAULT 60`, `last_used_at timestamptz`, `created_by_user_id UUID`, `created_at`, `updated_at` â€” with index on `(api_key)` and index on `(status)` in `app/Modules/Integration/Infrastructure/Migrations/2026_10_01_000002_create_api_clients_table.php`
- [x] T015 [P] [US1] Create migration `2026_10_01_000003_create_api_client_key_rotations_table.php` â€” columns: `id UUID PK`, `api_client_id UUID FK`, `old_key_prefix varchar(8)`, `rotated_by_user_id UUID`, `rotated_at timestamptz`, `reason text` â€” append-only, no update/delete â€” in `app/Modules/Integration/Infrastructure/Migrations/2026_10_01_000003_create_api_client_key_rotations_table.php`
- [x] T016 [P] [US1] Create `ApiClientKeyRotationModel` Eloquent model with UUID v7 boot hook and `integration.api_client_key_rotations` table in `app/Modules/Integration/Infrastructure/Models/ApiClientKeyRotationModel.php`
- [x] T017 [P] [US1] Create `CreateApiClientData` DTO (name, permissions array, rate_limit_per_minute) in `app/Modules/Integration/Application/DTOs/CreateApiClientData.php`
- [x] T018 [US1] Implement `ApiClientApplicationService` with methods: `create(CreateApiClientData): array` (generates 32-char hex api_key, 64-char hex secret, bcrypt hashes secret, returns model + plaintext secret once), `rotateSecret(string $id, ?string $reason): array` (generates new key+secret, invalidates old, appends rotation log), `updateStatus(string $id, ApiClientStatus $status): ApiClientModel`, `list(array $filters): LengthAwarePaginator`, `get(string $id): ApiClientModel` â€” all audit-logged via `AuditServiceInterface` â€” in `app/Modules/Integration/Application/Services/ApiClientApplicationService.php`
- [x] T019 [P] [US1] Create `CreateApiClientController` (POST, returns 201 with full api_key + once-shown secret) and `CreateApiClientRequest` (validates name, permissions, rate_limit_per_minute) in `app/Modules/Integration/Infrastructure/Http/Controllers/Admin/CreateApiClientController.php` and `app/Modules/Integration/Infrastructure/Http/Requests/CreateApiClientRequest.php`
- [x] T020 [P] [US1] Create `ListApiClientsController` (GET, paginated, filterable by status) in `app/Modules/Integration/Infrastructure/Http/Controllers/Admin/ListApiClientsController.php`
- [x] T021 [P] [US1] Create `GetApiClientController` (GET by id, 404 if not found) in `app/Modules/Integration/Infrastructure/Http/Controllers/Admin/GetApiClientController.php`
- [x] T022 [P] [US1] Create `RotateApiClientSecretController` (POST, returns new secret once, 404 if not found) in `app/Modules/Integration/Infrastructure/Http/Controllers/Admin/RotateApiClientSecretController.php`
- [x] T023 [P] [US1] Create `UpdateApiClientStatusController` (PATCH, activate/deactivate) and `UpdateApiClientStatusRequest` in `app/Modules/Integration/Infrastructure/Http/Controllers/Admin/UpdateApiClientStatusController.php` and `app/Modules/Integration/Infrastructure/Http/Requests/UpdateApiClientStatusRequest.php`
- [x] T024 [P] [US1] Create `ApiClientResource` (id, name, api_key_prefix (first 8 chars), status, permissions, rate_limit_per_minute, last_used_at, created_at; api_key and secret only present on `$this->when($this->showSecret, ...)`) in `app/Modules/Integration/Infrastructure/Http/Resources/ApiClientResource.php`
- [x] T025 [US1] Add API client admin routes to `routes/modules/integration.php` under `prefix('api/v1/integrations/clients')->middleware(['identity.auth', 'identity.require-permission:integrations.clients.write'])` for write ops and `integrations.read` for reads
- [x] T026 [P] [US1] Create `api-client-list.tsx` â€” table with columns (Name, API Key Prefix, Status badge, Permissions count, Last Used, Actions: View + Deactivate); Create Client side-sheet with form (name, permissions multi-select, rate_limit); once-shown secret modal on creation â€” in `wallet-ecosystem/apps/admin/src/modules/integrations/pages/api-client-list.tsx`
- [x] T027 [P] [US1] Create `api-client-detail.tsx` â€” shows client detail, Rotate Secret button (triggers once-shown secret modal), Activate/Deactivate toggle â€” in `wallet-ecosystem/apps/admin/src/modules/integrations/pages/api-client-detail.tsx`
- [x] T028 [P] [US1] Create `app/(admin)/integrations/page.tsx` delegating to `<ListApiClientsPage />` with `Metadata` title "API Clients â€” OleKard Admin" in `wallet-ecosystem/apps/admin/app/(admin)/integrations/page.tsx`
- [x] T029 [P] [US1] Create `app/(admin)/integrations/[id]/page.tsx` delegating to `<ApiClientDetailPage clientId={params.id} />` in `wallet-ecosystem/apps/admin/app/(admin)/integrations/[id]/page.tsx`
- [x] T030 [P] [US1] Create `IntegrationSeeder` â€” seeds one `active` mock API client with `integrations.read` permission and one mock SMS integration (`provider_slug: mock-sms`, `is_default: true`) for quickstart scenarios â€” in `app/Modules/Integration/Infrastructure/Seeders/IntegrationSeeder.php`
- [x] T031 [US1] Write feature tests â€” `ApiClientTest.php` covers: create returns 201 with api_key + secret; list returns paginated clients; rotate invalidates old creds; deactivate rejects auth; 403 without permission â€” in `app/Modules/Integration/Tests/Feature/Integration/ApiClientTest.php`
- [x] T032 [US1] Write feature tests â€” `RateLimitTest.php` covers: per-client 30 req/min triggers 429 on 31st; per-IP window independent of client; Retry-After header present; valid requests under quota pass â€” in `app/Modules/Integration/Tests/Feature/Integration/RateLimitTest.php`

**Checkpoint**: API client lifecycle fully functional. Middleware chain enforces auth, dual rate limit, correlation ID, and structured logging.

---

## Phase 4: User Story 2 â€” External Provider Integrations (Priority: P2)

**Goal**: Administrators can register, configure, and switch external providers (OTP, SMS, email, KYC, payment, bank, mobile-money) without deployment. Provider calls are dispatched asynchronously. All outbound calls are logged in `ProviderCallLog`.

**Independent Test**: Register a mock-sms integration, set as default, verify it appears in list, update its credentials_reference, set a second integration as default, confirm the first is demoted. Verify `ProviderCallLog` entry is written. See quickstart.md SC-9.

- [x] T033 [P] [US2] Create `IntegrationModel` Eloquent model with UUID v7 boot hook, `integration.integrations` table, `status`/`is_default` casts, `IntegrationProviderType` + `IntegrationStatus` enum casts in `app/Modules/Integration/Infrastructure/Models/IntegrationModel.php`
- [x] T034 [P] [US2] Create migration `2026_10_01_000004_create_integrations_table.php` â€” columns: `id UUID PK`, `provider_type varchar(32)`, `provider_slug varchar(64)`, `display_name varchar(255)`, `credentials_reference varchar(255)`, `endpoint_override varchar(500)`, `status enum DEFAULT active`, `is_default boolean DEFAULT false`, `configuration jsonb`, `created_by_user_id UUID`, `created_at`, `updated_at`; `UNIQUE(provider_type, provider_slug)`; partial unique index `WHERE is_default = TRUE` per provider_type enforced at application layer â€” in `app/Modules/Integration/Infrastructure/Migrations/2026_10_01_000004_create_integrations_table.php`
- [x] T035 [P] [US2] Create `ProviderCallLogModel` Eloquent model with UUID v7 boot hook and `integration.provider_call_logs` table, append-only (no updating/deleting) in `app/Modules/Integration/Infrastructure/Models/ProviderCallLogModel.php`
- [x] T036 [P] [US2] Create migration `2026_10_01_000005_create_provider_call_logs_table.php` â€” columns: `id UUID PK`, `integration_id UUID FK`, `correlation_id UUID`, `provider_reference varchar(255)`, `request_summary jsonb`, `response_code int`, `outcome enum(success,failure,timeout)`, `retry_eligible boolean DEFAULT false`, `elapsed_ms int`, `created_at`; indexes on `(integration_id, created_at)` and `(correlation_id)` â€” in `app/Modules/Integration/Infrastructure/Migrations/2026_10_01_000005_create_provider_call_logs_table.php`
- [x] T037 [P] [US2] Create `CreateIntegrationData` and `UpdateIntegrationData` DTOs in `app/Modules/Integration/Application/DTOs/`
- [x] T038 [US2] Implement `IntegrationRegistry` â€” resolves the active (default) `IntegrationModel` for a given `IntegrationProviderType`, then resolves the bound Laravel service-container implementation keyed by `$model->provider_slug`; caches result for up to 60 seconds (cache invalidated on `IntegrationStatusChangedEvent` or `IntegrationRegisteredEvent`); returns null if no active default exists for the type â€” in `app/Modules/Integration/Infrastructure/Providers/IntegrationRegistry.php`
- [x] T039 [US2] Implement `IntegrationApplicationService` with methods: `register(CreateIntegrationData): IntegrationModel` (409 if slug+type duplicate), `update(string $id, UpdateIntegrationData): IntegrationModel`, `updateStatus(string $id, IntegrationStatus): IntegrationModel`, `setDefault(string $id): IntegrationModel` (demotes previous default for same type), `list(array $filters): Collection`, `get(string $id): IntegrationModel`, `logCall(string $integrationId, array $callData): ProviderCallLogModel` â€” all config changes audit-logged â€” in `app/Modules/Integration/Application/Services/IntegrationApplicationService.php`
- [x] T040 [P] [US2] Wire `SmsChannelInterface` and `EmailChannelInterface` bindings in `IntegrationServiceProvider` to resolve active Integration configuration at runtime via `IntegrationRegistry` â€” when no active integration exists for a type, fall back to the existing `LogSmsChannel`/`LogEmailChannel` stubs â€” in `app/Modules/Integration/IntegrationServiceProvider.php`
- [x] T041 [P] [US2] Create `RegisterIntegrationController` (POST, returns 201) and `RegisterIntegrationRequest` (validates provider_type enum, provider_slug format, credentials_reference, is_default) in `app/Modules/Integration/Infrastructure/Http/Controllers/Admin/RegisterIntegrationController.php` and `app/Modules/Integration/Infrastructure/Http/Requests/RegisterIntegrationRequest.php`
- [x] T042 [P] [US2] Create `ListIntegrationsController` (GET, filterable by provider_type + status) in `app/Modules/Integration/Infrastructure/Http/Controllers/Admin/ListIntegrationsController.php`
- [x] T043 [P] [US2] Create `GetIntegrationController` (GET by id) in `app/Modules/Integration/Infrastructure/Http/Controllers/Admin/GetIntegrationController.php`
- [x] T044 [P] [US2] Create `UpdateIntegrationController` + `UpdateIntegrationRequest` (PATCH â€” display_name, credentials_reference, endpoint_override, configuration) in `app/Modules/Integration/Infrastructure/Http/Controllers/Admin/UpdateIntegrationController.php`
- [x] T045 [P] [US2] Create `UpdateIntegrationStatusController` + `UpdateIntegrationStatusRequest` (PATCH status) in `app/Modules/Integration/Infrastructure/Http/Controllers/Admin/UpdateIntegrationStatusController.php`
- [x] T046 [P] [US2] Create `SetDefaultIntegrationController` (POST â€” demotes previous default for same type) in `app/Modules/Integration/Infrastructure/Http/Controllers/Admin/SetDefaultIntegrationController.php`
- [x] T047 [P] [US2] Create `ListProviderCallLogsController` (GET, paginated, filterable by integration_id, outcome, correlation_id) in `app/Modules/Integration/Infrastructure/Http/Controllers/Admin/ListProviderCallLogsController.php`
- [x] T048 [P] [US2] Create `GetProviderCallLogController` (GET by id) in `app/Modules/Integration/Infrastructure/Http/Controllers/Admin/GetProviderCallLogController.php`
- [x] T049 [P] [US2] Create `IntegrationResource` and `ProviderCallLogResource` in `app/Modules/Integration/Infrastructure/Http/Resources/`
- [x] T050 [US2] Add integration management and provider call log admin routes to `routes/modules/integration.php` under `identity.auth` + `identity.require-permission:integrations.providers.write` / `integrations.read`
- [x] T051 [P] [US2] Create `integration-list.tsx` â€” table columns (Provider Type, Slug, Display Name, Status badge, Is Default, Actions: Set Default + Activate/Deactivate); register new provider side-sheet form â€” in `wallet-ecosystem/apps/admin/src/modules/integrations/pages/integration-list.tsx`
- [x] T052 [P] [US2] Create `app/(admin)/integrations/providers/page.tsx` delegating to `<ListIntegrationsPage />` in `wallet-ecosystem/apps/admin/app/(admin)/integrations/providers/page.tsx`
- [x] T053 [US2] Write feature tests â€” `IntegrationManagementTest.php` covers: register 201; duplicate slug+type returns 409; set-default demotes previous; status update audit-logged; list filtered by provider_type; call log written â€” in `app/Modules/Integration/Tests/Feature/Integration/IntegrationManagementTest.php`

**Checkpoint**: Provider integrations registerable and configurable. IntegrationRegistry resolves active adapter per type. SMS/Email channels bound dynamically.

---

## Phase 5: User Story 3 â€” Webhook Processing Pipeline (Priority: P3)

**Goal**: The platform receives provider webhooks at a public endpoint, verifies signatures, de-duplicates by idempotency key, persists events, dispatches asynchronously, retries on handler failure with exponential back-off, moves exhausted events to dead-letter, alerts via E17, and gives operators a monitoring surface.

**Independent Test**: POST a mock-payment webhook with valid HMAC â€” returns 200, event stored with status `received`. POST same payload again â€” returns 200, no second DB row. POST with bad signature â€” returns 401. Trigger handler failure, verify retry â†’ dead_letter transition, verify E17 notification dispatched. See quickstart.md SC-5 to SC-10.

- [x] T054 [P] [US3] Create `WebhookEventModel` Eloquent model with UUID v7 boot hook, `integration.webhook_events` table, `WebhookEventStatus` enum cast, `updating`/`deleting` hooks enforce valid state transitions (cannot revert from `dead_letter` except via `requeue`) in `app/Modules/Integration/Infrastructure/Models/WebhookEventModel.php`
- [x] T055 [P] [US3] Create migration `2026_10_01_000006_create_webhook_events_table.php` â€” columns: `id UUID PK`, `provider_type varchar(32)`, `provider_slug varchar(64)`, `idempotency_key varchar(255)`, `event_type varchar(100)`, `raw_payload text`, `source_ip_hash varchar(64)`, `status enum(received,processing,processed,failed,dead_letter) DEFAULT received`, `retry_count int DEFAULT 0`, `max_retry_count int DEFAULT 5`, `last_error text`, `platform_entity_id UUID`, `platform_entity_type varchar(64)`, `received_at timestamptz`, `processed_at timestamptz`, `created_at`, `updated_at`; `UNIQUE(provider_slug, idempotency_key)`; partial index on `(status, created_at) WHERE status IN ('failed','dead_letter')` â€” in `app/Modules/Integration/Infrastructure/Migrations/2026_10_01_000006_create_webhook_events_table.php`
- [x] T056 [P] [US3] Create `WebhookProcessingAttemptModel` Eloquent model with UUID v7 boot hook and `integration.webhook_processing_attempts` table, append-only in `app/Modules/Integration/Infrastructure/Models/WebhookProcessingAttemptModel.php`
- [x] T057 [P] [US3] Create migration `2026_10_01_000007_create_webhook_processing_attempts_table.php` â€” columns: `id UUID PK`, `webhook_event_id UUID FK`, `attempt_number int`, `outcome enum(success,failure)`, `error_message text`, `attempted_at timestamptz`; index on `(webhook_event_id, attempted_at)` â€” in `app/Modules/Integration/Infrastructure/Migrations/2026_10_01_000007_create_webhook_processing_attempts_table.php`
- [x] T058 [US3] Implement `ProcessWebhookEventJob` â€” (1) DB transaction: set status=`processing`; (2) resolve domain handler via `IntegrationRegistry` by provider_slug + event_type and call it; (3) on success: DB transaction: set status=`processed`, processed_at=now(), append `WebhookProcessingAttempt(outcome=success)`, publish `WebhookEventProcessedEvent`; (4) on exception: increment retry_count; if retry_count < max_retry_count set status=`failed`, append attempt(outcome=failure), dispatch `RetryWebhookEventJob`; else set status=`dead_letter`, append attempt(outcome=failure), publish `WebhookEventDeadLetteredEvent` â€” in `app/Modules/Integration/Infrastructure/Jobs/ProcessWebhookEventJob.php`
- [x] T059 [US3] Implement `RetryWebhookEventJob` â€” receives webhook_event_id + attempt_number; applies exponential back-off delay (`2^attempt_number * 30` seconds, max 3600s); sets status=`processing`, then re-dispatches `ProcessWebhookEventJob` â€” dispatched on `webhooks` queue â€” in `app/Modules/Integration/Infrastructure/Jobs/RetryWebhookEventJob.php`
- [x] T060 [US3] Implement `WebhookApplicationService` â€” `receive(string $providerSlug, string $rawPayload, string $signature, array $headers, string $sourceIp): WebhookEventModel` (resolves Integration, calls `ProviderWebhookContract::parse()`, checks idempotency, persists with status=received, returns model); `requeue(string $webhookEventId): WebhookEventModel` (asserts dead_letter status, resets retry_count=0, sets status=received, dispatches `ProcessWebhookEventJob`) â€” in `app/Modules/Integration/Application/Services/WebhookApplicationService.php`
- [x] T061 [US3] Create `ReceiveWebhookController` â€” resolves provider slug from route param; calls `WebhookApplicationService::receive()`; catches `WebhookProviderNotFoundException` â†’ 404, `WebhookSignatureException` â†’ 401; on success returns 200 with `{success:true, data:{webhook_event_id, status:'received'}}`; dispatches `ProcessWebhookEventJob` AFTER returning response â€” in `app/Modules/Integration/Infrastructure/Http/Controllers/Webhook/ReceiveWebhookController.php`
- [x] T062 [P] [US3] Create `ListWebhookEventsController` (GET, paginated, filterable by status, provider_slug, provider_type) in `app/Modules/Integration/Infrastructure/Http/Controllers/Admin/ListWebhookEventsController.php`
- [x] T063 [P] [US3] Create `GetWebhookEventController` (GET by id, includes processing_attempts array) in `app/Modules/Integration/Infrastructure/Http/Controllers/Admin/GetWebhookEventController.php`
- [x] T064 [P] [US3] Create `RequeueWebhookEventController` (POST â€” 409 if not dead_letter status) in `app/Modules/Integration/Infrastructure/Http/Controllers/Admin/RequeueWebhookEventController.php`
- [x] T065 [P] [US3] Create `WebhookEventResource` (id, provider_type, provider_slug, idempotency_key, event_type, status, retry_count, max_retry_count, last_error, platform_entity_id, platform_entity_type, received_at, processed_at; includes processing_attempts when loaded) in `app/Modules/Integration/Infrastructure/Http/Resources/WebhookEventResource.php`
- [x] T066 [US3] Add public webhook receiver route (`POST api/v1/webhooks/{providerSlug}` â€” no identity.auth, with `integration.correlation-id` + `integration.rate-limit`) and admin webhook management routes to `routes/modules/integration.php`
- [x] T067 [P] [US3] Register `WebhookEventDeadLetteredEvent` â†’ E17 notification listener in `IntegrationServiceProvider` â€” listener calls `NotificationService::createFromEvent(new CreateNotificationCommand(..., notificationType: 'integration.webhook.dead_lettered', category: NotificationCategory::Operational, ...))` following E17 integration pattern â€” in `app/Modules/Integration/IntegrationServiceProvider.php`
- [x] T068 [P] [US3] Create `webhook-monitor.tsx` â€” table with status filter, provider_slug filter, status Badge column, retry_count column, Actions (View Detail + Requeue if dead_letter); detail side-panel shows raw payload summary and processing attempts timeline â€” in `wallet-ecosystem/apps/admin/src/modules/integrations/pages/webhook-monitor.tsx`
- [x] T069 [P] [US3] Create `app/(admin)/integrations/webhooks/page.tsx` delegating to `<WebhookMonitorPage />` in `wallet-ecosystem/apps/admin/app/(admin)/integrations/webhooks/page.tsx`
- [x] T070 [US3] Write feature tests â€” `WebhookReceiveTest.php` covers: valid signature returns 200 with received status; invalid signature returns 401; unknown provider_slug returns 404; duplicate delivery (same idempotency_key, sequential) returns 200 without second DB row â€” in `app/Modules/Integration/Tests/Feature/Integration/WebhookReceiveTest.php`
- [x] T070b [US3] Write concurrency test â€” `WebhookConcurrencyTest.php` â€” dispatch two parallel HTTP clients (using Laravel `Concurrency::run()` or two separate test transactions) that POST the identical provider webhook payload simultaneously; assert exactly one row exists in `integration.webhook_events` for the idempotency_key after both requests complete and exactly one `ProcessWebhookEventJob` was dispatched; verifies `UNIQUE(provider_slug, idempotency_key)` holds under race condition and satisfies SC-005 (constitution Principle 4 MUST test concurrency for financial webhook flows) â€” in `app/Modules/Integration/Tests/Feature/Integration/WebhookConcurrencyTest.php`
- [x] T071 [US3] Write feature tests â€” `WebhookRetryTest.php` covers: handler failure sets status=failed and dispatches RetryWebhookEventJob; after max retries reached status=dead_letter and WebhookEventDeadLetteredEvent published; requeue endpoint resets retry_count and status=received; requeue on non-dead_letter returns 409 â€” in `app/Modules/Integration/Tests/Feature/Integration/WebhookRetryTest.php`

**Checkpoint**: Full webhook pipeline operational. SC-5 to SC-10 pass in quickstart.md. T070b concurrency test satisfies constitution Principle 4.

---

## Phase 6: Polish & Cross-Cutting Concerns

**Purpose**: Integration events, outbox wiring, OpenAPI copy, static analysis, quickstart validation.

- [x] T072 [P] Create all 8 integration event classes implementing `IntegrationEventInterface` as `final readonly class` â€” `ApiClientCreatedEvent`, `ApiClientSecretRotatedEvent`, `ApiClientStatusChangedEvent`, `IntegrationRegisteredEvent`, `IntegrationStatusChangedEvent`, `WebhookEventReceivedEvent`, `WebhookEventProcessedEvent`, `WebhookEventDeadLetteredEvent` â€” each with `eventName()`, `occurredAt()`, `correlationId()`, `toArray()` â€” in `app/Modules/Integration/Domain/Events/`
- [x] T073 Wire all 8 integration events with `OutboxEventPublisher` inside DB transactions in `IntegrationServiceProvider` and update `ApiClientApplicationService`, `IntegrationApplicationService`, `WebhookApplicationService`, and `ProcessWebhookEventJob` to publish via `OutboxEventPublisher` (requires active `DB::connection('platform')->transaction()`) in `app/Modules/Integration/IntegrationServiceProvider.php`
- [x] T074 [P] Copy `specs/025-api-integration-platform/contracts/integration.yaml` to `wallet-ecosystem/services/api/openapi/v1/integration.yaml`
- [x] T077 [P] Emit observability metrics via MetricsCollectorInterface — inject into ApiClientAuthMiddleware (emit integration.request.auth_failure on 401), DualRateLimitMiddleware (emit integration.request.rate_limited on 429), and ProcessWebhookEventJob (emit integration.webhook.processed on success, integration.webhook.dead_lettered on dead-letter) — add assertion in RateLimitTest that metric is emitted on 429 — in app/Modules/Integration/Infrastructure/Http/Middleware/ and app/Modules/Integration/Infrastructure/Jobs/ProcessWebhookEventJob.php
- [x] T075 Run PHPStan level 8 on `app/Modules/Integration/` â€” `./vendor/bin/phpstan analyse app/Modules/Integration --level=8 --configuration=phpstan.neon` â€” fix all errors (requires Docker)
- [x] T076 Run quickstart.md scenarios SC-1 through SC-10 against Docker environment and confirm all pass (requires Docker)

---

## Dependencies & Execution Order

### Phase Dependencies

- **Phase 1 (Setup)**: No dependencies â€” start immediately
- **Phase 2 (Foundation)**: Depends on Phase 1 â€” BLOCKS all user stories
- **Phase 3 (US1)**: Depends on Phase 2 â€” T013â€“T032
- **Phase 4 (US2)**: Depends on Phase 2; T038 (IntegrationRegistry) depends on T033â€“T036 (models/migrations) â€” T039 depends on T038
- **Phase 5 (US3)**: Depends on Phase 2 and T038 (IntegrationRegistry for handler dispatch); T058/T059/T060 depend on T054â€“T057 (models/migrations)
- **Phase 6 (Polish)**: Depends on all user story phases complete

### User Story Dependencies

- **US1**: Can start after Phase 2. No dependency on US2 or US3.
- **US2**: Can start after Phase 2. T040 (channel binding) benefits from T038 (registry). Independent of US1 and US3.
- **US3**: Can start after Phase 2 + T038 (registry needed to resolve webhook handlers). Independent of US1 and US2 otherwise.

### Within Each Phase

- Models before migrations; migrations before services; services before controllers; controllers before routes
- All `[P]`-marked tasks within a phase can run simultaneously
- Admin portal pages (all `[P]`) can run in parallel with backend tasks for the same story

### Parallel Opportunities

- T003, T004, T005 (Phase 1) â€” run in parallel
- T007, T008, T009, T010 (Phase 2) â€” T007/T008/T010 parallel; T009 after T010 (uses client ID from auth middleware)
- T013, T014, T015, T016, T017 (US1 models/DTOs) â€” all parallel
- T019â€“T024 (US1 controllers/resources) â€” all parallel after T018 (service)
- T026â€“T030 (US1 admin portal + seeder) â€” all parallel
- T033â€“T037 (US2 models/DTOs) â€” all parallel
- T041â€“T049 (US2 controllers/resources) â€” all parallel after T039 (service)
- T054â€“T057 (US3 models/migrations) â€” all parallel
- T062â€“T065 (US3 admin controllers/resource) â€” all parallel after T060 (service)

---

## Parallel Example: User Story 1

```
# Parallel: models + migrations
T013 ApiClientModel         T014 api_clients migration     T015 api_client_key_rotations migration
T016 ApiClientKeyRotationModel   T017 CreateApiClientData DTO

# Sequential: service (depends on models)
T018 ApiClientApplicationService

# Parallel: controllers (all depend on service)
T019 CreateApiClientController   T020 ListApiClientsController   T021 GetApiClientController
T022 RotateApiClientSecretController   T023 UpdateApiClientStatusController   T024 ApiClientResource

# Sequential: routes (depends on controllers)
T025 Add API client routes

# Parallel: admin portal + seeder (independent of routes)
T026 api-client-list.tsx   T027 api-client-detail.tsx
T028 integrations/page.tsx   T029 integrations/[id]/page.tsx   T030 IntegrationSeeder

# Sequential: tests (need full stack)
T031 ApiClientTest.php   T032 RateLimitTest.php
```

---

## Implementation Strategy

### MVP First (US1 Only)

1. Complete Phase 1: Setup
2. Complete Phase 2: Foundational middleware stack
3. Complete Phase 3: US1 (API Gateway + Client Management)
4. **STOP and VALIDATE**: Run quickstart.md SC-1 to SC-4 independently
5. API clients fully manageable; gateway auth/rate-limit/logging fully operational

### Incremental Delivery

1. Setup + Foundation â†’ middleware chain functional
2. US1 â†’ API client lifecycle + gateway enforcement (MVP)
3. US2 â†’ Provider configuration registry + adapter binding
4. US3 â†’ Webhook pipeline + monitoring
5. Polish â†’ Events, OpenAPI, PHPStan, quickstart

### Task Summary

| Phase | Tasks | Parallel Opportunities |
|---|---|---|
| Phase 1: Setup | T001â€“T006 (6) | T003, T004, T005 |
| Phase 2: Foundation | T007â€“T012 (6) | T008, T010, T012 |
| Phase 3: US1 | T013â€“T032 (20) | T013â€“T017, T019â€“T030 |
| Phase 4: US2 | T033â€“T053 (21) | T033â€“T037, T041â€“T052 |
| Phase 5: US3 | T054â€“T071b (19) | T054â€“T057, T062â€“T065, T067â€“T069 |
| Phase 6: Polish | T072â€“T076 (5) | T072, T074 |
| **Total** | **78** | |

---

## Phase 7: Convergence

- [x] T078 Emit gateway latency histogram -- in ApiClientAuthMiddleware record start time before `password_verify` and emit `metrics->histogram("integration.request.latency_ms", $elapsedMs)` immediately before returning `$next($request)`; add a test assertion in RateLimitTest that the histogram has at least one value after a successful authenticated request per SC-002 (partial)
- [x] T079 Add negative credential log assertion test -- in ApiClientTest add a test that after creating a client the Laravel log (captured via Log::spy() or withoutExceptionHandling + log assertion) contains no occurrence of the raw secret or full api_key string; satisfies SC-008 (missing)
- [x] T080 Assert IntegrationRegistry cache is invalidated on setDefault -- in IntegrationManagementTest add assertion that after calling the set-default endpoint the IntegrationRegistry resolves the newly-defaulted integration as the default for its provider type (i.e., app(IntegrationRegistryInterface::class)->resolveDefault(type) returns the new id); verifies SC-007 60-second propagation via synchronous cache flush per SC-007 (partial)
- [x] T081 [P] Emit integration.webhook.received metric in WebhookApplicationService -- after the idempotency check passes and before dispatching ProcessWebhookEventJob call `$this->metrics->increment("integration.webhook.received", ["provider_slug" => $providerSlug])`; inject MetricsCollectorInterface via constructor alongside existing dependencies in app/Modules/Integration/Application/Services/WebhookApplicationService.php per C3 (missing)
