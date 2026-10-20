# Implementation Plan — E25 API & Integration Platform

## Constitution Check
- [x] Approved Laravel/Next.js/Flutter stack respected — no new framework introduced
- [x] No TBD value invented — provider slugs and credentials are configurable, not hard-coded
- [x] Authorization remains server-side — `ApiClientAuthMiddleware` + `RequirePermissionMiddleware` applied to all admin endpoints
- [x] Financial integrity preserved — webhook handlers for financial events do not mutate wallet balances directly; they call domain services (Funding, Transfer, etc.) which own ledger posting
- [x] Security/audit requirements incorporated — API key + bcrypt-stored secret, all client management actions audit-logged, IP hashed in rate-limit keys and webhook source_ip_hash, no credentials in logs
- [x] UX/design-system requirements incorporated — Admin portal pages for API client + provider management + webhook monitoring; shared design tokens
- [x] QA strategy incorporated — 10 quickstart scenarios (SC-1 to SC-10), unit + feature tests per story

## Technical Context

- **Backend**: Laravel / PHP 8.4+
- **Database**: PostgreSQL — new `integration` schema; 6 tables (`api_clients`, `api_client_key_rotations`, `integrations`, `provider_call_logs`, `webhook_events`, `webhook_processing_attempts`), 7 migrations
- **Queue/Cache**: Redis + Laravel Horizon — `webhooks` queue for webhook processing
- **Web**: Next.js + TypeScript — Admin portal pages (4 pages)
- **Mobile**: Flutter — no changes for E25
- **API**: REST/OpenAPI — `contracts/integration.yaml`
- **Testing**: PHPUnit/Pest feature tests, unit tests; see `quickstart.md` for integration scenarios
- **Observability**: structured logs, correlation IDs on all requests, metrics via `MetricsCollectorInterface`

### Module Namespace

`App\Modules\Integration\` — follows the established module pattern.

### Dependencies on Prior Epics

| Dependency | Used by E25 for |
|---|---|
| `App\Modules\Platform\Domain\Contracts\Providers\ProviderAdapterInterface` | Provider dispatch (send/receive) |
| `App\Modules\Platform\Domain\Contracts\Providers\ProviderWebhookContract` | Webhook signature verification and normalization |
| `App\Modules\Platform\Domain\ValueObjects\Providers\NormalizedWebhookEvent` | Webhook data transfer |
| `App\Modules\Platform\Application\Services\OutboxEventPublisher` | Integration event publication |
| `App\Modules\Platform\Application\Services\AuditService` | Audit logging |
| `App\Modules\Platform\Application\Services\ConfigurationService` | Rate-limit quotas, provider config |
| `App\Modules\Platform\Domain\Contracts\MetricsCollectorInterface` | Latency and failure metrics |
| `App\Modules\Identity\Infrastructure\Http\Middleware\RequirePermissionMiddleware` | Admin route authorization |
| `App\Modules\Notification\Domain\Contracts\SmsChannelInterface` | SMS/OTP adapter binding target |
| `App\Modules\Notification\Domain\Contracts\EmailChannelInterface` | Email adapter binding target |

---

## Module Structure

```
app/Modules/Integration/
  Domain/
    Contracts/
      ApiClientRepositoryInterface.php
      IntegrationRepositoryInterface.php
      WebhookEventRepositoryInterface.php
      IntegrationRegistryInterface.php
    Enums/
      ApiClientStatus.php
      IntegrationProviderType.php
      IntegrationStatus.php
      ProviderCallOutcome.php
      WebhookEventStatus.php
    Events/
      ApiClientCreatedEvent.php
      ApiClientSecretRotatedEvent.php
      ApiClientStatusChangedEvent.php
      IntegrationRegisteredEvent.php
      IntegrationStatusChangedEvent.php
      WebhookEventReceivedEvent.php
      WebhookEventProcessedEvent.php
      WebhookEventDeadLetteredEvent.php
    Exceptions/
      ApiClientNotFoundException.php
      InvalidApiCredentialsException.php
      WebhookProviderNotFoundException.php
      WebhookNotDeadLetterException.php

  Application/
    Services/
      ApiClientApplicationService.php
      IntegrationApplicationService.php
      WebhookApplicationService.php
    DTOs/
      CreateApiClientData.php
      CreateIntegrationData.php
      UpdateIntegrationData.php

  Infrastructure/
    Http/
      Middleware/
        ApiClientAuthMiddleware.php
        DualRateLimitMiddleware.php
        CorrelationIdMiddleware.php       (ensures X-Correlation-ID on every request)
        RequestResponseLoggerMiddleware.php
      Controllers/
        Admin/
          CreateApiClientController.php
          ListApiClientsController.php
          GetApiClientController.php
          RotateApiClientSecretController.php
          UpdateApiClientStatusController.php
          RegisterIntegrationController.php
          ListIntegrationsController.php
          GetIntegrationController.php
          UpdateIntegrationController.php
          UpdateIntegrationStatusController.php
          SetDefaultIntegrationController.php
          ListWebhookEventsController.php
          GetWebhookEventController.php
          RequeueWebhookEventController.php
          ListProviderCallLogsController.php
          GetProviderCallLogController.php
        Webhook/
          ReceiveWebhookController.php
      Requests/
        CreateApiClientRequest.php
        UpdateApiClientStatusRequest.php
        RegisterIntegrationRequest.php
        UpdateIntegrationRequest.php
        UpdateIntegrationStatusRequest.php
      Resources/
        ApiClientResource.php
        IntegrationResource.php
        WebhookEventResource.php
        ProviderCallLogResource.php
    Jobs/
      ProcessWebhookEventJob.php
      RetryWebhookEventJob.php
    Models/
      ApiClientModel.php
      ApiClientKeyRotationModel.php
      IntegrationModel.php
      ProviderCallLogModel.php
      WebhookEventModel.php
      WebhookProcessingAttemptModel.php
    Migrations/
      2026_10_01_000001_create_integration_schema.php
      2026_10_01_000002_create_api_clients_table.php
      2026_10_01_000003_create_api_client_key_rotations_table.php
      2026_10_01_000004_create_integrations_table.php
      2026_10_01_000005_create_provider_call_logs_table.php
      2026_10_01_000006_create_webhook_events_table.php
      2026_10_01_000007_create_webhook_processing_attempts_table.php
    Providers/
      IntegrationRegistry.php           (maps provider_slug → ProviderWebhookContract impl)
    Seeders/
      IntegrationSeeder.php
    Tests/
      Feature/
        Integration/
          ApiClientTest.php             (SC-1, SC-4)
          RateLimitTest.php             (SC-2, SC-3)
          WebhookReceiveTest.php        (SC-5, SC-6, SC-7)
          WebhookRetryTest.php          (SC-8, SC-10)
          IntegrationManagementTest.php (SC-9)

  IntegrationServiceProvider.php

routes/modules/integration.php

wallet-ecosystem/apps/admin/
  app/(admin)/integrations/
    page.tsx                            → ListApiClientsPage
    [id]/page.tsx                       → ApiClientDetailPage
  app/(admin)/integrations/providers/
    page.tsx                            → ListIntegrationsPage
  app/(admin)/integrations/webhooks/
    page.tsx                            → WebhookMonitorPage

wallet-ecosystem/apps/admin/src/modules/integrations/pages/
  api-client-list.tsx
  api-client-detail.tsx
  integration-list.tsx
  webhook-monitor.tsx

wallet-ecosystem/services/api/openapi/v1/integration.yaml   (copied from contracts/)
```

---

## Middleware Stack

All inbound requests to the E25 module's admin endpoints pass through:
1. `CorrelationIdMiddleware` — generates/validates `X-Correlation-ID`
2. `ApiClientAuthMiddleware` — validates Bearer base64(key:secret) for M2M endpoints (webhook receiver only); admin endpoints use existing `identity.auth`
3. `DualRateLimitMiddleware` — evaluates per-client + per-IP Redis windows
4. `RequestResponseLoggerMiddleware` — structured log on ingress and egress

The public webhook endpoint (`POST /api/v1/webhooks/{slug}`) uses:
1. `CorrelationIdMiddleware`
2. `DualRateLimitMiddleware` (IP window only — no client ID on public endpoint)
3. Signature verification is handled inside `ReceiveWebhookController` via `ProviderWebhookContract::parse()`

---

## Webhook Job Flow

```
HTTP POST /api/v1/webhooks/{slug}
  └─ ReceiveWebhookController
       ├─ Resolve IntegrationModel by provider_slug (404 if missing/inactive)
       ├─ Resolve ProviderWebhookContract from container
       ├─ parse(rawPayload, signature, headers)  ← WebhookSignatureException → 401
       ├─ Idempotency check: SELECT by (provider_slug, idempotency_key)
       │   └─ If found & status ≠ received: return 200 immediately
       ├─ INSERT WebhookEvent(status=received)
       ├─ Return 200 with webhook_event_id
       └─ Dispatch ProcessWebhookEventJob(webhook_event_id)

ProcessWebhookEventJob::handle()
  ├─ DB::transaction: set status=processing
  ├─ Resolve domain handler for event_type (via IntegrationRegistry)
  ├─ Call handler (e.g., FundingProviderCallbackService::handle())
  ├─ On success: DB::transaction: set status=processed, processed_at=now()
  │   ├─ Append WebhookProcessingAttempt(outcome=success)
  │   └─ Publish WebhookEventProcessedEvent
  └─ On exception:
       ├─ Increment retry_count
       ├─ If retry_count < max_retry_count:
       │   ├─ Set status=failed
       │   ├─ Append WebhookProcessingAttempt(outcome=failure)
       │   └─ Dispatch RetryWebhookEventJob (exponential back-off)
       └─ If retry_count >= max_retry_count:
           ├─ Set status=dead_letter
           ├─ Append WebhookProcessingAttempt(outcome=failure)
           └─ Publish WebhookEventDeadLetteredEvent (→ E17 alert)
```

---

## Integration Events (IntegrationEventInterface)

| Event class | eventName() | Trigger |
|---|---|---|
| `ApiClientCreatedEvent` | `Integration.ApiClient.Created` | Admin creates client |
| `ApiClientSecretRotatedEvent` | `Integration.ApiClient.SecretRotated` | Admin rotates secret |
| `ApiClientStatusChangedEvent` | `Integration.ApiClient.StatusChanged` | Admin activate/deactivate |
| `IntegrationRegisteredEvent` | `Integration.Provider.Registered` | Admin registers provider |
| `IntegrationStatusChangedEvent` | `Integration.Provider.StatusChanged` | Admin activate/deactivate |
| `WebhookEventReceivedEvent` | `Integration.Webhook.Received` | Webhook accepted |
| `WebhookEventProcessedEvent` | `Integration.Webhook.Processed` | Handler success |
| `WebhookEventDeadLetteredEvent` | `Integration.Webhook.DeadLettered` | Max retries exceeded |

All events are `final readonly class implements IntegrationEventInterface`.

---

## Admin Portal Pages

| Route | Component | Purpose |
|---|---|---|
| `/integrations` | `ListApiClientsPage` | List, create, activate/deactivate API clients |
| `/integrations/:id` | `ApiClientDetailPage` | View client detail, rotate secret |
| `/integrations/providers` | `ListIntegrationsPage` | List, register, set-default provider integrations |
| `/integrations/webhooks` | `WebhookMonitorPage` | Filter by status/provider, view detail, re-queue dead-letter |

---

## DB / Financial Controls

- No financial mutations in E25; webhook handlers call existing domain services
- `UNIQUE (provider_slug, idempotency_key)` on webhook_events — DB-level de-duplication guard
- `api_client_key_rotations` and `webhook_processing_attempts` are append-only
- All migrations are idempotent (IF NOT EXISTS guards)

---

## Implementation Approach

1. Migrations + enums + base models (Phase 1 — Setup)
2. Middleware chain (CorrelationId, DualRateLimit, Logger) + ApiClientAuth (Phase 2 — Foundation)
3. ApiClientApplicationService + admin controllers + Admin portal pages (Phase 3 — US1)
4. IntegrationApplicationService + IntegrationRegistry + provider stubs for SMS/email via Notification channels (Phase 4 — US2)
5. ReceiveWebhookController + ProcessWebhookEventJob + RetryWebhookEventJob + WebhookApplicationService + webhook admin controllers (Phase 5 — US3)
6. Integration events, outbox wiring, PHPStan, OpenAPI copy, quickstart validation (Phase 6 — Polish)

## Complexity Tracking

Any architecture deviation requires an ADR and constitution check update. Provider slug choices remain TBD per constitution Principle 2 — seeder uses `mock-*` slugs for development.
