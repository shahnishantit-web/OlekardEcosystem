# C4 Level 2 — Container Diagram

> This diagram shows the containers (independently deployable units) that make up the OleKard platform.

```mermaid
graph TB
    Customer["👤 Customer"]
    Agent["👤 Agent"]
    Admin["👤 Administrator"]

    subgraph OleKard["OleKard Platform"]
        CustomerApp["Customer App\n[Flutter / Mobile]\nCustomer-facing wallet experience"]
        AgentApp["Agent App\n[Flutter / Mobile]\nAgent / merchant operations"]
        AdminApp["Admin App\n[Next.js / Web]\nOperations, configuration, reporting"]

        API["API\n[Laravel 11 / PHP 8.4+]\nREST API — modular monolith\nAll domain logic lives here"]

        HorizonWorker["Horizon Worker\n[Laravel Horizon]\nAsync queue processing\n(Outbox dispatcher, notifications)"]

        PostgreSQL["PostgreSQL 15\n[Database]\n9 named schemas\n(one per domain module)"]

        Redis["Redis 7\n[Cache + Queue broker]\nIdempotency cache, queue,\nHorizon metadata"]
    end

    Customer -->|"HTTPS / REST"| CustomerApp
    Agent -->|"HTTPS / REST"| AgentApp
    Admin -->|"HTTPS"| AdminApp

    CustomerApp -->|"HTTPS / REST v1"| API
    AgentApp -->|"HTTPS / REST v1"| API
    AdminApp -->|"HTTPS / REST v1"| API

    API -->|"TCP — named connections\n(9 schemas)"| PostgreSQL
    API -->|"TCP"| Redis

    HorizonWorker -->|"TCP — reads outbox_events"| PostgreSQL
    HorizonWorker -->|"TCP — queue"| Redis
```

## Container Descriptions

| Container | Technology | Responsibility |
|-----------|-----------|----------------|
| Customer App | Flutter (Dart) | Customer wallet: balance, transfers, history, KYC |
| Agent App | Flutter (Dart) | Agent operations: cash-in, cash-out, commissions |
| Admin App | Next.js + TypeScript | Platform administration: configuration, risk, reporting |
| API | Laravel 11 / PHP 8.4+ | All domain logic, REST API, module boundaries enforced here |
| Horizon Worker | Laravel Horizon | Processes job queues: outbox dispatch, async notifications |
| PostgreSQL 15 | PostgreSQL | Durable storage — 9 module schemas, financial source of truth |
| Redis 7 | Redis | Queue broker, idempotency read cache, Horizon metadata |

## Notes

- Redis is **never** the source of financial truth. All financial data is authoritative in PostgreSQL.
- The API container hosts all 9 domain modules as bounded namespaces within the same process during Platform Foundation. Extraction to independent services requires only changing connection host environment variables (see ADR-003).
- See `c4-component.md` for the internal structure of the API container.
