# Platform Audit Action Vocabulary

All platform-level audit actions follow the naming convention: `{module}.{aggregate}.{verb}` in lower-kebab-case.

Domain epics must register their own actions in domain-specific files following the same convention.

## Platform Actions

| Action | Module | Aggregate | Trigger |
|--------|--------|-----------|---------|
| `idempotency.replay` | platform | idempotency | Second request with matching idempotency key returned cached response |
| `idempotency.conflict` | platform | idempotency | Request with same key but different payload detected |
| `idempotency.key-mismatch` | platform | idempotency | Idempotency key reused with different HTTP method or body hash |
| `configuration.changed` | platform | configuration | Configuration value created or updated |
| `configuration.activated` | platform | configuration | Draft configuration value promoted to active |
| `platform.permission.denied` | platform | permission | Actor denied a required permission |
| `platform.outbox.dead-lettered` | platform | outbox | Outbox event exceeded max_attempts and moved to dead_letter status |

## Naming Rules

- All lowercase, dot-separated: `{module}.{aggregate}.{verb}`
- Use past-tense verbs: `denied`, `changed`, `activated`, `dead-lettered`
- Hyphens permitted within each segment for compound words: `dead-lettered`, `key-mismatch`
- No underscores in the action name itself

## Domain Epic Registration

Each domain epic (E01–E34) should add a `docs/architecture/audit-action-vocabulary-{module}.md` file following this format for their module-specific events. Do not add domain events to this platform file.
