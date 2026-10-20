# Feature Specification: Platform Foundation

**Feature Branch**: `999-platform-foundation`

**Created**: 2026-08-14

**Status**: Draft

**Input**: Platform Foundation — Technical infrastructure and cross-cutting contracts

## Executive Summary

Platform Foundation is the **prerequisite infrastructure layer** for all 34 business-domain epics in the Wallet Ecosystem. It establishes:

- Repository structure and module boundaries
- Laravel backend architecture (domain-oriented, extraction-ready)
- PostgreSQL database conventions
- REST/OpenAPI API standards
- Event architecture and Outbox pattern
- Idempotency and correlation frameworks
- Security and authorization extension points
- Observability and audit foundations
- Testing infrastructure
- Next.js and Flutter technical foundations
- Design system and accessibility infrastructure
- CI/CD and local development
- Configuration/policy framework
- Environment management

**Platform Foundation does NOT implement any business domain.** It provides the stable architectural contracts, shared libraries, and cross-cutting infrastructure that enable later epics (E01–E34) to be implemented independently.

---

## Problem Statement

The Wallet Ecosystem comprises 538 stories across 34 epics implementing:
- Central identity and authentication
- User and profile management
- Wallet and ledger financial operations
- Wallet funding, transfers, withdrawals
- Agent and commission workflows
- Risk, fraud, and AML
- Admin operations and reconciliation
- Notifications, disputes, and compliance
- Mobile and web applications

Without a unified technical foundation, these domains would suffer from:
- **Inconsistent API contracts** → integration errors, documentation gaps
- **No shared architectural patterns** → business logic leaks into presentation, domains couple tightly
- **Fragmented security/audit/observability** → compliance risk, operational blindness
- **No extraction-ready structure** → blocked transition to enterprise microservices target-state
- **Duplicate configuration/validation/error handling** → maintenance burden, inconsistency
- **Uncontrolled dependencies** → circular imports, tight coupling, refactoring paralysis
- **No shared testing infrastructure** → inconsistent quality, test duplication

**Platform Foundation eliminates these risks by establishing shared contracts and reusable infrastructure before domain implementations begin.**

---

## Objective

Define and establish the **complete technical foundation and cross-cutting contracts** required to implement the Wallet Ecosystem as a:
- Laravel domain-oriented modular monolith (current baseline)
- Extraction-ready architecture (enterprise target-state)
- Secure, observable, testable, and maintainable system

The foundation enables 34 subsequent business-domain features to be implemented independently while maintaining architectural coherence, financial integrity, security, and compliance.

---

## Clarifications

### Session 2026-08-16

- Q: Which identifier type should be used as the primary key for all database tables across the platform — UUID or ULID? → A: UUID v7 (time-ordered, standard UUID format, supported via `ramsey/uuid` bundled with Laravel)
- Q: Which state management architecture should be used as the standard across both Flutter applications (Customer and Agent)? → A: Riverpod (`flutter_riverpod`)
- Q: Should idempotency key storage use PostgreSQL as the durable source of truth, Redis as the primary store, or a hybrid? → A: PostgreSQL authoritative + Redis read-through cache (cache miss falls back to atomic PostgreSQL lookup)
- Q: Which event naming convention should all domain and integration events follow across the platform? → A: Dot-notation PascalCase — `{Domain}.{Aggregate}.{PastTenseVerb}` (e.g., `Wallet.Transfer.Completed`, `Identity.User.Registered`)
- Q: How should Laravel domain modules be isolated at the database level to ensure extraction-ready architecture? → A: PostgreSQL schema-per-module (`identity.*`, `wallet.*`, `ledger.*` etc.) in a single database; extraction later requires only a connection string change

**Checklist Gap Resolutions (2026-08-16)**

- Gap CHK002 (Service Provider registration): Each module MUST have its own `ServiceProvider`; all module Service Providers MUST be registered explicitly in `config/app.php` or via a documented central bootstrap provider. → FR-015 extended.
- Gap CHK003 (Module route-loading): Module routes MUST be loaded exclusively by the module's own Service Provider using `$this->loadRoutesFrom()` under the `/v1` prefix; global `routes/api.php` is for Platform module only. → FR-176 added.
- Gap CHK020 (SELECT FOR UPDATE SKIP LOCKED concurrency): Concurrent idempotency lock contention MUST return HTTP 409 `IDEMPOTENCY_IN_PROGRESS` + `Retry-After` immediately; the API MUST NOT block waiting for the lock. → FR-047 updated.
- Gap CHK029/CHK100 (TbdConfigurationException → HTTP 503): Promoted to spec-level requirement. Error code `TBD_CONFIGURATION`, `retryable: false`, `Retry-After: 3600`. → FR-177 added.
- Gap CHK038 (per-event max_attempts config key format): Configuration key format is `events.max_attempts.{EventName}` with fallback `events.max_attempts.default`. → FR-055 updated.
- Gap CHK039 (Outbox polling interval): Configurable via `events.outbox.polling_interval_seconds`; default is an implementation setting documented in the platform guide, not a business value. → FR-178 added.
- Gap CHK043 (NullImplementation behavior): MUST throw `ModuleNotImplementedException` including module name and interface method; silent null returns are not acceptable. → FR-179 added.
- Gap CHK081 ("80% coverage" definition): ≥80% line coverage per module, ≥70% branch coverage per module; vendor/generated/test files excluded; backend CI-enforced; frontend/mobile reported only. → SC-006 and FR-158 updated.
- Gap CHK089/CHK105 (platform:check-scope command): Formally defined as Artisan command with scan logic, exit codes, and CI gate requirement. Option A selected (define, not remove). → FR-180 added.

**Financial Integrity Gap Resolutions (2026-08-16)**

- Gap CHK007 (idempotency uniqueness scope — BLOCKING): Idempotency key uniqueness MUST be scoped to `(actor_id, idempotency_key)`, not globally unique; `actor_id` is NOT NULL (zero UUID for system calls). → FR-046 updated; data-model.md idempotency_keys table corrected (UNIQUE INDEX changed to composite key).
- Gap CHK001 (money representation/conversion layer): Canonical in-memory representation is `int` (smallest currency unit); DB storage is `NUMERIC(19,4)`; conversion occurs exclusively at Infrastructure layer. → FR-181 added.
- Gap CHK016 (domain state + Outbox atomicity): Outbox INSERT and domain state change MUST occur in the same PostgreSQL transaction. → FR-050 updated.
- Gap CHK033 (stuck-processing Outbox recovery): Outbox records stuck in `processing` status after worker crash MUST be reset to `pending` by a scheduled cleanup job. → FR-182 added; data-model.md outbox_events table updated with `processing_started_at` column.
- Gap CHK043 (financial audit action vocabulary): Financial audit actions MUST follow `{module}.{aggregate}.{verb}` convention. → FR-183 added.
- Gap CHK044 (financial audit before_state/after_state format): State snapshots MUST be named-field diffs in smallest-unit integer amounts, not full entity snapshots. → FR-184 added.
- Gap CHK047/CHK021/CHK022 (missing financial module stubs): `WalletModuleContract`, `LedgerModuleContract`, `TransactionModuleContract` stubs defined in Platform Foundation. → FR-185 added; contracts/module-contracts.md updated.

---

## Scope

### ✅ Platform Foundation WILL Establish

**Repository & Module Structure**:
- `apps/` — Next.js (Admin), Flutter (Customer, Agent)
- `services/` — Laravel API backend
- `packages/` — Shared design system, utilities
- `specs/`, `docs/`, `tests/`, `scripts/`
- Dependency rules, ownership, naming conventions
- Cross-module contract boundaries

**Laravel Backend Architecture**:
- Domain-oriented modular structure (Identity, Authorization, Wallet, Ledger, Transaction, etc.)
- Thin controller layer
- Application service/use-case layer
- Domain model and business logic layer
- Infrastructure/repository layer
- Middleware conventions
- Exception handling model
- Authorization extension points
- Request validation and API resource conventions

**Cross-Domain Contracts** (interfaces, no implementation):
- Actor/context contracts
- Money/value-object foundations
- Currency, transaction reference contracts
- Idempotency, correlation ID contracts
- Audit context contracts
- Event contracts
- Notification contracts
- Configuration/policy contracts
- Pagination, error response contracts

**PostgreSQL Conventions**:
- Migration strategy
- Schema organization (PostgreSQL schema-per-module: `identity.*`, `wallet.*`, `ledger.*`, etc.)
- Naming conventions (tables, columns, indexes)
- UUID v7 strategy (time-ordered, standard UUID format, `ramsey/uuid`)
- Primary keys, foreign keys, unique constraints
- Nullable field rules
- Timestamps and UTC handling
- Audit metadata fields
- Transaction boundaries and isolation strategies
- Data-access layer patterns

**API Foundation**:
- `/v1` versioning and deprecation strategy
- Resource naming and HTTP method conventions
- Status codes and response structure
- Error envelope (code, message, correlation ID, retryability)
- Pagination, filtering, sorting conventions
- Rate limiting headers
- Request/response identifiers (Correlation-ID, Request-ID)
- Idempotency-Key usage (structure, validation, storage strategy)
- OpenAPI documentation standards
- Standard security headers

**Error Model**:
- Validation errors (field, type, constraint violations)
- Authentication errors (invalid credentials, token expired)
- Authorization errors (insufficient permissions)
- Resource-not-found errors
- Conflict errors (duplicate, state violations)
- Business-rule errors (limit exceeded, policy violation)
- Rate-limit errors
- Provider/integration errors
- Timeout errors
- Internal server errors
- Error code taxonomy (no business-domain-specific codes yet)

**Idempotency Framework**:
- Idempotency key structure and validation
- Request fingerprinting strategy
- Response caching and replay
- Key uniqueness enforcement
- Expiration/retention policy
- Concurrency behavior
- Storage strategy (Redis, database)
- Auditability of idempotent replays

**Event Architecture**:
- Domain Event and Integration Event models
- Event envelope (ID, aggregate ID, actor, correlation ID, causation ID, timestamp, schema version)
- Event naming conventions and versioning
- Serialization strategy
- Idempotent event consumer patterns
- Retry strategy (exponential backoff, max retries)
- Dead-letter queue handling
- Event observability hooks
- Outbox Pattern for reliable event delivery

**Queue & Redis Foundation**:
- Redis responsibilities and usage boundaries
- Cache conventions and key namespacing
- Queue naming conventions
- Laravel Horizon configuration
- Worker retry strategies
- Exponential backoff policies
- Dead-letter handling
- Job idempotency
- Queue observability

**Configuration/Policy Framework**:
- Centralized configuration service/repository
- Support for future configurable policies:
  - Transaction limits, wallet limits, KYC rules, MFA policies, risk thresholds, fraud rules, fees, commissions, settlement rules, retention periods, notification rules, approval workflows, currencies
- Configuration versioning and audit history
- Effective dates and active/draft states
- Approval workflow extension points
- Rollback capability
- Default values for approved items: **BWP (primary), USD, EUR, ZAR, CNY, USDT**
- TBD placeholders: GAP-027 step-up threshold, provider selection, reconciliation tolerances, DR/RPO/RTO, unspecified performance/security/UX business decisions

**Security Foundation**:
- Centralized identity integration boundary (OAuth2/OIDC, JWT)
- Token validation and issuer verification
- PKCE support boundary
- Service-to-service authentication (client credentials, API keys)
- Authorization context and claim extraction
- MFA extension point (TOTP, SMS, etc.)
- Step-up authentication extension point
- Zero Trust principles (authenticate and authorize all calls)
- Secrets management (externalized, never logged)
- Encryption for sensitive data at rest
- Secure headers (HSTS, X-Frame-Options, X-Content-Type-Options, etc.)
- API protection (rate limiting, brute-force protection)
- PII/sensitive field masking in logs
- Security event audit framework

**Audit Foundation**:
- Immutable audit event framework
- Audit event fields: actor, action, resource, before/after state, timestamp, IP/device, correlation ID
- Event recording for: authentication, authorization, configuration changes, financial operations, KYC decisions, administrative actions, security events
- Write-once storage strategy
- Tamper detection capability
- Immutable archival strategy
- Event observability and searchability

**Observability Foundation**:
- Structured logging (JSON format)
- Log levels and verbosity conventions
- Correlation ID propagation (header to logging)
- Request tracing context
- Distributed tracing support (OpenTelemetry readiness)
- Application metrics (requests, latency, errors, business metrics)
- Queue metrics (job count, latency, failures)
- Database metrics (query count, slow queries, connection pool)
- Health check endpoints (readiness, liveness, startup)
- Error monitoring and alerting hooks
- Dashboard conventions
- Financial and security operation traceability

**Design System Foundation**:
- **Tokens**: Colors, semantic colors, typography, spacing, elevation, radius, breakpoints, motion, themes
- **Component Architecture**: Button, Input, Select, Card, Dialog, Sheet, Toast, Badge, Tabs, Table, Navigation, Loading, Empty, Error, Confirmation states
- Implementation for Next.js (React components)
- Implementation for Flutter (Dart widgets)
- Design token versioning and updates
- Theme support (light/dark modes)
- Component documentation standards

**Accessibility Foundation**:
- Semantic HTML/Flutter structure
- Keyboard navigation and focus management
- Focus trapping and restoration
- Screen reader support (ARIA labels, roles)
- Contrast requirements (WCAG AA minimum)
- Reduced motion respect
- Touch target sizing (minimum 44x44 dp/pt)
- Accessible error messages and form validation
- Accessible loading/empty/error states
- Accessibility testing framework integration

**Next.js Foundation**:
- Project structure (pages, components, lib, styles, API routes)
- TypeScript configuration
- Environment configuration (development, test, staging, production)
- API client architecture (HTTP client, error handling, interceptors)
- Authorization-aware routing boundary (protected routes)
- Layout and shell architecture (navigation, auth context)
- Design-system integration
- Theme handling (token injection, dark mode)
- Responsive behavior (breakpoint conventions)
- Loading/error/empty state foundations
- Testing foundation (Jest, React Testing Library)
- Build configuration and optimization

**Flutter Foundation**:
- Shared project structure (models, services, screens, widgets, utils)
- Environment management (development, test, production)
- API client (HTTP client, error handling, retry logic)
- Navigation and routing
- State management architecture (Provider, Riverpod, or similar)
- Dependency injection container
- Error handling and user feedback patterns
- Loading/empty/offline state foundations
- Authentication integration boundary
- Accessibility support (SemanticWidget, Semantics)
- Localization readiness
- Design-token integration
- Analytics/observability hooks
- Testing foundation (unit, widget, integration tests)

**CI/CD Foundation**:
- Backend pipeline: lint (PHP CodeSniffer/Psalm), static analysis, unit tests, feature/integration tests, API tests, security scanning (OWASP Top 10, dependency checks)
- Next.js pipeline: lint (ESLint), type checking (TypeScript), unit/component tests, build validation, security scanning
- Flutter pipeline: analyze (Dart analyzer), format (Dart format), unit/widget tests, integration tests, build validation
- Infrastructure-as-Code validation, container scanning, dependency scanning, configuration validation
- Environment promotion (Development → Test → Staging → Production)
- Deployment automation
- Rollback capability

**Docker & Local Development**:
- Docker Compose for Laravel, PostgreSQL, Redis, Horizon
- Environment file management (.env, .env.local)
- Local secrets handling (secure defaults)
- Database initialization and migrations
- Queue startup and Horizon monitoring
- Health check configuration
- Developer onboarding documentation

**Testing Foundation**:
- **Backend**: Unit, feature/integration, API contract, database transaction, queue/job, domain event, security (input validation, auth, CSRF), concurrency/race-condition
- **Next.js**: Unit, component, API contract, accessibility, E2E foundation
- **Flutter**: Unit, widget, integration, accessibility, device matrix, platform-specific
- **Platform**: System/E2E, regression, smoke, performance, load, stress, scalability, resilience, security, penetration, DR/BCP, infrastructure
- Test reporting and evidence attachment
- Code coverage tooling and reporting
- No tests for future business workflows in this feature (framework only)

**Documentation Foundation**:
- Architecture Decision Records (ADRs)
- Architecture diagrams (C4 model readiness)
- API contracts (OpenAPI/Swagger)
- Database schema diagrams and conventions
- Event contracts and versioning
- Security decisions and threat modeling
- QA evidence templates
- Runbooks and operational procedures
- Deployment guides
- Developer onboarding guide

**Environment Strategy**:
- Development: local Docker Compose, hot reloading, debug logging
- Test: automated test environment, clean database per test
- Staging: production-like environment, feature testing, security validation
- Production: optimized, hardened, monitored
- Secrets management: externalized, never committed, environment-specific
- Configuration layering (default → environment → secrets)

---

### ❌ Platform Foundation Will NOT Implement

**Business Domain Workflows**:
- User registration, login, OTP, PIN, password reset, MFA enrollment/verification, session management
- User profile, contact verification, account lifecycle, data export
- Device registration, trust detection, device security
- Role and permission business logic (framework only)
- KYC intake, document verification, biometric verification, KYC decision workflows
- Wallet balance logic, wallet status workflows, wallet lifecycle
- Ledger posting, double-entry accounting, ledger integrity checks, reversals
- Transaction processing, transaction history, transaction receipts
- Wallet funding, withdrawal, provider processing, reconciliation matching
- Wallet-to-wallet transfers, recipient validation, transfer limits
- Agent onboarding, agent approval, customer onboarding, float wallets, cash-in/out
- Commission rules, commission calculation, commission payouts
- Risk scoring, fraud rules, transaction risk decisions
- Utility payments, QR payments, merchant payments
- Notifications (business logic; infrastructure only)
- Disputes, support cases, evidence management
- Reconciliation, settlement, exception handling
- Admin operations, user operations, KYC review, financial adjustments
- Audit workflows (infrastructure; business audit events belong to domains)
- Reporting, analytics, export logic
- API integrations with external providers (contracts only)

**Business UI Screens**:
- Admin dashboard, user management, KYC screens, wallet screens, transaction screens
- Reconciliation screens, reports screens, configuration screens
- Customer app screens (auth, wallet, transfers, funding, withdrawals, services)
- Agent app screens (auth, customer operations, cash-in/out, float, commission, receipts)

**Business Configuration Values** (unless already approved):
- Transaction limits, wallet limits, KYC thresholds
- MFA policies, risk thresholds, fraud rules
- Fee and commission rates
- Reconciliation tolerances
- Dr/RPO/RTO values (marked TBD)
- Performance targets (marked TBD)
- UI/UX business decisions (marked TBD)

---

## User Scenarios & Testing

### User Story 1 - Developer Onboarding (Priority: P1)

A developer joins the project and needs to set up the Wallet Ecosystem locally for feature development. They should be able to clone the repository, run a single command, and have a fully functional development environment with Laravel, PostgreSQL, Redis, and both frontend applications running.

**Why this priority**: This is the foundation for all subsequent development. Without reproducible onboarding, every developer faces configuration friction and inconsistency.

**Independent Test**: Developer can complete full local setup in under 30 minutes following the provided onboarding guide, run all tests, and verify API/frontend connectivity.

**Acceptance Scenarios**:

1. **Given** developer clones the repository, **When** they follow the `DEVELOPMENT.md` guide, **Then** Docker Compose starts Laravel, PostgreSQL, Redis, and Horizon, with all services healthy.
2. **Given** all services are running, **When** developer runs backend tests, **Then** all passing tests execute without manual configuration.
3. **Given** all services are running, **When** developer runs Next.js and Flutter development servers, **Then** both applications start without build errors and can communicate with the backend API.

---

### User Story 2 - Architect Defines Repository Structure (Priority: P1)

An architect needs to establish clear module boundaries, ownership rules, and dependency directions that will prevent business-domain logic from leaking into cross-cutting concerns and ensure the modular monolith remains extraction-ready for the enterprise microservices target state.

**Why this priority**: Repository structure is foundational. Without clear boundaries, architectural drift begins immediately and compounds across 34 epics.

**Independent Test**: Architecture documentation clearly specifies: which modules can depend on which, where business logic belongs, where shared libraries live, and how to validate adherence with automated checks.

**Acceptance Scenarios**:

1. **Given** the repository structure is documented, **When** a developer implements a feature, **Then** they can determine which modules they need to modify and which modules are off-limits.
2. **Given** a pull request is submitted, **When** CI/CD runs dependency checks, **Then** it blocks any pull request that violates module boundary rules.
3. **Given** an architect reviews the codebase, **When** they check module dependencies, **Then** they confirm no circular imports or cross-domain coupling exists.

---

### User Story 3 - Backend Service Establishes API Contract (Priority: P1)

A backend engineer implementing an identity service (E01) needs to follow consistent API conventions for:
- Endpoint naming (`/v1/auth/...`)
- HTTP methods and status codes
- Request/response structure
- Error handling
- Correlation IDs and tracing
- Idempotency

**Why this priority**: API consistency enables frontend engineers and integration testing without rework. Idempotency is critical for financial operations.

**Independent Test**: Identity service API follows all conventions, OpenAPI documentation is auto-generated and valid, and a test client can successfully call the API with proper error handling.

**Acceptance Scenarios**:

1. **Given** an identity service API endpoint is implemented, **When** the frontend calls it with valid input, **Then** it returns a consistent response envelope with `success`, `data`, `correlation_id`, and HTTP status 200.
2. **Given** an identity service API endpoint is called with invalid input, **When** the request is validated, **Then** it returns HTTP 422 with error envelope containing field-level validation errors.
3. **Given** a financial mutation (future wallet transfer) is submitted with an Idempotency-Key, **When** the same request is resubmitted, **Then** the API returns the same response without creating duplicate effects.

---

### User Story 4 - Database Schema Follows Conventions (Priority: P1)

A database engineer implementing the Wallet Ledger schema (E07) needs to follow consistent conventions for:
- Primary keys (UUIDs)
- Timestamps (UTC)
- Foreign key relationships
- Indexes for query optimization
- Audit metadata fields

**Why this priority**: Database consistency prevents query anomalies, ensures data integrity, and enables consistent backup/recovery procedures.

**Independent Test**: Wallet Ledger schema passes migration validation, audit fields are present, indexes are optimized for common queries, and data integrity constraints are enforced at the database level.

**Acceptance Scenarios**:

1. **Given** a Wallet Ledger migration is written, **When** it is run against a test database, **Then** all tables are created with correct column types, constraints, and indexes.
2. **Given** a wallet ledger entry is created, **When** the database is queried, **Then** `created_at`, `updated_at`, and audit fields are populated and consistent.
3. **Given** a transaction references a non-existent wallet, **When** the insert is attempted, **Then** the foreign key constraint prevents the insert and returns a clear error.

---

### User Story 5 - Security Foundation Enforces Zero Trust (Priority: P1)

A security engineer needs to verify that every API call (including service-to-service) is authenticated and authorized. Credentials are never logged, and security events are immutably recorded.

**Why this priority**: Security is non-negotiable for a financial system. Without enforcement at the platform level, individual domain implementations will have inconsistent security.

**Independent Test**: Unauthorized API calls are rejected, security events are recorded in an immutable audit log, and credentials are never present in any log output.

**Acceptance Scenarios**:

1. **Given** a client calls an API endpoint without authentication, **When** the request reaches the API, **Then** it returns HTTP 401 (Unauthorized) with no sensitive data exposed.
2. **Given** a client calls an API endpoint without authorization for the requested resource, **When** the request reaches the API, **Then** it returns HTTP 403 (Forbidden) with a generic message.
3. **Given** a user logs in with a password, **When** the logs are examined, **Then** the password is never present in any log message (masked or redacted).

---

### User Story 6 - Event-Driven Communication Enables Async Workflows (Priority: P2)

A domain engineer implementing wallet funding (E08) needs to emit events when state changes occur (e.g., FundingInitiated, FundingCompleted), and other domains can subscribe to these events to trigger notifications, audit logging, or reconciliation checks.

**Why this priority**: Event-driven architecture decouples domains and enables reliable async workflows (critical for provider timeouts and recovery).

**Independent Test**: Events are emitted after successful state changes, events are persisted before delivery (Outbox pattern), event consumers are idempotent, and events can be replayed from the Outbox for recovery.

**Acceptance Scenarios**:

1. **Given** a wallet funding request is successfully processed, **When** the ledger entry is persisted, **Then** a `FundingCompleted` event is recorded in the Outbox.
2. **Given** a `FundingCompleted` event is in the Outbox, **When** an async worker processes it, **Then** it is delivered to all subscribed handlers (notifications, audit, reconciliation).
3. **Given** an event handler fails to process an event, **When** the retry strategy is activated, **Then** it retries with exponential backoff up to max retries, then moves to a dead-letter queue.

---

### User Story 7 - Configuration Framework Enables Future Business Rules (Priority: P2)

An operations engineer needs to configure transaction limits, wallet limits, KYC thresholds, and fee rates centrally without code changes. Configuration changes are versioned, audited, and can be scheduled for future effective dates.

**Why this priority**: Configuration-driven policies enable regulatory compliance, A/B testing, and rapid policy adjustments without deployment.

**Independent Test**: Configuration values are stored centrally, can be updated without code changes, version history is maintained, and audit events record who changed what and when.

**Acceptance Scenarios**:

1. **Given** an operations engineer accesses the Admin configuration interface, **When** they update the maximum daily transaction limit, **Then** the new value is persisted and immediately enforced (or scheduled for a future effective date if specified).
2. **Given** a transaction limit configuration is changed, **When** the change is saved, **Then** an audit event records the actor, timestamp, old value, new value, and reason.
3. **Given** a configuration change is scheduled for tomorrow, **When** today's transactions are processed, **Then** the old limit is enforced until tomorrow at 00:00 UTC.

---

### User Story 8 - Observability Provides Operational Visibility (Priority: P2)

An operations engineer needs to trace end-to-end request flows through the system, monitor financial operation latency, detect errors, and understand system behavior without accessing sensitive user data.

**Why this priority**: Observability is critical for post-launch operations, compliance audits, and incident response.

**Independent Test**: Correlation IDs are propagated across service boundaries, request traces are queryable, financial operations are timed, and dashboards display aggregate metrics without exposing PII.

**Acceptance Scenarios**:

1. **Given** a customer initiates a wallet transfer, **When** the request flows through authentication, authorization, ledger posting, and notifications, **Then** a single correlation ID is present in all logs and traces.
2. **Given** an operations engineer queries the observability platform for a correlation ID, **When** they retrieve the request trace, **Then** they see the full flow including timing, errors, and state changes.
3. **Given** financial operations are logged, **When** the logs are searched for PII (phone number, email, balance), **Then** no sensitive values are exposed; only references (user IDs) are present.

---

### User Story 9 - Testing Infrastructure Enables Confidence (Priority: P2)

A QA engineer needs to write tests that verify correct behavior across unit, integration, API, and end-to-end layers without creating brittle or flaky tests. Tests should be maintainable and provide clear failure messages.

**Why this priority**: Comprehensive testing enables rapid iteration, regression prevention, and release confidence.

**Independent Test**: Test suites run consistently, pass/fail are deterministic (not flaky), test coverage is measurable, and CI/CD gates test quality before merge.

**Acceptance Scenarios**:

1. **Given** a feature is implemented with unit tests, integration tests, and API tests, **When** CI/CD runs the test suite, **Then** all tests pass consistently (no flakiness).
2. **Given** a test fails, **When** the test output is reviewed, **Then** the failure message is clear and pinpoints the issue without requiring a debugger.
3. **Given** a codebase is analyzed for test coverage, **When** the coverage report is generated, **Then** it shows which lines/branches are covered and which are not.

---

### User Story 10 - Design System Ensures Visual Consistency (Priority: P2)

A designer and frontend engineer collaborate on building the Admin Portal and Customer App. They use design tokens (colors, typography, spacing, components) that are versioned, shared across applications, and map to accessible component implementations.

**Why this priority**: Design system consistency reduces implementation time, ensures accessibility, and enables rapid theme/brand changes.

**Independent Test**: Design tokens are documented, are implemented in both Next.js and Flutter, match the visual designs, and all shared components meet WCAG AA accessibility standards.

**Acceptance Scenarios**:

1. **Given** a designer specifies a new color in the design system (e.g., `error-primary`), **When** the design token is added to the shared token library, **Then** it is immediately available in Next.js, Flutter, and design tools.
2. **Given** a component (e.g., Button) is implemented in both Next.js and Flutter, **When** they are rendered side-by-side, **Then** they appear visually identical and respect the same design tokens.
3. **Given** the Button component is focused in a keyboard navigation flow, **When** a screen reader is used, **Then** the button label is announced clearly and the focus state is visually distinct.

---

### Edge Cases

- **Multi-environment configuration**: Production credentials are never accidentally deployed to development/test environments
- **Backwards compatibility**: API deprecation should allow old clients to function while new clients use new contract versions
- **Concurrency conflicts**: Simultaneous updates to the same resource should be detected (optimistic locking) and handled gracefully
- **Provider timeout recovery**: If an external provider times out, the system should retain the transaction state (PENDING) and allow retry/recovery without duplication
- **Secrets rotation**: Secrets (database passwords, API keys) should be rotatable without requiring deployment
- **Incident recovery**: If a critical service fails, the system should enter a degraded state and provide clear status to users rather than crashing
- **Large data migrations**: Database schema migrations on large tables should not lock the table and should support rollback

---

## Requirements

### Functional Requirements

#### Repository & Module Structure

- **FR-001**: The repository MUST contain separate directories for backend (`services/api/`), web frontend (`apps/admin/`), and mobile applications (`apps/customer/`, `apps/agent/`).
- **FR-002**: Domain modules within the Laravel backend MUST have explicit boundaries (separate namespace, dedicated PostgreSQL schema per module — e.g., `identity.*`, `wallet.*`, `ledger.*` — and stable public contracts). Cross-module data access MUST go through application-layer contracts, not cross-schema JOINs.
- **FR-003**: Shared libraries and utilities MUST reside in a dedicated `packages/` directory and be explicitly licensed for reuse.
- **FR-004**: Module dependency validation MUST be enforced in CI/CD (no circular imports, no cross-domain data access).
- **FR-005**: A dependency matrix MUST be documented showing which modules can depend on which (e.g., Domain modules may depend on Shared libraries, but not vice versa).

#### Laravel Backend Architecture

- **FR-006**: The backend MUST use a domain-oriented modular structure where each domain (Identity, Wallet, Ledger, etc.) is a self-contained module with clear public contracts.
- **FR-007**: Controllers MUST remain thin; they MUST validate input, invoke application services, and return standardized responses. Controllers MUST NOT contain domain business logic.
- **FR-008**: Each domain MUST have an Application Service layer that orchestrates domain logic and cross-domain communication.
- **FR-009**: Each domain MUST have a Domain Model layer containing business logic, value objects, and aggregate roots.
- **FR-010**: Each domain MUST have an Infrastructure layer for data access, external integrations, and technical services.
- **FR-011**: Exception handling MUST be centralized with a global exception handler that maps domain exceptions to HTTP responses.
- **FR-012**: Authorization MUST be enforced via middleware/guards with extensible policies that domain modules can register.
- **FR-013**: Request validation MUST use Laravel Form Requests or DTOs with reusable validation rules.
- **FR-014**: API responses MUST use API Resources/Transformers for consistent serialization.
- **FR-015**: Dependency injection MUST be configured via Service Providers, enabling testable and swappable implementations. Each module MUST have its own `ServiceProvider` class; all module Service Providers MUST be registered explicitly in `config/app.php` (or via a central bootstrap provider that enumerates them in a documented, deterministic order). Module Service Providers are the sole location where that module's bindings, event listeners, and observers are registered.
- **FR-176**: Module routes MUST be loaded exclusively by the module's own Service Provider using `$this->loadRoutesFrom(base_path('routes/modules/{module}.php'))` scoped under the `/v1` API prefix. Route files MUST reside at `services/api/routes/modules/{module}.php` (e.g., `routes/modules/identity.php`, `routes/modules/wallet.php`). Route registrations MUST NOT appear in the global `routes/api.php` file for any module other than Platform. The Platform module's Service Provider MAY register platform-level routes (health, readiness, startup).
- **FR-179**: Module stub implementations (`NullImplementation` classes registered before a concrete module is available) MUST throw `ModuleNotImplementedException` when any interface method is invoked. Silent null returns or empty-collection returns are not acceptable for platform service contracts. The exception MUST include the module name and interface method name to aid diagnosis. This behavior applies in all environments.
- **FR-185**: Platform Foundation MUST define stub contract interfaces for future financial modules: `WalletModuleContract` (with methods `isActive(string $walletId): bool`, `ownerActorId(string $walletId): ?string`, `currency(string $walletId): CurrencyContract`), `LedgerModuleContract` (with methods `postEntry(LedgerEntryRequest $request): string`, `entryExists(string $transactionReference): bool`), and `TransactionModuleContract` (with methods `generateReference(): string`, `status(string $transactionReference): ?string`). These stub contracts MUST be registered via `NullImplementation` classes that throw `ModuleNotImplementedException` until replaced by concrete module implementations. Concrete business logic MUST NOT be implemented within Platform Foundation.

#### PostgreSQL Conventions

- **FR-016**: All tables MUST use UUID v7 primary keys (not auto-incrementing integers). UUID v7 provides time-ordered identifiers in standard UUID format, supported via `ramsey/uuid` (bundled with Laravel).
- **FR-017**: All tables MUST have `created_at` and `updated_at` timestamps in UTC.
- **FR-018**: Audit metadata (`created_by`, `updated_by`) MUST be present on tables where applicable.
- **FR-019**: Foreign keys MUST enforce referential integrity with appropriate cascade/restrict rules.
- **FR-020**: Unique constraints MUST be defined at the database level for natural keys (e.g., email, phone number with country code).
- **FR-021**: Indexes MUST be created for all foreign keys and for columns used in WHERE clauses of frequent queries.
- **FR-022**: Timestamps MUST be stored in UTC; timezone conversion MUST happen only in the application layer.
- **FR-023**: Boolean fields MUST use a nullable boolean or an enum to handle unknown states.
- **FR-024**: Decimal/money values MUST use `NUMERIC(19,4)` or appropriate precision for currency operations (never FLOAT).
- **FR-186**: Each PostgreSQL module schema MUST be accessed exclusively through a named Laravel database connection defined for that module. Named connections MUST follow the short-module-name pattern `{module}` (e.g., `platform`, `identity`, `wallet`, `ledger`) as defined in `config/database.php`. All repository classes within a module MUST reference their module's named connection explicitly (via `DB::connection('{module}')` or the Eloquent model's `$connection` property). No repository or query builder MAY use the default database connection to access a module schema. The named connections resolve to the same physical PostgreSQL database instance during monolith operation; extraction to an independent service requires only changing the connection host environment variable. *Note: the `{module}` short-name convention was established by T010 and is used consistently throughout the codebase; the earlier draft of this requirement referenced `{module}_db` which would have required a full rename — the short-name convention was chosen to avoid that churn (see ADR-002).*
- **FR-193**: When a module's named PostgreSQL database connection is unavailable (e.g., connection refused, network error before a query can execute), the platform MUST: (a) return HTTP 503 with platform error code `SERVICE_UNAVAILABLE` using the standard API error envelope (FR-029, FR-030); (b) log the failure at `ERROR` level including the module name, named connection key, and `correlation_id` so the event is observable via the platform observability mechanism (FR-085); (c) NOT return a partial successful response — the entire module endpoint MUST fail atomically. Retry behavior, connection timeout values, fallback connections, and connection pool configuration are NOT specified by this requirement and remain implementation decisions.

#### API Foundation

- **FR-025**: All API endpoints MUST be prefixed with `/v1/`.
- **FR-026**: Resource endpoints MUST follow REST conventions: `GET /v1/{resource}` (list), `POST /v1/{resource}` (create), `GET /v1/{resource}/{id}` (retrieve), `PATCH /v1/{resource}/{id}` (update), `DELETE /v1/{resource}/{id}` (delete).
- **FR-027**: List endpoints MUST support pagination with `page` and `per_page` query parameters.
- **FR-028**: List endpoints MUST support filtering and sorting as appropriate to the resource.
- **FR-029**: API responses MUST return a standard envelope: `{ success: boolean, data: object|array|null, error: object|null, correlation_id: string, [resource_id|transaction_id]: string }`.
- **FR-030**: Error responses MUST include: `code` (machine-readable), `message` (human-readable), `details` (field-level validation errors if applicable).
- **FR-031**: HTTP status codes MUST follow conventions: `200` (success), `201` (created), `400` (bad request), `401` (unauthorized), `403` (forbidden), `404` (not found), `409` (conflict), `422` (unprocessable entity), `429` (rate limited), `500` (internal error).
- **FR-032**: All API requests MUST include an `X-Correlation-ID` header (generated by the API if not provided); this ID MUST be present in all logs and responses. Correlation IDs MAY use UUID v4 or UUID v7 (time-ordering is not required for tracing); this is distinct from idempotency keys, which MUST use UUID v7 (FR-043). The API MUST also generate a server-side `X-Request-ID` (UUID v7) per incoming HTTP request; unlike `X-Correlation-ID`, `X-Request-ID` is ALWAYS server-generated and MUST NOT be accepted from the client — it identifies this specific HTTP request instance and is not propagated to downstream calls. Both `X-Correlation-ID` and `X-Request-ID` MUST appear in every API response. See contracts/api-response-envelope.md §X-Request-ID vs X-Correlation-ID for the complete semantics.
- **FR-033**: State-changing financial operations MUST accept an `Idempotency-Key` header and MUST be idempotent. A **state-changing financial operation** is any API endpoint whose successful execution creates, modifies, or terminally resolves a financial record — including (but not limited to): initiating a transfer, funding a wallet, posting a ledger entry, approving or rejecting a payment, issuing a reversal, or confirming a withdrawal. Read-only operations (GET, HEAD) and non-financial state changes (e.g., updating a user profile display name) are excluded. Domain epics MUST explicitly identify which of their endpoints qualify as state-changing financial operations in their API contracts.
- **FR-034**: API MUST be documented via OpenAPI/Swagger and documentation MUST be auto-generated from code.
- **FR-035**: Deprecated API endpoints MUST include a `Deprecation` header and a migration guide in documentation.

#### Error Handling

- **FR-036**: Validation errors MUST be returned with HTTP 422 and include field-level error details.
- **FR-037**: Authentication errors MUST be returned with HTTP 401 and MUST NOT disclose whether the user exists.
- **FR-038**: Authorization errors MUST be returned with HTTP 403 with a generic message; detailed reasons MUST only appear in audit logs.
- **FR-039**: Resource-not-found errors MUST be returned with HTTP 404 without disclosing internal structure.
- **FR-040**: Business-rule violations MUST use deterministic HTTP status codes: (a) **HTTP 409** for conflicts arising from system state (e.g., insufficient balance, wallet inactive, duplicate resource, idempotency conflict, state machine violation); (b) **HTTP 422** for input that is syntactically valid but violates a business rule determinable at validation time without checking system state (e.g., amount below the platform minimum, currency not in the supported currency list); (c) **HTTP 400** is reserved for structurally malformed requests that fail format/type validation before business logic runs. Implementations MUST NOT use HTTP 400 as a general business-error catch-all. Domain epics MUST map their exceptions to this taxonomy when defining their error codes.
- **FR-041**: Provider/integration errors MUST distinguish between retryable (500, timeout) and non-retryable errors (400, invalid data).
- **FR-042**: All errors MUST include a `code` (e.g., `VALIDATION_ERROR`, `INSUFFICIENT_BALANCE`) to enable client-side handling without parsing messages.

#### Idempotency Framework

- **FR-043**: Idempotency keys MUST be UUID v7 identifiers provided by the client. No alternative identifier format (e.g., UUID v4, ULID, custom tokens) is accepted; clients MUST generate a new UUID v7 per distinct operation attempt. Note: Correlation IDs (FR-032) MAY use UUID v4 or v7; this restriction applies only to idempotency keys.
- **FR-044**: An idempotent operation MUST accept the same request (same idempotency key, same payload) and return the same response without creating duplicate effects.
- **FR-045**: Idempotency MUST be enforced at the application layer, not delegated to the database or framework.
- **FR-046**: Idempotency key storage MUST retain responses for a configurable period (default: 24 hours). PostgreSQL is the authoritative durable store; Redis MAY be used as a read-through cache for performance. On cache miss, the system MUST perform an atomic lookup in PostgreSQL (`SELECT ... FOR UPDATE`) before executing the operation. Idempotency key uniqueness MUST be scoped to `(actor_id, idempotency_key)`; two different actors MAY reuse the same key value without conflict. The `actor_id` column MUST NOT be NULL; the zero UUID (`00000000-0000-0000-0000-000000000000`) MUST be used for unauthenticated system calls.
- **FR-047**: Concurrent requests with the same idempotency key MUST be serialized using PostgreSQL `SELECT ... FOR UPDATE SKIP LOCKED`. The first request acquires the lock and proceeds; any concurrent request that cannot acquire the lock MUST immediately receive HTTP 409 with error code `IDEMPOTENCY_IN_PROGRESS` and a `Retry-After` header. The API MUST NOT block waiting for the lock; callers are responsible for retrying after the indicated interval.
- **FR-048**: Idempotent operations MUST be auditible; duplicate requests MUST be logged.
- **FR-181**: The canonical in-memory representation for monetary amounts MUST be `int` (smallest currency unit, e.g., thebe for BWP, cents for USD). Database storage for monetary amounts MUST use `NUMERIC(19,4)`. Conversion between integer in-memory values and `NUMERIC` database values MUST occur exclusively at the Infrastructure layer (repository/persistence adapters). Domain and application service layers MUST operate on integer amounts only; they MUST NOT perform float arithmetic on monetary values.

#### Event Architecture

- **FR-049**: Domain Events MUST be emitted after successful state changes within the same transaction.
- **FR-050**: Integration Events MUST be persisted in an Outbox table before external delivery is attempted. The Outbox INSERT and the domain state change MUST occur within the same PostgreSQL database transaction; if either write fails, both MUST be rolled back, leaving no partial financial effect.
- **FR-187**: Failed financial operations MUST leave no partial financial effect. When any step in a financial operation fails after partial execution, the net financial effect across all affected financial records MUST be zero. The primary mechanism is wrapping all domain state changes and outbox inserts within a single PostgreSQL transaction (FR-050); a database-level rollback guarantees zero net effect for intra-transaction failures. For operations requiring compensating actions across transaction boundaries, the implementation MUST explicitly post a compensating ledger entry before returning an error response. Returning HTTP 2xx while financial records are in a partially-applied state is prohibited.
- **FR-051**: Events MUST use a standard envelope: `{ id, aggregate_id, actor_id, correlation_id, causation_id, occurred_at, event_name, event_version, payload, metadata }`.
- **FR-052**: Event names MUST follow dot-notation PascalCase convention: `{Domain}.{Aggregate}.{PastTenseVerb}` (e.g., `Wallet.Transfer.Completed`, `Identity.User.Registered`). This enables wildcard topic subscriptions (e.g., `Wallet.*`) and aligns with CloudEvents standards.
- **FR-053**: Event consumers MUST be idempotent; processing the same event twice MUST produce the same result.
- **FR-054**: Events MUST support versioning; older consumers MUST continue to handle newer events without breaking.
- **FR-055**: Failed event deliveries MUST use exponential backoff retry, with max retries configurable per event type. The configuration key for per-event-type max_attempts MUST follow the format `events.max_attempts.{EventName}` (using the full dot-notation event name, e.g., `events.max_attempts.Wallet.Transfer.Completed`). A global fallback key `events.max_attempts.default` MUST be supported; if neither key is configured, the platform default stored in `platform.configuration_values` applies.
- **FR-056**: Events not deliverable after max retries MUST be moved to a dead-letter queue and alerted.
- **FR-178**: The Outbox dispatcher polling interval MUST be configurable via the configuration key `events.outbox.polling_interval_seconds`. This is an implementation setting (not a business rule or business policy); the specific default value MUST be documented in the platform implementation guide as an operational recommendation. This specification defines no numeric default for this key.
- **FR-182**: A scheduled cleanup job MUST periodically reset Outbox records where `status = 'processing'` AND `processing_started_at < now() - config(events.outbox.processing_timeout_seconds)` back to `status = 'pending'` with `processing_started_at = NULL`. The `attempts` counter MUST be preserved (not reset). This recovers events lost when a dispatcher worker crashes mid-processing. The cleanup interval is an operational setting; this specification defines no numeric value for `events.outbox.processing_timeout_seconds`. If the key is not configured (null), the implementation MUST NOT disable this safety mechanism; it MUST instead schedule using a 300-second implementation-level safety fallback and MUST emit a WARNING-level log on each scheduler cycle stating that the key is unconfigured and the fallback is in use. When `events.outbox.processing_timeout_seconds` is configured, that value MUST take precedence over the fallback.
- **FR-188**: The Outbox dispatcher provides NO ordering guarantees for event delivery. Horizon workers process Outbox records concurrently using `SELECT FOR UPDATE SKIP LOCKED`, which means events for the same aggregate may be dispatched by different workers in parallel. Worker crashes and retries may cause events to be delivered out of creation order. Event consumers MUST tolerate out-of-order delivery and MUST NOT assume sequence monotonicity between events dispatched in separate transactions. Consumers that reconstruct aggregate state from events MUST use the event `occurred_at` timestamp or a sequence number embedded in the payload — not arrival order.
- **FR-189**: Financial integration events MUST include a non-null `causation_id`. The `causation_id` MUST be set to the `id` of the preceding event or the `X-Request-ID` of the originating HTTP request that caused this event. Financial modules are: Wallet, Ledger, Transaction, and any module implementing monetary transfers, funding, withdrawals, or payment processing. Exception: platform-scheduled and system-initiated events (e.g., outbox stuck-processing alerts, configuration activation events, cleanup job outputs) MAY have `causation_id = null` when there is no meaningful causal predecessor; publishers of such events MUST document the absence of causation in the event's `metadata` field using the key `causation_absent_reason`.
- **FR-190**: The Outbox pattern provides **at-least-once** event delivery semantics. A single logical integration event MAY be dispatched and delivered to consumers more than once due to Horizon worker crashes mid-dispatch, network retries, or duplicate Outbox record processing. Exactly-once delivery is NOT guaranteed by the platform event infrastructure. All event consumers MUST be idempotent per FR-053 and MUST implement deduplication using the event `id` field in their `processed_events` table. Consumers MUST NOT rely on delivery count, delivery order, or any exactly-once guarantee.

#### Queue & Redis Foundation

- **FR-057**: Redis MUST NOT be used as the source of financial truth; persistent state MUST reside in PostgreSQL.
- **FR-058**: Cache keys MUST use namespacing to prevent collisions (e.g., `cache:user:{user_id}:profile`).
- **FR-059**: Queue jobs MUST have a configurable TTL; expired jobs MUST not be executed.
- **FR-060**: Horizon workers MUST be configured with appropriate timeout, retry, and backoff strategies.
- **FR-061**: Job idempotency MUST be enforced for jobs that produce side effects (e.g., sending emails, creating ledger entries).

#### Configuration/Policy Framework

- **FR-062**: Configuration values MUST be stored in a centralized repository (not hard-coded in environment files or code).
- **FR-063**: Configuration MUST support versioning and audit history.
- **FR-064**: Configuration changes MUST support effective dates (scheduled activation).
- **FR-065**: Configuration MUST support active/draft states (approve before activation).
- **FR-066**: Configuration MUST be retrievable and updatable via Admin APIs.
- **FR-067**: Critical configuration changes (e.g., transaction limits) MUST trigger audit events and optional approval workflows.
- **FR-068**: The following policy categories MUST be configurable: transaction limits, wallet limits, KYC rules, MFA policies, risk thresholds, fraud rules, fees, commissions, settlement rules, retention periods, notification rules, approval workflows, supported currencies.
- **FR-069**: Default configuration values for approved items: **BWP (primary currency)**, **USD, EUR, ZAR, CNY, USDT (supported currencies)**.
- **FR-070**: Configuration placeholders MUST remain TBD for: **GAP-027 step-up threshold, provider selection, reconciliation tolerances, DR/RPO/RTO, unspecified performance/security/UX business decisions**.
- **FR-177**: When `ConfigurationServiceInterface::get()` is invoked with `requireValue = true` and the resolved value is null (a TBD placeholder), the implementation MUST throw `TbdConfigurationException`. The global exception handler MUST translate this exception to HTTP 503 with error code `TBD_CONFIGURATION`, response field `retryable: false`, and response header `Retry-After: 3600`. This response signals that an operations-team action is required to configure the value; it MUST NOT be treated as a transient client-retryable error.

#### Security Foundation

- **FR-071**: All API endpoints (except health checks) MUST require authentication.
- **FR-072**: Authentication MUST validate bearer tokens (JWT) or service credentials with signature verification.
- **FR-073**: Authorization MUST be enforced server-side via permission checks; client-side authorization hints are not sufficient.
- **FR-074**: Sensitive information (passwords, OTPs, PINs, secrets) MUST never be logged in plaintext or returned in error messages.
- **FR-075**: Secrets and cryptographic keys MUST be externalized (never committed to version control).
- **FR-076**: The system MUST prevent brute-force attacks on authentication endpoints (rate limiting, account lockout).
- **FR-077**: Security events (login failures, permission denied, suspicious activity) MUST be logged for audit and alerting.
- **FR-078**: API responses MUST include security headers: `Strict-Transport-Security`, `X-Content-Type-Options`, `X-Frame-Options`, `Content-Security-Policy`.
- **FR-079**: PII fields (phone number, email, national ID) MUST be masked in logs.
- **FR-192**: Platform permission identifiers MUST follow the naming convention `{module}.{resource}.{action}` where each segment uses lower-kebab-case (e.g., `identity.user.read`, `wallet.transfer.initiate`, `ledger.entry.post`, `configuration.policy.update`). The `{module}` segment MUST match the lower-kebab-case form of the module's directory name in `services/api/app/Modules/`. The `{resource}` segment MUST be a lower-kebab-case noun identifying the resource type. The `{action}` segment MUST be a verb drawn from: `read`, `write`, `create`, `update`, `delete`, `initiate`, `approve`, `reject`, `export`, `impersonate`, or a domain-specific verb explicitly registered in the platform permission registry. No undocumented permission strings are permitted; each module MUST declare its permission identifiers in its Service Provider.

#### Audit Foundation

- **FR-080**: An immutable audit event MUST be recorded for: authentication, authorization (permission denied), configuration changes, financial operations, KYC decisions, administrative actions, security events.
- **FR-081**: Audit events MUST include: `actor` (who), `action` (what), `resource` (on what), `before_state` (if applicable), `after_state` (if applicable), `timestamp` (when), `ip_address` (from where), `correlation_id` (linked to request flow).
- **FR-082**: Audit storage MUST be write-once; events MUST NOT be modified or deleted after creation.
- **FR-083**: Audit data MUST support archival to immutable storage (write-once backup, cold storage).
- **FR-084**: Audit events MUST be queryable without exposing raw sensitive data (audit search returns masked results).
- **FR-183**: Financial audit action names MUST follow the convention `{module}.{aggregate}.{verb}` in lower-kebab-case (e.g., `wallet.transfer.initiated`, `ledger.entry.posted`). Platform-level actions include: `idempotency.replay`, `idempotency.conflict`, `configuration.changed`. An action vocabulary registry MUST be maintained in the platform documentation and expanded as modules are added; no undocumented action strings are permitted in audit events.
- **FR-184**: Audit event `before_state` and `after_state` fields MUST be named-field diff JSON objects containing only the fields that changed (not a full entity snapshot). Financial amounts in these fields MUST be represented as integers in smallest currency units (consistent with FR-181). PII fields MUST be masked by the calling module before being passed to the audit service; the audit infrastructure MUST NOT perform PII masking on behalf of callers.
- **FR-191**: Every financial mutation MUST be attributed to an identifiable actor. The platform supports three actor types for financial attribution: **USER** — an authenticated end-user identified by their UUID v7 (extracted from a valid JWT); **SERVICE** — a service-to-service caller identified by its service client ID (extracted from service credentials); **SYSTEM** — a platform-initiated background operation (e.g., scheduled cleanup, automated reconciliation), identified by a well-known system actor UUID registered in platform configuration. Financial mutations with an unresolved actor — where neither a USER, SERVICE, nor SYSTEM context can be established — MUST be rejected before any financial record is created. The actor type and ID MUST appear in the audit event, the outbox event `actor_id` field, and the idempotency key's `actor_id` column.

#### Observability Foundation

- **FR-085**: All structured logs MUST be in JSON format with consistent field names (`timestamp`, `level`, `message`, `correlation_id`, `actor`, `context`).
- **FR-086**: Log levels MUST be used consistently: `DEBUG` (detailed flow), `INFO` (business events), `WARNING` (unexpected but recoverable), `ERROR` (operation failed), `CRITICAL` (system integrity at risk).
- **FR-087**: Correlation IDs MUST be propagated from HTTP header to all application logs, queue jobs, and database audit fields.
- **FR-088**: Request tracing MUST be supported; a client can query the system for all logs related to a correlation ID.
- **FR-089**: Application metrics MUST be collected: request count, request latency (p50, p95, p99), error rate, authentication/authorization events.
- **FR-090**: Queue metrics MUST be collected: job count, job latency, job failure rate, dead-letter queue size.
- **FR-091**: Database metrics MUST be collected: query count, slow query detection, connection pool utilization.
- **FR-092**: Health check endpoints MUST be available: `/health` (liveness), `/ready` (readiness), `/startup` (startup probe).
- **FR-093**: Financial operations and security-sensitive operations MUST be observable end-to-end via correlation IDs without exposing user data.

#### Design System Foundation

- **FR-094**: Design tokens MUST be defined for: colors (primary, secondary, error, success, warning), semantic colors (text, background, border), typography (font family, size, weight, line height), spacing (4px grid), elevation (shadow levels), radius (border radius values), breakpoints (mobile, tablet, desktop), motion (easing, duration).
- **FR-095**: Shared components MUST be implemented for: Button, Input, Select, Card, Dialog, Sheet, Toast, Badge, Tabs, Table, Navigation, Loading, Empty, Error, Confirmation.
- **FR-096**: Components MUST be documented with design specifications and usage examples.
- **FR-097**: Design tokens MUST be available in both Next.js (CSS variables, Tailwind config) and Flutter (theme data).
- **FR-098**: Components MUST support dark and light themes via token switching.
- **FR-099**: All components MUST have accessible versions (next requirement).

#### Accessibility Foundation

- **FR-100**: All user-facing UI MUST have semantic structure (proper heading hierarchy, list structures, landmark regions for screen readers).
- **FR-101**: All interactive elements MUST be keyboard navigable; focus order MUST be logical.
- **FR-102**: Focus states MUST be visually distinct; users MUST be able to see which element has keyboard focus.
- **FR-103**: Modals and dialogs MUST trap focus (focus must not escape); on close, focus MUST be restored to the triggering element.
- **FR-104**: Screen readers MUST announce button labels, link text, form field labels, and error messages clearly.
- **FR-105**: ARIA attributes MUST be used appropriately (`aria-label`, `aria-labelledby`, `aria-describedby`, `role`, `aria-live` for alerts).
- **FR-106**: Text MUST have sufficient contrast (WCAG AA: 4.5:1 for normal text, 3:1 for large text).
- **FR-107**: Animations and transitions MUST respect `prefers-reduced-motion`; users who disable motion MUST not see animations.
- **FR-108**: Touch targets (buttons, links, inputs) MUST be at least 44x44 dp (Flutter) or 44x44 px (web).
- **FR-109**: Form validation errors MUST be announced to screen readers and associated with form fields.
- **FR-110**: Loading, empty, and error states MUST be accessible (loading message announced, empty state described, error details provided).

#### Next.js Foundation

- **FR-111**: Next.js project MUST use TypeScript for type safety.
- **FR-112**: Environment configuration MUST be layered (default `.env`, environment-specific `.env.{ENV}`, local overrides `.env.local`).
- **FR-113**: The API client MUST handle errors consistently (retry on 5xx, handle 401/403, format error responses).
- **FR-114**: Routes that require authentication MUST be protected via middleware or higher-order components.
- **FR-115**: Layout components MUST provide global navigation, auth context, and theme provider.
- **FR-116**: Design tokens MUST be injected into the application (CSS variables or Tailwind configuration).
- **FR-117**: Dark/light theme switching MUST be supported via context and persisted (localStorage or user preference).
- **FR-118**: Responsive design MUST be tested at multiple breakpoints (mobile, tablet, desktop).
- **FR-119**: Loading, error, and empty states MUST be defined for every page/component that fetches data.
- **FR-120**: Testing infrastructure MUST use Jest and React Testing Library; components MUST be unit tested.

#### Flutter Foundation

- **FR-121**: Flutter projects (Customer, Agent) MUST share a common architecture (models, services, utils).
- **FR-122**: Environment management MUST use separate build flavors or environment configuration (development, test, production).
- **FR-123**: The API client MUST handle errors, retry on recoverable errors, and timeout appropriately.
- **FR-124**: Navigation MUST be centralized; deep linking MUST be supported.
- **FR-125**: State management MUST use Riverpod (`flutter_riverpod`); business logic MUST not reside in widgets. Riverpod provides compile-time safety, no BuildContext dependency for service-layer code, and superior testability for financial-grade state management.
- **FR-126**: Dependency injection MUST be configured (service locator, provider injection, or similar).
- **FR-127**: Error handling and user feedback (SnackBar, Dialog) MUST be consistent.
- **FR-128**: Loading, empty, offline, and error states MUST be defined and tested.
- **FR-129**: Authentication integration MUST be boundary-based; actual authentication logic belongs to the identity service.
- **FR-130**: Accessibility MUST be enforced (SemanticWidget, Semantics labels, sufficient touch targets).
- **FR-131**: Localization MUST be prepared (strings externalized, ready for translation).
- **FR-132**: Design tokens MUST be integrated (theme data, colors, typography).
- **FR-133**: Analytics/observability hooks MUST be provided (event tracking, error reporting integration points).
- **FR-134**: Testing infrastructure MUST support unit, widget, and integration tests.

#### CI/CD Foundation

- **FR-135**: Backend CI pipeline MUST run: PHP linting (PHP CodeSniffer, Psalm or similar), static analysis, unit tests, feature/integration tests, API contract tests, security scanning (OWASP Top 10, dependency vulnerabilities).
- **FR-136**: Next.js CI pipeline MUST run: ESLint, TypeScript type checking, Jest unit/component tests, build validation, security scanning.
- **FR-137**: Flutter CI pipeline MUST run: dart analyze, dart format check, unit tests, widget tests, integration test validation, build validation.
- **FR-138**: Infrastructure-as-Code validation MUST check Dockerfile, docker-compose, and Kubernetes manifests (if applicable).
- **FR-139**: All CI pipeline stages MUST complete before merge to main branch.
- **FR-140**: Deployment MUST be automated from CI/CD; promotion from dev → test → staging → production MUST be scripted.
- **FR-141**: Rollback capability MUST be automated; deployment MUST support instant rollback if health checks fail.
- **FR-180**: The platform MUST provide an Artisan command `php artisan platform:check-scope` that validates Platform Foundation scope integrity. The command MUST: (1) scan `services/api/app/Modules/` for classes that import or reference business-domain entities outside the Platform module's own namespace; (2) detect prohibited patterns including direct Eloquent model references across module boundaries and hardcoded business-rule values; (3) exit with code `0` and print `Scope: OK` when no violations are found; (4) exit with code `1` and print a violation list (file path, class, violation description) when violations are found. This command MUST be executed as a CI pipeline gate before merge to main.

#### Docker & Local Development

- **FR-142**: Docker Compose MUST define all services: Laravel, PostgreSQL, Redis, Horizon (Laravel queue worker).
- **FR-143**: Environment files (.env, .env.local) MUST be provided with secure defaults; no production secrets MUST be committed.
- **FR-144**: Database initialization MUST run automatically on container startup (migrations, seeds).
- **FR-145**: PostgreSQL data MUST be persisted (docker volume); local data is retained across container restarts.
- **FR-146**: Health checks MUST be configured for all services; docker-compose MUST not mark services as "running" until health checks pass.
- **FR-147**: Developer onboarding guide MUST document: clone, setup, run tests, start apps, common troubleshooting.
- **FR-148**: Local API documentation (Swagger UI) MUST be accessible at `http://localhost/api/docs`.

#### Testing Foundation

- **FR-149**: Unit tests MUST test business logic in isolation with mocked dependencies.
- **FR-150**: Feature/integration tests MUST test domain workflows with database and external service interactions.
- **FR-151**: API contract tests MUST verify request/response format against OpenAPI spec.
- **FR-152**: Database tests MUST verify schema, constraints, and migrations.
- **FR-153**: Event tests MUST verify event emission, serialization, and consumption.
- **FR-154**: Security tests MUST verify authorization, authentication, input validation, and output escaping.
- **FR-155**: Concurrency tests (where applicable to financial operations) MUST verify race condition handling.
- **FR-156**: Test data factories/fixtures MUST be reusable across multiple test cases.
- **FR-157**: Test output MUST clearly identify failures (assertion message, expected vs actual).
- **FR-158**: Test coverage reports MUST be generated and tracked. The target is ≥80% line coverage per module and ≥70% branch coverage per module (not as a global codebase average). Vendor packages, auto-generated code (migration stubs, scaffolded DTOs), and test files themselves MUST be excluded from measurement. Backend (Laravel/PHP) coverage MUST be enforced in the CI pipeline. Next.js and Flutter coverage MUST be reported; per-platform CI enforcement is an implementation decision.
- **FR-159**: No tests for business domain workflows (E01–E34) MUST be created in this feature; only test infrastructure.

#### Documentation Foundation

- **FR-160**: Architecture Decision Records (ADRs) MUST document significant technical decisions (rationale, alternatives considered, consequences).
- **FR-161**: Architecture diagrams MUST be maintained (C4 model: context, container, component, code level).
- **FR-162**: API contracts MUST be documented via OpenAPI/Swagger and auto-generated from code.
- **FR-163**: Database schema diagrams MUST be documented; ER diagrams MUST be generated from migrations.
- **FR-164**: Event contracts MUST be documented (event name, payload, schema version).
- **FR-165**: Security decisions MUST be documented (authentication method, authorization model, encryption approach, threat model).
- **FR-166**: QA evidence templates MUST be provided for story acceptance (test results, security validation, performance validation).
- **FR-167**: Runbooks MUST document operational procedures (deployment, rollback, incident response, backup/recovery).
- **FR-168**: Deployment guide MUST document environment setup, secret management, infrastructure requirements.

#### Environment Strategy

- **FR-169**: Four environments MUST exist: Development (local), Test (automated), Staging (production-like), Production.
- **FR-170**: Development environment MUST support hot reloading, verbose logging, and debugging.
- **FR-171**: Test environment MUST have clean database per test run; no cross-test data pollution.
- **FR-172**: Staging environment MUST closely mirror production (same OS, same major service versions, similar data volume).
- **FR-173**: Configuration MUST be layered: defaults → environment-specific → secrets (externalized).
- **FR-174**: Production secrets MUST never be present in version control, configuration files, or logs.
- **FR-175**: Secrets rotation MUST be possible without redeployment.

### Key Entities (Architectural Contracts, No Implementation)

#### Identity & Actor

- **Actor**: User, System Service (represents who/what is performing an action)
- **AuthenticationContext**: Token, user claims, session info
- **AuthorizationContext**: Permissions, roles, resource access

#### Financial Domains (Contracts Only)

- **Currency**: ISO 4217 code, precision/scale, supported regions
- **Money**: Amount (decimal), currency (contract)
- **TransactionReference**: Unique identifier linking financial operations
- **IdempotencyKey**: Client-provided unique key preventing duplicate financial effects
- **CorrelationID**: Unique identifier for tracing request flows

#### Event Domain

- **DomainEvent**: ID, aggregate ID, actor, correlation ID, causation ID, timestamp, name, version, payload
- **IntegrationEvent**: Same as DomainEvent, plus delivery status (pending, delivered, failed)

#### Configuration/Policy

- **ConfigurationKey**: Identifier for a policy (e.g., `transaction.limit.daily`)
- **ConfigurationValue**: The actual value, version, effective date, audit metadata
- **ApprovalContext**: Who approved, when, reason

#### Audit

- **AuditEvent**: Actor, action, resource, before/after state, timestamp, correlation ID, IP/device, immutability marker

---

## Success Criteria

### Measurable Outcomes

- **SC-001**: New developers can complete local setup (clone, run, all tests passing) in under 30 minutes following the provided guide.
- **SC-002**: All API endpoints follow the standardized response envelope and error format; no custom formats exist.
- **SC-003**: Correlation IDs are present in 100% of API responses and in all logs; tracing a single request through the system is possible.
- **SC-004**: Idempotency is enforced for all state-changing financial operations (tested with duplicate request scenarios).
- **SC-005**: All tests (unit, integration, API) execute consistently without flakiness; CI/CD pass rate is 99%+.
- **SC-006**: Code coverage for business logic meets ≥80% line coverage per module and ≥70% branch coverage per module (not as a global average). Vendor packages, auto-generated code, and test files are excluded from measurement. Backend (Laravel/PHP) coverage is enforced as a CI gate; Next.js and Flutter coverage is reported.
- **SC-007**: Security scanning in CI/CD detects and prevents common vulnerabilities (OWASP Top 10, dependency vulnerabilities).
- **SC-008**: All database migrations complete without data loss; rollback capability is verified for each migration.
- **SC-009**: API documentation (OpenAPI) is auto-generated and kept in sync with implementation (no manual sync required).
- **SC-010**: Design tokens are implemented in both Next.js and Flutter; visual consistency is 100% for shared components.
- **SC-011**: All shared components meet WCAG AA accessibility standards; no component has accessibility violations.
- **SC-012**: Event delivery is guaranteed (Outbox pattern); failed events are retried and eventually processed or moved to dead-letter queue.
- **SC-013**: Secrets are externalized; no secrets appear in logs, error messages, or version control.
- **SC-014**: Observability queries (correlation ID, actor, resource) return results within 1 second.
- **SC-015**: Configuration changes are deployed without code/container changes; effective date scheduling is supported.

---

## Assumptions

- **Architecture & Tech Stack**: The approved technology stack (Laravel, PHP 8.4+, PostgreSQL, Redis, Horizon, Next.js, Flutter, REST/OpenAPI) is correct and will not change during Platform Foundation.

- **Extraction-Ready Design**: The enterprise target-state (microservices with bounded contexts, internal APIs, async events) remains the long-term goal; modular monolith is the current baseline and must remain extraction-ready.

- **Existing Infrastructure**: No existing Laravel, Next.js, or Flutter codebases need to be migrated; Platform Foundation starts from scratch with clean projects.

- **Local Development**: Developers have Docker/Docker Compose installed; local development will use containers for backend services (Laravel, PostgreSQL, Redis).

- **Database**: PostgreSQL will be the primary relational database; migrations will be version-controlled and replayable.

- **Security Model**: OAuth2/OIDC is the approved identity mechanism; the exact provider (Auth0, AWS Cognito, custom) is TBD but the architecture accommodates any provider.

- **Configuration Sources**: Configuration and secrets will be externalized (environment variables, secret manager); no hard-coded values will be committed.

- **Testing Scope**: Platform Foundation establishes testing **infrastructure** (frameworks, fixtures, patterns); actual business-domain tests belong to E01–E34.

- **Financial Operations**: Idempotency, concurrency safety, and audit are non-negotiable for financial operations; the framework will enforce these at the platform layer.

- **Event Delivery**: Events are eventually consistent; strong consistency is not required (financial operations will use idempotency and compensation patterns).

- **Observability**: JSON structured logging and correlation IDs will be the primary observability mechanism; distributed tracing (OpenTelemetry) is optional/future.

- **Design System**: Design tokens and components are shared between Next.js and Flutter; visual consistency is the goal.

- **Accessibility**: WCAG AA is the baseline accessibility standard; all public-facing features must meet or exceed it.

- **TBD Items Remain Unimplemented**: Provider selection, GAP-027 step-up threshold, reconciliation tolerances, DR/RPO/RTO, and unspecified business decisions will remain configurable placeholders; no invented values.

---

## Dependencies

### External Dependencies (Approved Stack)

- **Laravel**: PHP framework (v11.x LTS recommended)
- **PostgreSQL**: Primary relational database (v15.x)
- **Redis**: Cache and queue backend (v7.x)
- **Laravel Horizon**: Queue monitoring and management
- **Next.js**: React framework for Admin web app (v14.x)
- **Flutter**: Mobile app framework (stable channel, 3.x+)
- **Docker & Docker Compose**: Local development environment

### Internal Dependencies (Within This Feature)

- **Constitution**: Platform Foundation must enforce the 15 principles established in `.specify/memory/constitution.md`.
- **Master Roadmap**: Platform Foundation is prerequisite for all 34 epics (E01–E34); it must establish all contracts those epics depend on.
- **Architecture Documents**: Security baseline, target architecture, UX principles, design system, testing strategy must inform the foundation design.

---

## Risks

### Risk 1: Insufficient Extraction-Ready Design (Medium Severity)

**Description**: If module boundaries are not strictly defined during Platform Foundation, business domain implementations may couple domains directly (shared database tables, tight dependencies, shared mutable state). This prevents service extraction during enterprise target-state transition.

**Mitigation**:
- Define explicit module boundaries and dependency directions in architecture documentation
- Implement automated dependency checks in CI/CD (Arch Unit Tests or similar)
- Enforce contracts between modules (stable interfaces, DTOs, no shared entities)
- Regular architecture reviews (monthly) during Platform Foundation and subsequent epics

---

### Risk 2: TBD Values Invented During Implementation (High Severity)

**Description**: Developers or implementers might assume default values for TBD items (GAP-027 step-up threshold, provider selection, reconciliation tolerances) to "get the feature working." This silently changes requirements and prevents later policy changes.

**Mitigation**:
- Clearly mark all TBD items in code with comments (`// TODO: This value is TBD per constitution Principle 2`)
- Create configuration placeholders instead of hard-coded values
- Add assertions in test suites that detect hard-coded TBD values
- Require explicit approval from product owner before committing any TBD-marked value

---

### Risk 3: API Contract Drift (Medium Severity)

**Description**: If API conventions are not enforced in CI/CD, implementations may deviate (inconsistent error formats, missing correlation IDs, incorrect status codes). Frontends then need custom handling for each API.

**Mitigation**:
- Use OpenAPI spec validation in CI/CD to ensure all endpoints match the spec
- Create shared request/response DTO validators
- Add API contract tests that verify error responses, headers, pagination, and correlation IDs
- Monthly API review to catch inconsistencies early

---

### Risk 4: Security Foundation Not Used by Domains (High Severity)

**Description**: If security middleware/guards are not mandatory, individual domains might implement custom authentication/authorization, leading to bypass vulnerabilities or inconsistencies.

**Mitigation**:
- Make security middleware non-optional; throw exception if bypassed
- Require all routes to explicitly opt-in to authentication/authorization (whitelist, not blacklist)
- Security testing in CI/CD (verify all endpoints require auth, verify error responses don't leak data)
- Security code review before domain implementations begin

---

### Risk 5: Observability Not Adopted (Medium Severity)

**Description**: If correlation IDs and structured logging are optional, operational teams cannot trace errors or understand system behavior post-launch.

**Mitigation**:
- Middleware automatically injects correlation IDs; developers don't need to do anything
- Fail tests if sensitive data (passwords, PII) is detected in logs
- Provide structured logging examples in Platform Foundation; domain teams copy these patterns
- Observability requirements in definition of done for all later features

---

### Risk 6: Design System Components Not Used (Low Severity)

**Description**: If developers implement custom components instead of using the shared design system, visual inconsistency results and accessibility is not guaranteed.

**Mitigation**:
- Host shared components in `packages/design-system/` with clear documentation
- Provide design system documentation and examples in the onboarding guide
- Peer review process for any custom components (convince a designer it's necessary)
- Design consistency audits (quarterly check that deployed apps use design system)

---

### Risk 7: Testing Framework Not Adopted (Medium Severity)

**Description**: If test infrastructure is not enforced, teams might skip tests or write brittle/slow tests. Coverage drops, regressions increase.

**Mitigation**:
- CI/CD requires minimum code coverage (80%); PRs with lower coverage are blocked
- Provide test templates and factories for common patterns
- Test review process (senior engineer approves test strategy for new features)
- Performance budgets for tests (slow tests are flagged and optimized)

---

### Risk 8: Configuration Not Centralized (Medium Severity)

**Description**: If policy values are hard-coded in domain implementations, changing policies requires code changes and redeployment.

**Mitigation**:
- Implement centralized configuration service first (Platform Foundation)
- All policy reads go through the service, not direct config access
- Validation in code: domain implementations must use configuration service, not environment variables
- Test configuration changes without redeployment

---

## TBD / Open Items

### Explicitly TBD (Per Constitution Principle 2)

- **GAP-027 Step-up Authentication Threshold**: The transaction amount/risk score threshold at which step-up (MFA, fingerprint, PIN) is required remains TBD. The system will support configurable thresholds; actual value is a business decision.
- **Provider Selection**: Which payment processor, bank connection provider, and verification service provider will be used remains TBD. The architecture accommodates multiple providers via adapter pattern; specific provider is a business/procurement decision.
- **Reconciliation Tolerances**: Acceptable variance between system ledger and provider/bank statements (rounding errors, timing differences) remains TBD. Configuration framework will support configurable tolerances.
- **DR/RPO/RTO Values**: Disaster recovery objectives, recovery point objective (data loss tolerance), and recovery time objective (downtime tolerance) remain TBD. They are operational/business decisions.
- **Performance Targets**: Specific SLAs for API latency, throughput, and availability remain TBD (marked for business decision).
- **Security Policy Values**: Encryption standards (AES-256 vs others), TLS versions (1.2 vs 1.3), and session timeout values remain TBD (marked for security team decisions).
- **UI/UX Business Decisions**: Specific workflows, copy, confirmations, and user flows (not covered in architectural specifications) remain TBD (marked for product/design team decisions).

### Intentionally Deferred to Later Phases

- **Provider Integrations**: Actual implementation of payment processor APIs, bank connections, and verification services will be implemented during E08 (Funding), E10 (Withdrawal), E20 (Reconciliation).
- **Business Domain Workflows**: Registration, login, OTP, wallet management, ledger posting, transfer logic, and all other business domain workflows are deliberately deferred to E01–E34.
- **Admin UI Screens**: Admin portal, dashboard, configuration UI will be built during E19 (Admin Portal) and E28 (Configuration Management).
- **Mobile UI Screens**: Customer and agent app screens will be built during E31 (Customer App), E32 (Agent App).
- **Specific Test Implementations**: Platform Foundation defines test frameworks and infrastructure; actual business-domain tests are written in E01–E34.

---

## Acceptance Criteria

Platform Foundation is **complete** only when:

1. ✅ Repository structure is documented and validated (ownership, dependency rules, naming conventions).
2. ✅ Laravel backend foundation is defined: domain-oriented modules, thin controllers, application services, domain models, infrastructure layer.
3. ✅ PostgreSQL conventions are documented: primary keys (UUID v7), schema-per-module isolation (`identity.*`, `wallet.*`, etc.), timestamps (UTC), audit metadata, foreign keys, indexes, null handling.
4. ✅ API conventions are documented: versioning (`/v1`), resource naming, HTTP methods, status codes, request/response envelope, pagination, correlation IDs.
5. ✅ Error handling model is defined: validation, auth, authz, not-found, conflict, business-rule, rate-limit, provider, timeout, internal errors.
6. ✅ Correlation ID support is implemented: middleware injects, logs include, responses include, traces are queryable.
7. ✅ Idempotency framework is defined: key structure, fingerprinting, response caching, storage strategy, concurrency handling.
8. ✅ Event architecture is defined: domain/integration events, envelope, naming, versioning, serialization, idempotent consumers, retry strategy, dead-letter handling.
9. ✅ Outbox pattern is defined for reliable event delivery.
10. ✅ Redis/Horizon conventions are defined: cache namespacing, queue naming, job idempotency, retry/backoff, worker configuration.
11. ✅ Configuration/policy framework is defined: centralized storage, versioning, audit history, effective dates, approval workflows, default values (currencies), TBD placeholders.
12. ✅ Security boundaries are defined: identity integration, token validation, PKCE, service-to-service auth, authorization extension point, MFA extension point, step-up extension point, secrets management.
13. ✅ Audit foundation is defined: immutable event recording, audit fields, storage strategy, tamper detection, archival.
14. ✅ Observability foundation is defined: structured logging, correlation IDs, tracing, metrics (app, queue, database), health endpoints.
15. ✅ Next.js technical foundation is defined: project structure, TypeScript, environment config, API client, auth routing, layout, design-system integration, testing.
16. ✅ Flutter technical foundation is defined (Customer + Agent): project structure, environment management, API client, navigation, state management, dependency injection, testing.
17. ✅ Design-system foundation is defined: tokens (colors, typography, spacing, etc.), components (Button, Input, Select, etc.), implementations (Next.js, Flutter), documentation.
18. ✅ Accessibility foundation is defined: semantic structure, keyboard navigation, focus management, screen reader support, contrast, reduced motion, touch targets.
19. ✅ CI/CD foundation is defined: backend/frontend/mobile pipelines, security scanning, dependency checking, environment promotion, deployment automation.
20. ✅ Testing foundation is defined: unit, integration, API contract, database, event, security, concurrency testing frameworks (infrastructure only, no business tests).
21. ✅ Local development is reproducible: Docker Compose with all services, health checks, database initialization, onboarding guide.
22. ✅ Environment strategy is documented: dev, test, staging, production with configuration layering and secret management.
23. ✅ Documentation standards are defined: ADRs, architecture diagrams, API contracts, schema diagrams, event contracts, security decisions, QA evidence templates, runbooks.
24. ✅ No business-domain workflow is implemented: no registration, login, OTP, wallet management, transfer, funding, withdrawal, KYC, agent workflows, reconciliation.
25. ✅ No TBD business value has been invented: GAP-027, provider selection, reconciliation tolerances, DR/RPO/RTO, performance/security/UX decisions remain TBD.
26. ✅ Architecture is compatible with Wallet (E06), Ledger (E07), KYC (E05), Transaction (E11), and Authentication (E01) specifications (contracts align, no conflicts).
27. ✅ Architecture remains extraction-ready: module boundaries are explicit, dependencies are stable, contracts are internal-API-based, no tight coupling prevents service extraction.

---

## Definition of Done

Platform Foundation is done when:

- [ ] All architectural contracts (33 requirements above) are documented in code, design documents, and architecture diagrams.
- [ ] Repository structure is validated: dependency checks in CI/CD prevent violations, code organization matches documentation.
- [ ] All shared libraries and utilities are implemented and unit tested.
- [ ] API conventions are enforced: baseline endpoint, middleware, error handler, response envelope are in place and tested.
- [ ] Database conventions are enforced: migration template, naming conventions, audit metadata template are in place.
- [ ] Idempotency framework is implemented and tested: key validation, response caching, concurrency handling.
- [ ] Event architecture is implemented: event model, serialization, Outbox pattern, consumer example.
- [ ] Security foundation is implemented: auth middleware, authz guard, secrets management, audit logging.
- [ ] Configuration service is implemented and tested: storage, versioning, audit history, approval workflow hooks.
- [ ] Observability is implemented: structured logging, correlation ID middleware, tracing support, metrics collection, health endpoints.
- [ ] Design system is implemented (tokens + shared components) for both Next.js and Flutter.
- [ ] CI/CD pipelines are defined and tested: backend, frontend, mobile pipelines execute without errors.
- [ ] Docker Compose is functional: all services start, health checks pass, database initializes, developer can run full suite locally.
- [ ] Testing infrastructure is in place: unit test template, factory templates, API contract test template, accessibility test template, concurrency test template (no business tests written yet).
- [ ] Documentation is complete: architecture diagrams, ADRs, API conventions, database conventions, event contracts, security decisions, environment strategy, onboarding guide.
- [ ] All tests pass (unit, integration, API contract tests of the platform layer).
- [ ] Code coverage for platform layer is 80%+.
- [ ] Security scanning passes (no OWASP Top 10 violations, no dependency vulnerabilities).
- [ ] Architecture review confirms extraction-ready design and no TBD values invented.
- [ ] No business domain implementations exist; all work is infrastructure/contracts.

---

## Success Path

### Phase 1: Repository & Architecture (Week 1)

- [ ] Define and document repository structure (`apps/`, `services/`, `packages/`, `specs/`, `docs/`)
- [ ] Create Laravel project structure (domain modules, folder structure, naming conventions)
- [ ] Create Next.js project structure (pages, components, lib, styles)
- [ ] Create Flutter projects (customer, agent) with shared architecture
- [ ] Document dependency rules and implement initial CI/CD dependency checks
- [ ] Architecture review and sign-off

### Phase 2: Backend Foundation (Week 2–3)

- [ ] Implement Laravel middleware (auth, error handling, correlation ID, security headers)
- [ ] Implement Service Providers and dependency injection configuration
- [ ] Define and implement API resource/DTO layer
- [ ] Implement standard error handler and response envelope
- [ ] Implement configuration service (storage, versioning, audit)
- [ ] Implement idempotency framework (key validation, response caching)
- [ ] Implement event model and Outbox pattern
- [ ] Implement audit event framework

### Phase 3: Database & Testing (Week 3–4)

- [ ] Define and implement PostgreSQL conventions (migrations, templates, naming)
- [ ] Implement test database seeding and factory patterns
- [ ] Implement API contract tests for platform endpoints
- [ ] Implement security tests (auth, authz, input validation, output masking)
- [ ] Implement concurrency tests (where applicable)

### Phase 4: Observability & Security (Week 4)

- [ ] Implement structured logging (JSON format, correlation ID propagation)
- [ ] Implement distributed tracing support (OpenTelemetry readiness)
- [ ] Implement metrics collection (requests, latency, errors, business metrics)
- [ ] Implement health check endpoints
- [ ] Implement secrets management (externalized, rotation capability)
- [ ] Implement security event audit logging
- [ ] Security review and sign-off

### Phase 5: Frontend Foundation (Week 5)

- [ ] Implement design tokens (colors, typography, spacing, etc.)
- [ ] Implement shared components (Button, Input, Select, etc.) in Next.js
- [ ] Implement shared components in Flutter
- [ ] Implement accessibility features (focus management, screen reader support, contrast)
- [ ] Implement Next.js API client, auth routing, layout
- [ ] Implement Flutter API client, navigation, state management
- [ ] Accessibility review and sign-off

### Phase 6: CI/CD & Local Development (Week 5–6)

- [ ] Implement CI/CD pipelines (backend, frontend, mobile)
- [ ] Implement Docker Compose (all services, health checks, initialization)
- [ ] Create developer onboarding guide
- [ ] Create QA validation checklist
- [ ] Test full local setup with new developer (dry run)

### Phase 7: Documentation & Final Validation (Week 6)

- [ ] Create architecture diagrams (C4 model)
- [ ] Create ADRs for significant technical decisions
- [ ] Create API conventions guide with examples
- [ ] Create database conventions guide with schema diagrams
- [ ] Create testing guide with examples
- [ ] Create security decision document and threat model
- [ ] Final architecture review and sign-off
- [ ] Final compliance review (no TBD values invented, no business logic)

---

## Next Steps

Once Platform Foundation specification is approved:

1. **Plan Phase**: Use `/speckit-plan` to create implementation plan (artifacts, dependencies, risk mitigation).
2. **Task Generation**: Use `/speckit-tasks` to create actionable, dependency-ordered tasks.
3. **Implementation**: Execute tasks using `/speckit-implement` to build Platform Foundation.
4. **Validation**: QA validates against acceptance criteria.
5. **Release**: Once complete, Platform Foundation becomes the baseline for all subsequent epics (E01–E34).

---

## Clarifications

### Session 2026-08-16

- Q: What return type should `TransactionModuleContract::status()` have when the reference is not found? → A: `?string` (nullable); returns `null` if the transaction reference is not found. Updated FR-185 to match contracts/module-contracts.md.
- Q: May correlation IDs use UUID v4, or must they be UUID v7 like idempotency keys? → A: Correlation IDs MAY use UUID v4 or UUID v7; time-ordering is not required for tracing. Idempotency keys MUST be UUID v7 (FR-043). Updated FR-032.
- F1: What is the canonical route file location for module routes? → A: `services/api/routes/modules/{module}.php` (global routes directory). Each module's Service Provider calls `loadRoutesFrom(base_path('routes/modules/{module}.php'))`. Updated FR-176 to eliminate the `module_path('routes/api.php')` alternative. No competing convention retained.
- F2: Should OutboxDispatchTest assert the audit record when an event is dead-lettered? → A: Yes. T095 criterion (5) must assert both `status='dead_letter'` AND that `AuditService::record()` was called with `action='platform.outbox.dead-lettered'`. Use `AuditServiceSpy` in test setup.
- F3: Which test class name is correct for the security log masking quickstart filter? → A: `PiiMaskerTest` (matches the PiiMasker class created by T065). Updated quickstart.md line filter from `SecurityLogMaskingTest` to `PiiMaskerTest`.
- F4: Should `events.outbox.processing_timeout_seconds` have a seeded numeric default? → A: No. The specification MUST NOT define a numeric default. The key is seeded as `value=null, value_type='null'`. The operational recommendation (begin with 300 seconds) is documented in `DEVELOPMENT.md` only. Updated T059 and T078 accordingly; added operational tuning note requirement to T023.
- F5: How should Flutter localization readiness be established in Platform Foundation? → A: Add `flutter_localizations`, `l10n.yaml`, `lib/l10n/app_en.arb` with placeholder keys, and wire `localizationsDelegates`/`supportedLocales` into `MaterialApp`. No additional locale decisions. Added T126 to Phase 12.
- F6: How should Flutter analytics/observability be abstracted? → A: Define abstract `AnalyticsService` interface + `NoOpAnalyticsService` stub; bind via overrideable `analyticsServiceProvider` in Riverpod DI. No vendor selection. Added T127 to Phase 12.
- F7: Should ThemeProvider in Next.js have an explicit SSR-safe persistence requirement? → A: Yes. `ThemeProvider` MUST persist theme preference via cookie (not localStorage) so the initial server render matches the stored theme with no flash-of-wrong-theme. Use `next-themes` cookie mode or equivalent. Added acceptance criterion to T102.
- F01: When `events.outbox.processing_timeout_seconds` is null, should the stuck-processing cleanup job be disabled? → A: No. NULL MUST NOT disable the safety mechanism. The implementation MUST use a 300-second implementation-level scheduling fallback when the key is null, emit a WARNING-level log indicating unconfigured state, and use the configured value when present (configured value takes precedence). Updated FR-182 and T078; updated T023 DEVELOPMENT.md guidance.

### Session 2026-08-17 — FOUNDATION-MUST-RESOLVE Resolutions

- Q: CHK006 — Should FR-043 accept "equivalent time-ordered" identifiers as alternatives to UUID v7? → A: No. UUID v7 only. FR-043 updated; "or equivalent time-ordered unique identifiers" removed.
- Q: CHK004 — What should MoneyContract.add()/subtract() do when currencies differ? → A: MUST throw `CurrencyMismatchException` (domain error). contracts/module-contracts.md updated with exception doc and class definition.
- Q: CHK005 — Should MoneyContract enforce non-negative amounts? → A: No. Money represents a signed exact value; negative amounts (e.g., debits, reversals) are valid at the Money level. Validity in a given business context is determined by the owning domain. MoneyContract updated with `isNegative()` method; constituted negative-amount policy note added.
- Q: CHK010 — What happens when the same idempotency key is reused with a different HTTP method? → A: `409 IDEMPOTENCY_KEY_MISMATCH` — method mismatch is treated as an intent mismatch equivalent to a payload hash mismatch. contracts/idempotency-protocol.md conflict table updated.
- Q: CHK014 — How should stale `processing` idempotency records be recovered? → A: Via a fencing contract: cleanup MUST acquire exclusive ownership before resetting; the original worker MUST NOT be able to commit a financial operation after ownership transfer. Simple delete-and-re-execute is prohibited. contracts/idempotency-protocol.md "Stale Processing Record Fencing" section added.
- Q: CHK012 — How should "state-changing financial operation" (FR-033) be defined? → A: Any API endpoint whose successful execution creates, modifies, or terminally resolves a financial record. FR-033 updated with domain-neutral definition; GET/non-financial excluded.
- Q: CHK017 — Must failed financial operations leave zero net effect? → A: Yes. FR-187 added: primary mechanism is database transaction rollback (FR-050); compensating entries required for cross-boundary failures; HTTP 2xx with partial financial state is prohibited.
- Q: CHK020 — What are the deterministic rules for 400/409/422 status codes? → A: 409 for system-state conflicts; 422 for business-rule violations at validation time; 400 for structurally malformed requests only. FR-040 and contracts/api-response-envelope.md updated.
- Q: CHK029/CHK007 — Is named-DB-connection-per-module a spec requirement? → A: Yes, mandatory. FR-186 added: each module schema MUST use a dedicated named Laravel connection; no default-connection access across schemas.
- Q: CHK031 — Does the Outbox guarantee event ordering? → A: No. FR-188 added: Outbox dispatcher provides NO ordering guarantees; consumers MUST tolerate out-of-order delivery; contracts/event-envelope.md updated.
- Q: CHK034 — Are financial events required to have non-null causation_id? → A: Yes. FR-189 added: financial integration events MUST have non-null causation_id; scheduled/system events MAY omit with documented reason in metadata.
- Q: CHK035 — Is event delivery exactly-once or at-least-once? → A: At-least-once. FR-190 added: exactly-once is NOT guaranteed; consumers MUST deduplicate on event id.
- Q: CHK042 — Must financial mutations require identifiable actor attribution? → A: Yes. FR-191 added: USER / SERVICE / SYSTEM actor model; unresolved actor MUST be rejected before financial record is created.
- Q: CHK032 — How do X-Request-ID and X-Correlation-ID differ? → A: X-Correlation-ID is client-provided-or-generated and propagated; X-Request-ID is always server-generated per HTTP request, never accepted from client, not propagated downstream. FR-032 updated; contracts/api-response-envelope.md §X-Request-ID vs X-Correlation-ID added.
- Q: CHK036 — How do event consumers declare which version they handle? → A: Via a `HANDLED_EVENT_VERSION` class constant (MAJOR version). contracts/event-envelope.md §Consumer Version Declaration updated with full mechanism.
- Q: CHK048 — What permission naming convention must modules follow? → A: `{module}.{resource}.{action}` in lower-kebab-case. FR-192 added; modules must declare permissions in their Service Provider.
- Q: CHK101 — Is a CI test for TBD configuration null values required? → A: Yes. T128 added to tasks.md: `TbdConfigurationIntegrityTest` asserting every TBD key resolves to null in test environment.
- Q: CHK009 — What is the required behavior when a module's named PostgreSQL database connection is unavailable? → A: FR-193 added: HTTP 503 with `SERVICE_UNAVAILABLE` error code, standard API error envelope, ERROR-level log with module name + connection key + correlation_id, no partial successful response. Retry/timeout/fallback policy not specified.
- Q: CHK104 — Where are Platform Foundation stub deliverables for each module documented? → A: In plan.md §Stub Module Deliverables Table, added 2026-08-17. Each module row lists: schema, ServiceProvider, contracts, NullImplementations, and migration template.

