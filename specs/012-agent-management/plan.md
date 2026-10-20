# Implementation Plan — E12 Agent Management

## Constitution Check

- [x] Approved Laravel/Next.js/Flutter stack respected — Laravel API, no stack changes
- [x] No TBD value invented — KYC provider = `stub` (configurable); balance disclosure = null (TBD-E12-001); virus scanner = `null` stub
- [x] Authorization remains server-side — `AuthorizationGuard` + permission slugs enforced on every route; IDOR check via `AgentCustomerRelationship` query
- [x] Financial integrity preserved — E12 has no direct financial mutations; wallet provisioning is E13 (triggered by `agent.approved` event); no ledger entries in E12
- [x] Security/audit requirements incorporated — FR-E12-020/022/023 covered; `security.unauthorized_access_attempt` event; PII masking enforced in Resources
- [x] UX/design-system requirements — E12 is a pure API epic; Flutter agent UI is E32 scope; admin UI is Next.js (E32/E33)
- [x] QA strategy incorporated — unit, feature, integration, security, and concurrency test suites planned (see research.md test structure)

## Technical Context

| Item | Value |
|------|-------|
| Module namespace | `App\Modules\Agent\` |
| Module root | `app/Modules/Agent/` |
| DB connection | `platform` |
| DB schema | `agent` (already created by Platform E01 migration `2026_08_01_000001`) |
| Migration prefix | `2026_09_23_001xxx` |
| Route file | `routes/modules/agent.php` (placeholder exists) |
| Service provider | `AgentServiceProvider` (expand existing stub — remove "deferred to E16" comment) |
| Permission connection | `identity` |
| Tests | `tests/{Unit,Feature,Integration,Security,Concurrency}/Modules/Agent/` |

## Dependency Matrix

| Epic | Dependency type | How consumed |
|------|----------------|--------------|
| E01 (Identity) | FK — `user_id` references `identity.users.id` | DB foreign key; no runtime API call |
| E03 (Sessions) | Event consumer — receives `agent.suspended` | E12 emits; E03 handles. No direct call. |
| E04 (RBAC) | Permission slugs; `agent` role assignment | Permission seed migration; role resolved by slug at runtime |
| E05 (KYC) | Provider-adapter reference | E12 owns `AgentKycProviderContract`; stub default |
| E06 (Wallet) | Event consumer — receives `agent.customer.registered` | E12 emits; E06 opens customer wallet |
| E13 (Agent Float) | Event consumer — receives `agent.approved` | E12 emits; E13 provisions float wallet |
| E17 (Notifications) | Event consumer — receives lifecycle events | E12 emits; E17 sends notifications |

## Phase 0 — Research (Complete)

See `research.md` for all resolved decisions. Key outputs:

- Module path, DB schema, migration timestamps confirmed
- `AgentKycProviderContract` + `StubAgentKycProvider` (TBD provider)
- `AgentDocumentStorageContract` + `LaravelDiskAgentDocumentStorage`
- `VirusScannerContract` + `NullVirusScanner` stub
- `under_review` is system-implicit on first admin review action
- E03 session invalidation via outbox `agent.suspended` event
- E04 `agent` role assigned by slug lookup at runtime
- `SELECT FOR UPDATE` concurrency guards for CC-E12-001 and CC-E12-002
- All configuration keys and defaults defined

## Phase 1 — Design (Complete)

| Artifact | Status | Path |
|----------|--------|------|
| `research.md` | ✅ | `specs/012-agent-management/research.md` |
| `data-model.md` | ✅ | `specs/012-agent-management/data-model.md` |
| `contracts/api.yaml` | ✅ | `specs/012-agent-management/contracts/api.yaml` |
| `quickstart.md` | ✅ | `specs/012-agent-management/quickstart.md` |

## Implementation Approach (Phase 2 — via `/speckit-tasks` + `/speckit-implement`)

### Priority order (P0 first)

**P0 stories (US-AGENT-001 through 008, 010–015)** must be complete for MVP:
1. Foundation: migrations, enums, service provider, permissions/role seed
2. Agent application lifecycle (US-AGENT-001 through 007)
3. Agent operations: customer search, register, wallet view, transactions (US-AGENT-010–014)
4. Authorization enforcement (US-AGENT-015)

**P1 story (US-AGENT-009 — Reactivate)** can follow.

### Key implementation constraints

1. **AgentServiceProvider**: Replace stub comment with real bindings. Bind `AgentKycProviderContract`, `AgentDocumentStorageContract`, `VirusScannerContract`. Register event listeners for future consumption if needed.

2. **Thin controllers**: `AgentApplicationController`, `AgentLifecycleController`, `AgentCustomerController` — only request parsing, service delegation, resource mapping. Zero business logic in controllers.

3. **State machine enforcement**: `AgentApplicationService::assertTransitionAllowed(string $from, string $to)` — throw `InvalidStateTransitionException` for any illegal transition.

4. **Concurrency (CC-E12-001)**: In `AgentLifecycleService::approve()`, wrap in `DB::transaction()` with `SELECT FOR UPDATE` on `agent_applications`. The `UNIQUE (application_id)` index on `agent.agents` is the final guard.

5. **Concurrency (CC-E12-002)**: In `AgentLifecycleService::suspend()`, wrap in `DB::transaction()` with `SELECT FOR UPDATE` on `agent.agents`. Idempotency key scoped to `agent_suspend:{agent_id}:{key}`.

6. **Outbox events**: All domain events published via `EventPublisherInterface::publish()` within the same DB transaction as the state change. Never publish after commit.

7. **Immutability**: `AgentStatusHistoryModel::performUpdate()` and `performDeleteOnModel()` MUST throw `AgentStatusHistoryImmutableException`.

8. **IDOR prevention**: Every agent-customer endpoint must call `AgentCustomerService::assertLinked(string $agentId, string $customerId)` before any data access. Returns `403` — never `404`.

9. **PII masking**: `CustomerSummaryResource` MUST mask national_id (last 4 digits only). `CustomerWalletViewResource` MUST NOT include full balance until TBD-E12-001 is resolved.

10. **Virus scan flow**: `AgentDocumentService::upload()` calls `VirusScannerContract::scan()` BEFORE `AgentDocumentStorageContract::store()`. If scan returns infected: create quarantined `AgentDocument` record (no storage_reference), publish `agent.application.document_rejected`, throw `DocumentVirusDetectedException` → 422.

### Migration sequence

```
2026_09_23_001000 — CREATE TABLE agent.agent_applications
2026_09_23_001100 — CREATE TABLE agent.agent_documents
2026_09_23_001200 — CREATE TABLE agent.agents
2026_09_23_001300 — CREATE TABLE agent.agent_customer_relationships
2026_09_23_001400 — CREATE TABLE agent.agent_status_history
2026_09_23_001500 — SEED platform configuration defaults
2026_09_23_001600 — SEED identity.permissions (12 slugs)
2026_09_23_001700 — SEED identity.roles + identity.role_permissions for agent role
```

## Data / Financial Controls

- E12 has no direct financial mutations (P4 fully satisfied)
- Agent wallet provisioning is event-driven (E13 domain)
- Customer wallet opening is event-driven (E06 domain)
- All idempotency keys scoped to operation + resource ID
- `SELECT FOR UPDATE` on state transitions

## Observability

- All state transitions emit structured audit events via outbox
- Correlation IDs propagated from request headers through all events
- `security.unauthorized_access_attempt` events for IDOR attempts
- `agent.customer.searched`, `agent.customer.wallet_viewed`, `agent.customer.transactions_viewed` audit trail for data access

## TBD Items (do not invent values)

| ID | Item | Deferred to |
|----|------|------------|
| TBD-E12-001 | Exact balance disclosure rules for agent viewing customer wallet | E18/E23 |
| TBD-E12-002 | Real KYC provider vendor for agent verification | E1000/vendor selection |
| TBD-E12-003 | Real virus scanner vendor | Infrastructure/ops decision |
