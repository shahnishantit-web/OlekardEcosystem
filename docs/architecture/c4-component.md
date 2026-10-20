# C4 Level 3 — Component Diagram (API Container)

> This diagram shows the components inside the API container (Laravel modular monolith).
> Each component is a bounded module with an explicit public contract interface.

```mermaid
graph TB
    subgraph Clients["Clients (from c4-container.md)"]
        CustomerApp["Customer App"]
        AgentApp["Agent App"]
        AdminApp["Admin App"]
    end

    subgraph API["API Container (Laravel 11)"]
        subgraph HTTP["HTTP Layer"]
            MW["Middleware Stack\n(CorrelationId, Auth, Idempotency,\nResponseEnvelope, Logging)"]
            Routing["Module Routes\n(loaded by each ServiceProvider\nvia loadRoutesFrom)"]
        end

        subgraph Platform["Platform Module (Shared Kernel)"]
            AuditSvc["AuditService"]
            IdempotencySvc["IdempotencyService"]
            OutboxPublisher["OutboxEventPublisher"]
            ConfigSvc["ConfigurationService"]
            MetricsCollector["MetricsCollector"]
            SecretProvider["SecretProvider"]
        end

        subgraph DomainModules["Domain Modules (Stub — Epic implementations replace NullImplementations)"]
            Identity["Identity Module\n[NullImplementation]"]
            Wallet["Wallet Module\n[NullImplementation]"]
            Ledger["Ledger Module\n[NullImplementation]"]
            KYC["KYC Module\n[NullImplementation]"]
            Transaction["Transaction Module\n[NullImplementation]"]
            Risk["Risk Module\n[NullImplementation]"]
            Agent["Agent Module\n[NullImplementation]"]
            Notification["Notification Module\n[NullImplementation]"]
        end
    end

    subgraph Infra["Infrastructure (from c4-container.md)"]
        PostgreSQL["PostgreSQL"]
        Redis["Redis"]
    end

    Clients -->|"HTTPS v1"| MW
    MW --> Routing
    Routing --> Platform
    Routing --> DomainModules

    Platform -->|"platform schema"| PostgreSQL
    Platform -->|"idempotency cache"| Redis

    DomainModules -.->|"via Contract interface\n(throws ModuleNotImplementedException\nuntil epic replaces stub)"| Platform
```

## Module Public Contracts

Each domain module exposes a typed PHP interface as its public boundary. Cross-module calls MUST use these contracts — never direct namespace imports.

| Module | Contract Interface | Status |
|--------|-------------------|--------|
| Platform | `AuditServiceInterface`, `IdempotencyServiceInterface`, `EventPublisherInterface`, `ConfigurationServiceInterface` | Implemented |
| Identity | `IdentityModuleContract` | Stub (NullImplementation) |
| Wallet | `WalletModuleContract` | Stub (NullImplementation) |
| Ledger | `LedgerModuleContract` | Stub (NullImplementation) |
| KYC | `KycModuleContract` | Stub (NullImplementation) |
| Transaction | `TransactionModuleContract` | Stub (NullImplementation) |
| Risk | `RiskModuleContract` | Stub (NullImplementation) |
| Agent | `AgentModuleContract` | Stub (NullImplementation) |
| Notification | `NotificationModuleContract` | Stub (NullImplementation) |

See `specs/999-platform-foundation/contracts/module-contracts.md` for contract method signatures.

## Module Directory Structure (per module)

```
app/Modules/{Module}/
├── Domain/
│   ├── Contracts/          # Public interface (consumed by other modules)
│   ├── Events/             # Integration event classes
│   ├── ValueObjects/
│   └── Permissions/        # Permission constant registry
├── Application/
│   └── Services/           # Orchestration — domain logic, no HTTP concern
├── Infrastructure/
│   ├── Migrations/         # Module's PostgreSQL schema (named connection)
│   ├── NullImplementations/ # Stub until epic replaces with real impl
│   └── Repositories/       # Data access — module's named connection only
└── Providers/
    └── {Module}ServiceProvider.php  # Registers bindings, loads routes/migrations
```
