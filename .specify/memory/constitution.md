# Wallet Ecosystem Constitution

## Status
- Version: 1.0.0
- Backend: Laravel / PHP 8.4+
- Database: PostgreSQL
- Cache/Queue: Redis + Laravel Horizon
- Web Administration: Next.js + TypeScript
- Mobile: Flutter
- Design System: shared token-driven system
- API: REST/OpenAPI
- Architecture baseline: modular monolith with explicit bounded modules
- Enterprise target state: bounded-context services communicating through internal APIs and asynchronous events

## Principle 1 — Source-of-Truth Requirements
The approved enterprise/SRS specification and the 538-story backlog are the primary product source of truth. No implementation may silently change business intent.

## Principle 2 — TBD Governance
Any requirement marked TBD remains TBD. Developers and AI agents MUST NOT invent a value. Where possible, implement a configurable capability or safe disabled/default state without asserting a business decision.

## Principle 3 — Configuration Over Hard-Coding
The following SHALL be centrally configurable from the Administration surface where specified:
transaction limits, wallet limits, KYC rules, MFA policies, risk thresholds, fraud rules, fees/commissions, settlement rules, retention periods, notification rules, and approval workflows.

## Principle 4 — Financial Integrity
Financial truth is an immutable double-entry ledger.
- Historical ledger entries MUST NOT be edited or deleted.
- Reversals use compensating entries.
- Every financial journal MUST balance: debits == credits.
- Partial debit/credit outcomes are prohibited.
- State-changing financial APIs MUST be idempotent.
- Concurrency and race conditions MUST be explicitly tested.

## Principle 5 — Identity and Authorization
Authentication is centralized across Customer, Agent, Enterprise and Administration contexts.
Authorization is server-side, role/permission driven, and auditable.
Privileged financial operations use step-up/MFA where required.
GAP-027 step-up threshold is resolved: the approved default seed is `auth.step_up_threshold = 10000` with `auth.step_up_threshold_currency = BWP`, runtime-configurable through the Platform Configuration service. MUST NOT be hard-coded in domain logic.

## Principle 6 — Zero Trust and Security
All user-to-service and service-to-service calls MUST be authenticated and authorized.
Sensitive credentials, OTPs and PINs MUST never be logged in plaintext.
Secrets MUST be externally managed.
Transport and data-at-rest protections follow the approved security specification.

## Principle 7 — Domain Boundaries
Controllers remain thin. Domain/application services own business behavior.
Wallet, Ledger, Transaction, Payment, Risk, KYC, Agent, Reconciliation and other domains MUST have explicit interfaces.
The modular-monolith baseline MUST remain extraction-ready for the enterprise target-state.

## Principle 8 — API Contracts
APIs use versioned `/v1` contracts, consistent error envelopes, UTC timestamps, UUID v7 identifiers, correlation IDs, and OpenAPI documentation.
Financial mutations require `Idempotency-Key`.

## Principle 9 — Event Reliability
Domain/integration events are emitted after durable state changes.
Event delivery MUST be idempotent.
Use an outbox/reliable-event pattern for important integration events.

## Principle 10 — UX and Design System
Customer and Agent experiences are mobile-first and optimized for constrained environments.
Administration is desktop-first; mobile is primarily read/approve.
Design tokens are the source of truth for visual implementation.
Critical financial/security states MUST be explicit and distinguishable.

## Principle 11 — Accessibility
User-facing features must meet the approved accessibility requirements, including keyboard, focus, screen-reader, contrast, motion and semantic behavior where applicable.

## Principle 12 — Test-First Quality
Every story includes acceptance criteria and QA scenarios.
The project uses unit, integration, API, system/E2E, regression, smoke, UAT, accessibility, performance, load, stress, scalability, resilience, security, penetration, DR/BCP and infrastructure testing as applicable.

## Principle 13 — Observability
Use structured logging, metrics, tracing and correlation IDs.
Financial transactions, security events and configuration changes must be auditable.

## Principle 14 — No Silent Architecture Changes
Claude/AI agents MUST NOT replace Laravel, Next.js or Flutter, introduce a different ORM/framework, or alter service boundaries without an approved architecture decision.

## Principle 15 — Release Governance
A release requires passing acceptance criteria, QA evidence, security gates, required documentation, regression checks, and no unresolved critical/high blocker.
