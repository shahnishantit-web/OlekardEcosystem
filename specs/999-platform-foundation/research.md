# Research: Platform Foundation

**Branch**: `999-platform-foundation` | **Date**: 2026-08-16

All technology decisions are derived from the approved specification and the five clarifications resolved during `/speckit-clarify`. No TBD values are invented.

---

## Decision 1 — Laravel Modular Monolith Structure

**Decision**: Custom module structure via Laravel Service Providers — no external package (`nwidart/laravel-modules` or similar).

**Rationale**:
- Service Providers are a first-class Laravel concept; no extra abstraction.
- Each module registers its own routes, migrations, bindings, and event listeners in its `ModuleServiceProvider`.
- Extraction-ready: moving a module to a separate service means moving its directory and converting its Service Provider to a standalone Laravel app bootstrap.
- Avoids vendor lock-in on a module-management package.

**Module directory layout** (inside `services/api/`):
```
app/
└── Modules/
    ├── Platform/          ← cross-cutting infrastructure (idempotency, events, audit, config, observability)
    ├── Identity/          ← auth boundaries (E01–E03 stubs only)
    ├── Wallet/            ← wallet domain stubs (E06)
    ├── Ledger/            ← ledger domain stubs (E07)
    ├── Kyc/               ← KYC domain stubs (E05)
    ├── Transaction/       ← transaction domain stubs (E11)
    ├── Risk/              ← risk domain stubs
    ├── Agent/             ← agent domain stubs
    └── Notification/      ← notification domain stubs
```

Each module follows:
```
{Module}/
├── Application/           # Use cases, command/query handlers, application services
│   ├── Commands/
│   ├── Queries/
│   └── Services/
├── Domain/                # Business logic (entities, value objects, domain events, repo interfaces)
│   ├── Contracts/         # PHP interfaces exposed to other modules
│   ├── Events/
│   ├── Models/
│   └── ValueObjects/
├── Infrastructure/        # Technical implementation (Eloquent repos, external adapters, migrations)
│   ├── Persistence/
│   └── Migrations/
└── Providers/
    └── ModuleServiceProvider.php
```

**Alternatives Considered**:
- `nwidart/laravel-modules`: Popular but adds abstraction, requires its own CLI, migrations per module use a separate artisan command. Rejected: unnecessary complexity.
- Flat `app/` structure with naming conventions: Cannot enforce boundary checks programmatically. Rejected: violates extraction-readiness.

---

## Decision 2 — PostgreSQL Schema-per-Module

**Decision**: One PostgreSQL schema per domain module, all in a single database. Laravel uses one named database connection per module, each with a different `search_path`.

**Schema map**:
| Schema | Module | DB Connection key |
|---|---|---|
| `platform` | Platform (cross-cutting) | `platform` |
| `identity` | Identity | `identity` |
| `wallet` | Wallet | `wallet` |
| `ledger` | Ledger | `ledger` |
| `kyc` | Kyc | `kyc` |
| `transaction` | Transaction | `transaction` |
| `risk` | Risk | `risk` |
| `agent` | Agent | `agent` |
| `notification` | Notification | `notification` |

**Implementation in Laravel**:
```php
// config/database.php — one entry per module schema
// All point to same host/database; only search_path differs
'platform' => [
    'driver' => 'pgsql', 'host' => env('DB_HOST'),
    'database' => env('DB_DATABASE'), 'schema' => 'platform', 'search_path' => 'platform',
],
'identity' => [..., 'schema' => 'identity', 'search_path' => 'identity'],
// ...
```

Eloquent models declare their connection:
```php
class IdempotencyKey extends Model { protected $connection = 'platform'; }
```

**Cross-module foreign references**: Never use SQL FOREIGN KEY constraints across schemas (prevents independent extraction). Instead, reference by storing the UUID and validating at the application layer.

**Extraction seam**: When extracting Identity to its own service, change `DB_IDENTITY_HOST` to a separate database host. No migration needed. Module Repositories already use the named connection.

**Alternatives Considered**:
- Shared `public` schema with table-name prefixes (`identity_users`): Application-layer only isolation, DB allows cross-table JOINs unchecked. Rejected: not genuinely extraction-ready.
- Separate databases per module from day one: Complex local Docker setup (9+ databases), harder dev onboarding. Rejected: premature; connection-string change achieves same at extraction time.

---

## Decision 3 — UUID v7 in PHP / Laravel

**Decision**: Use `ramsey/uuid` v4.7+ (`Ramsey\Uuid\Uuid::uuid7()`) for all UUID v7 generation. Wrap in a `UuidService` facade for testability.

**Rationale**:
- `ramsey/uuid` is bundled with Laravel; no new dependency.
- UUID v7 is time-ordered (first 48 bits = millisecond timestamp) → sequential inserts → minimal B-tree fragmentation on primary key indexes.
- Standard UUID format (32 hex + 4 dashes) → compatible with all UUID columns, JDBC, ORMs, frontend.
- PHP 8.2+ supports `\Ramsey\Uuid\Uuid::uuid7()->toString()`.

**Laravel migration column type**: `$table->uuid('id')->primary()` (PostgreSQL maps to `uuid` native type).

**Model trait**:
```php
trait HasUuidV7 {
    protected static function booting(): void {
        static::creating(fn($m) => $m->id ??= Uuid::uuid7()->toString());
    }
    public function getIncrementing(): bool { return false; }
    public function getKeyType(): string { return 'string'; }
}
```

**Alternatives Considered**:
- UUID v4 (`Str::uuid()`): Random, no time ordering, index fragmentation on large tables. Rejected in clarification.
- ULID (`Str::ulid()`): Also time-ordered, but non-standard format (26-char); extra parsing in some clients. Rejected in clarification.

---

## Decision 4 — Idempotency: PostgreSQL Authoritative + Redis Cache

**Decision**: `platform.idempotency_keys` (PostgreSQL) is the authoritative store. Redis acts as a read-through cache with the same TTL (default 24h). Cache miss → atomic PostgreSQL lock → execute or replay.

**Concurrency protection mechanism**:
1. Client sends `Idempotency-Key: {uuid7}` header.
2. Middleware checks Redis: `GET idempotency:{key}` → HIT returns cached response immediately.
3. Redis MISS: attempt `INSERT INTO platform.idempotency_keys (idempotency_key, locked_at) VALUES (...) ON CONFLICT (idempotency_key) DO NOTHING RETURNING id`.
4. If INSERT returned a row → this is the first execution → proceed.
5. If INSERT returned nothing → concurrent/duplicate → `SELECT ... FOR UPDATE SKIP LOCKED` to wait or return in-progress status.
6. After execution → update PostgreSQL record with response, warm Redis cache.

**Redis key pattern**: `idempotency:{actor_id}:{idempotency_key}` (namespaced per FR-058).

**Why not Redis-only**: FR-057 forbids Redis as source of financial truth. Redis AOF persistence has a recovery window; a restart between operation and persistence → lost idempotency record → duplicate financial effect.

---

## Decision 5 — Event Architecture: Outbox + Horizon

**Decision**: Domain events are persisted in `platform.outbox_events` within the same database transaction as the domain state change. A dedicated Laravel Horizon worker (OutboxDispatcherJob) polls pending events, dispatches them to the configured event bus, and marks them delivered.

**Polling strategy**:
- Job runs every 5 seconds (configurable) via Horizon scheduler.
- Selects up to 100 `pending` events ordered by `created_at`.
- Marks records `processing` before dispatching (prevents double-dispatch on concurrent workers).
- Uses PostgreSQL advisory locks on batch to prevent concurrent Horizon workers from processing the same events.

**Why polling over CDC (Debezium)**: CDC requires Kafka and a dedicated Debezium connector. For a modular monolith Phase 1, polling is simpler with near-equivalent reliability. The outbox table schema is CDC-compatible — when the system scales to microservices, Debezium can replace the polling worker without schema changes.

**Why not PostgreSQL LISTEN/NOTIFY**: NOTIFY is not durable (missed by offline consumers). Outbox table IS durable — events survive restarts.

---

## Decision 6 — Event Naming Convention

**Decision**: `{Domain}.{Aggregate}.{PastTenseVerb}` — dot-notation PascalCase.

Examples:
- `Wallet.Transfer.Completed`
- `Identity.User.Registered`
- `Ledger.Entry.Posted`
- `Kyc.Verification.Approved`
- `Configuration.Policy.Updated`

**Wildcard subscription**: `Wallet.*` matches all Wallet domain events. This works with Redis pub/sub patterns, AWS EventBridge prefix matching, and RabbitMQ topic exchanges.

**Event version**: SemVer string in the envelope `event_version` field (`"1.0.0"`). Consumers check version and handle unknown fields gracefully (forward-compatibility).

---

## Decision 7 — Riverpod Architecture (Flutter)

**Decision**: `flutter_riverpod` + `riverpod_generator` + `riverpod_lint` (code-generation approach).

**Provider taxonomy**:
| Provider type | Use |
|---|---|
| `@riverpod` (Provider) | Pure sync values, constants, config |
| `@riverpod` (FutureProvider) | Single async loads |
| `@riverpod` (AsyncNotifier) | Async state with mutations (most business logic) |
| `@riverpod` (Notifier) | Sync state with mutations |
| `@riverpod` (StreamProvider) | Real-time data (WebSocket, SSE) |

**Architecture layers in Flutter**:
```
presentation/         ← Widgets, ConsumerWidget, UI state only
application/          ← AsyncNotifier providers (orchestrate domain logic)
domain/               ← Pure Dart: models, value objects, repo interfaces
infrastructure/       ← API client implementations, local storage
```

**Testing**: `ProviderContainer` (no Flutter widget tree required) for unit testing all providers in isolation.

**Alternatives Considered**:
- Provider: Requires BuildContext to read, limited scalability. Rejected.
- BLoC: Explicit event → state machine, high boilerplate. Rejected for this project's complexity/team size balance.
- GetX: Tight coupling between state, navigation, and DI. Rejected: violates module isolation.

---

## Decision 8 — Next.js App Router

**Decision**: Next.js 14 with App Router (not Pages Router).

**Rationale**:
- App Router is the current default and forward path.
- React Server Components reduce bundle size for data-heavy admin screens.
- Layout files provide clean per-section auth guards.
- `next/navigation` replaces `next/router` with better type safety.

**TypeScript**: Strict mode (`"strict": true` in tsconfig).

**API client**: Native `fetch` with server-action wrappers in App Router; React Query (TanStack Query) for client-side caching and mutation state.

---

## Decision 9 — Design Token Pipeline

**Decision**: W3C Design Tokens Community Group JSON format as the single source of truth. Style Dictionary v4 transforms tokens to:
- CSS custom properties for Next.js (Tailwind CSS integration)
- Dart `const` values for Flutter themes

**Token file location**: `packages/design-system/tokens/` (shared across both apps).

**Alternatives Considered**:
- Figma Tokens: Requires Figma plugin, proprietary format. Rejected: tool dependency.
- Tailwind config only: Not shareable with Flutter. Rejected: design system must be cross-platform.

---

## Decision 10 — OpenAPI Generation Strategy

**Decision**: `darkaonline/l5-swagger` (PHP annotations) for Laravel → generates `openapi.json`. TypeScript types auto-generated from OpenAPI spec via `openapi-typescript`.

**Alternatives Considered**:
- Scramble (annotation-free): Newer, cleaner, but less control over custom schemas. May be viable; team can evaluate.
- Hand-written OpenAPI: Drift risk with implementation. Rejected.

---

## Decision 11 — PHP Testing: Pest

**Decision**: Pest (built on PHPUnit) for all backend tests.

**Rationale**: Fluent `it()` / `expect()` API, better DX than raw PHPUnit, supports Architecture Tests (via `pest-plugin-arch`) for enforcing module boundaries — replaces need for Deptrac for most cases.

```php
// Architecture test example
arch('Domain models must not depend on Infrastructure')
    ->expect('App\\Modules\\Identity\\Domain')
    ->not->toUse('App\\Modules\\Identity\\Infrastructure');
```

**Alternatives Considered**:
- PHPUnit directly: Works, but more verbose. Pest is a strict superset. Rejected.
- Deptrac: Separate YAML-based dependency checker. Pest Architecture plugin covers this inline. Use Deptrac as a secondary check in CI if needed.

---

## Decision 12 — Dependency Boundary Enforcement

**Decision**: Pest Architecture Tests (primary) + optional Deptrac for CI.

**Rules encoded as tests** (samples):
- `Domain` layers must not use `Infrastructure` layers.
- `Platform` module may be used by all modules.
- No module's `Domain` may import another module's `Domain` (communicate via contracts only).
- `Http/Controllers` must not contain domain logic.

---

## Decision 13 — Configuration Service Storage

**Decision**: PostgreSQL `platform.configuration_values` table with versioning and effective dates. Redis cache for read performance (invalidated on write). Admin API provides CRUD.

**Key format**: Dot-notation (`transaction.limit.daily`, `wallet.limit.single`, `kyc.rule.document_types`).

**TBD placeholder approach**: Approved configurable keys are seeded with `null` or system-safe defaults. Reads return `null` or a typed `TbdConfigValue` sentinel. Domain code checks for TBD before use and throws `TbdConfigurationException` (surfaced as a 503 with a clear error code), never silently assuming a value.

---

## TBD / Deferred Items

These are correctly left open per Constitution Principle 2. The architecture supports plugging in the value later:

| Item | How architecture stays ready |
|---|---|
| GAP-027 step-up threshold | `configuration.mfa.step_up_threshold` key seeded as `null`; evaluated at call time by Identity module |
| CI/CD provider | Pipeline scripts use environment variables; YAML format is the only provider-specific artifact |
| Audit write-once enforcement | `audit_events` table has no UPDATE/DELETE grants in migration; write-once can be upgraded to WORM storage or external sink by changing the `AuditDriver` contract implementation |
| Observability backend | Structured log output to stdout; log aggregation backend is infrastructure concern, not application code |
| Performance targets | Not hard-coded; metrics are collected and SLOs configured in the monitoring platform when chosen |
| Security policy values (TLS, encryption) | Controlled by infra config (nginx TLS, PostgreSQL `ssl_ciphers`); not hard-coded in app |
