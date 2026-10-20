# Research — E12 Agent Management

## Module & Infrastructure

**Decision**: Module path `app/Modules/Agent/` — already seeded by earlier epics with an `AgentServiceProvider` stub (comment says "deferred to E16"; that comment is now superseded by E12).  
**Rationale**: Consistent with the modular-monolith structure used by Identity, KYC, Transaction, Wallet, and all prior epics.  
**Action**: Expand the existing stub; do not create a new provider.

**Decision**: DB connection `platform`, schema `agent`.  
**Rationale**: The `agent` schema is already created in `2026_08_01_000001_create_all_schemas.php`. All modules use `connection = 'platform'` and scope tables to their own schema.  
**Migration prefix**: `2026_09_23_001xxx` — follows Transaction module's `2026_09_22_001xxx`.

---

## Dependency Matrix

| Epic | What E12 needs | Integration pattern |
|------|---------------|---------------------|
| E01 (Identity) | `user_id` on AgentApplication/Agent references `identity.users.id` | FK at DB level; no runtime API call |
| E03 (Device & Session) | Session invalidation on agent suspension | E12 emits `agent.suspended` event; E03 consumes it asynchronously. **No direct call from E12 to E03.** |
| E04 (RBAC) | Permission slugs seeded in `identity.permissions`; `agent` role in `identity.roles` | Permission seed migration; role slug looked up at runtime from `identity.roles` where `slug = 'agent'` |
| E05 (KYC) | Provider-adapter pattern for agent KYC verification | E12 owns its own `AgentKycProviderContract` (module boundary independence per P7). E05's `NullKycVerificationProvider` is the reference implementation. Configure via `agent.kyc.provider = stub` |
| E06 (Wallet) | Customer user registration triggers wallet opening | E12 emits `agent.customer.registered` event; E06 consumes it. **No direct wallet creation in E12.** |
| E13 (Agent Float) | Agent wallet provisioning on approval | E12 emits `agent.approved` event; E13 consumes it asynchronously |
| E17 (Notifications) | Approval / rejection / suspension notifications | E12 emits lifecycle events; E17 consumes them |

---

## KYC Provider Adapter

**Decision**: E12 defines `App\Modules\Agent\Domain\Contracts\AgentKycProviderContract` with a single `verify(AgentKycPayload): AgentKycResult` method. The default implementation is `StubAgentKycProvider` (always returns configurable pass/fail). The real provider (TBD) is wired via config `agent.kyc.provider`.

**Rationale**: Constitution Principle 2 (TBD Governance) — cannot invent the real provider. Constitution Principle 7 (Domain Boundaries) — Agent module must not have a compile-time dependency on the Kyc module's internal contracts. The stub allows full implementation and testing without a live KYC provider.

**Alternatives considered**: Reusing `App\Modules\Kyc\Domain\Contracts\KycVerificationProvider` directly — rejected because it would couple Agent to Kyc's internal domain types.

---

## Document Storage

**Decision**: E12 defines `App\Modules\Agent\Domain\Contracts\AgentDocumentStorageContract` with `store(UploadedFile): string` (returns opaque storage reference). Default implementation: `LaravelDiskAgentDocumentStorage` using the `agent-documents` disk (S3-compatible, configured in `filesystems.php`).

**Rationale**: Mirrors E05's `KycDocumentStorageContract` / `LaravelDiskKycDocumentStorage` pattern. Storage reference is opaque — E12 stores it but does not interpret it.

---

## Virus Scanner

**Decision**: E12 defines `App\Modules\Agent\Domain\Contracts\VirusScannerContract` with `scan(UploadedFile): VirusScanResult`. Default implementation: `NullVirusScanner` (always returns clean). Configure real scanner via `agent.document.virus_scan.provider`.

**Rationale**: FR-E12-024 requires virus scanning before persistence. Constitution Principle 2 — the real scanner vendor is not specified; a null stub allows full implementation without blocking. Constitution Principle 3 — scanner provider must be centrally configurable.

**Scan flow**: Virus scan → if clean, store → create AgentDocument. If infected, quarantine (do not store), set `AgentDocument.status = quarantined`, emit `agent.application.document_rejected`.

---

## Session Invalidation (E03 Integration)

**Decision**: E12 publishes `agent.suspended` domain event via the platform outbox (`platform.outbox_events`). E03 is responsible for subscribing and revoking sessions.

**Rationale**: FR-E12-021 specifies E03 consumes `agent.suspended` asynchronously. This keeps E12 decoupled from E03's session store. Constitution Principle 9 (Event Reliability) requires outbox pattern.

**Event payload**: `{ agent_id, user_id, suspended_by, suspension_reason, suspended_at, correlation_id }`

---

## E04 Role Assignment

**Decision**: On approval, E12 assigns the `agent` role by querying `identity.roles WHERE slug = 'agent'` and inserting into `identity.user_roles`. The `agent` role is seeded in E04 migrations.

**Rationale**: FR-E12-011 requires role grant on approval. The role slug `agent` is the E04 system role (confirmed by checking identity migrations). E12 resolves the role ID at runtime — no hard-coded UUID.

**Implementation**: `AgentRoleAssignmentService` queries `identity.roles` via the `identity` DB connection. This is a cross-schema write (E12 writing to identity schema) — acceptable because it is in the same DB and there is no circular dependency.

---

## `under_review` Transition Mechanism

**Decision**: The `under_review` state is system-implicit. When an admin calls `POST /v1/agent-applications/{id}/approve` or `POST /v1/agent-applications/{id}/reject` on an application in `kyc_passed` or `kyc_failed` status, the system transitions to `under_review` first (atomically within the same transaction) before performing the approve/reject action. No dedicated API action is required.

**Rationale**: Spec assumption confirms `under_review` is implicit. This avoids an extra round-trip and makes the state machine deterministic from the admin's perspective.

**Auto-approval**: If `agent.approval.auto_approve = true` (default `false`), the approval service skips the `under_review` intermediate state and transitions directly from `kyc_passed` to `approved`.

---

## Outbox / Event Reliability

**Decision**: All domain events (`agent.approved`, `agent.suspended`, `agent.application.document_rejected`, `security.unauthorized_access_attempt`, etc.) are published via the existing `App\Modules\Platform\Domain\Contracts\EventPublisherInterface`, which writes to `platform.outbox_events`.

**Rationale**: Constitution Principle 9 — event delivery must be idempotent; outbox guarantees at-least-once delivery even on application crash.

---

## Idempotency Strategy

**Decision**: All state-changing operations use `App\Modules\Platform\Domain\Contracts\IdempotencyServiceInterface`. Idempotency keys are scoped per resource:
- Application creation: header `Idempotency-Key` directly
- Approve: `agent_approve:{application_id}:{idempotency_key}`
- Reject: `agent_reject:{application_id}:{idempotency_key}`
- Suspend: `agent_suspend:{agent_id}:{idempotency_key}`
- Reactivate: `agent_reactivate:{agent_id}:{idempotency_key}`
- Register customer: `agent_register_customer:{agent_id}:{idempotency_key}`

**Rationale**: FR-E12-002 requires idempotency on all state-changing operations.

---

## Maker-Checker

**Decision**: Configurable via platform config key `agent.approval.require_maker_checker` (bool, default `true`). The `actor_id` of the admin who last updated the application (tracked in `AgentApplication.last_updated_by`) is compared to the `actor_id` of the approver/suspender. If they match and maker-checker is enabled, `422 MAKER_CHECKER_VIOLATION` is returned.

---

## Concurrency Guards

**CC-E12-001 (Concurrent Approval)**: `SELECT FOR UPDATE` on `agent_applications WHERE id = ? AND status IN (kyc_passed, kyc_failed, under_review)` within a DB transaction before transitioning to `approved`. Partial unique index: `UNIQUE ON agent.agents (application_id)` prevents duplicate Agent rows.

**CC-E12-002 (Concurrent Suspension)**: `SELECT FOR UPDATE` on `agent.agents WHERE id = ?` before status transition. Idempotency key prevents duplicate suspension events.

---

## Configuration Keys (to seed)

| Key | Default | Description |
|-----|---------|-------------|
| `agent.approval.require_maker_checker` | `true` | Enforce maker-checker on approve/suspend |
| `agent.approval.auto_approve` | `false` | Skip `under_review`; directly approve from `kyc_passed` |
| `agent.kyc.provider` | `stub` | KYC provider adapter: `stub` or `<vendor>` |
| `agent.document.max_size_bytes` | `10485760` | Document upload size limit (10 MB) |
| `agent.document.allowed_formats` | `pdf,jpg,jpeg,png` | Comma-separated accepted MIME types |
| `agent.document.virus_scan.provider` | `null` | Virus scanner provider: `null` or `<vendor>` |
| `agent.customer.search.min_length` | `3` | Minimum search string length |

---

## PHP Class Structure

```
app/Modules/Agent/
├── Providers/
│   └── AgentServiceProvider.php                   (expand existing stub)
├── Domain/
│   ├── Enums/
│   │   ├── ApplicationStatus.php
│   │   ├── AgentStatus.php
│   │   ├── DocumentType.php
│   │   └── DocumentStatus.php
│   ├── Contracts/
│   │   ├── AgentKycProviderContract.php
│   │   ├── AgentDocumentStorageContract.php
│   │   └── VirusScannerContract.php
│   ├── Events/
│   │   ├── AgentApplicationCreated.php
│   │   ├── AgentApproved.php
│   │   ├── AgentRejected.php
│   │   ├── AgentSuspended.php
│   │   ├── AgentReactivated.php
│   │   ├── AgentDocumentRejected.php
│   │   └── AgentCustomerRegistered.php
│   ├── Exceptions/
│   │   ├── ApplicationNotEditableException.php
│   │   ├── ApplicationNotApprovableException.php
│   │   ├── ApplicationInTerminalStatusException.php
│   │   ├── ApplicationIncompleteException.php
│   │   ├── MakerCheckerViolationException.php
│   │   ├── AgentNotActiveException.php
│   │   ├── AgentNotSuspendedException.php
│   │   ├── UnauthorizedCustomerAccessException.php
│   │   ├── DuplicatePhoneException.php
│   │   ├── DocumentVirusDetectedException.php
│   │   └── DocumentTooLargeException.php
│   └── ValueObjects/
│       ├── AgentKycPayload.php
│       └── AgentKycResult.php
├── Application/
│   └── Services/
│       ├── AgentApplicationService.php
│       ├── AgentLifecycleService.php
│       ├── AgentDocumentService.php
│       ├── AgentCustomerService.php
│       └── AgentRoleAssignmentService.php
└── Infrastructure/
    ├── Migrations/
    │   ├── 2026_09_23_001000_create_agent_applications_table.php
    │   ├── 2026_09_23_001100_create_agent_documents_table.php
    │   ├── 2026_09_23_001200_create_agents_table.php
    │   ├── 2026_09_23_001300_create_agent_customer_relationships_table.php
    │   ├── 2026_09_23_001400_create_agent_status_history_table.php
    │   ├── 2026_09_23_001500_seed_agent_configuration_defaults.php
    │   ├── 2026_09_23_001600_seed_agent_permissions.php
    │   └── 2026_09_23_001700_seed_agent_role.php
    ├── Models/
    │   ├── AgentApplicationModel.php
    │   ├── AgentDocumentModel.php
    │   ├── AgentModel.php
    │   ├── AgentCustomerRelationshipModel.php
    │   └── AgentStatusHistoryModel.php
    ├── Stubs/
    │   ├── StubAgentKycProvider.php
    │   ├── NullVirusScanner.php
    │   └── LaravelDiskAgentDocumentStorage.php
    └── Http/
        ├── Controllers/
        │   ├── AgentApplicationController.php
        │   ├── AgentLifecycleController.php
        │   └── AgentCustomerController.php
        ├── Requests/
        │   ├── CreateApplicationRequest.php
        │   ├── UpdatePersonalInfoRequest.php
        │   ├── UpdateBusinessInfoRequest.php
        │   ├── UploadDocumentRequest.php
        │   ├── ApproveApplicationRequest.php
        │   ├── RejectApplicationRequest.php
        │   ├── SuspendAgentRequest.php
        │   ├── ReactivateAgentRequest.php
        │   ├── SearchCustomersRequest.php
        │   └── RegisterCustomerRequest.php
        └── Resources/
            ├── AgentApplicationResource.php
            ├── AgentDocumentResource.php
            ├── AgentResource.php
            ├── CustomerSummaryResource.php
            ├── CustomerWalletViewResource.php
            └── AgentTransactionResource.php
```

## Test Structure

```
tests/
├── Unit/Modules/Agent/
│   ├── ApplicationStatusEnumTest.php
│   ├── AgentStatusEnumTest.php
│   ├── AgentApplicationServiceTest.php      (state machine, maker-checker, concurrency)
│   ├── AgentDocumentServiceTest.php         (virus scan, format validation, quarantine)
│   └── AgentCustomerServiceTest.php         (link enforcement, IDOR prevention)
├── Feature/Modules/Agent/
│   ├── CreateApplicationTest.php
│   ├── PersonalInfoTest.php
│   ├── BusinessInfoTest.php
│   ├── DocumentUploadTest.php
│   ├── KycTriggerTest.php
│   ├── ApproveApplicationTest.php
│   ├── RejectApplicationTest.php
│   ├── SuspendAgentTest.php
│   ├── ReactivateAgentTest.php
│   ├── SearchCustomersTest.php
│   ├── RegisterCustomerTest.php
│   ├── CustomerKycTest.php
│   ├── CustomerWalletViewTest.php
│   └── CustomerTransactionViewTest.php
├── Security/Modules/Agent/
│   ├── AgentCustomerInjectionTest.php       (SC-E12-004 — IDOR prevention)
│   ├── AgentPermissionEnforcementTest.php
│   └── AgentPiiStorageTest.php              (FR-E12-022)
├── Concurrency/Modules/Agent/
│   ├── ConcurrentApprovalTest.php           (CC-E12-001)
│   └── ConcurrentSuspensionTest.php         (CC-E12-002)
└── Integration/Modules/Agent/
    ├── AgentFullOnboardingTest.php           (SC-E12-001 — 5-step flow)
    └── AgentSuspensionEventTest.php          (SC-E12-005 — suspension within 1s)
```
