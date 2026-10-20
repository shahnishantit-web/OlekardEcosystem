# Implementation Plan: Platform Foundation

**Branch**: `999-platform-foundation` | **Date**: 2026-08-16 | **Spec**: [spec.md](spec.md)

**Input**: Platform Foundation — Technical infrastructure and cross-cutting contracts for the Wallet Ecosystem

---

## Summary

Platform Foundation establishes the complete technical infrastructure that all 34 subsequent business-domain epics (E01–E34) depend upon. It implements zero business logic. It delivers: a Laravel 11/PHP 8.4 modular monolith with PostgreSQL schema-per-module isolation, idempotency infrastructure (PostgreSQL authoritative + Redis cache), Outbox-pattern event architecture with dot-notation PascalCase naming, security and audit foundations, observability, configuration/policy framework, Next.js 14 App Router admin shell, Flutter Riverpod mobile foundation for Customer and Agent apps, a shared token-driven design system, accessibility infrastructure, CI/CD pipelines, Docker-based local development, and comprehensive testing infrastructure.

---

## Technical Context

**Language/Version**: PHP 8.4+ (Laravel 11.x LTS), TypeScript 5.x, Dart 3.x

**Primary Dependencies**:
- `laravel/framework` 11.x, `laravel/horizon`, `ramsey/uuid` 4.7+
- `pestphp/pest`, `pestphp/pest-plugin-arch`, `darkaonline/l5-swagger`
- `react` 18.x, `next` 14.x, `@tanstack/react-query`, `tailwindcss`
- `flutter_riverpod`, `riverpod_generator`, `riverpod_lint`, `dio`, `go_router`
- `style-dictionary` 4.x (design token pipeline)

**Storage**: PostgreSQL 15.x (primary, schema-per-module), Redis 7.x (cache, queue, idempotency cache)

**Testing**: Pest (PHP/backend), Jest + React Testing Library (Next.js), flutter_test + integration_test (Flutter)

**Target Platform**: Linux container (production), Docker Compose (local dev), Next.js on Node.js, Flutter on iOS/Android/Web

**Performance Goals**: TBD per constitution. Observability query response ≤ 1 second (SC-014). Onboarding ≤ 30 minutes (SC-001).

**Constraints**: No cross-schema SQL JOINs. Redis not source of financial truth (FR-057). No TBD values invented. No business domains implemented.

**Scale/Scope**: 9 domain modules, ~175 FRs, 6 implementation phases over ~6 weeks. Foundation for 34 epics, 538 stories.

---

## Constitution Check

*Gate: Must pass before proceeding. Re-checked after Phase 1 design.*

| Principle | Status | Evidence |
|---|---|---|
| P1 — Source of Truth | ✅ PASS | Plan derives from approved spec; no silent business intent changes |
| P2 — TBD Governance | ✅ PASS | All TBD items preserved as configurable placeholders; none invented |
| P3 — Configuration over Hard-Coding | ✅ PASS | `ConfigurationService` covers all policy categories; supported currencies seeded |
| P4 — Financial Integrity | ✅ PASS | Idempotency via PostgreSQL (FR-057 compliant); Outbox pattern; audit immutability |
| P5 — Identity and Authorization | ✅ PASS | Security foundation defines boundary; actual auth logic deferred to E01 |
| P6 — Zero Trust | ✅ PASS | All endpoints require auth; secrets externalized; no credentials in logs |
| P7 — Domain Boundaries | ✅ PASS | Schema-per-module; no cross-schema JOINs; module contracts via interfaces |
| P8 — API Contracts | ✅ PASS | `/v1/` versioning; UUID v7 IDs; `Idempotency-Key` required on financial ops; OpenAPI |
| P9 — Event Reliability | ✅ PASS | Outbox pattern; idempotent consumers; retry + DLQ |
| P10 — UX and Design System | ✅ PASS | Shared token-driven design system; Next.js + Flutter implementations |
| P11 — Accessibility | ✅ PASS | WCAG AA baseline; keyboard/focus/ARIA/contrast/motion/touch requirements |
| P12 — Test-First Quality | ✅ PASS | Testing infrastructure for all test types; coverage enforcement |
| P13 — Observability | ✅ PASS | Structured JSON logging; correlation IDs; metrics; health endpoints |
| P14 — No Silent Architecture Changes | ✅ PASS | Stack unchanged: Laravel, Next.js, Flutter, PostgreSQL, Redis |
| P15 — Release Governance | ✅ PASS | Acceptance criteria, QA evidence, security gate, coverage gate defined |

**GATE: ALL PASS — proceed with implementation.**

---

## Project Structure

### Documentation (this feature)

```
specs/999-platform-foundation/
├── spec.md                    # Approved specification
├── plan.md                    # This file
├── research.md                # Technology decisions and rationale
├── data-model.md              # Entity definitions, schema map, table schemas
├── quickstart.md              # Validation guide
├── contracts/
│   ├── api-response-envelope.md   # REST API response contract
│   ├── event-envelope.md          # Event envelope + naming convention
│   ├── module-contracts.md        # PHP interface contracts per module
│   └── idempotency-protocol.md    # Idempotency request/response protocol
└── tasks.md                   # Task breakdown (output of /speckit-tasks)
```

### Repository Root Structure

```
wallet-ecosystem/                   # Monorepo root
├── apps/
│   ├── admin/                      # Next.js 14 App Router (Admin/Enterprise)
│   │   ├── app/                    # App Router: layouts, pages, loading, error
│   │   ├── components/             # Admin-specific components
│   │   ├── lib/                    # API client, utils, hooks
│   │   ├── public/
│   │   ├── next.config.ts
│   │   ├── tailwind.config.ts
│   │   └── tsconfig.json
│   ├── customer/                   # Flutter Customer App
│   │   ├── lib/
│   │   │   ├── main.dart
│   │   │   ├── app/                # App-level providers, routing, theme
│   │   │   ├── features/           # Feature modules (stub in Platform Foundation)
│   │   │   ├── shared/             # Shared widgets, utils, models
│   │   │   └── infrastructure/     # API client, local storage, device services
│   │   ├── test/
│   │   └── pubspec.yaml
│   └── agent/                      # Flutter Agent App (same structure as customer)
├── packages/
│   ├── design-system/              # Shared design tokens + components
│   │   ├── tokens/                 # W3C Design Token format (source of truth)
│   │   │   ├── color.json
│   │   │   ├── typography.json
│   │   │   ├── spacing.json
│   │   │   ├── elevation.json
│   │   │   ├── radius.json
│   │   │   ├── breakpoints.json
│   │   │   └── motion.json
│   │   ├── build/                  # Style Dictionary output
│   │   │   ├── css/                # CSS custom properties for Next.js
│   │   │   └── dart/               # Dart constants for Flutter
│   │   └── style-dictionary.config.js
│   └── shared-utils/               # Cross-platform utilities (not yet used)
├── services/
│   └── api/                        # Laravel 11 backend
│       ├── app/
│       │   ├── Http/
│       │   │   ├── Controllers/    # Thin controllers only
│       │   │   ├── Middleware/     # Auth, IdempotencyMiddleware, CorrelationId, SecurityHeaders
│       │   │   ├── Requests/       # Form Request validators
│       │   │   └── Resources/      # API Resources (response transformers)
│       │   ├── Modules/
│       │   │   ├── Platform/       # Cross-cutting (implemented by Platform Foundation)
│       │   │   │   ├── Application/
│       │   │   │   │   └── Services/
│       │   │   │   │       ├── IdempotencyService.php
│       │   │   │   │       ├── OutboxEventPublisher.php
│       │   │   │   │       ├── AuditService.php
│       │   │   │   │       └── ConfigurationService.php
│       │   │   │   ├── Domain/
│       │   │   │   │   ├── Contracts/  (interfaces used by other modules)
│       │   │   │   │   ├── Events/     (domain event base classes)
│       │   │   │   │   └── ValueObjects/ (Money, Currency, Actor, CorrelationId...)
│       │   │   │   ├── Infrastructure/
│       │   │   │   │   ├── Persistence/  (Eloquent models for platform.*)
│       │   │   │   │   ├── Cache/        (Redis idempotency cache)
│       │   │   │   │   └── Migrations/   (all platform.* migrations)
│       │   │   │   └── Providers/
│       │   │   │       └── PlatformServiceProvider.php
│       │   │   ├── Identity/       # Stub module (E01–E03)
│       │   │   ├── Wallet/         # Stub module (E06)
│       │   │   ├── Ledger/         # Stub module (E07)
│       │   │   ├── Kyc/            # Stub module (E05)
│       │   │   ├── Transaction/    # Stub module (E11)
│       │   │   ├── Risk/           # Stub module
│       │   │   ├── Agent/          # Stub module
│       │   │   └── Notification/   # Stub module
│       │   └── Providers/
│       │       └── AppServiceProvider.php
│       ├── config/
│       │   └── database.php        # One connection per module schema
│       ├── database/
│       │   └── migrations/         # Delegated to module Infrastructure/Migrations/
│       ├── routes/
│       │   ├── api.php             # /v1/ routes; includes module route files
│       │   └── modules/            # Per-module route files
│       └── tests/
│           ├── Unit/
│           ├── Feature/
│           ├── Architecture/       # Pest arch tests
│           └── Support/            # Factories, fixtures, TestCase base
├── docs/
│   ├── adrs/                       # Architecture Decision Records
│   ├── architecture/               # C4 diagrams
│   ├── api/                        # OpenAPI/Swagger docs
│   └── runbooks/                   # Operational procedures
├── scripts/
│   ├── dev/                        # Developer helper scripts
│   └── ci/                         # CI helper scripts
├── docker-compose.yml
├── docker-compose.override.yml     # Local dev overrides
├── .env.example
└── DEVELOPMENT.md                  # Onboarding guide
```

---

## Module Architecture (Laravel)

### Module Layers and Rules

```
┌─────────────────────────────────────────────────────────────────┐
│  HTTP Layer (app/Http/)                                          │
│  Controllers → thin: validate input, call ApplicationService,   │
│               return ApiResource. NO business logic.            │
├─────────────────────────────────────────────────────────────────┤
│  Application Layer (Modules/{Name}/Application/)                │
│  Use cases, command/query handlers, orchestration.              │
│  Calls Domain services and interfaces.                          │
│  May emit Integration Events via EventPublisherInterface.       │
├─────────────────────────────────────────────────────────────────┤
│  Domain Layer (Modules/{Name}/Domain/)                          │
│  Business logic, value objects, domain models, domain events.   │
│  Defines repository interfaces (no Eloquent here).              │
│  NO dependencies on Infrastructure or other module's Domain.    │
├─────────────────────────────────────────────────────────────────┤
│  Infrastructure Layer (Modules/{Name}/Infrastructure/)          │
│  Eloquent implementations of repository interfaces.             │
│  External API adapters. Database migrations.                    │
│  Uses named DB connection for this module's schema only.        │
└─────────────────────────────────────────────────────────────────┘
                                │
                  Cross-module communication
                                │
              Modules/{Name}/Domain/Contracts/
              (PHP interfaces, injected via DI)
```

### Service Provider Registration

Each module's `ModuleServiceProvider`:
1. Binds domain repository interfaces to Eloquent implementations.
2. Binds module contract interfaces to application service implementations.
3. Loads module migrations from `Infrastructure/Migrations/`.
4. Loads module routes from `routes/modules/{module}.php`.
5. Registers module's event listeners.

All `ModuleServiceProvider` classes are registered in `config/app.php` or via `AppServiceProvider`.

### Stub Module Requirements

For modules not implemented in Platform Foundation (Identity, Wallet, etc.):
- Schema created (empty).
- `ModuleServiceProvider` stub registered (no bindings — throws `ModuleNotImplementedException` if called).
- Contract interfaces defined in `Domain/Contracts/`.
- A `NullImplementation` of each contract registered during Platform Foundation (for testing infra without domain logic).

#### Per-Module Stub Deliverables (CHK104)

The table below defines the exact deliverables Platform Foundation MUST produce for each module. "Full impl" means the module is fully implemented in this phase. All other modules receive the listed stubs so that dependent Platform Foundation infrastructure (DI bindings, route scaffolding, architecture tests) can be wired without business logic.

| Module | Schema | `ModuleServiceProvider` | Contract interfaces | `NullImplementation` | Notes |
|---|---|---|---|---|---|
| **Platform** | `platform` (full) | Full impl | Full impl | N/A — Platform is fully implemented | Cross-cutting infrastructure |
| **Identity** | `identity` (empty) | Stub | `IdentityModuleContract` | `NullIdentityModule` — throws `ModuleNotImplementedException` | Auth flows deferred to E01 |
| **Wallet** | `wallet` (empty) | Stub | `WalletModuleContract` | `NullWalletModule` — throws `ModuleNotImplementedException` | Business logic deferred to E06 |
| **Ledger** | `ledger` (empty) | Stub | `LedgerModuleContract` | `NullLedgerModule` — throws `ModuleNotImplementedException` | Double-entry logic deferred to E07 |
| **Kyc** | `kyc` (empty) | Stub | None at Platform Foundation | N/A | No cross-module contract required at this stage |
| **Transaction** | `transaction` (empty) | Stub | `TransactionModuleContract` | `NullTransactionModule` — throws `ModuleNotImplementedException` | Reference generation deferred to E11 |
| **Risk** | `risk` (empty) | Stub | None at Platform Foundation | N/A | No cross-module contract required at this stage |
| **Agent** | `agent` (empty) | Stub | None at Platform Foundation | N/A | No cross-module contract required at this stage |
| **Notification** | `notification` (empty) | Stub | `NotificationModuleContract` | `NullNotificationModule` — throws `ModuleNotImplementedException` | Async dispatch deferred to notification epic |

**Acceptance**: The architecture tests MUST verify that every listed contract interface exists, every `NullImplementation` is registered in the service container, and calling any `NullImplementation` method throws `ModuleNotImplementedException`.

---

## PostgreSQL Schema Strategy

### Schema Ownership (Special Attention)

See [data-model.md](data-model.md) for full table definitions.

**Schema creation** is the responsibility of Platform Foundation. All 9 schemas are created by migration `2026_08_01_000001_create_all_schemas.php`:

```sql
CREATE SCHEMA IF NOT EXISTS platform;
CREATE SCHEMA IF NOT EXISTS identity;
CREATE SCHEMA IF NOT EXISTS wallet;
CREATE SCHEMA IF NOT EXISTS ledger;
CREATE SCHEMA IF NOT EXISTS kyc;
CREATE SCHEMA IF NOT EXISTS transaction;
CREATE SCHEMA IF NOT EXISTS risk;
CREATE SCHEMA IF NOT EXISTS agent;
CREATE SCHEMA IF NOT EXISTS notification;
```

### Laravel Connection Configuration

```php
// config/database.php
// All connections point to the same host + database in monolith phase.
// Only search_path differs. Extraction = change host env var.
'platform'     => pgsql_config('platform'),
'identity'     => pgsql_config('identity'),
'wallet'       => pgsql_config('wallet'),
'ledger'       => pgsql_config('ledger'),
'kyc'          => pgsql_config('kyc'),
'transaction'  => pgsql_config('transaction'),
'risk'         => pgsql_config('risk'),
'agent'        => pgsql_config('agent'),
'notification' => pgsql_config('notification'),
```

Helper `pgsql_config(string $schema)` returns an array with `driver`, `host`, `port`, `database`, `username`, `password`, `charset`, `schema`, and `search_path` all set correctly.

### Cross-Module Foreign References

| Situation | Implementation |
|---|---|
| Module A stores a reference to an entity owned by Module B | Store the UUID in a local column. No FK constraint to cross-schema table. |
| Module A needs B's entity data synchronously | Call Module B's `ContractInterface` via DI. B returns a DTO. |
| Module A needs eventual consistency from B | Subscribe to B's Integration Events; maintain a local projection in Module A's schema. |
| Read model spanning multiple modules | Create a `*Projection` table in the read-requesting module's schema, populated by event subscriptions. |

### Migration Naming Convention

```
services/api/app/Modules/{ModuleName}/Infrastructure/Migrations/
  YYYY_MM_DD_HHMMSS_create_{schema}_{table_name}.php
  YYYY_MM_DD_HHMMSS_add_{column}_to_{schema}_{table_name}.php
```

Modules load their own migrations via their Service Provider:
```php
$this->loadMigrationsFrom(__DIR__ . '/../Migrations');
```

### Transaction Boundaries

- Transactions MUST NOT span multiple module schemas.
- If a business operation requires atomicity across modules (rare in monolith), use the Saga pattern: each step is locally atomic, compensating events roll back on failure.
- The Outbox write MUST be in the same transaction as the domain state change (ensures at-least-once event delivery).

---

## Cross-Module Communication Contracts

See [contracts/module-contracts.md](contracts/module-contracts.md) for full PHP interface definitions.

**Communication patterns** (in priority order):
1. **Interface call** (sync, same request): Module A injects Module B's `ContractInterface`. B returns a DTO.
2. **Event subscription** (async): Module A handles Module B's `IntegrationEvent` from the Outbox.
3. **Read projection** (async, eventual): Module A builds its own read model from B's events.

**Never**:
- Direct Eloquent model access across modules.
- Cross-schema SQL JOINs.
- Direct access to another module's repository or application service.

---

## API Architecture

### URL Structure

```
/v1/{module}/{resource}
/v1/{module}/{resource}/{id}
/v1/{module}/{resource}/{id}/{sub-resource}

Examples:
POST   /v1/wallets/transfers
GET    /v1/wallets/transfers/{id}
PATCH  /v1/configuration/policies/{key}
GET    /v1/identity/users (E01 — not in Platform Foundation)
```

Health and platform endpoints (no module prefix):
```
GET /health        # Liveness probe
GET /ready         # Readiness probe
GET /startup       # Startup probe
GET /api/docs      # Swagger UI (local only)
```

### Middleware Stack (applied in order)

```
1. TrustProxies                  (Laravel built-in)
2. SecurityHeadersMiddleware      (HSTS, X-Frame, X-Content-Type, CSP placeholder)
3. CorrelationIdMiddleware        (extract or generate X-Correlation-ID; inject into log context)
4. RequestLoggingMiddleware       (log request start; mask PII fields)
5. AuthenticationMiddleware       (verify JWT; extract actor; reject unauthenticated)
6. IdempotencyMiddleware          (check/enforce idempotency on state-changing financial ops)
7. ThrottleRequests               (Laravel built-in rate limiting)
8. [route-specific authorization middleware]
9. Controller
10. ResponseEnvelopeMiddleware    (wrap controller response in standard envelope)
11. ResponseLoggingMiddleware     (log response status; timing)
```

### Controller Convention

```php
class TransferController extends ApiController
{
    public function store(StoreTransferRequest $request, TransferService $service): JsonResponse
    {
        $result = $service->initiate($request->toCommand());
        return $this->created(new TransferResource($result));
    }
}
```

`ApiController` provides `$this->ok()`, `$this->created()`, `$this->accepted()`, `$this->notFound()` etc., all returning the standard envelope.

---

## OpenAPI Conventions

- All API endpoints MUST be annotated with `darkaonline/l5-swagger` PHPDoc annotations.
- `php artisan l5-swagger:generate` outputs `storage/api-docs/openapi.json`.
- Swagger UI accessible at `/api/docs` (local and staging only; disabled in production).
- TypeScript types generated from OpenAPI spec: `npx openapi-typescript openapi.json -o apps/admin/lib/api/types.ts`.
- OpenAPI spec is included in CI pipeline validation (contract tests verify spec matches implementation).

---

## Error Model

See [contracts/api-response-envelope.md](contracts/api-response-envelope.md) for full error code taxonomy.

### Global Exception Handler

`app/Exceptions/Handler.php` maps exceptions to HTTP responses:

| Exception Class | HTTP | Error Code |
|---|---|---|
| `ValidationException` | 422 | `VALIDATION_ERROR` |
| `AuthenticationException` | 401 | `AUTHENTICATION_REQUIRED` |
| `TokenExpiredException` | 401 | `TOKEN_EXPIRED` |
| `AuthorizationException` | 403 | `PERMISSION_DENIED` |
| `ModelNotFoundException` | 404 | `RESOURCE_NOT_FOUND` |
| `DuplicateResourceException` | 409 | `RESOURCE_CONFLICT` |
| `IdempotencyKeyMismatchException` | 409 | `IDEMPOTENCY_KEY_MISMATCH` |
| `IdempotencyInProgressException` | 409 | `IDEMPOTENCY_IN_PROGRESS` |
| `RateLimitExceededException` | 429 | `RATE_LIMIT_EXCEEDED` |
| `BusinessRuleViolationException` | 400 | `BUSINESS_RULE_VIOLATION` |
| `TbdConfigurationException` | 503 | `TBD_CONFIGURATION` |
| `ProviderException` | 502/504 | `PROVIDER_ERROR` / `PROVIDER_TIMEOUT` |
| `Throwable` (fallback) | 500 | `INTERNAL_ERROR` |

All error responses include `correlation_id`. Stack traces are logged (not returned in responses). Error details MUST NOT expose: user existence, internal paths, DB schema, secrets.

---

## Correlation ID

### Middleware Flow

`CorrelationIdMiddleware`:
1. Read `X-Correlation-ID` header. If absent or invalid UUID, generate UUID v4.
2. Store in `CorrelationContext` (singleton bound in Service Container).
3. Inject into Monolog log context (all subsequent log calls include `correlation_id`).
4. Inject into response header `X-Correlation-ID`.
5. Pass to Horizon queue jobs via job property (jobs carry `correlation_id`).
6. Persist to `platform.audit_events.correlation_id` and `platform.outbox_events.correlation_id`.

### Log Context

```json
{
  "timestamp": "2026-08-16T10:30:00.000Z",
  "level": "INFO",
  "message": "Transfer initiated",
  "correlation_id": "019263a8-0000-7000-8000-000000000000",
  "request_id": "019263a8-0000-7001-8000-000000000000",
  "actor_id": "019263a8-0000-7002-8000-000000000000",
  "module": "Wallet",
  "context": { "transfer_id": "019263a8-..." }
}
```

---

## Idempotency Infrastructure (Special Attention)

See [contracts/idempotency-protocol.md](contracts/idempotency-protocol.md) for the full protocol.

### Component Design

```
IdempotencyMiddleware
  │
  ├── IdempotencyServiceInterface (injected)
  │     │
  │     ├── IdempotencyCacheRepository (Redis)
  │     │     └── Key pattern: "idempotency:{actor_id}:{idempotency_key}"
  │     │         TTL: configurable (default 24h)
  │     │
  │     └── IdempotencyKeyRepository (Eloquent, platform.idempotency_keys)
  │           └── Operations: insert-on-conflict, select-for-update, update-response
  │
  └── CorrelationContextInterface (injected — for audit events)
```

### Concurrency Protection Detail

```
Concurrent requests with same Idempotency-Key:
  Request A: Redis MISS → INSERT (success) → status='processing' → execute → update 'completed'
  Request B: Redis MISS → INSERT (conflict, ON CONFLICT DO NOTHING returns 0 rows)
             → SELECT FOR UPDATE SKIP LOCKED (gets the row)
             → if status='processing' → return 409 IDEMPOTENCY_IN_PROGRESS
             → if status='completed'  → return cached response (302 replay)
```

### Redis Cache Warming

After PostgreSQL write completes:
```
SET idempotency:{actor}:{key} {json_response}
EX  {ttl_seconds}
```

On cache hit: return immediately without any PostgreSQL lookup.

---

## Redis / Horizon Architecture

### Redis Responsibilities

| Responsibility | Key Pattern | TTL |
|---|---|---|
| Idempotency cache | `idempotency:{actor}:{key}` | 24h (configurable) |
| Configuration cache | `config:{config_key}` | 5 min (configurable) |
| Rate limiting | `throttle:{actor}:{endpoint}` | Per rate limit window |
| Queue (Horizon) | `queues:{queue_name}` | N/A (Horizon managed) |
| Cache (general) | `cache:{module}:{entity}:{id}` | Varies |

**Never**: financial balances, ledger state, transaction outcomes, audit events.

### Horizon Queue Configuration

```php
// config/horizon.php
'environments' => [
    'production' => [
        'supervisor-default' => [
            'connection' => 'redis',
            'queue' => ['critical', 'high', 'default', 'low'],
            'balance' => 'auto',
            'processes' => 10,
            'tries' => 3,
            'timeout' => 60,
        ],
        'supervisor-outbox' => [
            'queue' => ['outbox'],
            'processes' => 3,
            'tries' => 1,  // Retry logic is in OutboxDispatcherJob itself
        ],
    ],
],
```

### Queue Naming Convention

```
{module}.{priority}
Examples:
  platform.critical       (idempotency cleanup, outbox dispatch)
  identity.high           (auth events)
  wallet.high             (financial operations)
  notification.default    (notification delivery)
  reports.low             (background analytics)
```

### Job Idempotency

All Horizon jobs that produce side effects MUST implement idempotency:
- Check if job effect already applied before executing.
- Use `platform.outbox_events` status for `OutboxDispatcherJob`.
- Use a dedicated deduplication key for notification/email jobs.

---

## Outbox / Event Infrastructure

See [contracts/event-envelope.md](contracts/event-envelope.md) for the full event contract.

### Components

```
Domain Operation (Application Service)
  │
  ├── Domain Event → dispatched in-process (Laravel event dispatcher)
  │                   consumed by same-process listeners (e.g., update domain projections)
  │
  └── Integration Event → EventPublisher.publish(event)
        │
        └── INSERT INTO platform.outbox_events (within same DB transaction)

[Every N seconds — Horizon Scheduler]
  OutboxDispatcherJob
    ├── SELECT ... FOR UPDATE SKIP LOCKED LIMIT 100
    ├── UPDATE status = 'processing'
    ├── dispatch to event bus (Redis pub/sub, SQS, or configurable driver)
    ├── UPDATE status = 'delivered' / 'failed'
    └── Move to 'dead_letter' after max_attempts

[Event consumers — in same monolith or extracted services]
  EventConsumer
    ├── Check processed_events log for event.id (idempotency)
    ├── Process event
    └── Record event.id as processed
```

### Event Bus Driver Interface

`EventBusDriverInterface` abstracts the transport:
- `InProcessDriver` (default local): dispatches via Laravel event system (no external broker).
- `RedisDriver`: publishes to Redis pub/sub channels.
- Future: `SqsDriver`, `EventBridgeDriver` — plugged in via driver registration, no consumer code changes.

The driver is configured via `platform.configuration_values` key `events.bus_driver` (TBD for production; `InProcessDriver` for Platform Foundation).

---

## Configuration / Policy Framework

### Service Design

```
ConfigurationService (implements ConfigurationServiceInterface)
  │
  ├── 1. Check Redis cache: GET config:{config_key}
  │         HIT → deserialize and return
  │
  ├── 2. Cache MISS → query platform.configuration_values
  │         WHERE config_key = ?
  │           AND status = 'active'
  │           AND (effective_from IS NULL OR effective_from <= now())
  │           AND (effective_until IS NULL OR effective_until > now())
  │         ORDER BY version DESC LIMIT 1
  │
  ├── 3. Found → cache result in Redis (TTL: 5 min), return value
  │
  └── 4. Not found → return null (or throw TbdConfigurationException if requireValue=true)
```

### Seeded Default Values (Platform Foundation)

```php
// Seeds run by: 2026_08_01_000007_seed_platform_configuration_defaults.php
$defaults = [
    'platform.currencies.supported' => ['BWP', 'USD', 'EUR', 'ZAR', 'CNY', 'USDT'],
    'platform.currency.primary'     => 'BWP',
    'idempotency.ttl_seconds'       => 86400,  // 24 hours
    'events.max_attempts'           => 5,
    'events.bus_driver'             => 'in_process',

    // TBD items seeded as null (not invented)
    'mfa.step_up_threshold'         => null,   // GAP-027
    'provider.payment_processor'    => null,   // TBD
    'reconciliation.tolerance'      => null,   // TBD
];
```

### Scheduled Activation

A Horizon job (`ConfigurationActivationJob`) runs every minute, querying for `draft` configurations with `effective_from <= now()` and promoting them to `active`. Old `active` version becomes `archived`.

### Approval Workflow Extension Point

`platform.configuration_values.status = 'draft'` means "awaiting approval". The Platform Foundation defines the state machine (draft → approved → active → archived) but does NOT implement the approval UI or workflow — that belongs to E28 (Configuration Management).

---

## Security Foundation

### Identity Integration Boundary

Platform Foundation establishes the authentication contract boundary only. The actual OAuth2/OIDC provider implementation belongs to E01.

**What Platform Foundation implements**:
- `JwtAuthenticationMiddleware`: validates Bearer JWT signature and expiry; extracts claims; populates `CorrelationContext` with actor.
- `ServiceCredentialMiddleware`: validates client_id + client_secret for service-to-service calls.
- `AuthorizationGuard`: checks `actor->hasPermission(permission_name)` via `IdentityModuleContract` (stub during Platform Foundation, real implementation in E01).

**What Platform Foundation does NOT implement**:
- JWT issuing (login), token refresh, OTP, PIN, MFA enrollment.
- OAuth2 authorization code flow.
- PKCE.
- Session management.

### Token Validation (JWT)

```
AuthenticationMiddleware:
  1. Extract Authorization: Bearer {token}
  2. Decode JWT header → get kid (key ID)
  3. Fetch public key from JWKS endpoint (cached in Redis: jwks:{kid}, TTL: 1h)
  4. Verify signature with public key
  5. Verify exp, iss, aud claims
  6. Extract actor_id, roles, permissions from claims
  7. Populate CorrelationContext.actor
  8. On failure → throw AuthenticationException (maps to 401)
```

JWKS endpoint URL: configured via `IDENTITY_JWKS_URI` env variable (TBD — provider-agnostic).

### Authorization

Permission names follow `{module}.{resource}.{action}` pattern (e.g., `wallet.transfer.create`, `configuration.policy.update`).

`AuthorizationGuard` checks permissions via `IdentityModuleContract::hasPermission()`. All authorization decisions are logged to `platform.audit_events`.

### Secrets Management

Environment variables are the minimum viable secret management. The `SecretProvider` interface abstracts the source:
- `EnvSecretProvider` (default): reads from `$_ENV`.
- Future: `VaultSecretProvider`, `AwsSecretsManagerProvider` — swapped via config without code changes.

**Never**: commit `.env` with real credentials. `.env.example` ships with placeholder values.

### Security Headers Middleware

```
Strict-Transport-Security: max-age=31536000; includeSubDomains
X-Content-Type-Options: nosniff
X-Frame-Options: DENY
Referrer-Policy: strict-origin-when-cross-origin
Content-Security-Policy: [TBD by security team — placeholder installed]
```

### PII Masking

`PiiMasker` utility class provides:
- `mask(string $value, MaskType $type): string`
- Types: `EMAIL` (u***@d***.com), `PHONE` (+267***1234), `NATIONAL_ID` (****1234), `AMOUNT` (hidden if log level < INFO)

`RequestLoggingMiddleware` applies `PiiMasker` to request body fields matching a configured PII field list before writing to logs.

---

## Audit Foundation

### Audit Event Recording

All security, configuration, and financial events MUST be recorded via `AuditServiceInterface::record(AuditEventData $data)`.

`AuditEventData`:
- `actorId`, `actorType`, `action`, `resourceType`, `resourceId`
- `beforeState` (pre-masked), `afterState` (pre-masked)
- `outcome` (success/failure/partial)
- `correlationId`, `ipAddress`, `userAgent`

### Immutability Enforcement

Migration `create_platform_audit_events` includes:
```sql
-- After table creation, revoke unsafe privileges from app role
REVOKE UPDATE, DELETE ON platform.audit_events FROM wallet_app;
```

The application DB role (`wallet_app`) has only `INSERT` and `SELECT` on `platform.audit_events`. The DBA role retains full access for emergency recovery only.

### Archival Readiness

`AuditArchivalDriverInterface` abstracts the archival backend:
- `NullArchivalDriver` (default): no archival (Platform Foundation does not implement archival).
- Future: `S3WormDriver`, `AzureBlobImmutableDriver` — registered by infra team when DR/archival policy is decided (TBD).

---

## Observability Foundation

### Structured Logging

`Monolog` with JSON formatter. All log entries include:
```json
{
  "timestamp": "2026-08-16T10:30:00.000Z",
  "level": "INFO",
  "message": "...",
  "correlation_id": "...",
  "request_id": "...",
  "actor_id": "...",
  "module": "...",
  "context": { }
}
```

Log output destination: `stdout` (Docker captures; aggregation backend is TBD infrastructure concern).

Log level conventions (enforced by Pest Architecture Tests):
- `DEBUG`: detailed flow (disabled in production by default)
- `INFO`: business events, request in/out, state changes
- `WARNING`: unexpected-but-recoverable (slow query, retry, partial failure)
- `ERROR`: operation failed, domain exception, provider error
- `CRITICAL`: system integrity at risk (DB unreachable, cert expired, config corruption)

### Metrics

`MetricsCollectorInterface` abstraction:
- `InMemoryCollector` (Platform Foundation): collects and logs metrics on shutdown.
- Future: `PrometheusCollector`, `DatadogCollector` — swapped via driver config.

Metrics collected:
- `api.request.count{method, path, status}` — counter
- `api.request.duration_ms{method, path, status}` — histogram (p50/p95/p99)
- `api.error.count{code}` — counter
- `queue.job.count{queue, status}` — counter
- `queue.job.duration_ms{queue}` — histogram
- `db.query.count{connection}` — counter
- `db.slow_query.count{connection}` — counter (threshold: 100ms, configurable)

### Health Endpoints

```
GET /health     → 200 {"status":"ok","checks":{"db":"ok","redis":"ok","horizon":"ok"}}
GET /ready      → 200 {"status":"ready"} | 503 (if critical dependency down)
GET /startup    → 200 {"status":"started"} | 503 (while migrations running)
```

Health checks run on each request to `/health`. Readiness probe checks PostgreSQL query + Redis ping.

### Distributed Tracing Readiness

`OpenTelemetry` SDK installed but not configured to a backend (TBD). Tracing instrumentation added to:
- `CorrelationIdMiddleware` (creates root span)
- `IdempotencyMiddleware` (adds span)
- `OutboxDispatcherJob` (child span per event)
- Eloquent query logging (DB span)

Configuration: `OTEL_EXPORTER_OTLP_ENDPOINT` env variable (null by default = no-op exporter).

---

## Next.js Foundation (Admin App)

### Structure (App Router)

```
apps/admin/
├── app/
│   ├── layout.tsx               # Root layout: ThemeProvider, AuthContext, QueryClient
│   ├── page.tsx                 # Root redirect (→ /dashboard or /login)
│   ├── (auth)/                  # Auth shell (unauthenticated routes)
│   │   └── login/page.tsx       # Stub — implemented by E01
│   ├── (shell)/                 # Authenticated shell (protected by layout middleware)
│   │   ├── layout.tsx           # Navigation, sidebar, header
│   │   └── dashboard/page.tsx   # Stub — dashboard stub only
│   ├── error.tsx                # Global error boundary
│   ├── loading.tsx              # Global loading state
│   └── not-found.tsx            # 404 page
├── components/
│   ├── ui/                      # Design system re-exports + admin-specific
│   └── layout/                  # Navigation, Header, Sidebar stubs
├── lib/
│   ├── api/
│   │   ├── client.ts            # Base fetch wrapper with correlation ID, error handling
│   │   ├── types.ts             # Auto-generated from OpenAPI spec
│   │   └── hooks/               # React Query hooks (stubs for each module)
│   ├── auth/
│   │   └── context.tsx          # AuthContext + useAuth hook (stub, wired by E01)
│   ├── theme/
│   │   └── provider.tsx         # ThemeProvider (light/dark, token injection)
│   └── utils/
│       ├── correlation.ts       # X-Correlation-ID generation
│       └── errors.ts            # API error type guards
├── styles/
│   └── globals.css              # CSS custom properties from design token build
├── middleware.ts                 # Next.js middleware: auth check → redirect to /login
├── next.config.ts
├── tailwind.config.ts           # Design token integration
└── tsconfig.json                # strict: true
```

### API Client Contract

```typescript
// lib/api/client.ts
interface ApiResponse<T> {
  success: boolean;
  data: T | null;
  error: ApiError | null;
  correlation_id: string;
  meta?: PaginationMeta;
}

class ApiClient {
  async get<T>(path: string): Promise<ApiResponse<T>>;
  async post<T>(path: string, body: unknown, options?: { idempotencyKey?: string }): Promise<ApiResponse<T>>;
  async patch<T>(path: string, body: unknown): Promise<ApiResponse<T>>;
  async delete(path: string): Promise<ApiResponse<void>>;
}
```

### Protected Routes

`middleware.ts` checks for auth session cookie/token. Unauthenticated → redirect to `/(auth)/login`. Auth boundary is stub in Platform Foundation; wired to real auth in E01.

---

## Flutter Foundation

### Shared Architecture (Customer + Agent)

Both apps share the same architecture. The `apps/customer/` and `apps/agent/` directories follow identical structure with different feature modules.

```
lib/
├── main.dart                    # Entry point: ProviderScope, AppWidget
├── app/
│   ├── app.dart                 # MaterialApp / root widget
│   ├── router.dart              # GoRouter configuration
│   └── theme.dart               # AppTheme from design tokens
├── core/
│   ├── api/
│   │   ├── api_client.dart      # Dio-based HTTP client
│   │   ├── api_response.dart    # Response envelope model
│   │   └── interceptors/        # Auth, correlation ID, retry, logging interceptors
│   ├── auth/
│   │   └── auth_provider.dart   # Auth state stub (Riverpod AsyncNotifier)
│   ├── observability/
│   │   ├── logger.dart          # Structured logger
│   │   └── correlation.dart     # Correlation ID generation/propagation
│   └── di/
│       └── providers.dart       # Global DI container (all Riverpod providers)
├── shared/
│   ├── widgets/                 # Design system widget implementations
│   ├── models/                  # Shared value objects (Money, Currency, Actor)
│   └── errors/                  # Error handling utilities
└── features/                    # Stub feature modules (E31/E32 implement)
```

### Riverpod Architecture

```
                    ┌─────────────────────────────┐
                    │  Riverpod ProviderScope      │
                    │  (root of widget tree)       │
                    └──────────────┬──────────────┘
                                   │
          ┌────────────────────────┼────────────────────────┐
          │                        │                        │
   Infrastructure             Application              Presentation
   Providers                  Providers                Providers
     │                          │                        │
   ApiClientProvider          AsyncNotifier            ConsumerWidget
   LocalStorageProvider       (business logic)          (reads state,
   (bound to platform          uses infra               dispatches events)
    concrete impl)             via interfaces)
```

**Provider rules**:
- `ConsumerWidget` reads providers and dispatches events.
- `AsyncNotifier` / `Notifier` owns state; calls infrastructure providers.
- Infrastructure providers (`ApiClientProvider`, etc.) are overridable in tests.
- No business logic in widgets. No direct Dio calls in widgets.

### Navigation (GoRouter)

```dart
// app/router.dart
final router = GoRouter(
  redirect: (context, state) {
    final isAuthenticated = ref.read(authProvider).isAuthenticated;
    if (!isAuthenticated && !state.fullPath!.startsWith('/auth/')) {
      return '/auth/login';
    }
    return null;
  },
  routes: [
    GoRoute(path: '/auth/login', builder: (_, __) => LoginPage()),  // stub
    ShellRoute(builder: shellBuilder, routes: [
      GoRoute(path: '/dashboard', builder: (_, __) => DashboardPage()),  // stub
    ]),
  ],
);
```

### API Client (Dio)

Interceptors applied in order:
1. `AuthInterceptor` — adds `Authorization: Bearer {token}` header.
2. `CorrelationIdInterceptor` — adds `X-Correlation-ID` header.
3. `RetryInterceptor` — retries on 5xx (max 3 times, exponential backoff).
4. `LoggingInterceptor` — logs request/response with PII masking.
5. `ErrorInterceptor` — converts Dio errors to typed `ApiError` objects.

---

## Shared Design System Foundation

### Token Pipeline

```
packages/design-system/tokens/*.json  (W3C Design Token format — source of truth)
    │
    └── style-dictionary build
          │
          ├── → packages/design-system/build/css/tokens.css      (CSS custom properties)
          │       imported by apps/admin/styles/globals.css
          │       configured in tailwind.config.ts
          │
          └── → packages/design-system/build/dart/tokens.dart    (Dart constants)
                  imported by apps/customer/lib/app/theme.dart
```

### Token Categories

| Category | Format | Example Key |
|---|---|---|
| Color primitives | HSL or hex | `--color-blue-500` |
| Semantic colors | References primitive | `--color-error-primary: var(--color-red-600)` |
| Typography | font-size, weight, line-height | `--font-size-body-md: 1rem` |
| Spacing | 4px grid | `--space-4: 1rem` (= 16px) |
| Elevation | box-shadow | `--elevation-2: 0 2px 8px rgba(...)` |
| Radius | border-radius | `--radius-md: 0.5rem` |
| Breakpoints | media query values | `--breakpoint-tablet: 768px` |
| Motion | duration, easing | `--motion-duration-normal: 200ms` |

### Shared Components (Platform Foundation Implements)

All components have Next.js (React) and Flutter (Dart widget) implementations.

| Component | Accessibility Requirements |
|---|---|
| `Button` | keyboard focus, ARIA role=button, disabled state |
| `Input` | label association, aria-invalid, aria-describedby for errors |
| `Select` | keyboard navigation, ARIA listbox pattern |
| `Card` | semantic landmark, focusable when interactive |
| `Dialog` | focus trap, Escape to close, aria-modal, focus restore |
| `Sheet` (bottom sheet) | same as Dialog + touch dismiss |
| `Toast` | aria-live="polite", auto-dismiss accessible |
| `Badge` | aria-label for screen readers |
| `Tabs` | ARIA tabs pattern, keyboard arrow navigation |
| `Table` | thead/th scope, sortable column ARIA |
| `Navigation` | nav landmark, aria-current="page" |
| `Loading` | aria-live="polite", role="status" |
| `Empty` | descriptive message for screen readers |
| `Error` | role="alert", descriptive message |
| `Confirmation` | Dialog pattern, destructive action highlighted |

---

## Accessibility Foundation

### Web (Next.js)

- All components use semantic HTML (`<button>`, `<nav>`, `<main>`, `<header>`, `<h1>`–`<h6>`).
- `axe-core` integrated into Jest component tests via `jest-axe`. Any WCAG AA violation fails the test.
- `TabIndex` and `autoFocus` managed by `FocusManager` utility.
- Dialog focus trap via `focus-trap-react` library.
- Reduced motion via `prefers-reduced-motion` CSS media query and `useReducedMotion` hook.

### Mobile (Flutter)

- All interactive widgets wrapped with `Semantics(label: ..., button: true)`.
- `SemanticsDebugger` used in development mode to verify semantic tree.
- `ExcludeSemantics` used for purely decorative elements.
- Minimum touch target: `SizedBox(width: 44, height: 44, child: ...)` wrapper utility.
- `MediaQuery.of(context).disableAnimations` respected for reduced motion.

---

## Testing Foundation

### Backend Test Infrastructure

```
tests/
├── Unit/                        # Pure unit tests (mocked dependencies)
│   └── Modules/
│       └── Platform/
│           ├── IdempotencyServiceTest.php
│           ├── ConfigurationServiceTest.php
│           ├── AuditServiceTest.php
│           └── OutboxEventPublisherTest.php
├── Feature/                     # Integration tests (real DB via RefreshDatabase)
│   └── Modules/
│       └── Platform/
│           ├── IdempotencyIntegrationTest.php  (includes concurrency test)
│           ├── OutboxDispatchTest.php
│           └── ConfigurationApiTest.php
├── Architecture/                # Pest arch tests
│   ├── ModuleBoundaryTest.php   # No cross-domain imports
│   ├── ControllerThinTest.php   # Controllers use no domain logic
│   └── InfrastructureIsolationTest.php
├── Api/                         # API contract tests
│   └── ResponseEnvelopeTest.php # All endpoints return correct envelope
└── Support/
    ├── TestCase.php             # Base class: RefreshDatabase, CorrelationId setup
    ├── Factories/               # Model factories for platform.* tables
    └── Mocks/                   # Null implementations for module stubs
```

### Backend Test Helpers

- `RefreshDatabase` trait + `DatabaseTransactions` for test isolation.
- `WithFakeHorizon` trait: captures queued jobs without executing them.
- `PlatformTestCase` base class sets up CorrelationContext, seeds config defaults.
- `ConcurrencyTest` helper: spawns parallel PHP processes to test race conditions.
- `AssertAuditEvent::recorded($action)` custom assertion for audit tests.
- `AssertOutboxEvent::published($eventName)` custom assertion for event tests.

### Coverage Gate

```php
// Pest configuration (pest.config.php)
uses()->coverage()->min(80);  // Fails if coverage < 80%
```

### Next.js Test Infrastructure

```
apps/admin/
└── __tests__/
    ├── components/              # Jest + React Testing Library
    ├── a11y/                    # jest-axe accessibility tests
    ├── api/                     # API client contract tests (msw mock server)
    └── support/
        └── setup.ts             # jest-axe setup, msw setup, custom matchers
```

### Flutter Test Infrastructure

```
apps/customer/
└── test/
    ├── unit/                    # Pure Dart tests with ProviderContainer
    ├── widget/                  # Flutter widget tests (no real platform)
    ├── integration/             # Integration tests via integration_test package
    └── support/
        ├── mocks/               # Mockito / mocktail mock implementations
        └── fixtures/            # JSON response fixtures
```

---

## CI/CD Foundation

### Pipeline Stages (Technology-Agnostic)

The pipeline YAML format depends on the CI/CD provider (TBD). Scripts in `scripts/ci/` are provider-agnostic shell scripts invoked by any CI runner.

**Backend pipeline** (`scripts/ci/backend.sh`):
```
1. composer install --no-dev
2. php artisan config:cache
3. vendor/bin/phpcs (PHP CodeSniffer)
4. vendor/bin/psalm --no-progress
5. vendor/bin/pest --coverage --min=80
6. vendor/bin/pest --filter=ArchitectureTest
7. php artisan l5-swagger:generate && validate-openapi openapi.json
8. composer audit (dependency vulnerability scan)
```

**Next.js pipeline** (`scripts/ci/frontend.sh`):
```
1. npm ci
2. npx eslint .
3. npx tsc --noEmit
4. npx jest --coverage --coverageThreshold='{"global":{"lines":80}}'
5. npx next build
6. npm audit --audit-level=high
```

**Flutter pipeline** (`scripts/ci/mobile.sh`):
```
1. flutter pub get
2. flutter analyze
3. dart format --set-exit-if-changed .
4. flutter test --coverage
5. flutter build apk --debug  # validates build
6. flutter build ios --no-codesign --debug
```

**Security gate** (all pipelines):
- Dependency vulnerability scan (composer audit / npm audit / pub outdated).
- No hardcoded secrets (git-secrets or similar scan on commit).

### Environment Promotion Gates

```
feature-branch → test env   (auto-deploy on PR open)
main branch    → staging    (auto-deploy on merge to main)
staging        → production (manual approval + green health checks)
```

### Rollback

Health checks are called after deployment. If any check returns non-200 within the health check timeout, the deployment is rolled back automatically to the previous version. Rollback script: `scripts/ci/rollback.sh`.

---

## Docker / Local Development

### docker-compose.yml Services

```yaml
services:
  api:
    build: services/api/
    depends_on: [postgres, redis]
    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost:8000/health"]
      interval: 30s
      timeout: 5s
      retries: 3

  postgres:
    image: postgres:15-alpine
    environment:
      POSTGRES_DB: wallet_ecosystem
      POSTGRES_USER: wallet_app
      POSTGRES_PASSWORD: ${DB_PASSWORD}
    volumes:
      - postgres_data:/var/lib/postgresql/data
    healthcheck:
      test: ["CMD", "pg_isready", "-U", "wallet_app"]

  redis:
    image: redis:7-alpine
    healthcheck:
      test: ["CMD", "redis-cli", "ping"]

  horizon:
    build: services/api/
    command: php artisan horizon
    depends_on: [api, redis, postgres]
    healthcheck:
      test: ["CMD", "php", "artisan", "horizon:status"]

  admin:
    build: apps/admin/
    ports: ["3000:3000"]

volumes:
  postgres_data:
```

### Local Setup (One-Command)

```bash
cp .env.example .env          # Copy env template
docker compose up -d --wait   # Start all services, wait for health
docker compose exec api php artisan migrate  # Run migrations
docker compose exec api php artisan db:seed  # Seed config defaults
```

### Environment File Strategy

```
.env.example          # Committed — all keys with placeholder values
.env                  # Not committed — local developer values
.env.testing          # Committed — test environment with test DB config
.env.local            # Not committed — local overrides (highest priority)
```

No production secrets ever appear in `.env.example`. Sensitive values use `${VAR_NAME}` with documentation comment.

---

## Environment Strategy

| Environment | Deployment | DB | Logging | Secrets |
|---|---|---|---|---|
| Development | Local Docker Compose | Local PostgreSQL | DEBUG level, stdout | `.env` (local only) |
| Test | CI Docker Compose | Fresh DB per run | WARNING level | CI secret manager |
| Staging | Container/K8s | Staging PostgreSQL | INFO level | Secret manager (TBD) |
| Production | Container/K8s | Production PostgreSQL | INFO level | Secret manager (TBD) |

**Configuration layering**: `.env.{environment}` → `.env` → environment variables → secret manager.

**Secret rotation**: All secrets read at runtime via `SecretProvider` interface. Rotation requires no redeploy (process reads fresh value on next request or cache invalidation).

---

## Documentation / ADR Structure

### ADR Template

```
docs/adrs/
├── ADR-001-modular-monolith-structure.md
├── ADR-002-postgresql-schema-per-module.md
├── ADR-003-uuid-v7-primary-keys.md
├── ADR-004-idempotency-postgresql-redis.md
├── ADR-005-outbox-pattern-horizon.md
├── ADR-006-riverpod-state-management.md
├── ADR-007-next-app-router.md
├── ADR-008-design-token-pipeline.md
└── ADR-009-pest-testing-framework.md
```

Each ADR:
```
# ADR-XXX: {Decision Title}
**Status**: Accepted | Superseded by ADR-YYY
**Date**: YYYY-MM-DD

## Context
## Decision
## Rationale
## Alternatives Considered
## Consequences
```

### Architecture Diagrams

```
docs/architecture/
├── c4-context.md       # System context (Wallet Ecosystem + external actors)
├── c4-container.md     # Containers (Laravel API, Next.js, Flutter apps, PostgreSQL, Redis)
├── c4-component.md     # Laravel components (modules, middleware, services)
└── c4-code.md          # Key code-level sequences (idempotency, outbox, auth boundary)
```

---

## Dependency Graph

```
                          [External]
                         OAuth2/OIDC Provider (TBD)

Platform Foundation (this feature)
├── PROVIDES (to all E01–E34):
│   ├── PostgreSQL schemas (9 schemas)
│   ├── platform.* tables (idempotency, outbox, audit, config)
│   ├── Module architecture conventions + Service Provider pattern
│   ├── PHP interfaces (IdempotencyService, EventPublisher, AuditService, ConfigService, etc.)
│   ├── API response envelope + error codes
│   ├── Correlation ID middleware
│   ├── Security middleware (auth boundary, authz guard, security headers)
│   ├── Structured logging + PII masking
│   ├── Health endpoints
│   ├── Design system tokens + shared components (Next.js + Flutter)
│   ├── Riverpod architecture + DI setup (Flutter)
│   ├── API client (Next.js + Flutter)
│   ├── CI/CD pipeline scripts
│   └── Docker Compose local dev environment
│
└── DEPENDS ON (external packages):
    ├── laravel/framework 11.x
    ├── ramsey/uuid 4.7+
    ├── laravel/horizon
    ├── pestphp/pest
    ├── next 14.x
    ├── flutter_riverpod
    ├── go_router
    ├── dio
    └── style-dictionary 4.x

Epics that depend on Platform Foundation (cannot start before PF complete):
E01 (Identity) → needs: auth boundary, module architecture, DB schema identity.*
E02 (Profile)  → needs: identity schema, module contracts
E05 (KYC)      → needs: kyc schema, event architecture, audit foundation
E06 (Wallet)   → needs: wallet schema, idempotency, audit, events
E07 (Ledger)   → needs: ledger schema, Money value object, idempotency, audit
All others     → need: API conventions, observability, config framework
```

---

## Implementation Sequencing

### Phase 1 — Repository & Architecture (Week 1)

**Deliverables**:
- Monorepo directory structure created.
- Laravel 11 project bootstrapped in `services/api/`.
- Next.js 14 project bootstrapped in `apps/admin/`.
- Flutter projects bootstrapped in `apps/customer/` and `apps/agent/`.
- Module directories created (Platform + 8 stub modules).
- Service Provider stubs registered.
- PostgreSQL all-schema migration written and tested.
- Docker Compose with all services, health checks passing.
- Dependency injection base configuration.
- Architecture tests (Pest) written and failing (TDD for boundaries).

**Gate**: `docker compose up` starts all services healthy. All 9 DB schemas visible.

### Phase 2 — Backend Platform Foundation (Weeks 2–3)

**Deliverables**:
- `CorrelationIdMiddleware` + `CorrelationContext`.
- `SecurityHeadersMiddleware`.
- `PiiMasker` utility + `RequestLoggingMiddleware`.
- `ApiController` base + `ResponseEnvelopeMiddleware`.
- Global exception handler with full error taxonomy.
- `IdempotencyMiddleware` + `IdempotencyService` (PostgreSQL + Redis).
- `platform.idempotency_keys` migration + Eloquent model.
- `OutboxEventPublisher` + `platform.outbox_events` migration.
- `OutboxDispatcherJob` (Horizon worker).
- `AuditService` + `platform.audit_events` migration (immutable).
- `ConfigurationService` + `platform.configuration_values` migrations.
- Currency and TBD placeholder seeds.
- `ConfigurationActivationJob` (scheduled).
- JWT validation middleware (integration boundary stub).
- Authorization guard (stub via `IdentityModuleContract` NullImpl).
- All platform contracts defined as PHP interfaces.
- Money + Currency value objects.
- Unit tests for all services (≥ 80% coverage).
- Feature/integration tests for all platform APIs.
- API contract tests (response envelope, error codes, correlation IDs).

**Gate**: Backend CI pipeline passes. Idempotency concurrency test passes.

### Phase 3 — Database & Testing Infrastructure (Weeks 3–4)

**Deliverables**:
- Migration convention document + `MigrationTemplate` example.
- Pest Architecture tests pass (module boundaries enforced).
- Database constraint tests (foreign key, unique, nullable rules).
- Concurrency tests for idempotency (parallel request simulation).
- Security tests (auth enforcement, PII masking, no credential leakage).
- Test factories for `platform.*` models.
- `ConcurrencyTest` helper utility.
- `AssertAuditEvent` + `AssertOutboxEvent` custom assertions.
- Code coverage report gate configured (≥ 80%).

**Gate**: All tests pass. Coverage ≥ 80%. Architecture tests pass.

### Phase 4 — Observability & Security Hardening (Week 4)

**Deliverables**:
- Structured JSON logging fully configured (Monolog).
- OpenTelemetry SDK installed + no-op exporter configured.
- `MetricsCollector` abstraction + `InMemoryCollector` implementation.
- All metrics collected (request, queue, DB).
- Health check endpoints (`/health`, `/ready`, `/startup`) functional.
- OpenAPI documentation generated and validated.
- Swagger UI accessible at `/api/docs` locally.
- TypeScript types auto-generated from OpenAPI spec.
- `SecretProvider` abstraction + `EnvSecretProvider`.
- Security scan passing (composer audit, no OWASP Top 10 violations).
- ADR documents written for all major decisions.

**Gate**: Security scan clean. OpenAPI spec valid. Health endpoints return 200.

### Phase 5 — Frontend Foundation (Week 5)

**Deliverables**:
- Design token W3C JSON files (`color.json`, `typography.json`, etc.).
- Style Dictionary build pipeline.
- CSS custom properties generated for Next.js.
- Dart token constants generated for Flutter.
- All 15 shared components implemented in Next.js (React) + Flutter (Dart).
- WCAG AA accessibility: all components pass `jest-axe` tests.
- Dark/light theme switching working in Next.js.
- Flutter theme system wired to design tokens.
- Next.js App Router shell: layout, auth context stub, theme provider, API client.
- Next.js protected routes (middleware stub).
- Flutter Riverpod architecture: `ProviderScope`, `AppRouter`, API client, auth stub.
- GoRouter navigation setup.
- Accessibility tests integrated into CI.

**Gate**: `npm run test:a11y` — no WCAG AA violations. Design tokens visible in both apps.

### Phase 6 — CI/CD & Local Dev (Weeks 5–6)

**Deliverables**:
- CI pipeline scripts (`scripts/ci/backend.sh`, `frontend.sh`, `mobile.sh`).
- Docker Compose complete and tested.
- `DEVELOPMENT.md` onboarding guide (covers clone → setup → test → run).
- QA evidence template for story acceptance.
- `scripts/ci/rollback.sh` rollback automation.
- Environment-specific configuration files.
- Security scan gate in CI.
- Architecture test gate in CI.
- Coverage gate in CI.

**Gate**: New-developer onboarding dry-run completes in ≤ 30 minutes.

### Phase 7 — Documentation & Final Validation (Week 6)

**Deliverables**:
- C4 architecture diagrams (context, container, component).
- All 9 ADR documents.
- API conventions guide with examples.
- Database conventions guide with schema diagrams.
- Testing guide with examples.
- Security decision document and threat model stub.
- Final compliance review (no TBD values invented, no business logic).
- All acceptance criteria (AC-01 through AC-27) verified.

**Gate**: Final architecture review sign-off. All 27 acceptance criteria ✅.

---

## Risks and Mitigations

| # | Risk | Severity | Mitigation |
|---|---|---|---|
| R1 | Module boundaries not enforced → domain coupling | High | Pest Architecture Tests in CI gate (fail PR if violated) |
| R2 | Cross-schema JOINs introduced by accident | High | Architecture test checks all Repository classes for cross-schema query patterns |
| R3 | Redis used as idempotency source of truth | High | Integration test verifies idempotency survives Redis flush (PostgreSQL is authoritative) |
| R4 | TBD values invented (GAP-027, provider, etc.) | High | `TbdConfigurationException` thrown at runtime; `assertNot(null)` tests for each TBD key |
| R5 | Design token drift between Next.js and Flutter | Medium | Single source of truth in `packages/design-system/tokens/`; build pipeline outputs both; visual diff test |
| R6 | Outbox events never delivered (Horizon stops) | Medium | Horizon supervisor monitored via health check; dead-letter queue alerts |
| R7 | Idempotency concurrency race condition | High | Dedicated concurrency test simulating 10 parallel requests with same key |
| R8 | PII leaks into logs | High | `PiiMasker` unit tests; log output regex scan in security test |
| R9 | Onboarding > 30 minutes | Low | Pre-pull Docker images in compose; scripted setup validated by dry-run |
| R10 | API envelope drift in domain epics | Medium | API contract tests in CI verify envelope for every registered route |

---

## Validation Strategy

### Automated Gates (CI must pass before merge)

- [ ] PHP: Pest all tests pass
- [ ] PHP: Coverage ≥ 80%
- [ ] PHP: Architecture tests pass (no cross-domain violations)
- [ ] PHP: OpenAPI spec valid (no undocumented endpoints)
- [ ] PHP: Security scan clean (composer audit)
- [ ] Next.js: TypeScript type check passes
- [ ] Next.js: ESLint passes
- [ ] Next.js: Jest all tests pass
- [ ] Next.js: Accessibility tests pass (no WCAG AA violations)
- [ ] Flutter: dart analyze — no issues
- [ ] Flutter: flutter test — all pass
- [ ] All: Docker Compose health checks green

### Manual Validation (Platform Foundation Release)

- [ ] All 27 acceptance criteria validated (see spec.md)
- [ ] New-developer onboarding dry-run ≤ 30 minutes
- [ ] Idempotency concurrency test passes (10 parallel same-key requests)
- [ ] Audit event immutability verified (UPDATE/DELETE rejected at DB level)
- [ ] Security event recorded for every 401/403 response
- [ ] TBD values confirmed null/sentinel (not invented) in DB
- [ ] Final architecture review: no cross-module coupling detected
- [ ] SC-001 through SC-015 all verified

---

## TBD / Deferred Items

| Item | Architecture Readiness |
|---|---|
| GAP-027 step-up threshold | `ConfigurationService` key `mfa.step_up_threshold` seeded as `null`; evaluated at call-time by Identity module |
| OAuth2/OIDC provider | `IDENTITY_JWKS_URI` env var; `JwtAuthenticationMiddleware` provider-agnostic |
| CI/CD provider | Pipeline scripts are shell scripts; only the trigger YAML is provider-specific |
| Audit write-once enforcement | DB revokes UPDATE/DELETE from app role; `AuditArchivalDriverInterface` is the extension point |
| Observability backend | Log to stdout; `MetricsCollectorInterface` is the extension point; `OTEL_EXPORTER_OTLP_ENDPOINT` is the tracing extension point |
| DR/RPO/RTO | Not hard-coded; infrastructure concern; no app code depends on specific values |
| Performance SLAs | Metrics collected; SLOs configured in monitoring platform when chosen |
| Security policy values (TLS version, cipher) | Controlled by nginx/infra config; not in application code |
| Reconciliation tolerances | `configuration.reconciliation.tolerance` seeded as `null` |

---

## Complexity Tracking

No constitution violations detected. No complexity justification required.
