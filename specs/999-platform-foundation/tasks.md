# Implementation Tasks: Platform Foundation

**Feature**: Platform Foundation (`999-platform-foundation`)
**Date**: 2026-08-16
**Spec**: [spec.md](spec.md) | **Plan**: [plan.md](plan.md) | **Data Model**: [data-model.md](data-model.md)
**FR Count**: 193 | **User Stories**: US1–US10 | **Phase Count**: 14

---

## Scope Reminder

Platform Foundation implements **zero business logic**. It MUST NOT implement: authentication flows, registration, login, OTP, PIN, MFA, KYC, wallet operations, ledger posting, transfers, funding, withdrawals, payment processing, agent workflows, risk/fraud decisions, reconciliation, or settlement. Those belong to E01–E34.

---

## Financial Safety Invariants (enforced by all relevant tasks)

- Never mutate wallet balances directly — only ledger posting (E07) may do so.
- Never alter historical ledger entries.
- Idempotency key uniqueness is `UNIQUE(actor_id, idempotency_key)` — NOT globally unique.
- `actor_id` is NOT NULL; zero UUID `00000000-0000-0000-0000-000000000000` for unauthenticated system calls.
- Domain state change + Outbox INSERT must be in the SAME PostgreSQL transaction.
- Money in-memory: `int` (smallest currency unit). Money in DB: `NUMERIC(19,4)`. Conversion only at Infrastructure layer.
- `platform.audit_events` is immutable: app role has INSERT + SELECT only.
- Redis is never the source of financial truth.

## Database Isolation Invariants (enforced by all schema/repository tasks)

- Each module uses exactly one named PostgreSQL connection matching its schema.
- No migration may create a `FOREIGN KEY` to a table in another schema.
- No repository may execute a SQL JOIN across schemas.
- Cross-module data access: via Module Contract interface (sync) or Integration Event subscription (async).
- All violations detected by `platform:check-scope` Artisan command and Pest Architecture Tests.

---

## Dependency Graph (story completion order)

```
Phase 1 (Setup) → Phase 2 (Foundation)
                      │
              ┌───────┼─────────────────────────────────┐
              │       │                                 │
           [US4]   [US2] → [US3]                    [US1]
           DB         Module  API
           Schema     Arch    Contract
              │       │       │
              └───────┴───────┘
                      │
              ┌───────┼───────────────────────────────┐
              │       │                               │
           [US5]   [US6]                           [US7]
           Security Event                          Config
              │       │                               │
              └───────┴───────────────────────────────┘
                      │
              ┌───────┼──────────────────────────────────┐
              │       │                                  │
           [US8]   [US9]                             [US10]
           Observ  Testing                           Design System
              │       │                                  │
              └───────┴──────────────────────────────────┘
                      │
                  Phase N (Polish)
```

---

## Phase 1: Setup

*Project bootstrap. No user story labels — these are prerequisites for everything.*

**Goal**: Monorepo scaffolded, tooling installed, development environment running.

**Independent test**: `docker compose up -d --wait` succeeds; `GET /health` returns 200.

### Category 1 — Repository and Project Bootstrap

- [X] T001 Initialize git monorepo at `wallet-ecosystem/` with root `.gitignore`, `.gitattributes`, and top-level `README.md`
- [X] T002 [P] Create monorepo directory skeleton: `apps/admin/`, `apps/customer/`, `apps/agent/`, `packages/design-system/`, `packages/shared-utils/`, `services/api/`, `docs/adrs/`, `docs/architecture/`, `docs/api/`, `docs/runbooks/`, `scripts/dev/`, `scripts/ci/`
- [X] T003 [P] Create Laravel 11 application scaffold at `services/api/` via `composer create-project laravel/laravel` (PHP 8.4+); commit generated baseline
- [X] T004 [P] Install required Composer packages: `ramsey/uuid:^4.7`, `darkaonline/l5-swagger`, `pestphp/pest`, `pestphp/pest-plugin-arch`, `pestphp/pest-plugin-laravel`, `nunomaduro/larastan`, `squizlabs/php_codesniffer` in `services/api/`
- [X] T005 [P] Create Next.js 14 App Router project scaffold at `apps/admin/` via `npx create-next-app@14 --typescript --tailwind --app --src-dir=false`; remove default boilerplate; set `strict: true` in `apps/admin/tsconfig.json`
- [X] T006 [P] Install required npm packages in `apps/admin/`: `@tanstack/react-query`, `jest`, `@testing-library/react`, `@testing-library/jest-dom`, `jest-axe`, `msw`, `openapi-typescript`, `axe-core`
- [X] T007 [P] Create Flutter project scaffold at `apps/customer/` via `flutter create customer --org com.olekard`; create parallel `apps/agent/` via `flutter create agent --org com.olekard`; add `flutter_riverpod`, `riverpod_generator`, `riverpod_lint`, `dio`, `go_router`, `flutter_test`, `integration_test` to each `pubspec.yaml`
- [X] T008 [P] Create `packages/design-system/` directory with `package.json` (name: `@olekard/design-system`); install `style-dictionary@^4`; create `packages/design-system/style-dictionary.config.js` skeleton
- [X] T009 Create root `.env.example` with all required environment variable keys and placeholder values (no real secrets); document each variable; create `services/api/.env.example` and `services/api/.env.testing`

<details>
<summary>T001 Detail</summary>

**FR mapping**: FR-001 (monorepo foundation), FR-153 (developer onboarding)
**Purpose**: Establish the monorepo root that all other tasks build upon.
**Dependencies**: None
**Files/modules affected**: `wallet-ecosystem/.gitignore`, `wallet-ecosystem/.gitattributes`, `wallet-ecosystem/README.md`
**Implementation detail**: Initialize git repo. `.gitignore` must exclude: `.env`, `*.env.local`, `node_modules/`, `vendor/`, `.dart_tool/`, `build/`, `storage/logs/`, `storage/framework/cache/`. `.gitattributes` must normalize line endings for cross-platform consistency. `README.md` must link to `DEVELOPMENT.md` and note "see specs/999-platform-foundation/" for architecture.
**Acceptance criteria**: `git status` shows clean working tree after commit. `.gitignore` excludes all sensitive and generated paths.
**Test requirements**: Manual: confirm `.env` files do not appear in `git status` after creation.
**Security considerations**: `.gitignore` must prevent accidental credential commits. Never commit real `.env` values.
**Definition of Done**: Repo initialized, `.gitignore` reviewed and covers all exclusion categories, committed.

</details>

<details>
<summary>T003 Detail</summary>

**FR mapping**: FR-003 (Laravel 11), FR-004 (PHP 8.4+)
**Purpose**: Establish the Laravel API base application.
**Dependencies**: T002
**Files/modules affected**: `services/api/` (entire Laravel scaffold)
**Implementation detail**: Use `composer create-project laravel/laravel:^11 .` inside `services/api/`. Remove unused packages. Set PHP version constraint to `^8.4` in `composer.json`. Configure `config/app.php`: `timezone = 'UTC'`, `locale = 'en'`. Verify `artisan` runs without error.
**Acceptance criteria**: `php artisan --version` outputs Laravel 11.x. `php artisan serve` starts without errors.
**Test requirements**: `php artisan test` runs with zero failures on default scaffold.
**Security considerations**: Remove `APP_DEBUG=true` from any committed config defaults.
**Definition of Done**: `composer install` + `php artisan migrate` succeed on a clean environment.

</details>

---

## Phase 2: Foundation

*Blocking infrastructure. No user story labels — must complete before any US phase.*

**Goal**: Database connections, module directory structure, error handling baseline, and environment configuration in place.

### Category 22 — Environment Configuration

- [X] T010 Configure `services/api/config/database.php` with 9 named PostgreSQL connections (`platform`, `identity`, `wallet`, `ledger`, `kyc`, `transaction`, `risk`, `agent`, `notification`) each using `pgsql_config(string $schema)` helper that sets `driver=pgsql`, reads env vars `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, and sets `search_path` to the schema name
- [X] T011 Create `services/api/config/horizon.php` with `supervisor-default` (queues: critical/high/default/low, processes: 10, tries: 3, timeout: 60) and `supervisor-outbox` (queue: outbox, processes: 3, tries: 1) as per plan; configure queue naming convention `{module}.{priority}` with examples documented in file
- [X] T012 [P] Create `services/api/config/logging.php` overriding default stack to use `stderr` channel with `JsonFormatter` (Monolog); fields: `timestamp`, `level`, `message`, `correlation_id`, `request_id`, `actor_id`, `module`, `context`

<details>
<summary>T010 Detail</summary>

**FR mapping**: FR-014 (schema-per-module), FR-068 (named connections)
**Purpose**: Establish the 9 named database connections that enforce schema isolation.
**Dependencies**: T003
**Files/modules affected**: `services/api/config/database.php`
**Implementation detail**: Add a private `pgsql_config(string $schema): array` helper (or closure) inside the config file that returns the standard PostgreSQL connection array with `'search_path' => $schema`. All 9 connections call this helper with their schema name. Each connection reads from the same env vars in monolith phase; extraction = change host env var per module. Set `'strict' => true` and `'charset' => 'utf8'` on all connections. Set `DB_CONNECTION=platform` as default.
**Acceptance criteria**: `php artisan config:show database` lists all 9 connections. `DB::connection('wallet')` and `DB::connection('platform')` both connect without error in test environment.
**Test requirements**: Feature test: assert `DB::connection('platform')->getPdo()` succeeds. Assert `DB::connection('identity')->getPdo()` succeeds.
**Security considerations**: No hardcoded credentials. All values from env. `DB_PASSWORD` must never appear in logs.
**Definition of Done**: All 9 named connections defined; `php artisan tinker` can connect to each without error.

</details>

### Category 2 — Laravel Foundation (Module Structure)

- [X] T013 Create module directory structure for `Platform` module under `services/api/app/Modules/Platform/` with subdirectories: `Application/Services/`, `Domain/Contracts/`, `Domain/Events/`, `Domain/ValueObjects/`, `Infrastructure/Persistence/`, `Infrastructure/Cache/`, `Infrastructure/Migrations/`, `Providers/`
- [X] T014 [P] Create stub module directories for all 8 non-Platform modules: `Identity/`, `Wallet/`, `Ledger/`, `Kyc/`, `Transaction/`, `Risk/`, `Agent/`, `Notification/` — each with `Domain/Contracts/`, `Infrastructure/Migrations/`, `Providers/` subdirectories under `services/api/app/Modules/`
- [X] T015 Create `services/api/app/Modules/Platform/Providers/PlatformServiceProvider.php` extending `Illuminate\Support\ServiceProvider`; it must: (1) call `$this->loadMigrationsFrom(__DIR__ . '/../Infrastructure/Migrations')`, (2) register bindings for all Platform domain contracts to their implementations (stubs initially), (3) be registered in `services/api/bootstrap/providers.php`
- [X] T016 [P] Create stub `ModuleServiceProvider` for each of the 8 non-Platform modules (e.g., `services/api/app/Modules/Identity/Providers/IdentityServiceProvider.php`); each stub must: (1) call `$this->loadMigrationsFrom()` pointing to its own Migrations dir, (2) implement no bindings (throws `ModuleNotImplementedException` on resolution), (3) be registered in `bootstrap/providers.php`
- [X] T017 Create `services/api/app/Exceptions/ModuleNotImplementedException.php` and `services/api/app/Exceptions/TbdConfigurationException.php`; `TbdConfigurationException` must set HTTP 503, error code `TBD_CONFIGURATION`, `retryable: false`, `Retry-After: 3600` in its response
- [X] T018 Create `services/api/app/Http/Controllers/ApiController.php` base class with helper methods: `ok(mixed $data = null): JsonResponse`, `created(mixed $data): JsonResponse`, `accepted(): JsonResponse`, `noContent(): JsonResponse`, `notFound(string $message = 'Resource not found'): JsonResponse`; all methods wrap response in standard envelope (defined by T040)

<details>
<summary>T017 Detail</summary>

**FR mapping**: FR-179 (NullImplementation behavior), FR-177 (TbdConfigurationException)
**Purpose**: Establish exception types that enforce correct error contracts for unimplemented modules and TBD configuration.
**Dependencies**: T013
**Files/modules affected**: `services/api/app/Exceptions/ModuleNotImplementedException.php`, `services/api/app/Exceptions/TbdConfigurationException.php`
**Implementation detail**: `ModuleNotImplementedException` must include the module name and method name in its message (e.g., "Module Wallet method isActive is not implemented in Platform Foundation"). `TbdConfigurationException` must carry the config key and expose `getRetryAfter(): int` (hardcoded 3600). Both exceptions are mapped in the global Handler (T039).
**Acceptance criteria**: Instantiating `ModuleNotImplementedException('Wallet', 'isActive')` produces message containing "Wallet" and "isActive". `TbdConfigurationException` produces 503 JSON response with `error_code: TBD_CONFIGURATION` and `Retry-After: 3600` header.
**Test requirements**: Unit test: both exception constructors and message format. Feature test: calling a stub module contract method returns 503 with correct body.
**Security considerations**: Exception messages must not leak internal paths or DB schema details.
**Definition of Done**: Both exception classes exist, tested, and mapped in Handler.

</details>

---

## Phase 3: US1 — Developer Onboarding

**User Story**: As a new developer, I can clone the repo and have a working local environment within 30 minutes using `docker compose up -d --wait`.

**Priority**: P1 | **Success Criterion**: SC-001 (≤ 30 min onboarding)

**Independent test**: Full `docker compose up -d --wait` → migrate → seed → `GET /health` → `GET /ready` all pass on a clean machine.

### Category 21 — Docker / Local Development

- [X] T019 [US1] Create `wallet-ecosystem/docker-compose.yml` with services: `api` (builds from `services/api/`, depends on postgres+redis, healthcheck via `curl /health`), `postgres` (postgres:15-alpine, POSTGRES_DB=wallet_ecosystem, POSTGRES_USER=wallet_app, volume mounted), `redis` (redis:7-alpine), `horizon` (same api image, command: `php artisan horizon`, depends on api+postgres+redis), `admin` (builds from `apps/admin/`, port 3000:3000)
- [X] T020 [US1] Create `wallet-ecosystem/docker-compose.override.yml` with local development overrides: bind-mount `services/api/` for hot reload, expose postgres port 5432:5432, expose redis port 6379:6379, set `APP_ENV=local` and `APP_DEBUG=true`
- [X] T021 [US1] Create `services/api/Dockerfile` (multi-stage: `base` stage installs PHP extensions + composer; `dev` stage adds xdebug; `prod` stage is lean, no dev tools); expose port 8080; set `CMD ["php-fpm"]`; include healthcheck instruction
- [X] T022 [US1] Create `apps/admin/Dockerfile` (multi-stage: `deps` installs node modules; `builder` runs `npm run build`; `runner` is node:20-alpine serving next start); expose port 3000

### Category 23 — Documentation

- [X] T023 [US1] Create `wallet-ecosystem/DEVELOPMENT.md` covering: prerequisites (Docker Desktop, Flutter SDK, PHP 8.4), one-command local setup (`docker compose up -d --wait && docker compose exec api php artisan migrate && php artisan db:seed`), environment variables reference (link to `.env.example`), module structure overview (link to plan.md), running tests (backend/frontend/mobile commands), common troubleshooting (port conflicts, DB migration errors), architecture decision records location (`docs/adrs/`); MUST include an **Operational Tuning** section documenting: "Operational recommendation: begin `events.outbox.processing_timeout_seconds` with 300 seconds and tune based on workload/operational evidence. This key is seeded as null; the implementation uses a 300-second safety fallback when null so the stuck-processing safety mechanism is never disabled. Operators SHOULD configure this key explicitly to override the fallback with a value tuned to their workload."
- [X] T024 [US1] Create ADR template at `docs/adrs/000-template.md` with sections: Status, Context, Decision, Consequences, Alternatives Considered; create first ADR `docs/adrs/001-monorepo-structure.md` documenting the monorepo choice

<details>
<summary>T019 Detail</summary>

**FR mapping**: FR-153 (onboarding ≤ 30 min, SC-001), FR-156 (Docker local dev)
**Purpose**: Enable one-command local environment startup.
**Dependencies**: T003, T005, T007, T009, T010
**Files/modules affected**: `wallet-ecosystem/docker-compose.yml`
**Implementation detail**: Each service must have a `healthcheck` defined. `api` healthcheck: `curl -f http://localhost:8000/health`. `postgres` healthcheck: `pg_isready -U wallet_app`. `redis` healthcheck: `redis-cli ping`. `horizon` healthcheck: `php artisan horizon:status`. Use `depends_on` with `condition: service_healthy` for all dependency chains. All secrets use `${VAR}` env var references — never hardcoded. Add a `volumes` section for `postgres_data`.
**Acceptance criteria**: `docker compose up -d --wait` exits 0 on a machine with no prior state. All services report healthy. `curl http://localhost:8000/health` returns `{"status":"ok"}`.
**Test requirements**: Manual: destroy all volumes (`docker compose down -v`), re-run `docker compose up -d --wait`, verify zero failures.
**Security considerations**: No real credentials in `docker-compose.yml`. Use `${DB_PASSWORD}` references. `postgres_data` volume must not be committed.
**Definition of Done**: One-command startup succeeds from clean state; all 5 services healthy.

</details>

### Category 24 — Quickstart / Validation Automation

- [X] T025 [US1] Create `scripts/dev/validate-env.sh` that checks all required env vars are set (reads from `.env.example` keys, checks each exists in environment); exits 1 with descriptive error if any key missing
- [X] T026 [US1] Create `scripts/dev/setup.sh` wrapping: `cp .env.example .env`, `docker compose up -d --wait`, `docker compose exec api php artisan migrate`, `docker compose exec api php artisan db:seed`, `docker compose exec api php artisan l5-swagger:generate`; script must be idempotent (safe to run on existing environment)

---

## Phase 4: US2 — Architecture / Module Structure

**User Story**: As a developer, I can create a new business module following established patterns without modifying Platform Foundation's core.

**Priority**: P1 | **Success Criterion**: SC-002 (module structure documented and enforced)

**Independent test**: `php artisan test --filter=ArchitectureTest` passes. `php artisan platform:check-scope` exits 0 on clean codebase. A new stub module created following the pattern compiles without error.

### Category 10 — Module Contracts

- [X] T027 [US2] Create `services/api/app/Modules/Platform/Domain/Contracts/IdempotencyServiceInterface.php` with methods: `findCompletedResponse(string $idempotencyKey): ?IdempotencyResult`, `acquireLock(string $idempotencyKey, string $requestHash): IdempotencyLock`, `recordResponse(IdempotencyLock $lock, int $statusCode, array $responseBody): void`, `recordFailure(IdempotencyLock $lock, \Throwable $e): void`
- [X] T028 [P] [US2] Create `services/api/app/Modules/Platform/Domain/Contracts/EventPublisherInterface.php` with methods: `publish(IntegrationEventInterface $event): void`, `publishAll(array $events): void`
- [X] T029 [P] [US2] Create `services/api/app/Modules/Platform/Domain/Contracts/AuditServiceInterface.php` with method: `record(AuditEventData $event): void`
- [X] T030 [P] [US2] Create `services/api/app/Modules/Platform/Domain/Contracts/ConfigurationServiceInterface.php` with methods: `get(string $key, bool $requireValue = false): mixed`, `getOrDefault(string $key, mixed $default): mixed`, `isConfigured(string $key): bool`, `invalidate(string $key): void`
- [X] T031 [P] [US2] Create `services/api/app/Modules/Platform/Domain/Contracts/CorrelationContextInterface.php` with methods: `correlationId(): string`, `requestId(): string`, `actor(): ?ActorContract`, `withCorrelationId(string $id): static`, `withActor(ActorContract $actor): static`
- [X] T032 [P] [US2] Create `services/api/app/Modules/Platform/Domain/Contracts/ActorContract.php` with methods: `id(): string`, `type(): string`, `isUser(): bool`, `isService(): bool`
- [X] T033 [P] [US2] Create `services/api/app/Modules/Platform/Domain/Contracts/MoneyContract.php` with methods: `amount(): int`, `currency(): CurrencyContract`, `add(MoneyContract $other): static`, `subtract(MoneyContract $other): static`, `isZero(): bool`, `isPositive(): bool`, `equals(MoneyContract $other): bool`, `toDisplayString(): string`; add PHPDoc note: "amount() returns integer in smallest currency unit (e.g., thebe for BWP)"
- [X] T034 [P] [US2] Create `services/api/app/Modules/Platform/Domain/Contracts/CurrencyContract.php` with methods: `code(): string`, `precision(): int`, `name(): string`, `isSupported(): bool`
- [X] T035 [P] [US2] Create `services/api/app/Modules/Platform/Domain/Contracts/IntegrationEventInterface.php` with methods: `eventId(): string`, `aggregateId(): string`, `aggregateType(): string`, `actorId(): ?string`, `correlationId(): string`, `causationId(): ?string`, `occurredAt(): \DateTimeImmutable`, `eventName(): string`, `eventVersion(): string`, `payload(): array`, `metadata(): array`
- [X] T036 [P] [US2] Create stub module contracts in their respective namespaces (as defined in `contracts/module-contracts.md`): `services/api/app/Modules/Identity/Domain/Contracts/IdentityModuleContract.php`, `services/api/app/Modules/Notification/Domain/Contracts/NotificationModuleContract.php`, `services/api/app/Modules/Wallet/Domain/Contracts/WalletModuleContract.php`, `services/api/app/Modules/Ledger/Domain/Contracts/LedgerModuleContract.php`, `services/api/app/Modules/Transaction/Domain/Contracts/TransactionModuleContract.php`
- [X] T037 [US2] Create `NullImplementation` classes for each stub module contract in `services/api/tests/Support/Mocks/` (e.g., `NullIdentityModule.php`); each method must throw `ModuleNotImplementedException` with module name and method name; register each `NullImplementation` in the corresponding stub `ModuleServiceProvider` binding

### Category 2 — Module Boundary Enforcement

- [X] T038 [US2] Create `services/api/app/Console/Commands/CheckScopeCommand.php` implementing `php artisan platform:check-scope`; command scans `services/api/app/Modules/` for PHP files; checks for: (1) any `use App\Modules\{A}\Domain` in `App\Modules\{B}` where A≠B and A≠Platform (cross-domain import), (2) any cross-schema SQL JOINs in repository files (pattern: `JOIN {schema}.`), (3) any Eloquent model used outside its owning module; exits 0 if clean, exits 1 with list of violations if not; output is CI-parseable

<details>
<summary>T038 Detail</summary>

**FR mapping**: FR-180 (platform:check-scope command), FR-011 (module isolation), FR-068 (schema-per-module)
**Purpose**: Automated enforcement of module boundary rules as a CI gate.
**Dependencies**: T013, T014, T015, T016
**Files/modules affected**: `services/api/app/Console/Commands/CheckScopeCommand.php`
**Implementation detail**: Use PHP `RecursiveDirectoryIterator` to walk `app/Modules/`. Parse each `.php` file for `use` statements and SQL strings. For cross-domain import check: extract namespace from file path, extract imported namespace from `use` statement; flag if both are Module namespaces (not Platform). For SQL JOIN check: regex `JOIN\s+\w+\.\w+` in Eloquent models and repositories. Command signature: `platform:check-scope {--fix : Dry run, suggests fixes}`. Output violations as `ERROR: [file:line] description`. Include violation count summary at end.
**Acceptance criteria**: Running on a clean codebase exits 0. Adding a `use App\Modules\Wallet\Domain\WalletRepository` to an Identity file causes exit 1 with that file listed. Adding a cross-schema JOIN string causes exit 1.
**Test requirements**: Unit test: violation detection logic with sample PHP strings. Feature test: create temporary files with violations, run command, assert exit 1.
**Security considerations**: Command is read-only (no file writes). Safe to run in CI.
**Definition of Done**: Command exits 0 on clean codebase; exits 1 with specific violation details on polluted codebase; registered in CI pipeline.

</details>

---

## Phase 5: US3 — API Contract

**User Story**: As a backend developer, I can implement any business endpoint by inheriting from the platform API foundation without re-implementing authentication, idempotency, error handling, or correlation ID propagation.

**Priority**: P1 | **Success Criterion**: SC-003 (all endpoints return standard envelope)

**Independent test**: `POST /v1/test-echo` with `Idempotency-Key` header returns correct envelope; second identical request returns cached response; third with different body returns 409.

### Category 4 — API Foundation

- [X] T039 [US3] Create `services/api/app/Exceptions/Handler.php` mapping all exception types to HTTP responses per plan error model: `ValidationException→422`, `AuthenticationException→401`, `AuthorizationException→403`, `ModelNotFoundException→404`, `IdempotencyKeyMismatchException→409`, `IdempotencyInProgressException→409`, `RateLimitExceededException→429`, `TbdConfigurationException→503`, `Throwable→500`; all responses use standard envelope; include `correlation_id` in every error response; never include stack trace, file paths, DB schema names, or user existence signals in response body

<details>
<summary>T039 Detail</summary>

**FR mapping**: FR-011 (centralized exception handling), FR-036–FR-042 (error categories and HTTP status codes), FR-193 (named DB connection failure → HTTP 503 SERVICE_UNAVAILABLE; exception class created and handler mapping applied by T129)
**Purpose**: Central exception handler mapping all domain exceptions to standardized HTTP responses with the standard envelope.
**Dependencies**: T017 (Laravel scaffold — base exception classes), T040 (ResponseEnvelopeMiddleware — envelope format), T041 (routes registered), T043 (custom exception classes: `IdempotencyKeyMismatchException`, `IdempotencyInProgressException`, `BusinessRuleViolationException`, `DuplicateResourceException`, `ProviderException` must exist before Handler imports and maps them — complete T043 before finalizing T039's exception mappings); T129 amends this file post-completion to add `ModuleConnectionUnavailableException→503` mapping
**Files/modules affected**: `services/api/app/Exceptions/Handler.php`
**Acceptance criteria**: All 9 exception types map to correct HTTP status codes (FR-193 tenth mapping added by T129). No stack traces in error responses. Correlation ID present in all error responses. Error body never reveals DB schema names, internal file paths, or user existence.
**Definition of Done**: All exception → status mappings tested. Security review confirms no information leakage.

</details>

- [X] T040 [US3] Create response envelope middleware `services/api/app/Http/Middleware/ResponseEnvelopeMiddleware.php`; wraps controller output in `{"success": bool, "data": ..., "error": null|{code, message, details}, "correlation_id": "...", "meta": null|{...}}`; must handle both `JsonResponse` and `Response` types; registers as global after-middleware
- [X] T041 [US3] Configure `services/api/routes/api.php` to: set `/v1/` prefix for all business routes; include module-specific route files from `services/api/routes/modules/`; define health routes outside the `/v1/` prefix at `/health`, `/ready`, `/startup`; apply middleware stack in documented order (TrustProxies → SecurityHeaders → CorrelationId → RequestLogging → Authentication → Idempotency → Throttle → ResponseEnvelope → ResponseLogging)
- [X] T041b [US3] Create placeholder route files for all stub modules in `services/api/routes/modules/`: `identity.php`, `wallet.php`, `ledger.php`, `kyc.php`, `transaction.php`, `risk.php`, `agent.php`, `notification.php`; each file contains only: `<?php // Module routes are loaded by {Module}ServiceProvider. This placeholder is required by routes/api.php include. Do not add routes directly here.`; these placeholders prevent glob/include errors in T041 when stub module Service Providers have not yet registered routes; each module's concrete Service Provider (T036–T037) MUST call `$this->loadRoutesFrom(base_path('routes/modules/{module}.php'))` from `boot()`, replacing this placeholder with real routes when the module is implemented
- [X] T042 [US3] Install and configure `darkaonline/l5-swagger` in `services/api/`; configure `config/l5-swagger.php` with `api.title = 'Wallet Ecosystem API'`, `version = '1.0.0'`, output path `storage/api-docs/openapi.json`; create `services/api/app/Http/Controllers/HealthController.php` with `GET /health`, `GET /ready`, `GET /startup` endpoints each returning JSON with status; annotate all three with OpenAPI PHPDoc

### Category 5 — Error Handling

- [X] T043 [P] [US3] Create custom exception classes in `services/api/app/Exceptions/`: `IdempotencyKeyMismatchException.php`, `IdempotencyInProgressException.php`, `BusinessRuleViolationException.php`, `DuplicateResourceException.php`, `ProviderException.php`; each must carry the `correlationId` so Handler can include it in the error response; note: `ModuleConnectionUnavailableException.php` (FR-193) is a separate exception class created in T129 and mapped in Handler.php by T129
- [X] T044 [P] [US3] Create `services/api/app/Http/Resources/ApiResource.php` base class extending `JsonResource` that auto-applies the standard envelope to resource responses; create `services/api/app/Http/Resources/PaginatedResource.php` for paginated list responses including `meta.pagination` object with `page`, `per_page`, `total`, `total_pages`

### Category 6 — Correlation / Tracing

- [X] T045 [US3] Create `services/api/app/Modules/Platform/Domain/ValueObjects/CorrelationContext.php` implementing `CorrelationContextInterface`; create `services/api/app/Http/Middleware/CorrelationIdMiddleware.php` that: reads `X-Correlation-ID` header; validates UUID format; generates UUID v4 if absent or invalid (NOTE: UUID v4 is deliberate here — correlation IDs do not require time-ordering per FR-032; this differs from idempotency keys which MUST be UUID v7 per FR-043); stores in `CorrelationContext` singleton; injects into Monolog log context via `Log::withContext()`; adds `X-Correlation-ID` to response header; passes `correlation_id` to all Horizon jobs dispatched from the request
- [X] T046 [P] [US3] Create `services/api/app/Http/Middleware/RequestLoggingMiddleware.php` logging on request start: `INFO` level with fields `method`, `path`, `correlation_id`, `actor_id` (if available), masked request body (PII fields redacted via `PiiMasker`); create `services/api/app/Http/Middleware/ResponseLoggingMiddleware.php` logging on response: `INFO` with `status`, `duration_ms`, `correlation_id`

### Category 7 — Idempotency

- [X] T047 [US3] Create `services/api/app/Modules/Platform/Infrastructure/Persistence/IdempotencyKeyModel.php` (Eloquent model for `platform.idempotency_keys`); connection must be `platform`; table must be `idempotency_keys`; no `$guarded = []` — use explicit `$fillable`; implement `scopeForActor(Builder $query, string $actorId): Builder`
- [X] T048 [US3] Create `services/api/app/Modules/Platform/Infrastructure/Cache/IdempotencyCacheRepository.php`; Redis key pattern: `idempotency:{actor_id}:{idempotency_key}`; TTL from `ConfigurationServiceInterface::get('idempotency.ttl_seconds')`; implements: `find(string $actorId, string $key): ?array`, `store(string $actorId, string $key, array $response, int $ttl): void`, `delete(string $actorId, string $key): void`
- [X] T049 [US3] Create `services/api/app/Modules/Platform/Application/Services/IdempotencyService.php` implementing `IdempotencyServiceInterface`; flow: (1) Redis cache check → HIT: return cached response, (2) PostgreSQL `INSERT INTO platform.idempotency_keys ... ON CONFLICT (actor_id, idempotency_key) DO NOTHING` — if 0 rows inserted, SELECT FOR UPDATE SKIP LOCKED the existing row, (3) if status=processing → throw `IdempotencyInProgressException`, (4) if status=completed → warm Redis cache, return cached response, (5) if newly inserted → return null (caller proceeds with business logic), (6) on completion → record response via `recordResponse()` + warm Redis cache; `actor_id` is extracted from `CorrelationContext`; zero UUID used if actor is null (system calls); FINANCIAL SAFETY: uniqueness is `(actor_id, idempotency_key)` — NOT globally unique
- [X] T050 [US3] Create `services/api/app/Http/Middleware/IdempotencyMiddleware.php`; applies to `POST`, `PATCH`, `PUT`, `DELETE` methods only; routes excluded via config list; flow: extract `Idempotency-Key` header → validate UUID format → compute `request_hash` as SHA-256 of `{method}:{path}:{body}` → call `IdempotencyService::findCompletedResponse()` → if found: return 200 with stored response + `Idempotency-Replay: true` header → otherwise: acquire lock → pass to controller → record response or failure; attach `IdempotencyLock` to request for controller use

<details>
<summary>T049 Detail</summary>

**FR mapping**: FR-046 (idempotency scope), FR-047 (idempotency flow), FR-048 (idempotency fields), FR-057 (PostgreSQL authoritative), FR-059 (Redis cache)
**Purpose**: Core idempotency service enforcing financial safety through duplicate processing prevention.
**Dependencies**: T047, T048, T031, T045
**Files/modules affected**: `services/api/app/Modules/Platform/Application/Services/IdempotencyService.php`
**Implementation detail**: The `acquireLock()` method must use a PostgreSQL transaction with `SELECT ... FOR UPDATE SKIP LOCKED` to prevent concurrent execution of the same idempotency key. The `INSERT ON CONFLICT DO NOTHING` is the racing insert. If 0 rows affected, the row already exists — SELECT FOR UPDATE to get its status. If `status='processing'` and another request holds the lock: throw `IdempotencyInProgressException`. If status='completed': return the stored `response_body` without executing business logic. The `actor_id` column is NEVER NULL — use the zero UUID `00000000-0000-0000-0000-000000000000` for unauthenticated system calls. The UNIQUE INDEX is `ON (actor_id, idempotency_key)` — two different actors may use the same key string value without conflict.
**Acceptance criteria**: (1) Two concurrent requests with same actor+key: one proceeds, one gets 409 IDEMPOTENCY_IN_PROGRESS. (2) Second request after completion: gets exact same response body as first. (3) Same key, different actor: both proceed independently. (4) Same key, different body: gets 409 IDEMPOTENCY_KEY_MISMATCH. (5) System call with no actor: uses zero UUID, does not fail.
**Test requirements**: Unit test: all branching paths with mocked DB/Redis. Feature test: actual concurrent HTTP requests using parallel processes. Feature test: replay verification. Feature test: different-actor reuse.
**Security considerations**: Redis cache must never be the source of truth for financial decisions. PostgreSQL is authoritative. Idempotency key validated as UUID before use (prevents injection). Actor extracted from authenticated context — never from request body.
**Definition of Done**: All 5 acceptance criteria pass with actual concurrent requests. Coverage ≥80%.

</details>

### Category 8 — Redis / Horizon

- [X] T051 [US3] Install `laravel/horizon` via Composer in `services/api/`; publish Horizon config; configure `config/horizon.php` per plan (supervisor-default + supervisor-outbox); create `services/api/app/Modules/Platform/Infrastructure/Migrations/2026_08_01_000000_install_horizon_tables.php` that runs `php artisan horizon:install` migrations; register `HorizonServiceProvider` in `bootstrap/providers.php`
- [X] T052 [P] [US3] Create `services/api/app/Http/Middleware/SecurityHeadersMiddleware.php` applying headers: `Strict-Transport-Security: max-age=31536000; includeSubDomains`, `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: strict-origin-when-cross-origin`, `Content-Security-Policy: [placeholder, TBD by security team]`; register as early middleware

---

## Phase 6: US4 — Database Schema Conventions

**User Story**: As a database administrator, I can identify which module owns each database table and trust that no module accesses another module's tables directly.

**Priority**: P1 | **Success Criterion**: SC-004 (all schemas created; no cross-schema FKs; arch tests pass)

**Independent test**: `php artisan migrate` creates all 9 schemas and 6 platform tables. `php artisan test --filter=ModuleBoundaryTest` passes. No FK violations detected.

### Category 3 — PostgreSQL / Schema Foundation

- [X] T053 [US4] Create migration `services/api/app/Modules/Platform/Infrastructure/Migrations/2026_08_01_000001_create_all_schemas.php`; executes `CREATE SCHEMA IF NOT EXISTS` for all 9 schemas: `platform`, `identity`, `wallet`, `ledger`, `kyc`, `transaction`, `risk`, `agent`, `notification`; sets schema search_path appropriately; `down()` method is intentionally empty (dropping schemas in production requires DBA approval)
- [X] T054 [US4] Create migration `2026_08_01_000002_create_platform_idempotency_keys.php` creating `platform.idempotency_keys` with all columns per data-model.md; UNIQUE INDEX on `(actor_id, idempotency_key)`; INDEX on `(expires_at)`; INDEX on `(actor_id, created_at)`; connection must be explicitly `platform`; include comment "key uniqueness is scoped per actor — two different actors may reuse the same key value"
- [X] T055 [US4] Create migration `2026_08_01_000003_create_platform_outbox_events.php` creating `platform.outbox_events` with all columns per data-model.md including `processing_started_at timestamptz NULL`; INDEX on `(status, next_attempt_at)`; INDEX on `(status, processing_started_at)` for stuck-processing recovery; INDEX on `(aggregate_id, event_name)`; INDEX on `(correlation_id)`; app DB role has INSERT/SELECT/UPDATE (no DELETE); FINANCIAL SAFETY: no UPDATE/DELETE on delivered events
- [X] T056 [US4] Create migration `2026_08_01_000004_create_platform_audit_events.php` creating `platform.audit_events` with all columns per data-model.md; after table creation include SQL: `REVOKE UPDATE, DELETE ON platform.audit_events FROM wallet_app;` to enforce immutability at DB layer; all indexes per data-model.md; `down()` method must re-grant privileges before dropping table
- [X] T057 [US4] Create migration `2026_08_01_000005_create_platform_configuration_values.php` creating `platform.configuration_values` with all columns per data-model.md; UNIQUE INDEX on `(config_key, version)`; INDEX on `(config_key, status, effective_from)`; INDEX on `(status, effective_from)`
- [X] T058 [US4] Create migration `2026_08_01_000006_create_platform_configuration_audit.php` creating `platform.configuration_audit` with all columns per data-model.md; same immutability REVOKE as audit_events; FK to `platform.configuration_values(id)` is permitted (same schema)
- [X] T059 [US4] Create seeder `services/api/database/seeders/PlatformConfigurationSeeder.php` (called by migration `2026_08_01_000007_seed_platform_configuration_defaults.php`) seeding default config values: `platform.currencies.supported → ['BWP','USD','EUR','ZAR','CNY','USDT']`, `platform.currency.primary → 'BWP'`, `idempotency.ttl_seconds → 86400`, `events.max_attempts.default → 5`, `events.bus_driver → 'in_process'`; TBD items seeded as `value=null, value_type='null'` (no numeric default invented): `mfa.step_up_threshold`, `provider.payment_processor`, `reconciliation.tolerance`, `events.outbox.processing_timeout_seconds`; NEVER invent TBD values; the operational recommendation for `events.outbox.processing_timeout_seconds` (begin with 300 seconds) is documented in `DEVELOPMENT.md` only, NOT encoded as a seeded default
- [X] T060 [US4] Create Eloquent models for all platform tables (one per table): `services/api/app/Modules/Platform/Infrastructure/Persistence/OutboxEventModel.php`, `AuditEventModel.php`, `ConfigurationValueModel.php`, `ConfigurationAuditModel.php`; each must set `protected $connection = 'platform'`; each must use explicit `protected $table` including schema prefix (`platform.outbox_events`); `AuditEventModel` must override `save()` to throw `\LogicException` (audit events are INSERT-only, never updated via Eloquent)

<details>
<summary>T060 Detail</summary>

**FR mapping**: FR-085 (audit immutability), FR-014 (schema-per-module)
**Purpose**: Eloquent models for platform tables with schema isolation enforced at model level.
**Dependencies**: T053–T059, T010
**Files/modules affected**: `services/api/app/Modules/Platform/Infrastructure/Persistence/` (4 model files)
**Implementation detail**: All models: `$connection = 'platform'`. `IdempotencyKeyModel`: `$table = 'idempotency_keys'`; `$fillable = ['id', 'idempotency_key', 'actor_id', 'http_method', 'http_path', 'request_hash', 'status', 'expires_at']`. `OutboxEventModel`: `$table = 'outbox_events'`; custom `$casts` for `payload` and `metadata` as `array`. `AuditEventModel`: override `save()`, `update()`, `delete()` to throw `\LogicException('Audit events are immutable')`. `ConfigurationValueModel`: `$table = 'configuration_values'`; cast `value` as `array` when `value_type = 'json'`. All models use `HasUuids` trait for UUID v7 primary keys — or implement `newUniqueId()` returning `\Ramsey\Uuid\Uuid::uuid7()->toString()`.
**Acceptance criteria**: Attempting `$auditEvent->save()` throws `LogicException`. `IdempotencyKeyModel::create([...])` uses `platform` connection. Unit test verifies connection name on each model.
**Test requirements**: Unit: connection name, immutability guard for AuditEventModel. Feature: create a record via each model, verify it lands in the correct schema.
**Security considerations**: Immutability of audit events enforced at both DB level (REVOKE) and application level (Eloquent override) — defense in depth.
**Definition of Done**: All 4 models created; connection names verified in tests; `AuditEventModel` immutability tested.

</details>

### Architecture Tests for Module Isolation

- [X] T061 [US4] Create `services/api/tests/Architecture/ModuleBoundaryTest.php` using Pest Architecture plugin; assert: (1) `App\Modules\{X}\Domain` does not use `App\Modules\{Y}\Domain` unless Y=Platform, (2) `App\Modules\{X}\Infrastructure` does not use `App\Modules\{Y}\Infrastructure`, (3) no class in any module `Domain\` layer uses Eloquent (`Illuminate\Database\Eloquent`), (4) controller classes do not use domain service classes directly (must go through Application layer)
- [X] T062 [P] [US4] Create `services/api/tests/Architecture/ControllerThinTest.php`; assert controllers: do not import any `Domain\` namespace classes (only `Application\` and `Http\`), do not contain methods longer than 15 lines (proxy check), do not call `DB::` facade directly

---

## Phase 7: US5 — Security Foundation

**User Story**: As a security engineer, I can audit every privileged action and trust that the platform enforces authentication boundaries and immutable audit records.

**Priority**: P1 | **Success Criterion**: SC-005 (all endpoints protected; audit events immutable)

**Independent test**: Unauthenticated request to any `/v1/` route returns 401. Authenticated request with invalid JWT returns 401. Every call to `AuditService::record()` creates an immutable row in `platform.audit_events` with correct `correlation_id`.

### Category 12 — Security Foundation

- [X] T063 [US5] Create `services/api/app/Http/Middleware/AuthenticationMiddleware.php`; flow: extract `Authorization: Bearer {token}` → decode JWT header → extract `kid` → fetch public key from JWKS endpoint (env var `IDENTITY_JWKS_URI`; cache in Redis key `jwks:{kid}` with TTL 3600s) → verify signature + `exp` + `iss` + `aud` claims → extract `actor_id`, `roles`, `permissions` from claims → populate `CorrelationContext::withActor()` → on any failure: throw `AuthenticationException` (→ 401); never reveal whether token is malformed vs expired in response body (both return 401 `AUTHENTICATION_REQUIRED`)
- [X] T064 [P] [US5] Create `services/api/app/Modules/Platform/Domain/ValueObjects/Actor.php` implementing `ActorContract`; properties: `id` (UUID v7 string), `type` (enum: user/service/system); create `services/api/app/Modules/Platform/Domain/ValueObjects/Money.php` implementing `MoneyContract`; FINANCIAL SAFETY: `amount` is `int` (smallest unit); `add()` and `subtract()` use integer arithmetic only; `toDisplayString()` divides by `10^precision` only for display
- [X] T065 [P] [US5] Create `services/api/app/Modules/Platform/Infrastructure/Security/PiiMasker.php` with static methods: `maskEmail(string $email): string` → `u***@d***.com`, `maskPhone(string $phone): string` → `+267***1234`, `maskNationalId(string $id): string` → `****1234`; used by `RequestLoggingMiddleware` and callers of `AuditService::record()` for `before_state`/`after_state` fields
- [X] T066 [P] [US5] Create `services/api/app/Modules/Platform/Infrastructure/Security/SecretProvider.php` interface with `get(string $key): string` method; create `services/api/app/Modules/Platform/Infrastructure/Security/EnvSecretProvider.php` reading from `$_ENV`; bind in `PlatformServiceProvider`; all sensitive config values (DB password, JWT secret, JWKS URI) must be read through `SecretProvider`, never from `config()` directly in domain/application code
- [X] T067 [US5] Create authorization guard `services/api/app/Modules/Platform/Application/Services/AuthorizationGuard.php` with method `require(ActorContract $actor, string $permission): void`; calls `IdentityModuleContract::hasPermission($actor, $permission)` (NullImplementation in Platform Foundation throws `ModuleNotImplementedException`); on denial: record to audit log with action `platform.permission.denied`, throw `AuthorizationException`; permission naming convention: `{module}.{resource}.{action}` (e.g., `configuration.policy.update`)

<details>
<summary>T063 Detail</summary>

**FR mapping**: FR-090 (JWT validation), FR-091 (JWKS cache), FR-093 (actor extraction), FR-006 (zero trust)
**Purpose**: Enforce authentication at the API boundary for all business routes.
**Dependencies**: T041, T045, T031, T066
**Files/modules affected**: `services/api/app/Http/Middleware/AuthenticationMiddleware.php`
**Implementation detail**: JWKS fetch: `GET {IDENTITY_JWKS_URI}/.well-known/jwks.json`; cache each key by `kid` in Redis. JWT decode must use a proper JWT library (e.g., `firebase/php-jwt` or similar); do NOT implement JWT verification manually. Claims validated: `exp > now()`, `iss === env('JWT_ISSUER')`, `aud contains env('JWT_AUDIENCE')`. If `IDENTITY_JWKS_URI` is not set: throw `TbdConfigurationException` (not a 401 — the provider is TBD, not a client error). Routes excluded from auth: `/health`, `/ready`, `/startup`, `/api/docs`.
**Acceptance criteria**: (1) Valid JWT → actor populated in CorrelationContext → request proceeds. (2) Expired JWT → 401 with `TOKEN_EXPIRED`. (3) Invalid signature → 401 with `AUTHENTICATION_REQUIRED`. (4) Missing header → 401 with `AUTHENTICATION_REQUIRED`. (5) Unset `IDENTITY_JWKS_URI` → 503 `TBD_CONFIGURATION`. (6) Health endpoints → not authenticated.
**Test requirements**: Unit: JWT parsing logic with mock keys. Feature: actual HTTP requests with signed JWTs (use test RSA keys). Feature: each failure mode tested individually.
**Security considerations**: Never log JWT contents. Error messages must not reveal whether token exists or is malformed. JWKS cache TTL balances security (detect key rotation) vs performance (1h acceptable for signing keys).
**Definition of Done**: All 6 acceptance criteria pass. No JWT content in logs.

</details>

### Category 13 — Audit Foundation

- [X] T068 [US5] Create `services/api/app/Modules/Platform/Domain/Contracts/AuditEventData.php` (value object / DTO) with properties: `actorId: ?string`, `actorType: string`, `actorNameMasked: ?string`, `action: string`, `resourceType: string`, `resourceId: ?string`, `beforeState: ?array`, `afterState: ?array`, `outcome: string` (success/failure/partial), `correlationId: string`, `ipAddress: ?string`, `userAgent: ?string`, `metadata: array`; add PHPDoc: "beforeState and afterState MUST be pre-masked by the caller before passing to AuditService — PiiMasker must be applied before construction"; action naming MUST follow `{module}.{aggregate}.{verb}` lower-kebab-case (FR-183)
- [X] T069 [US5] Create `services/api/app/Modules/Platform/Application/Services/AuditService.php` implementing `AuditServiceInterface`; `record(AuditEventData $event): void` inserts a row into `platform.audit_events` via `AuditEventModel::insert()` (not `create()` — INSERT-only, never triggers model events that could mutate); set `id` to UUID v7; set `created_at = now()` in UTC; FINANCIAL SAFETY: method must be callable within an existing DB transaction without causing nested transaction issues (use `DB::connection('platform')->statement()` or ensure no implicit transaction wrapping)
- [X] T070 [P] [US5] Create `services/api/app/Modules/Platform/Infrastructure/Archival/AuditArchivalDriverInterface.php` with method `archive(array $eventIds): void`; create `NullArchivalDriver.php` implementing it with no-op body; bind `NullArchivalDriver` in `PlatformServiceProvider`; the driver is TBD for production — do not select a provider
- [X] T071 [US5] Create platform action vocabulary registry at `docs/architecture/audit-action-vocabulary.md` listing all platform-level audit actions: `idempotency.replay`, `idempotency.conflict`, `idempotency.key-mismatch`, `configuration.changed`, `configuration.activated`, `platform.permission.denied`, `platform.outbox.dead-lettered`; format: `| Action | Module | Aggregate | Trigger |`; note that domain epics must register their own actions in domain-specific files following the same `{module}.{aggregate}.{verb}` convention

---

## Phase 8: US6 — Event-Driven Communication

**User Story**: As a developer, I can publish an integration event from any business operation and trust it will be delivered at least once, even if the worker crashes mid-processing.

**Priority**: P2 | **Success Criterion**: SC-006 (events delivered; stuck-processing recovered)

**Independent test**: A domain operation that publishes an event + crashes the worker mid-delivery — the event is recovered and delivered after cleanup job runs. Two dispatches of same event ID are idempotent.

### Category 9 — Event / Outbox

- [X] T072 [US6] Create `services/api/app/Modules/Platform/Domain/Events/BaseIntegrationEvent.php` abstract class implementing `IntegrationEventInterface`; constructor generates UUID v7 `eventId` and captures `occurredAt = now()`; subclasses provide `eventName()`, `payload()`, `aggregateId()`, `aggregateType()`; event naming convention: `{Domain}.{Aggregate}.{PastTenseVerb}` dot-notation PascalCase (e.g., `Wallet.Transfer.Completed`)
- [X] T073 [US6] Create `services/api/app/Modules/Platform/Application/Services/OutboxEventPublisher.php` implementing `EventPublisherInterface`; `publish(IntegrationEventInterface $event): void` must: (1) verify a database transaction is currently active (throw `\LogicException` if not — domain state must be in same transaction), (2) INSERT into `platform.outbox_events` with `status='pending'`, `attempts=0`, `max_attempts` from config key `events.max_attempts.{EventName}` with fallback to `events.max_attempts.default`; `publishAll(array $events): void` inserts all in one batch INSERT; FINANCIAL SAFETY: if INSERT fails, the calling transaction rolls back both domain state and outbox record
- [X] T074 [US6] Create `services/api/app/Modules/Platform/Infrastructure/EventBus/EventBusDriverInterface.php` with method `dispatch(OutboxEventModel $event): void`; create `InProcessDriver.php` dispatching via Laravel's `Event::dispatch()`; bind `InProcessDriver` in `PlatformServiceProvider` (driver selection via `events.bus_driver` config value; `InProcessDriver` is default for Platform Foundation)
- [X] T075 [US6] Create `services/api/app/Jobs/OutboxDispatcherJob.php` (Horizon job on `outbox` queue); logic: (1) `SELECT * FROM platform.outbox_events WHERE status='pending' AND (next_attempt_at IS NULL OR next_attempt_at <= now()) ORDER BY created_at LIMIT 100 FOR UPDATE SKIP LOCKED`, (2) UPDATE selected rows to `status='processing', processing_started_at=now()`, (3) for each event: dispatch via `EventBusDriverInterface`, (4) on success: UPDATE `status='delivered', delivered_at=now()`, (5) on failure: increment `attempts`, compute exponential backoff `next_attempt_at`, UPDATE `status='failed'`; if `attempts >= max_attempts`: UPDATE `status='dead_letter'` AND call `AuditServiceInterface::record()` with `action='platform.outbox.dead-lettered'`, `resourceType='OutboxEvent'`, `resourceId=event.id`, `outcome='failure'`, `metadata=['event_name'=>..., 'attempts'=>..., 'last_error'=>...]`; FINANCIAL SAFETY: each event dispatch is idempotent — consumer must check `event_id` before processing
- [X] T075b [US6] Create `services/api/app/Modules/Platform/Domain/Contracts/ProcessedEventRepositoryInterface.php` defining the consumer-side event idempotency contract: `hasBeenProcessed(string $eventId, string $consumerClass): bool` and `markAsProcessed(string $eventId, string $consumerClass, \DateTimeImmutable $processedAt): void`; create a migration template at `services/api/database/migrations/templates/create_processed_events_template.php.stub` documenting the required schema (`event_id uuid NOT NULL`, `consumer_class varchar(255) NOT NULL`, `processed_at timestamptz NOT NULL`, `UNIQUE(event_id, consumer_class)`) that implementing modules copy into their own schema migration; PHPDoc MUST state: "Each consuming module MUST create its own processed_events table in its own schema and implement this interface — Platform Foundation provides only the interface contract and migration template. A shared cross-module processed_events table is forbidden (violates schema-per-module isolation)."
- [X] T076 [US6] Create `services/api/app/Jobs/OutboxStuckProcessingCleanupJob.php` (Horizon scheduled job, runs every `events.outbox.processing_timeout_seconds` seconds); resets rows where `status='processing' AND processing_started_at < now() - config('events.outbox.processing_timeout_seconds') seconds` back to `status='pending', processing_started_at=NULL`; `attempts` counter is NOT reset; log each reset as WARNING with `event_id` and `aggregate_id`; FINANCIAL SAFETY: this job recovers events lost when dispatcher worker crashes mid-processing without duplicating delivered events
- [X] T077 [P] [US6] Create `services/api/app/Jobs/IdempotencyTtlCleanupJob.php` (Horizon scheduled job); deletes rows from `platform.idempotency_keys` where `expires_at < now()`; runs daily; logs count of deleted rows at INFO level; does NOT delete `status='processing'` rows regardless of age (those are cleaned by stuck-processing recovery)
- [X] T038b [US6] Create `services/api/app/Console/Commands/TestEventCommand.php` implementing `php artisan platform:test-event`; command creates a `Platform.Foundation.TestEventTriggered` integration event (a concrete `BaseIntegrationEvent` subclass defined in the same file) within a DB transaction via `OutboxEventPublisher`, then commits — resulting in a `platform.outbox_events` row with `status='pending'`; used exclusively by the quickstart validation guide (section 6) to verify end-to-end outbox flow without needing a business domain; register command in `PlatformServiceProvider::register()` only when `app()->environment(['local', 'testing'])` — MUST NOT be registered in production; command outputs the new event's UUID on success; **Dependencies**: T072 (BaseIntegrationEvent), T073 (OutboxEventPublisher)
- [X] T078 [US6] Register Horizon scheduled jobs in `services/api/app/Console/Kernel.php`: `OutboxDispatcherJob` every minute on `platform.critical` queue; `OutboxStuckProcessingCleanupJob` runs every `events.outbox.processing_timeout_seconds` seconds — read from ConfigurationService; if null, MUST use a 300-second implementation-level safety fallback (MUST NOT disable the job) and MUST emit a WARNING-level log: `"events.outbox.processing_timeout_seconds is not configured; using 300s safety fallback — set this value for explicit operational control"`; when configured, the configured value takes precedence over the fallback; see DEVELOPMENT.md for operational guidance; `IdempotencyTtlCleanupJob` daily at 02:00 UTC; `ConfigurationActivationJob` every minute

<details>
<summary>T073 Detail</summary>

**FR mapping**: FR-050 (atomic Outbox), FR-060 (Outbox pattern), FR-182 (stuck-processing recovery)
**Purpose**: Guarantees domain state and integration event are written atomically — no partial financial effects.
**Dependencies**: T055, T060, T030, T045
**Files/modules affected**: `services/api/app/Modules/Platform/Application/Services/OutboxEventPublisher.php`
**Implementation detail**: The `publish()` method must call `DB::connection('platform')->transactionLevel()` (or check `DB::transactionLevel() > 0`); throw `\LogicException('OutboxEventPublisher::publish() must be called within an active database transaction')` if no transaction is open. The INSERT uses `DB::connection('platform')->table('outbox_events')->insert([...])` — not `OutboxEventModel::create()` — to avoid Eloquent event hooks that might start new transactions. The `max_attempts` per event type is fetched from `ConfigurationService::getOrDefault("events.max_attempts.{$event->eventName()}", 5)`. Payload and metadata are JSON-encoded.
**Acceptance criteria**: (1) Calling `publish()` outside a transaction throws `LogicException`. (2) Calling `publish()` inside a transaction with domain state INSERT — rolling back the transaction removes BOTH the domain state and the outbox row. (3) `publishAll([event1, event2])` inserts both in a single `INSERT ... VALUES (...), (...)` statement.
**Test requirements**: Feature test: wrap in DB transaction, call publish, rollback — assert zero outbox rows. Feature test: wrap in DB transaction, call publish, commit — assert one outbox row. Feature test: publishAll with 5 events creates 5 rows in one round-trip. Concurrency test: two parallel publishes of different events both succeed.
**Security considerations**: Payload must not contain PII — caller responsibility. Event ID is UUID v7 (time-ordered, not guessable in meaningful sequence). No secrets in event metadata.
**Definition of Done**: Atomic rollback test passes. PublishAll batch insert verified. Concurrency test passes.

</details>

---

## Phase 9: US7 — Configuration Framework

**User Story**: As an operator, I can change a platform configuration value and have it take effect within one cache TTL cycle without redeployment.

**Priority**: P2 | **Success Criterion**: SC-007 (config changes reflected within TTL; TBD items return null, not error)

**Independent test**: Seed a config value, update it, wait for cache TTL (or call `invalidate()`), verify new value returned. Request a TBD key without `requireValue` → returns null. Request with `requireValue=true` → returns 503.

### Category 11 — Configuration / Policy Framework

- [X] T079 [US7] Create `services/api/app/Modules/Platform/Application/Services/ConfigurationService.php` implementing `ConfigurationServiceInterface`; flow: (1) check Redis `GET config:{config_key}` → HIT: deserialize → return, (2) MISS: query `platform.configuration_values WHERE config_key=? AND status='active' AND (effective_from IS NULL OR effective_from <= now()) AND (effective_until IS NULL OR effective_until > now()) ORDER BY version DESC LIMIT 1`, (3) FOUND: cache in Redis with TTL from `config('platform.config_cache_ttl', 300)` seconds → return value, (4) NOT FOUND: return null OR throw `TbdConfigurationException` if `$requireValue=true`; `invalidate(string $key)` deletes Redis key `config:{config_key}`; never invent TBD values
- [X] T080 [US7] Create `services/api/app/Jobs/ConfigurationActivationJob.php` (Horizon scheduled job, runs every minute); queries `platform.configuration_values WHERE status='draft' AND (effective_from IS NULL OR effective_from <= now())`; for each: (1) archive current active version (UPDATE status='archived' WHERE config_key=? AND status='active'), (2) activate new version (UPDATE status='active' WHERE id=?), (3) INSERT into `platform.configuration_audit` with action='activated', (4) call `ConfigurationService::invalidate()` on the key; wraps each key's promotion in a separate DB transaction
- [X] T081 [P] [US7] Create configuration management API endpoints in `services/api/routes/modules/configuration.php`: `GET /v1/configuration/values/{key}` (returns active value), `POST /v1/configuration/values` (creates draft), `PATCH /v1/configuration/values/{id}/activate` (manual activation); create thin `ConfigurationController.php` and corresponding `ConfigurationApplicationService.php`; all write operations recorded to `platform.configuration_audit` and `platform.audit_events` with action `configuration.changed`; requires permission `configuration.policy.update`
- [X] T082 [US7] Create `services/api/app/Modules/Platform/Domain/ValueObjects/Currency.php` implementing `CurrencyContract`; `isSupported()` checks against `ConfigurationService::get('platform.currencies.supported')`; create `services/api/app/Modules/Platform/Domain/ValueObjects/Money.php` implementing `MoneyContract`; FINANCIAL SAFETY: `amount` stored as `int`; all arithmetic (`add()`, `subtract()`) operates on integers; `toDisplayString()` uses `currency()->precision()` to produce human-readable string (e.g., `BWP 50.00` from `amount=5000, precision=2`); never store or compute with floats

---

## Phase 10: US8 — Observability

**User Story**: As an operations engineer, I can trace any API request end-to-end and diagnose failures using structured logs and health endpoints.

**Priority**: P2 | **Success Criterion**: SC-008 (all requests logged with correlation ID; health endpoints operational)

**Independent test**: `GET /health` returns 200 with `{"status":"ok","checks":{"db":"ok","redis":"ok","horizon":"ok"}}`. Kill postgres → `GET /ready` returns 503. All log lines contain `correlation_id`.

### Category 14 — Observability

- [X] T083 [US8] Implement `GET /health`, `GET /ready`, `GET /startup` in `services/api/app/Http/Controllers/HealthController.php`; `/health` checks: PostgreSQL ping (`SELECT 1`), Redis ping (`redis-cli ping`), Horizon status; `/ready` returns 503 if PostgreSQL or Redis is down; `/startup` returns 503 while migrations are pending; no authentication required on these routes; no correlation ID required (but included if present); do not log these requests to avoid log spam
- [X] T084 [P] [US8] Configure Monolog in `config/logging.php` with JSON formatter outputting to `stderr`; all log entries must include: `timestamp` (ISO 8601 UTC), `level`, `message`, `correlation_id` (from `CorrelationContext`), `request_id`, `actor_id`, `module`, `context`; create `services/api/app/Logging/JsonFormatter.php` if Monolog's built-in is insufficient; ensure `correlation_id` is injected into every log entry via `Log::withContext()` in `CorrelationIdMiddleware`
- [X] T085 [P] [US8] Create `services/api/app/Modules/Platform/Domain/Contracts/MetricsCollectorInterface.php` with methods: `increment(string $metric, array $tags = []): void`, `histogram(string $metric, float $value, array $tags = []): void`, `gauge(string $metric, float $value, array $tags = []): void`; create `InMemoryCollector.php` that collects metrics in memory and logs them on `terminate()`; bind in `PlatformServiceProvider`; metrics defined in plan: `api.request.count`, `api.request.duration_ms`, `api.error.count`, `queue.job.count`, `queue.job.duration_ms`, `db.query.count`, `db.slow_query.count` (threshold: 100ms)
- [X] T086 [US8] Install `open-telemetry/sdk` (PHP) in `services/api/`; configure no-op exporter by default; add tracing spans to: `CorrelationIdMiddleware` (root span), `IdempotencyMiddleware` (child span), `OutboxDispatcherJob` (child span per event), Eloquent query observer (DB span); configure via `OTEL_EXPORTER_OTLP_ENDPOINT` env var — if not set, use no-op; DO NOT select an observability backend (TBD)

---

## Phase 11: US9 — Testing Infrastructure

**User Story**: As a developer, I can write and run tests for any module at the unit, integration, and architecture levels with a single command per platform.

**Priority**: P2 | **Success Criterion**: SC-009 (≥80% line coverage; ≥70% branch coverage; all arch tests pass)

**Independent test**: `vendor/bin/pest --coverage --min=80` passes. `vendor/bin/pest --filter=Architecture` passes. `flutter test --coverage` produces coverage report. `npm test` passes.

### Category 19 — Test Infrastructure

- [X] T087 [US9] Create `services/api/tests/Support/TestCase.php` base class extending `Illuminate\Foundation\Testing\TestCase`; use `RefreshDatabase` and `DatabaseTransactions` traits; `setUp()` method must: set up `CorrelationContext` with test correlation ID, call `PlatformConfigurationSeeder` to seed required config defaults; create `services/api/tests/Support/PlatformTestCase.php` adding `AssertAuditEvent` and `AssertOutboxEvent` assertion helpers
- [X] T088 [US9] Create custom assertion traits: `services/api/tests/Support/AssertAuditEvent.php` with `assertAuditEventRecorded(string $action, array $attributes = [])` that queries `platform.audit_events` and fails the test if no matching row exists; `services/api/tests/Support/AssertOutboxEvent.php` with `assertOutboxEventPublished(string $eventName, array $payloadSubset = [])` querying `platform.outbox_events`
- [X] T089 [P] [US9] Create model factories for all platform tables in `services/api/tests/Support/Factories/`: `IdempotencyKeyFactory.php`, `OutboxEventFactory.php`, `AuditEventFactory.php`, `ConfigurationValueFactory.php`; each factory must generate valid UUID v7 `id` values using `Ramsey\Uuid\Uuid::uuid7()->toString()`; `AuditEventFactory` must generate randomized `action` values following `{module}.{aggregate}.{verb}` naming
- [X] T090 [US9] Create `services/api/tests/Support/ConcurrencyTestHelper.php` with method `runConcurrently(int $processes, Closure $test): array`; uses `Symfony\Component\Process\Process` to spawn N parallel PHP processes each executing the closure; returns array of results/exceptions; MUST verify actual concurrent (not sequential) execution — see detail block; used for idempotency and outbox concurrency tests

<details>
<summary>T090 Detail</summary>

**FR mapping**: FR-047 (concurrent idempotency serialization), FR-050 (atomic outbox)
**Purpose**: Provide a concurrency test utility that proves N parallel processes actually overlap in time — not sequential fallback.
**Dependencies**: T001 (composer — `symfony/process` must be available), T087 (PlatformTestCase base)
**Files/modules affected**: `services/api/tests/Support/ConcurrencyTestHelper.php`
**Implementation detail**: Before spawning processes, assert `class_exists(\Symfony\Component\Process\Process::class)` (fail fast if `symfony/process` is not installed). Record wall-clock `$startTime = microtime(true)` before starting all processes. Start all N processes without waiting (call `$process->start()` on each before calling `$process->wait()` on any). After all processes complete, assert `$totalElapsed < ($singleProcessEstimatedMs * $processes * 0.8)` — total elapsed must be less than sequential execution time (use a configurable threshold, default 80%). Log each process's PID and start timestamp to a temp file so the test output proves processes overlapped. Assert every process exited with code 0 (or the expected code) — a timeout exit code means the process hung, not ran concurrently.
**Acceptance criteria**: (1) `symfony/process` absence causes a descriptive `\RuntimeException` at test time (not a class-not-found fatal). (2) Running 5 processes each sleeping 200ms completes in under 600ms total wall-clock (proves parallel execution). (3) Each spawned process logs a start timestamp; timestamps overlap within a 50ms window. (4) All processes return their result without timeout (default process timeout: 30s). (5) A sequential fallback (accidentally calling `wait()` before starting the next process) would fail criterion (2) — the helper must not silently fall back.
**Test requirements**: Self-test: `ConcurrencyTestHelperTest` — run 3 processes each sleeping 100ms; assert total time < 400ms. Run idempotency integration test (T094) using this helper.
**Security considerations**: Spawned processes inherit the test environment — ensure no production credentials are in test `.env`.
**Definition of Done**: All 5 acceptance criteria verified. Wall-clock timing assertion passes in CI.

</details>
- [X] T091 [P] [US9] Create `services/api/tests/Unit/Modules/Platform/IdempotencyServiceTest.php` with unit tests for: (1) cache hit returns response without DB query, (2) cache miss → DB INSERT → lock acquired, (3) concurrent duplicate → `IdempotencyInProgressException`, (4) completed duplicate → cached response returned, (5) different actor same key → independent records, (6) system call uses zero UUID actor_id
- [X] T092 [P] [US9] Create `services/api/tests/Unit/Modules/Platform/ConfigurationServiceTest.php` testing: Redis cache hit path, DB query path, TBD key with `requireValue=false` returns null, TBD key with `requireValue=true` throws `TbdConfigurationException`, `invalidate()` deletes Redis key
- [X] T093 [P] [US9] Create `services/api/tests/Unit/Modules/Platform/AuditServiceTest.php` testing: record inserts correct fields, action follows `{module}.{aggregate}.{verb}` naming, `before_state`/`after_state` with PII-masked data stored correctly, second call does NOT update previous record (immutability)
- [X] T094 [US9] Create `services/api/tests/Feature/Modules/Platform/IdempotencyIntegrationTest.php` with real DB tests: (1) full idempotency flow with actual PostgreSQL `INSERT ON CONFLICT`, (2) concurrent requests using `ConcurrencyTestHelper` — assert exactly one succeeds and other gets 409, (3) replay after completion returns identical body, (4) verify Redis cache warm-up after PostgreSQL completion
- [X] T095 [US9] Create `services/api/tests/Feature/Modules/Platform/OutboxDispatchTest.php` with tests: (1) publish inside transaction + commit → outbox row created, (2) publish inside transaction + rollback → no outbox row (FINANCIAL SAFETY), (3) `OutboxDispatcherJob` processes pending events, (4) stuck-processing cleanup resets rows without resetting `attempts`, (5) dead-letter after `max_attempts` exceeded — assert outbox row has `status='dead_letter'` AND assert `AuditService::record()` was invoked with `action='platform.outbox.dead-lettered'`; implement an `AuditServiceSpy` (in-memory capture of audit calls) registered via `$this->app->instance()` in the test setup to assert the audit record without hitting the DB
- [X] T096 [US9] Configure Pest coverage in `services/api/phpunit.xml` or `pest.php`; set `--coverage-min=80`; exclude `vendor/`, `tests/`, generated files, `bootstrap/`; set branch coverage minimum to 70%; add `--parallel` flag for CI speed
- [X] T097 [US9] Create `apps/admin/__tests__/support/setup.ts` configuring: `jest-axe` matchers (`expect.extend(toHaveNoViolations)`), `msw` mock server setup/teardown, custom `renderWithProviders()` helper wrapping `QueryClientProvider` and `AuthContext`; configure `apps/admin/jest.config.ts` with `testEnvironment: 'jsdom'`, coverage thresholds `lines: 80`
- [X] T098 [P] [US9] Create `apps/customer/test/support/` and `apps/agent/test/support/` with: `MockApiClient` (overrides `ApiClientProvider` in tests), `ProviderContainerHelper` (creates isolated `ProviderContainer` for unit tests), JSON fixtures in `fixtures/` directory; configure `flutter_test` and `integration_test` in each `pubspec.yaml`

---

## Phase 12: US10 — Design System

**User Story**: As a frontend/mobile developer, I can use design-system components that are accessible, consistently styled, and automatically updated when design tokens change.

**Priority**: P2 | **Success Criterion**: SC-010 (token pipeline produces consistent output; all components pass axe-core; WCAG AA)

**Independent test**: `npm run tokens:build` in `packages/design-system/` produces `build/css/tokens.css` and `build/dart/tokens.dart`. All shared component tests pass axe-core with zero violations. Flutter widget tests pass with `SemanticsDebugger` enabled.

### Category 17 — Design System

- [X] T099 [US10] Create design token source files in W3C Design Token format: `packages/design-system/tokens/color.json` (primitives: blue, red, green, yellow, neutral scales; semantic: brand-primary, error-primary, success-primary, warning-primary, text-primary, text-secondary, bg-primary, bg-secondary), `typography.json` (font-size/weight/line-height scales), `spacing.json` (4px grid: 1/2/4/6/8/10/12/16/20/24/32/40/48), `elevation.json`, `radius.json`, `breakpoints.json` (mobile/tablet/desktop), `motion.json` (duration/easing)
- [X] T100 [US10] Configure `packages/design-system/style-dictionary.config.js` to: read all `tokens/*.json` source files, output `build/css/tokens.css` as CSS custom properties (`--color-*`, `--font-size-*`, `--space-*`, etc.), output `build/dart/tokens.dart` as Dart `abstract class AppTokens { static const Color primaryBlue = Color(0xFF...); ... }`; add `npm run tokens:build` script to `packages/design-system/package.json`
- [X] T101 [US10] Import `packages/design-system/build/css/tokens.css` in `apps/admin/styles/globals.css`; configure `apps/admin/tailwind.config.ts` to extend theme using CSS custom property references for colors, spacing, typography, and breakpoints (e.g., `colors.brand.primary: 'var(--color-brand-primary)'`); verify `next build` succeeds with token integration

### Category 15 — Next.js Foundation

- [X] T102 [US10] Create `apps/admin/app/layout.tsx` (root layout) with: `ThemeProvider` (light/dark), `AuthContext` (stub), `QueryClientProvider` (React Query); import CSS tokens; configure `<html lang="en">`; apply base `<body>` classes from design tokens; **Acceptance criterion (theme persistence)**: `ThemeProvider` MUST persist and restore theme preference in an SSR-safe way — store preference in a cookie (not localStorage) so the initial server render matches the stored theme and there is no flash-of-wrong-theme on page load; use `next-themes` (cookie storage mode) or an equivalent SSR-safe approach
- [X] T103 [P] [US10] Create `apps/admin/app/page.tsx` redirecting to `/dashboard` if authenticated or `/login` if not; create `apps/admin/app/error.tsx` (global error boundary with `role="alert"` and correlation ID display); create `apps/admin/app/loading.tsx` (global loading with `aria-live="polite"`, `role="status"`); create `apps/admin/app/not-found.tsx` (404 page with navigation back)
- [X] T104 [US10] Create `apps/admin/middleware.ts` implementing route protection: reads auth session; unauthenticated requests to non-`/(auth)` routes redirect to `/login`; auth boundary is stub (always redirects to login in Platform Foundation; wired to real auth by E01)
- [X] T105 [P] [US10] Create `apps/admin/lib/api/client.ts` with `ApiClient` class: `get<T>()`, `post<T>()`, `patch<T>()`, `delete()` methods; each request adds `X-Correlation-ID` header (generated via `apps/admin/lib/utils/correlation.ts`); `post()` accepts optional `idempotencyKey` parameter which adds `Idempotency-Key` header; all responses parsed as `ApiResponse<T>` envelope; create `apps/admin/lib/utils/errors.ts` with type guards `isApiError()`, `isValidationError()`
- [X] T106 [P] [US10] Create `apps/admin/lib/auth/context.tsx` with `AuthContext` providing `AuthContextValue { user: null | ActorDTO; isAuthenticated: boolean; signOut: () => void; }`; stub implementation always returns `isAuthenticated: false` (E01 wires real implementation)

### Category 16 — Flutter / Riverpod Foundation

- [X] T107 [US10] Create `apps/customer/lib/main.dart` and `apps/agent/lib/main.dart` entry points: wrap app in `ProviderScope`; call `runApp(AppWidget())`; import `dart:io` and configure `HttpOverrides` for certificate handling
- [X] T108 [P] [US10] Create `apps/customer/lib/app/router.dart` (same for agent): configure `GoRouter` with auth redirect (reads `authProvider` from Riverpod; if not authenticated and not on `/auth/*` → redirect to `/auth/login`); define routes: `GoRoute(path: '/auth/login', builder: (_,__)=>LoginPage())` (stub), `ShellRoute(builder: shellBuilder, routes: [GoRoute(path: '/dashboard', builder: (_,__)=>DashboardPage())])` (stub)
- [X] T109 [US10] Create `apps/customer/lib/core/api/api_client.dart`: `Dio`-based client with 5 interceptors in order: `AuthInterceptor` (adds Bearer token), `CorrelationIdInterceptor` (adds `X-Correlation-ID`), `RetryInterceptor` (retries 5xx up to 3 times with exponential backoff), `LoggingInterceptor` (logs request/response with PII masking), `ErrorInterceptor` (converts `DioException` to typed `ApiError`); create `apps/customer/lib/core/api/api_response.dart` matching backend envelope
- [X] T110 [P] [US10] Create `apps/customer/lib/core/auth/auth_provider.dart`: `AsyncNotifier<AuthState>` stub; `AuthState { user: null; isAuthenticated: false; }`; E31/E32 implement real auth logic; create `apps/customer/lib/core/di/providers.dart` with all global Riverpod providers declared (ApiClientProvider, AuthProvider, etc.); all providers are `override`-able for tests
- [X] T126 [P] [US10] Configure Flutter localization readiness in both `apps/customer/` and `apps/agent/`: add `flutter_localizations` SDK package and `intl` to each `pubspec.yaml`; create `l10n.yaml` at each app root with `arb-dir: lib/l10n`, `template-arb-file: app_en.arb`, `output-localization-file: app_localizations.dart`; create `lib/l10n/app_en.arb` with minimal placeholder: `{"@@locale": "en", "appTitle": "Wallet", "@appTitle": {"description": "Application title"}}`; wire `localizationsDelegates: AppLocalizations.localizationsDelegates` and `supportedLocales: AppLocalizations.supportedLocales` into `MaterialApp` in `main.dart` (T107); run `flutter gen-l10n` to verify generated output; no additional locale files, business copy decisions, or translations — those are deferred to E31/E32
- [X] T127 [P] [US10] Create `apps/customer/lib/core/analytics/analytics_service.dart` and `apps/agent/lib/core/analytics/analytics_service.dart`: define `abstract class AnalyticsService` with methods `trackEvent(String name, {Map<String, Object>? properties})`, `trackScreen(String screenName)`, `setUserProperties(Map<String, Object> properties)`; create `NoOpAnalyticsService` implementing all methods as empty stubs (no vendor calls, no network I/O); create `analyticsServiceProvider` in `apps/customer/lib/core/di/providers.dart` bound to `NoOpAnalyticsService` by default; the provider MUST be `override`-able in tests and in E31/E32 when a real vendor is selected; no analytics vendor selection — vendor is TBD; add PHPDoc equivalent Dart comment: "Replace NoOpAnalyticsService by overriding analyticsServiceProvider in the app's ProviderScope when a vendor is selected."

### Category 18 — Accessibility

- [X] T111 [P] [US10] Create all 15 shared Next.js components in `apps/admin/components/ui/` with WCAG AA accessibility: `Button.tsx` (keyboard focus, `role="button"`, disabled aria), `Input.tsx` (label association, `aria-invalid`, `aria-describedby`), `Select.tsx` (ARIA listbox), `Card.tsx` (semantic landmark), `Dialog.tsx` (focus trap via `focus-trap-react`, `Escape` to close, `aria-modal`, focus restore), `Sheet.tsx` (Dialog + touch dismiss), `Toast.tsx` (`aria-live="polite"`, auto-dismiss), `Badge.tsx` (`aria-label`), `Tabs.tsx` (ARIA tabs, keyboard arrows), `Table.tsx` (`scope="col"` on `th`), `Navigation.tsx` (`nav` landmark, `aria-current="page"`), `Loading.tsx` (`aria-live="polite"`, `role="status"`), `Empty.tsx`, `ErrorState.tsx` (`role="alert"`), `Confirmation.tsx` (Dialog pattern)
- [X] T112 [P] [US10] Create accessibility test file `apps/admin/__tests__/a11y/components.test.tsx` using `jest-axe`; for each of the 15 components: render with representative props, run `await axe(container)`, `expect(results).toHaveNoViolations()`; any WCAG AA violation fails the CI gate
- [X] T113 [P] [US10] Create Flutter equivalents of all 15 components in `apps/customer/lib/shared/widgets/`; each must: use `Semantics(label: ..., button: true)` on interactive elements, meet 44×44 minimum touch target via `SizedBox` wrapper, respect `MediaQuery.of(context).disableAnimations` for reduced motion; add `SemanticsDebugger` check in widget tests
- [X] T114 [US10] Create `apps/admin/lib/utils/motion.ts` with `useReducedMotion()` hook reading `prefers-reduced-motion` media query; all animation-using components must check this hook; create global CSS: `@media (prefers-reduced-motion: reduce) { *, *::before, *::after { animation-duration: 0.01ms !important; transition-duration: 0.01ms !important; } }` in `globals.css`

<details>
<summary>T111 Detail</summary>

**FR mapping**: FR-140 (shared components), FR-143 (WCAG AA), FR-144 (keyboard navigation), FR-145 (ARIA), FR-146 (focus management)
**Purpose**: Establish accessible, token-driven shared components that all 34 epics will use for UI consistency.
**Dependencies**: T099, T100, T101, T102
**Files/modules affected**: `apps/admin/components/ui/` (15 files)
**Implementation detail**: Each component must use semantic HTML elements (never `<div>` where `<button>`, `<nav>`, `<main>`, etc. would be correct). `Dialog` requires `focus-trap-react` library (install if not already installed). `Toast` uses `role="status"` for polite announcements. `ErrorState` uses `role="alert"` for assertive announcements. All interactive elements must be reachable via Tab key in logical order. Color contrast must meet WCAG AA 4.5:1 for normal text, 3:1 for large text — enforced by token color choices. Components must not rely on color alone to convey information (add text or icons). All string labels must be configurable props (no hardcoded English only).
**Acceptance criteria**: All 15 components render without errors. axe-core finds zero violations on each component in isolation. All interactive elements reachable via keyboard. Focus visible on all focusable elements. Dialog traps and restores focus correctly.
**Test requirements**: `apps/admin/__tests__/a11y/components.test.tsx` — axe-core test for each component. `apps/admin/__tests__/components/Dialog.test.tsx` — focus trap and Escape key tests.
**Security considerations**: No user-provided HTML should be rendered via `dangerouslySetInnerHTML` in any component (XSS prevention). Label text from props must be displayed as text nodes, never injected as HTML.
**Definition of Done**: All 15 components exist, tested with axe-core (zero violations), keyboard navigation verified, focus management tested for Dialog.

</details>

---

## Phase 13: CI/CD and Environment

**Goal**: CI pipeline scripts, environment configuration strategy, and rollback mechanism in place.

### Category 20 — CI/CD

- [X] T115 Create `scripts/ci/backend.sh`: `composer install --no-dev`, `php artisan config:cache`, `vendor/bin/phpcs`, `vendor/bin/phpstan --no-progress`, `vendor/bin/pest --coverage --min=80`, `vendor/bin/pest --filter=ArchitectureTest`, `php artisan platform:check-scope`, `php artisan l5-swagger:generate`, `composer audit`; script exits 1 if any step fails
- [X] T116 [P] Create `scripts/ci/frontend.sh`: `npm ci`, `npx eslint .`, `npx tsc --noEmit`, `npx jest --coverage`, `npx next build`, `npm audit --audit-level=high`; exits 1 if any step fails
- [X] T117 [P] Create `scripts/ci/mobile.sh`: `flutter pub get`, `flutter analyze`, `dart format --set-exit-if-changed .`, `flutter test --coverage`, `flutter build apk --debug`, `flutter build ios --no-codesign --debug`; exits 1 if any step fails
- [X] T118 Create `scripts/ci/rollback.sh` that accepts a previous deployment tag/image tag as argument; re-deploys the previous version; calls health endpoints; exits 0 on health pass, exits 1 on failure; DO NOT select CI/CD provider — this script is provider-agnostic
- [X] T119 [P] Create `docs/adrs/002-schema-per-module.md` (ADR documenting schema-per-module decision), `docs/adrs/003-idempotency-strategy.md` (documenting UNIQUE(actor_id, idempotency_key) + PostgreSQL authoritative decision), `docs/adrs/004-outbox-pattern.md` (documenting atomic state+outbox in same transaction decision)

---

## Final Phase: Polish & Cross-Cutting

**Goal**: Remaining cross-cutting tasks that don't belong to a single user story.

- [X] T120 Create `services/api/phpstan.neon` (level 8 static analysis, include Laravel-specific stubs); create `services/api/.phpcs.xml` (PSR-12 standard + project-specific rules: no mixed types in financial methods, no float in money calculations); both config files referenced in `scripts/ci/backend.sh`
- [X] T121 [P] Create `apps/admin/.eslintrc.json` with rules: `@typescript-eslint/no-explicit-any: error`, `@typescript-eslint/strict-boolean-expressions: error`, `jsx-a11y/` ruleset enabled, `no-console: warn`; create `apps/admin/tsconfig.json` with `strict: true`, `noUncheckedIndexedAccess: true`, `exactOptionalPropertyTypes: true`
- [X] T122 [P] Create `apps/customer/analysis_options.yaml` and `apps/agent/analysis_options.yaml` with `riverpod_lint` rules enabled; `dart format` enforced; `prefer_const_constructors`, `avoid_print` rules active
- [X] T123 Create `services/api/tests/Architecture/InfrastructureIsolationTest.php`; assert: (1) no class in `App\Modules\{X}\Domain\` imports anything from `Illuminate\Database\Eloquent`, (2) no class in `App\Modules\{X}\Application\` imports anything from `Illuminate\Database\Eloquent` or `Illuminate\Http\`, (3) all Eloquent models are in `Infrastructure\Persistence\` only
- [X] T124 [P] Create `services/api/tests/Api/ResponseEnvelopeTest.php`; for each defined API route: assert response body matches `{"success": bool, "data": ..., "error": ..., "correlation_id": "..."}` schema; assert `correlation_id` is present and matches `X-Correlation-ID` response header; assert error responses include `error.code` field
- [X] T125 Create `specs/999-platform-foundation/quickstart.md` validation scenarios as automated scripts in `scripts/dev/validate-quickstart.sh`; checks SC-001 through SC-015: API response envelope, schema creation, security headers, idempotency replay, correlation ID propagation, event outbox creation, configuration lookup, health endpoints, module boundary test pass
- [X] T128 [P] Create `services/api/tests/Feature/Modules/Platform/TbdConfigurationIntegrityTest.php`; for each key in the known TBD configuration registry (loaded from `config/tbd_keys.php` or equivalent), assert that `ConfigurationService::get($key)` returns null (does NOT throw, does NOT return a fabricated value); assert that `ConfigurationService::get($key, requireValue: true)` throws `TbdConfigurationException`; assert that `ConfigurationService::isConfigured($key)` returns false; test runs in a clean test database with no configuration seeding so TBD keys are guaranteed absent; this test protects the invariant that TBD values do not silently resolve to invented defaults (FR-181)
- [X] T129 [US3] Create `services/api/app/Exceptions/ModuleConnectionUnavailableException.php` extending `\RuntimeException`; constructor accepts `string $moduleName`, `string $connectionName`, `string $correlationId`, `?\Throwable $previous = null`; expose `getModuleName()`, `getConnectionName()`, `getCorrelationId()` accessors; amend `services/api/app/Exceptions/Handler.php` (created by T039) to: (1) detect `\Illuminate\Database\QueryException` wrapping a PDO-level connection availability failure (driver error message matches connection-refused/host-unreachable patterns; limit to genuine named-connection unavailability, NOT arbitrary SQL errors such as syntax errors or constraint violations); (2) wrap the detected failure in `ModuleConnectionUnavailableException` using the named connection name from the QueryException context; (3) return HTTP 503 with `error.code: SERVICE_UNAVAILABLE`, `retryable: true`, using the standard API error envelope; (4) log at ERROR level via the platform logger, including `module_name`, `connection_name`, `correlation_id`, and exception message; create `services/api/tests/Feature/Exceptions/ModuleConnectionUnavailableTest.php` that: mocks a named module DB connection to throw a PDO connection-refused exception; asserts HTTP 503; asserts `error.code === "SERVICE_UNAVAILABLE"`; asserts `retryable === true`; asserts standard envelope structure (`success: false`, `data: null`, `error: {...}`, `correlation_id`); asserts ERROR-level log entry contains module name, connection name, correlation_id; includes a negative-case assertion that an ordinary `QueryException` (SQL syntax error) returns HTTP 500 `INTERNAL_ERROR`, NOT 503 (FR-193)

---

## Task Summary

| Phase | User Story | Priority | Tasks | Category Coverage |
|---|---|---|---|---|
| Phase 1: Setup | — | Blocker | T001–T009 | Cat 1 |
| Phase 2: Foundation | — | Blocker | T010–T018 | Cat 22, 2 |
| Phase 3: Developer Onboarding | US1 | P1 | T019–T026 | Cat 21, 23, 24 |
| Phase 4: Module Structure | US2 | P1 | T027–T038 | Cat 10, 2 |
| Phase 5: API Contract | US3 | P1 | T039–T052 | Cat 4, 5, 6, 7, 8 |
| Phase 6: DB Schema Conventions | US4 | P1 | T053–T062 | Cat 3 |
| Phase 7: Security Foundation | US5 | P1 | T063–T071 | Cat 12, 13 |
| Phase 8: Event-Driven | US6 | P2 | T072–T078 | Cat 9 |
| Phase 9: Configuration Framework | US7 | P2 | T079–T082 | Cat 11 |
| Phase 10: Observability | US8 | P2 | T083–T086 | Cat 14 |
| Phase 11: Testing Infrastructure | US9 | P2 | T087–T098 | Cat 19 |
| Phase 12: Design System | US10 | P2 | T099–T114, T126–T127 | Cat 15, 16, 17, 18 |
| Phase 13: CI/CD | — | Cross-cutting | T115–T119 | Cat 20 |
| Final: Polish | — | Cross-cutting | T120–T125, T128, T129 | Cat 19, 23 |

**Total tasks**: 132 (T001–T125 original + T038b, T041b, T075b from P1 clarifications + T126, T127 from P2 clarifications + T128 from /speckit-clarify 2026-08-17 + T129 from /speckit-analyze gap remediation 2026-08-17)
**P1 (US1–US5)**: T019–T071, T041b (54 tasks)
**P2 (US6–US10)**: T038b, T072–T114, T075b, T126–T127 (47 tasks)
**Parallelizable [P] tasks**: ~62 tasks
**Setup/Foundation/Polish**: T001–T018, T115–T125 (29 tasks)

## Parallel Execution Examples

**During Phase 4 (US2 — Module Structure)**: T027 through T036 (all contract interface files) can be written in parallel — they reference each other by name only, with no PHP circular dependency at interface level.

**During Phase 12 (US10 — Design System)**: T099 (tokens), T102 (Next.js layout), T107 (Flutter main) can be written in parallel. T111 and T113 can be written in parallel (Next.js vs Flutter components). After T100 completes, T101 and T107 can proceed in parallel. T126 (Flutter localization) and T127 (Flutter analytics abstraction) can run in parallel with each other after T107 completes.

**During Phase 11 (US9 — Testing)**: T089, T091, T092, T093 can be written in parallel once T087 and T088 are done.

## Suggested MVP Scope

**To validate the foundation before committing to all P2 work**, complete phases 1–7 and T087–T095 (core test infrastructure). This demonstrates:
- Working local environment
- Module boundaries enforced
- API contract with idempotency
- PostgreSQL schema isolation
- Security boundary in place
- Audit immutability enforced
- Core test infrastructure running

P2 work (US6–US10) can then proceed in parallel teams across Phases 8–12.

---

## Phase 14: Convergence

*Generated by /speckit-converge on 2026-08-17. Gaps between tasks.md intent and actual codebase state.*

- [X] T130 CRITICAL: Move all 8 stub module ServiceProviders from wrong double-nested path (`wallet-ecosystem/services/api/wallet-ecosystem/services/api/app/Modules/*/Providers/*.php`) to correct path (`wallet-ecosystem/services/api/app/Modules/*/Providers/*.php`) so Laravel autoloader and `bootstrap/providers.php` can resolve them per T016 (contradicts)
- [X] T131 CRITICAL: Move 5 existing NullImplementation classes from wrong double-nested path to `wallet-ecosystem/services/api/app/Modules/*/Infrastructure/NullImplementations/`; create missing `NullKycModule.php`, `NullRiskModule.php`, `NullAgentModule.php` each throwing `ModuleNotImplementedException` with module name and method name per T037, FR-179 (contradicts)
- [X] T132 [P] Create `services/api/app/Modules/Platform/Domain/ValueObjects/CorrelationContext.php` implementing `CorrelationContextInterface`; constructor accepts `correlationId`, `requestId`, optional `ActorContract`; all interface methods must be implemented; used by `CorrelationIdMiddleware` and `AuthenticationMiddleware` per T045, FR-032, FR-087 (missing)
- [X] T133 [P] Create `services/api/tests/Feature/Modules/Platform/IdempotencyIntegrationTest.php` with real PostgreSQL integration tests: (1) full idempotency flow with actual `INSERT ON CONFLICT`, (2) concurrent requests via `ConcurrencyTestHelper` — assert one proceeds and other gets HTTP 409 `IDEMPOTENCY_IN_PROGRESS`, (3) replay after completion returns identical response body, (4) different actor same key proceeds independently, (5) Redis cache warm-up verified after PostgreSQL completion per T094, FR-047, FR-155 (missing)
- [X] T134 [P] Create `services/api/tests/Feature/Modules/Platform/OutboxDispatchTest.php` with tests: (1) publish inside DB transaction + commit → outbox row created, (2) publish inside DB transaction + rollback → no outbox row (FINANCIAL SAFETY invariant), (3) `OutboxDispatcherJob` processes pending events to delivered status, (4) stuck-processing cleanup resets `status=processing` rows back to pending without resetting `attempt_count`, (5) dead-letter after `max_attempts` exceeded: `status=dead_letter` AND `AuditServiceSpy` captures `platform.outbox.dead-lettered` audit call per T095, FR-050 (missing)
- [X] T135 [P] Create model factories in `services/api/tests/Support/Factories/`: `IdempotencyKeyFactory.php`, `OutboxEventFactory.php`, `AuditEventFactory.php`, `ConfigurationValueFactory.php`; each factory MUST generate UUID v7 `id` values via `Ramsey\Uuid\Uuid::uuid7()->toString()`; `AuditEventFactory` MUST generate `action` following `{module}.{aggregate}.{verb}` naming per T089, FR-156 (missing)
- [X] T136 Create configuration management API: (1) `services/api/routes/modules/configuration.php` with `GET /v1/configuration/values/{key}`, `POST /v1/configuration/values`, `PATCH /v1/configuration/values/{id}/activate`; (2) `services/api/app/Http/Controllers/ConfigurationController.php` (thin, delegates to service); (3) `services/api/app/Modules/Platform/Application/Services/ConfigurationApplicationService.php` orchestrating reads/writes; all write operations recorded to `platform.configuration_audit` with action `configuration.changed`; requires permission `configuration.policy.update` per T081, FR-066 (missing)
- [X] T137 [P] Create `apps/customer/lib/main.dart` wrapping app in `ProviderScope` and calling `runApp()`; create `apps/customer/lib/app/router.dart` configuring `GoRouter` with auth-guard redirect (unauthenticated non-`/auth/*` paths redirect to `/auth/login`) and stub routes for `/auth/login` and `/dashboard` per T107, T108, FR-121, FR-124 (missing)
- [X] T138 [P] Create `apps/agent/lib/main.dart` (ProviderScope + runApp), `apps/agent/lib/app/router.dart` (GoRouter with auth redirect, stub login/dashboard routes), and `apps/agent/lib/core/auth/auth_provider.dart` (AsyncNotifier<AuthState> stub always returning `isAuthenticated: false`) per T107, T108, T110, FR-121 (missing)
- [X] T139 [P] Create all 15 Flutter shared widget components in `apps/customer/lib/shared/widgets/`: Button, Input, Select, Card, Dialog, Sheet, Toast, Badge, Tabs, Table, Navigation, Loading, Empty, ErrorState, Confirmation; each MUST use `Semantics(label: ..., button: true)` on interactive elements, meet 44×44 minimum touch target via `SizedBox`, and respect `MediaQuery.of(context).disableAnimations` for reduced motion per T113, FR-130 (missing)
- [X] T140 [P] Create `apps/admin/app/page.tsx` redirecting to `/dashboard` if authenticated or `/login` if not; create `apps/admin/middleware.ts` reading auth session and redirecting unauthenticated requests on non-`/(auth)` routes to `/login` (auth boundary is stub — always redirects to login in Platform Foundation) per T103, T104, FR-114 (missing)
- [X] T141 [P] Create `docs/adrs/000-template.md` with sections: Status, Context, Decision, Consequences, Alternatives Considered; create `docs/adrs/001-monorepo-structure.md` documenting the monorepo choice with rationale per T024, FR-160 (missing)
- [X] T142 [P] Create `scripts/dev/validate-env.sh` checking all required env vars from `.env.example` keys exist in environment (exits 1 with descriptive error on missing key); create `scripts/dev/setup.sh` wrapping: cp .env.example .env, docker compose up -d --wait, migrate, db:seed, l5-swagger:generate; script must be idempotent per T025, T026, FR-147 (missing)
- [X] T143 Amend `services/api/app/Exceptions/Handler.php` to add `QueryException` detection: inspect `$e->getPrevious()` for PDO-level connection-refused/host-unreachable patterns (NOT arbitrary SQL errors); when detected, wrap in `ModuleConnectionUnavailableException` with connection name extracted from `$e->getConnectionName()`; log at ERROR with `module_name`, `connection_name`, `correlation_id`, exception message; extend `ModuleConnectionUnavailableTest.php` to include full HTTP 503 integration test simulating a named DB connection failure and a negative case verifying SQL syntax errors still return 500 per T129 (partial), FR-193 (partial)
- [X] T144 Resolve FR-186 vs T010 naming discrepancy: FR-186 MUST states connection names follow `{module}_db` pattern (e.g., `platform_db`, `identity_db`) but `config/database.php` and all models/services use short names (`platform`, `identity`). Either (a) rename all 9 connections and update all model `$connection` properties and DB facade calls throughout codebase, OR (b) raise a spec clarification to align FR-186 with the T010-established convention. Document the chosen resolution per FR-186 (contradicts)

## Phase 15: Convergence

*Generated by /speckit-converge on 2026-08-18. Second convergence pass — gaps between Phase 14 completion and codebase state.*

- [X] T145 Fix WalletModuleContract, LedgerModuleContract, and TransactionModuleContract interface method signatures and their NullImplementation classes to match `contracts/module-contracts.md` and FR-185: rename `exists()→isActive()`, `getOwnerId()→ownerActorId(): ?string`, add `currency(string $walletId): CurrencyContract` to `WalletModuleContract`; rename `postJournal(array)→postEntry(LedgerEntryRequest $request): string` and replace `getBalance()→entryExists(string $transactionReference): bool` in `LedgerModuleContract`; remove `string $type` param from `generateReference()` and add `status(string $transactionReference): ?string` to `TransactionModuleContract`; update `NullWalletModule`, `NullLedgerModule`, `NullTransactionModule` to implement the corrected signatures throwing `ModuleNotImplementedException`; run Pest to confirm no regressions per FR-185 (contradicts)
- [X] T146 Implement missing Admin Next.js foundation files whose tasks were marked [X] but whose outputs are absent: (1) run `npm run tokens:build` in `wallet-ecosystem/packages/design-system/` to produce `build/css/tokens.css` and `build/dart/tokens.dart`; (2) create `apps/admin/styles/globals.css` importing `build/css/tokens.css` and containing reduced-motion media query; (3) create `apps/admin/tailwind.config.ts` extending theme with CSS variable references for all token categories; (4) create `apps/admin/app/layout.tsx` (ThemeProvider with `next-themes` cookie-storage mode, stub AuthContext, QueryClientProvider, `<html lang="en">`); (5) create `apps/admin/app/error.tsx` (`role="alert"`, correlation ID display), `apps/admin/app/loading.tsx` (`aria-live="polite"`, `role="status"`), `apps/admin/app/not-found.tsx` (404 with nav back); (6) create `apps/admin/lib/utils/correlation.ts` (generateCorrelationId), `apps/admin/lib/utils/errors.ts` (isApiError, isValidationError type guards); (7) create `apps/admin/lib/api/client.ts` with ApiClient class: `get<T>()`, `post<T>()`, `patch<T>()`, `delete()`, X-Correlation-ID header on every request, optional Idempotency-Key header on post(), ApiResponse<T> envelope parsing; (8) create `apps/admin/lib/auth/context.tsx` (AuthContext stub, isAuthenticated: false) per T101, T102, T103, T105, T106, FR-113–FR-116, FR-148 (missing)
- [X] T147 [P] Implement missing Flutter core layer files whose tasks were marked [X] but whose outputs are absent: (1) create `apps/customer/pubspec.yaml` and `apps/agent/pubspec.yaml` declaring dependencies: `flutter_riverpod`, `go_router`, `dio`, `flutter_localizations` (SDK), `intl`; (2) create `apps/customer/l10n.yaml` and `apps/agent/l10n.yaml` with `arb-dir: lib/l10n`, `template-arb-file: app_en.arb`, `output-localization-file: app_localizations.dart`; (3) create `apps/customer/lib/l10n/app_en.arb` and `apps/agent/lib/l10n/app_en.arb` with `{"@@locale":"en","appTitle":"Wallet","@appTitle":{"description":"Application title"}}`; (4) create `apps/customer/lib/core/api/api_client.dart` (Dio client with 5 interceptors in order: AuthInterceptor, CorrelationIdInterceptor, RetryInterceptor 3x exponential, LoggingInterceptor with PII masking, ErrorInterceptor converting DioException→ApiError) and `apps/customer/lib/core/api/api_response.dart` matching backend envelope; (5) create `apps/customer/lib/core/auth/auth_provider.dart` (AsyncNotifier<AuthState> stub, isAuthenticated: false); (6) create `apps/customer/lib/core/di/providers.dart` (ApiClientProvider, authProvider, all override-able for tests); (7) create `apps/customer/lib/core/analytics/analytics_service.dart` and `apps/agent/lib/core/analytics/analytics_service.dart` (abstract AnalyticsService with trackEvent/trackScreen/setUserProperties + NoOpAnalyticsService stub; analyticsServiceProvider bound to NoOp, override-able) per T109, T110, T126, T127, FR-121, FR-124, FR-125, FR-131 (missing)
- [X] T148 [P] Create 15 WCAG AA accessible shared Next.js components in `apps/admin/components/ui/`: Button.tsx (keyboard focus, role="button", disabled aria, min-height 44px), Input.tsx (label association, aria-invalid, aria-describedby), Select.tsx (ARIA listbox), Card.tsx (semantic landmark), Dialog.tsx (focus trap via focus-trap-react, Escape to close, aria-modal, focus restore on close), Sheet.tsx (Dialog pattern + touch dismiss), Toast.tsx (aria-live="polite", auto-dismiss), Badge.tsx (aria-label), Tabs.tsx (ARIA tabs, keyboard arrow navigation), Table.tsx (scope="col" on th, empty state), Navigation.tsx (nav landmark, aria-current="page"), Loading.tsx (aria-live="polite", role="status"), Empty.tsx, ErrorState.tsx (role="alert"), Confirmation.tsx (Dialog pattern with destructive variant); no dangerouslySetInnerHTML in any component; create `apps/admin/__tests__/a11y/components.test.tsx` using jest-axe: for each of the 15 components render with representative props and assert `expect(results).toHaveNoViolations()`; create `apps/admin/lib/utils/motion.ts` with `useReducedMotion()` hook reading `prefers-reduced-motion` media query per T111, T112, T114, FR-140, FR-143–FR-146 (missing)
- [X] T149 [P] Add `Log::withContext(['correlation_id' => $correlationId, 'request_id' => $requestId])` call in `app/Http/Middleware/CorrelationIdMiddleware.php` immediately after both IDs are set on the request object and before calling `$next($request)`; this injects correlation context into the global Laravel log channel so all log statements throughout the request lifecycle (not just those in RequestLoggingMiddleware) automatically include correlation_id in JSON output; verify JsonFormatter's `extra.correlation_id` is now populated by reading a log entry in a feature test per FR-084, FR-087, T084 (partial)
- [X] T150 Add `$this->loadRoutesFrom(base_path('routes/modules/{module}.php'))` to the `boot()` method of each of the 8 stub module ServiceProviders: IdentityServiceProvider, WalletServiceProvider, LedgerServiceProvider, KycServiceProvider, TransactionServiceProvider, RiskServiceProvider, AgentServiceProvider, NotificationServiceProvider; after all 8 are updated, amend `services/api/routes/api.php` to remove the `foreach (glob(base_path('routes/modules/*.php')) as $moduleRouteFile)` block (routes are now loaded by ServiceProviders); retain health routes and Platform module routes in api.php; run `php artisan route:list` to verify all module routes still resolve per FR-176, FR-015 (partial)
- [X] T151 [P] Create `apps/admin/.eslintrc.json` with rules: `@typescript-eslint/no-explicit-any: "error"`, `@typescript-eslint/strict-boolean-expressions: "error"`, jsx-a11y plugin ruleset enabled, `no-console: "warn"`; create `apps/admin/tsconfig.json` with `"strict": true`, `"noUncheckedIndexedAccess": true`, `"exactOptionalPropertyTypes": true`, `"target": "ES2022"`, `"lib": ["dom","dom.iterable","esnext"]`, `"module": "esnext"`, `"moduleResolution": "bundler"`, `"jsx": "preserve"`; create `apps/customer/analysis_options.yaml` and `apps/agent/analysis_options.yaml` with `riverpod_lint` rules enabled, `prefer_const_constructors: true`, `avoid_print: true`, `dart format` enforced per T121, T122, FR-158 (missing)
- [X] T152 [P] Create `docs/adrs/003-schema-per-module.md` documenting the PostgreSQL schema-per-module decision (one named schema + one named Laravel connection per domain module; rationale: extraction-ready isolation, no cross-schema JOINs, same DB instance for Platform Foundation phase); create `docs/adrs/004-idempotency-strategy.md` documenting the PostgreSQL-authoritative + Redis read-through cache strategy for idempotency (UNIQUE(actor_id, idempotency_key) composite key; rationale: durability guarantee, Redis is never financial source of truth, cache miss fallback to PostgreSQL); create `docs/adrs/005-outbox-pattern.md` documenting the atomic domain-state + outbox INSERT in same DB transaction pattern (rationale: eliminates dual-write risk, guarantees event delivery-or-rollback atomicity); each ADR MUST use the `docs/adrs/000-template.md` structure; note: slots 003–005 used because slot 002 was taken by ADR-002 database-connection-naming per T119, FR-160 (missing)
- [X] T153 [P] Add phpunit.xml coverage configuration: within the `<phpunit>` element add `<coverage processUncoveredFiles="true">` with `<report>` containing `<clover outputFile="build/logs/clover.xml"/>` and `<html outputDirectory="build/logs/html"/>`; in `wallet-ecosystem/scripts/ci/backend.sh` amend the `vendor/bin/pest` invocation to add `--coverage --coverage-clover=build/logs/clover.xml --min=80`; add a separate pest invocation with `--coverage --coverage-text` that captures branch coverage output (branch ≥70% per SC-006); ensure vendor/, tests/, and generated files are excluded from coverage scope per FR-158, SC-006, T096 (partial)
- [X] T154 Move CI scripts from `wallet-ecosystem/scripts/ci/` to root-level `scripts/ci/` to match the FR-001 monorepo structure (where `scripts/` is a top-level directory alongside `apps/`, `services/`, `packages/`): move `backend.sh`, `frontend.sh`, `mobile.sh`, `rollback.sh`; update any relative path references within scripts that assume `wallet-ecosystem/` as working directory; delete `wallet-ecosystem/scripts/ci/` directory after moving per T115, T116, T117, T118, FR-001 (partial)

## Phase 16: Convergence

*Generated by /speckit-converge on 2026-08-18. Third convergence pass — gaps remaining after Phase 15 completion.*

- [X] T155 Create agent-app API core layer: (1) `apps/agent/lib/core/api/api_response.dart` — `ApiResponse<T>`, `ApiError`, `ApiErrorDetail` with `fromJson` factories matching backend envelope; (2) `apps/agent/lib/core/api/api_client.dart` — Dio client with 5 interceptors in order: `AuthInterceptor` (adds Bearer token), `CorrelationIdInterceptor` (adds `X-Correlation-ID`), `RetryInterceptor` (retries 5xx up to 3 times with exponential backoff), `LoggingInterceptor` (PII masking), `ErrorInterceptor` (converts `DioException` to typed `ApiError`); (3) `apps/agent/lib/core/di/providers.dart` — `apiClientProvider`, `authProvider`, `analyticsServiceProvider` (bound to `NoOpAnalyticsService`), all override-able for tests; these files must mirror `apps/customer/lib/core/api/` and `apps/customer/lib/core/di/` exactly in structure (FR-121 requires both apps share a common architecture) per FR-121, FR-123, FR-126 (missing)
- [X] T156 [P] Create all 15 Flutter shared widget components in `apps/agent/lib/shared/widgets/` — Button, Input, Select, Card, Dialog, Sheet, Toast, Badge, Tabs, Table, Navigation, Loading, Empty, ErrorState, Confirmation — each MUST use `Semantics(label: ..., button: true)` on interactive elements, meet 44×44 minimum touch target via `SizedBox`, and respect `MediaQuery.of(context).disableAnimations` for reduced motion; these widgets are the agent-app equivalents of the 15 widgets in `apps/customer/lib/shared/widgets/` already created by T139; FR-121 requires Customer and Agent to share common architecture; FR-130 requires accessibility enforced in both apps per FR-121, FR-130, FR-134 (missing)
- [X] T157 [P] Create Flutter environment configuration layer for both apps: (1) `apps/customer/lib/core/config/app_environment.dart` and `apps/agent/lib/core/config/app_environment.dart` — `enum AppEnvironment { development, test, production }` with a static `fromDartDefine()` factory reading `const String.fromEnvironment('APP_ENV', defaultValue: 'development')` and mapping to enum values; (2) `apps/customer/lib/core/config/api_config.dart` and `apps/agent/lib/core/config/api_config.dart` — `class ApiConfig { static const String baseUrl = String.fromEnvironment('API_BASE_URL', defaultValue: 'http://localhost:8000'); static const int connectTimeoutMs = int.fromEnvironment('API_CONNECT_TIMEOUT_MS', defaultValue: 5000); }` using `--dart-define=APP_ENV=production` at build time; (3) wire `AppEnvironment.fromDartDefine()` into `main.dart` to select the correct config; document the `--dart-define` flags in `DEVELOPMENT.md`; DO NOT hardcode production URLs or credentials per FR-122 (missing)
- [X] T158 [P] Create Flutter design token theme integration: (1) `apps/customer/lib/core/theme/app_theme.dart` and `apps/agent/lib/core/theme/app_theme.dart` — `class AppTheme` with `static ThemeData light()` and `static ThemeData dark()` constructors that reference `AppTokens` constants from `wallet-ecosystem/packages/design-system/build/dart/tokens.dart` (already generated by T100); map token colors to `ColorScheme`, token typography to `TextTheme`, token spacing to component shape/padding defaults; (2) wire `theme: AppTheme.light()` and `darkTheme: AppTheme.dark()` into `MaterialApp` in `apps/customer/lib/main.dart` and `apps/agent/lib/main.dart`; verify `flutter analyze` passes after adding the import path; DO NOT hardcode any color or spacing values — all values MUST reference AppTokens constants per FR-132 (missing)
- [X] T159 Add platform permission registry to `PlatformServiceProvider`: (1) create `services/api/app/Modules/Platform/Domain/Permissions/PlatformPermissions.php` as a `final class` with public string constants for every permission identifier used by the Platform module: `CONFIGURATION_UPDATE = 'configuration.policy.update'`; all constants MUST follow the `{module}.{resource}.{action}` naming convention from FR-192; (2) update `services/api/app/Modules/Platform/Providers/PlatformServiceProvider.php` `boot()` method to call a private `registerPermissions(): void` helper that iterates the `PlatformPermissions` constants and either registers them to a central `PermissionRegistry` contract (if one exists) or logs them at DEBUG level for auditability; (3) update `routes/modules/configuration.php` to reference `PlatformPermissions::CONFIGURATION_UPDATE` instead of the raw string `'configuration.policy.update'` so the declared constant is the single source of truth; add a unit test asserting no undeclared permission strings exist in the module per FR-192 (partial)
- [X] T160 [P] Create QA evidence templates in `docs/qa-templates/`: (1) `docs/qa-templates/story-acceptance.md` — template sections: Story ID, Acceptance Criteria (tabular: criterion, test type, test ID, result, evidence link), Security Validation (auth/authz tested, PII not in logs, input validated), Performance Validation (response time measured, meets platform target), Accessibility (axe-core result, keyboard tested, screen reader tested), Sign-off (QA engineer, date); (2) `docs/qa-templates/security-review.md` — OWASP Top 10 checklist per change, dependency vulnerability check, auth/authz bypass attempts, sensitive data exposure check; (3) `docs/qa-templates/performance-validation.md` — load test results table (endpoint, p50, p95, p99 latency, RPS, error rate), database query plan, connection pool metrics; templates MUST contain placeholder text only — no real data, no invented business values per FR-166 (missing)
- [X] T161 [P] Create operational runbooks in `docs/runbooks/`: (1) `docs/runbooks/deployment.md` — step-by-step deployment procedure for each environment promotion (dev→test→staging→production): pre-deploy checklist (CI green, migration dry-run, rollback plan documented), deployment steps (docker pull, health check wait, smoke test), post-deploy verification; (2) `docs/runbooks/rollback.md` — how to execute `scripts/ci/rollback.sh <previous-tag>`, manual rollback steps if script fails, database migration rollback procedure (note: migrations with irreversible schema changes require DBA); (3) `docs/runbooks/incident-response.md` — P1/P2/P3 severity definitions, on-call escalation path (placeholder names), correlation-ID-based log query commands, financial operation freeze procedure; (4) `docs/runbooks/backup-recovery.md` — PostgreSQL volume backup procedure via docker, point-in-time recovery steps, Redis RDB backup, verification steps; all runbooks use placeholder values for provider-specific details (DO NOT invent TBD values) per FR-167 (missing)
- [X] T162 Create `docs/deployment-guide.md` covering: (1) **Environment Matrix** — table of 4 environments (Development/Test/Staging/Production) with columns: environment name, target audience, Docker configuration file, database strategy, secret source, log level, health-check URL pattern; (2) **Secret Management** — how to externalize secrets (env vars only, never committed), `.env.example` keys reference, rotation procedure outline (update external secret store → rolling container restart → verify health); (3) **Infrastructure Requirements** — minimum resource requirements per service (Docker container CPU/RAM), PostgreSQL 15.x, Redis 7.x, network topology (services must reach postgres+redis, api must be reachable via load balancer); (4) **Environment-Specific Configuration** — layering strategy (defaults → env-specific → secrets) and where each layer lives; placeholder values used where provider/infra decisions remain TBD per FR-168 (missing)

## Phase 17: Convergence

*Generated by /speckit-converge on 2026-08-19. Fourth convergence pass — gaps remaining after Phase 16 completion.*

- [X] T163 Create `docs/architecture/dependency-matrix.md` documenting which modules may depend on which: table with rows = dependent module, columns = dependency target, cells = Allowed / Forbidden / Platform-only; rules MUST encode: (1) any module MAY depend on Platform (shared kernel); (2) no module MAY import from another domain module's namespace directly — cross-module communication via contracts only; (3) Domain modules MAY depend on `packages/shared-utils/` and `packages/design-system/`; (4) Infrastructure layer MAY use ORM/DB within its own module schema only; reference `CheckScopeCommand` as the runtime enforcement and `docs/adrs/003-schema-per-module.md` as the rationale ADR; DO NOT invent TBD dependency rules per FR-005 (missing)
- [X] T164 Create `services/api/config/l5-swagger.php` — this file must contain the L5Swagger configuration that was supposed to be created by T042 but was never written: set `'title' => 'Wallet Ecosystem API'`, `'version' => '1.0.0'`, `'description' => 'Platform Foundation API'`, `'api.docs' => storage_path('api-docs/openapi.json')`, `'api.docs_json' => storage_path('api-docs/openapi.json')`, `'routes.api' => 'api/documentation'`, `'paths.annotations' => [app_path()]`; the file structure MUST follow the L5Swagger published config format (use `php artisan vendor:publish --provider="L5Swagger\L5SwaggerServiceProvider" --tag=l5-swagger` as the generation source); after creating the file, verify that `php artisan l5-swagger:generate` exits 0 and creates `storage/api-docs/openapi.json` per FR-148, FR-162 (partial)
- [X] T165 [P] Create C4 architecture diagrams in `docs/architecture/` using Mermaid diagram blocks (renderable in GitHub/GitLab markdown): (1) `docs/architecture/c4-context.md` — System Context diagram showing: OleKard System boundary, Customer actor, Agent actor, Admin/Enterprise actor, and external system placeholders (Payment Network, KYC Provider, Notification Provider) as TBD systems with no invented names or endpoints; (2) `docs/architecture/c4-container.md` — Container diagram showing: Customer App (Flutter), Agent App (Flutter), Admin App (Next.js), API (Laravel), PostgreSQL, Redis, Horizon Worker as containers with communication arrows and protocols (HTTPS/REST, TCP); (3) `docs/architecture/c4-component.md` — Component diagram for the API container showing all 9 modules (Platform, Identity, Wallet, Ledger, KYC, Transaction, Risk, Agent, Notification) as components with their public contract interfaces; (4) `docs/architecture/c4-code.md` — Code-level description noting that detailed class diagrams are deferred to each epic's implementation documentation; all diagrams MUST use Mermaid `graph` or `C4Context`/`C4Container` syntax; DO NOT invent TBD service names, URLs, or providers per FR-161 (missing)
- [X] T166 [P] Create database schema/ER diagram documentation for all 9 module schemas: (1) `docs/architecture/er-diagrams.md` — one Mermaid `erDiagram` block per module schema (platform, identity, wallet, ledger, kyc, transaction, risk, agent, notification) derived from the migration files in each module's `Infrastructure/Migrations/` directory; each entity block MUST list all columns with their types as defined in the migrations; relationships MUST show FK linkages within the same module schema only (no cross-schema FK lines — FK lines MUST NOT appear between different module schemas per FR-002); stub modules with no migrations yet are represented as empty schema blocks with a note "Migrations deferred to epic implementation"; the document header MUST note that these diagrams are generated from the migration source of truth and must be regenerated when migrations change per FR-163 (missing)
- [X] T167 Update `services/api/app/Console/Commands/CheckScopeCommand.php` line 47: change `$this->info('✓ Module boundary check passed. No violations found.');` to `$this->info('Scope: OK');` so the success output exactly matches the FR-180 specification ("exit with code `0` and print `Scope: OK` when no violations are found"); update the corresponding unit/feature test (if any) that asserts this output string; no other behavioral changes per FR-180 (partial)

## Phase 18: Convergence

*Generated by /speckit-converge on 2026-08-19. Fifth convergence pass — migration schema drift found: outbox_events and audit_events migrations do not match data-model.md or application code.*

- [X] T168 Fix `wallet-ecosystem/services/api/app/Modules/Platform/Infrastructure/Migrations/2026_08_01_000004_create_platform_outbox_events.php` to match data-model.md and FR-182: (1) rename `event_type varchar(200)` → `event_name varchar(255)` per data-model.md; (2) add `actor_id uuid NULL` per data-model.md; (3) add `metadata jsonb NOT NULL DEFAULT '{}'` per data-model.md; (4) rename `attempt_count integer` → `attempts smallint NOT NULL DEFAULT 0` per data-model.md; (5) add `max_attempts smallint NOT NULL DEFAULT 5` per data-model.md; (6) add `processing_started_at timestamptz NULL` per FR-182 and data-model.md; (7) rename `published_at` → `delivered_at timestamptz NULL` per data-model.md; (8) add `INDEX ON (status, processing_started_at)` for OutboxStuckProcessingCleanupJob; (9) add `INDEX ON (aggregate_id, event_name)` for event history lookups; (10) add `INDEX ON (correlation_id)` for trace correlation; then update all application code referencing old column names: `app/Modules/Platform/Infrastructure/Persistence/OutboxEventModel.php` (fillable: replace `event_type`→`event_name`, `attempt_count`→`attempts`), `app/Modules/Platform/Application/Services/OutboxEventPublisher.php` (insert keys), `app/Jobs/OutboxDispatcherJob.php` (read/update keys and log fields), `app/Jobs/OutboxStuckProcessingCleanupJob.php` (query/update/log fields); fix `tests/Feature/Modules/Platform/OutboxDispatchTest.php`: replace `event_name`→`event_name` (already correct), `attempt_count`→`attempts` in insert fixtures, fix `OutboxStuckProcessingCleanupJob` constructor call from `(new OutboxStuckProcessingCleanupJob())->handle(app(ConfigService::class))` to `(new OutboxStuckProcessingCleanupJob(app(ConfigurationService::class)))->handle()` per FR-182, data-model.md (partial)
- [X] T169 Fix `wallet-ecosystem/services/api/app/Modules/Platform/Infrastructure/Migrations/2026_08_01_000003_create_platform_audit_events.php` to match data-model.md: (1) add `action varchar(100) NOT NULL` per data-model.md (AuditService inserts this; it is the primary action field alongside legacy `event_type`); (2) add `actor_name_masked varchar(255) NULL` per data-model.md; (3) add `outcome varchar(20) NOT NULL DEFAULT 'success'` per data-model.md; (4) add `before_state jsonb NULL` per data-model.md; (5) add `after_state jsonb NULL` per data-model.md; (6) add `user_agent text NULL` per data-model.md; (7) change `actor_id varchar(36) NOT NULL` to `actor_id uuid NULL` per data-model.md (AuditService may pass null actorId for system events); verify AuditService tests still pass after migration correction per data-model.md (partial)
- [X] T170 [P] Add minimal package metadata to `wallet-ecosystem/packages/shared-utils/` per FR-003: (1) create `packages/shared-utils/README.md` stating this directory is reserved for cross-platform shared utilities (PHP helpers, type aliases, constants reusable by multiple domain modules); note it is empty at Platform Foundation phase and will be populated by domain epics; (2) create `packages/shared-utils/composer.json` stub with `name: olekard/shared-utils`, `description: Shared PHP utilities for the OleKard platform`, `license: proprietary`, `type: library`, empty `autoload` psr-4 namespace (`OleKard\\SharedUtils\\`); this satisfies FR-003 "explicitly licensed for reuse" without inventing utilities that don't yet exist per FR-003 (partial)
- [X] T171 [P] Update `docs/architecture/er-diagrams.md` `outbox_events` and `audit_events` entity blocks to reflect the corrected migrations after T168 and T169: (1) replace the `outbox_events` erDiagram block with all columns from data-model.md (event_name, actor_id, metadata, attempts, max_attempts, processing_started_at, delivered_at) and update the Platform Schema Indexes table to include the three new indexes (`(status, processing_started_at)`, `(aggregate_id, event_name)`, `(correlation_id)`); (2) replace the `audit_events` erDiagram block with all columns from data-model.md (action, actor_name_masked, outcome, before_state, after_state, user_agent, actor_id as nullable); note: T171 MUST NOT be started until T168 and T169 are complete — the ER diagram MUST reflect the migrations as they exist after those fixes per FR-163 (partial)

## Phase 19: Convergence

*Generated by /speckit-converge on 2026-08-20. Sixth convergence pass — six MEDIUM/LOW gaps found; no CRITICAL or HIGH; no constitution violations.*

- [X] T172 Delete the spurious `wallet-ecosystem/services/api/wallet-ecosystem/` directory tree: this nested duplicate of the monorepo structure (`apps/`, `services/`) was created by a mistaken path during T145 implementation and contains stale NullImplementation files at incorrect paths; run `rm -rf wallet-ecosystem/services/api/wallet-ecosystem/` from the repo root; verify the correct implementations still exist at `wallet-ecosystem/services/api/app/Modules/{Wallet,Ledger,Transaction}/` before deleting; re-run all Pest tests to confirm no regressions per T145, FR-185 (unrequested)
- [X] T173 Delete `wallet-ecosystem/scripts/ci/` directory as required by T154: T154 moved CI scripts to root `scripts/ci/` but did not delete the old location; the stale `wallet-ecosystem/scripts/ci/backend.sh` still contains the old relative path `cd "$(dirname "$0")/../../services/api"` (wrong from root) — keeping it risks accidental invocation of the wrong script; after deletion verify root `scripts/ci/backend.sh` uses the correct path `wallet-ecosystem/services/api` per T154, FR-001 (partial)
- [X] T174 Add duplicate-request logging to `services/api/app/Modules/Platform/Application/Services/IdempotencyService.php` per FR-048 and FR-183: (1) in `findCompletedResponse()`, after confirming the record is non-null (replay path), add `Log::info('idempotency.replay', ['actor_id' => $actorId, 'idempotency_key' => $idempotencyKey, 'correlation_id' => request()->header('X-Correlation-ID', '')]);`; (2) in `acquireLock()`, when `IdempotencyKeyMismatchException` is thrown, add `Log::warning('idempotency.conflict', ['actor_id' => $actorId, 'idempotency_key' => $idempotencyKey]);`; (3) update `tests/Feature/Modules/Platform/IdempotencyIntegrationTest.php` to assert the `idempotency.replay` log entry appears when a completed record is replayed (use `Log::shouldReceive` or check log output); do NOT add an audit event — the log entry alone satisfies FR-048 for Platform Foundation; `AuditService` calls for idempotency events are deferred to domain epics per FR-048, FR-183 (partial)
- [X] T175 Add `updated_at` column to the `idempotency_records` migration `wallet-ecosystem/services/api/app/Modules/Platform/Infrastructure/Migrations/2026_08_01_000002_create_platform_idempotency_records.php`: insert `$table->timestamp('updated_at')->notNull()->useCurrent();` after the `created_at` line; add `$table->index('updated_at', 'idempotency_records_updated_at_idx');` for the stuck-processing cleanup query; update `app/Modules/Platform/Infrastructure/Persistence/IdempotencyKeyModel.php` to include `updated_at` in `$fillable` and `$casts`; re-run `php artisan migrate:fresh --env=testing` to confirm migration applies cleanly; re-run all Pest tests per data-model.md, FR-017 (partial)
- [X] T176 [P] Create `docs/platform-audit-actions.md` — the audit action vocabulary registry required by FR-183: the document MUST list every platform-level audit action string in a table with columns: Action, Module, Aggregate, Trigger; seed with the four platform-level actions currently used in code: `platform.outbox.dead-lettered` (OutboxDispatcherJob dead-letter path), `idempotency.replay` (IdempotencyService replay path, added by T174), `idempotency.conflict` (IdempotencyService mismatch path, added by T174), `configuration.changed` (ConfigurationActivationJob); include a header note that domain epics MUST extend this registry when their modules introduce new audit actions, and that undocumented action strings are prohibited by FR-183; DO NOT invent action strings for modules that have not yet been implemented per FR-183 (partial)
- [X] T177 [P] Resolve the `audit_events` timestamp naming inconsistency between the migration and data-model.md: data-model.md specifies `created_at timestamptz NOT NULL DEFAULT now()` as the immutable write time, but the migration uses `occurred_at` and AuditService inserts into `occurred_at`; choose one of: (a) rename `occurred_at` → `created_at` in migration, AuditEventModel fillable/casts, AuditService insert, and `docs/architecture/er-diagrams.md` audit_events erDiagram block — then re-run `php artisan migrate:fresh --env=testing` and all Pest tests; or (b) update data-model.md §platform.audit_events to canonise `occurred_at` and add a footnote explaining the choice (immutable audit time is the event occurrence, not the DB write); do NOT do both — pick one source of truth and make all artifacts consistent; if option (b), also update the ER diagram header note to state that `occurred_at` serves as the immutable write timestamp per data-model.md (partial)

## Phase 20: Convergence

*Generated by /speckit-converge on 2026-08-20. Seventh convergence pass — two MEDIUM documentation gaps found; no CRITICAL, HIGH, or constitution violations.*

- [X] T178 [P] Delete the redundant `wallet-ecosystem/services/api/docs/platform-audit-actions.md` created by T176: T071 already satisfies FR-183 with the canonical registry at `wallet-ecosystem/docs/architecture/audit-action-vocabulary.md` (7 actions, correct naming convention, instructions for domain epic registration); the T176 file has only 4 actions and conflicts with T071's domain-epic registration instructions; after deletion verify `docs/architecture/audit-action-vocabulary.md` still contains all four actions added/confirmed by T174 (`idempotency.replay`, `idempotency.conflict`) — if any are missing, add them to the canonical file; also delete `wallet-ecosystem/services/api/docs/` directory if it becomes empty per FR-183 (unrequested)
- [X] T179 [P] Update `specs/999-platform-foundation/data-model.md` §`platform.idempotency_keys` to match the actual runtime table `platform.idempotency_records`: (1) rename the section heading from `platform.idempotency_keys` to `platform.idempotency_records`; (2) replace the `locked_at` column row with `processing_started_at timestamptz NULL — set when dispatcher acquires record; used to detect stuck-processing records`; (3) add `actor_type varchar(20) NOT NULL DEFAULT 'USER'` and `attempt_count integer NOT NULL DEFAULT 1` column rows in their correct position (between `actor_id` and `request_hash`); (4) update the migration file name reference in §Schema Creation Migrations from `create_platform_idempotency_keys.php` to `create_platform_idempotency_records.php`; (5) update the Indexes block to add `INDEX ON (updated_at)` for the stuck-processing cleanup query added by T175; do NOT modify any PHP migration, model, or service — only the documentation artifact per data-model.md (partial)
