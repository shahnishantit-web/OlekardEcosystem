# Tasks: E49 â€” Community Services Platform: Horizontal Foundation

**Input**: Design documents from `specs/049-community-services-horizontal/`

**Stack**: Laravel/PHP 8.4+ (API) Â· Next.js/TypeScript (Admin) Â· Flutter/Dart (Customer mobile)
**Schema**: `community_services` PostgreSQL schema Â· UUID v7 IDs Â· Redis/Horizon outbox

**Format**: `[ID] [P?] [Story?] Description â€” file path`
- **[P]** = parallelisable (no dependency on concurrent tasks in same phase)
- **[USn]** = user story label from spec.md

---

## Phase 1: Setup â€” Module Scaffold and Schema Namespace

**Purpose**: Create the CommunityServices module skeleton, register the service provider, establish the `community_services` PostgreSQL schema and configure migration numbering. No user story work begins until this phase is complete.

- [X] T001 Create `CommunityServices` module directory structure (Application/Domain/Infrastructure/Providers/Shared) under `wallet-ecosystem/services/api/app/Modules/CommunityServices/`
- [X] T002 [P] Create `CommunityServicesProvider.php` service provider registering all sub-module providers in `wallet-ecosystem/services/api/app/Modules/CommunityServices/Shared/`
- [X] T003 [P] Register `CommunityServicesProvider` in `wallet-ecosystem/services/api/config/app.php` providers array
- [X] T004 Create migration `2026_10_19_049000_create_community_services_schema.php` creating `community_services` PostgreSQL schema in `wallet-ecosystem/services/api/database/migrations/`
- [X] T005 [P] Create `ScopedToOrganisation` query scope interface in `wallet-ecosystem/services/api/app/Modules/CommunityServices/Shared/ScopedToOrganisation.php`
- [X] T006 [P] Create `CommunityServicesException` base exception and standard error codes enum in `wallet-ecosystem/services/api/app/Modules/CommunityServices/Shared/`
- [X] T007 Create `routes/community-services.php` route file and register it in `wallet-ecosystem/services/api/routes/api.php` under `/v1/community` prefix with auth middleware
- [X] T008 [P] Create admin module directory stubs under `wallet-ecosystem/apps/admin/src/modules/community-services/` (organisations, memberships, orders, refunds, partners, approvals, consent, catalogue sub-folders)

---

## Phase 2: Foundational â€” Core Domain Infrastructure

**Purpose**: Shared infrastructure consumed by every user story â€” domain events outbox, idempotency guard, tenant scope enforcement, and the `community_services` base model. **No user story phase can begin until this phase is complete.**

- [X] T009 Create migration `2026_10_19_049001_create_domain_events_table.php` creating `community_services.domain_events` outbox table (all columns per data-model.md) in `wallet-ecosystem/services/api/database/migrations/`
- [X] T010 Create `DomainEvent` Eloquent model with `community_services.domain_events` table, UUID v7 cast and `$guarded = []` in `wallet-ecosystem/services/api/app/Modules/CommunityServices/Shared/`
- [X] T011 Create `ProcessDomainEventJob` dispatching pending outbox events via Horizon, with idempotent dispatch guard, in `wallet-ecosystem/services/api/app/Modules/CommunityServices/Shared/`
- [X] T012 [P] Create migration `2026_10_19_049002_create_idempotency_records_table.php` for `community_services.idempotency_records` in `wallet-ecosystem/services/api/database/migrations/`
- [X] T013 Create `IdempotencyGuard` service implementing check-and-lock, replay-on-match and payload-conflict-detection logic in `wallet-ecosystem/services/api/app/Modules/CommunityServices/Shared/`
- [X] T014 [P] Create `CommunityServicesBaseModel` abstract Eloquent model applying `ScopedToOrganisation` and UUID v7 primary key cast in `wallet-ecosystem/services/api/app/Modules/CommunityServices/Shared/`
- [X] T015 Create feature test `tests/Feature/CommunityServices/IdempotencyGuardTest.php` verifying: same-key/same-payload replays, same-key/different-payload conflict (409), and concurrent lock behaviour
- [X] T016 Create feature test `tests/Feature/CommunityServices/TenantIsolationTest.php` verifying: cross-org data access returns 403, URL manipulation returns 403, payload injection returns 403

---

## Phase 3: User Story 1 â€” Scoped Organisation Onboarding (Priority: P1) ðŸŽ¯ MVP

**Goal**: Platform Admins can create, verify, approve, suspend and archive organisations. Organisers and payout destinations cannot be used until approved. Tenant isolation enforced at query layer.

**Independent Test**: SC-12 from quickstart.md â€” create two orgs, approve one, attempt cross-org access, confirm 403 and correct status gate behaviour.

### Implementation â€” US1

- [X] T017 [P] [US1] Create migration `2026_10_19_049003_create_organisations_table.php` for `community_services.organisations` (all columns per data-model.md) in `wallet-ecosystem/services/api/database/migrations/`
- [X] T018 [P] [US1] Create migration `2026_10_19_049004_create_organisation_evidence_table.php` for `community_services.organisation_evidence` in `wallet-ecosystem/services/api/database/migrations/`
- [X] T019 [P] [US1] Create migration `2026_10_19_049005_create_payout_destinations_table.php` for `community_services.payout_destinations` in `wallet-ecosystem/services/api/database/migrations/`
- [X] T020 [P] [US1] Create migration `2026_10_19_049006_create_organisation_status_history_table.php` for `community_services.organisation_status_history` in `wallet-ecosystem/services/api/database/migrations/`
- [X] T021 [P] [US1] Create `Organisation` Eloquent model with status state machine, `ScopedToOrganisation` scope, `evidence()` and `payoutDestinations()` relations in `wallet-ecosystem/services/api/app/Modules/CommunityServices/Organisation/Domain/`
- [X] T022 [P] [US1] Create `OrganisationEvidence` and `PayoutDestination` Eloquent models in `wallet-ecosystem/services/api/app/Modules/CommunityServices/Organisation/Domain/`
- [X] T023 [US1] Create `OrganisationService` with `create()`, `transition()`, `updatePayoutDestination()`, `suspend()` and `archive()` methods enforcing state machine rules in `wallet-ecosystem/services/api/app/Modules/CommunityServices/Organisation/Application/`
- [X] T024 [US1] Create `VerificationService` enforcing evidence-required states, payout-destination re-verification on change, and emitting `OrganisationApproved` domain event via outbox in `wallet-ecosystem/services/api/app/Modules/CommunityServices/Organisation/Application/`
- [X] T025 [US1] Create `OrganisationController` with `index`, `store`, `show`, `update`, `transitionStatus`, `updatePayoutDestination` actions (thin controller, delegates to OrganisationService) in `wallet-ecosystem/services/api/app/Modules/CommunityServices/Organisation/`
- [X] T026 [US1] Register organisation routes in `routes/community-services.php`: `GET/POST /v1/community/organisations`, `GET/PATCH /v1/community/organisations/{id}`, `POST /v1/community/organisations/{id}/status`, `PUT /v1/community/organisations/{id}/payout-destination`
- [X] T027 [US1] Implement file upload for organisation evidence: malware-scan hook, size/type validation, private S3/storage path, `OrganisationEvidenceController` returning expiring signed URL in `wallet-ecosystem/services/api/app/Modules/CommunityServices/Organisation/`
- [X] T028 [US1] Create admin Next.js page `wallet-ecosystem/apps/admin/src/modules/community-services/organisations/OrganisationListPage.tsx` with paginated table, status filter chips and status-transition action buttons
- [X] T029 [US1] Create admin Next.js page `wallet-ecosystem/apps/admin/src/modules/community-services/organisations/OrganisationDetailPage.tsx` with verification evidence view (expiring link), status history timeline and payout destination panel
- [X] T030 [US1] Create feature test `tests/Feature/CommunityServices/OrganisationTest.php` covering: create org, full state-machine transitions, payout-destination re-verification gate, cross-org isolation (AT01 SC-01)

### Catalogue Extension â€” US1 (HZ05 Service Catalogue and Discovery)

- [X] T125 [P] [US1] Create migration `2026_10_19_049025_create_service_entitlements_table.php` for `community_services.service_entitlements` (id UUID v7, organisation_id FK, service_type ENUM `funeral_assistance|events_ticketing|raffles_fundraising`, jurisdiction CHAR(2), enabled BOOL DEFAULT false, activation_gate_passed BOOL DEFAULT false, gate_evidence_id UUID nullable, effective_from TIMESTAMPTZ nullable, effective_to TIMESTAMPTZ nullable, created_at, updated_at; UNIQUE `(organisation_id, service_type, jurisdiction)`) in `wallet-ecosystem/services/api/database/migrations/`
- [X] T126 [P] [US1] Create `ServiceEntitlement` Eloquent model applying `ScopedToOrganisation` scope, with `isEnabled()` helper returning `enabled AND activation_gate_passed (for raffles_fundraising)` and `isRaffleGatePassed()` method in `wallet-ecosystem/services/api/app/Modules/CommunityServices/Catalogue/Domain/`
- [X] T127 [US1] Create `ServiceEntitlementService` with `enable()`, `disable()`, `passActivationGate()` (Platform Admin only; requires non-null `gate_evidence_id`) and `isServiceEnabled(organisation_id, service_type, jurisdiction)` methods; `isServiceEnabled()` is the gate called by `OrderService::createOrder()` before allowing a new order in `wallet-ecosystem/services/api/app/Modules/CommunityServices/Catalogue/Application/`
- [X] T128 [US1] Create `CatalogueController` with `discover()` (consumer â€” returns only approved orgs + entitled public offerings, funeral offerings hidden unless `public_campaign_approved = true`), `listEntitlements()` (admin), `upsertEntitlement()` (admin), `passGate()` (Platform Admin) actions; register routes `GET /v1/community/catalogue`, `GET/POST /v1/community/admin/service-entitlements`, `POST /v1/community/admin/service-entitlements/{id}/activate-gate` per `contracts/community-catalogue.yaml` in `wallet-ecosystem/services/api/app/Modules/CommunityServices/Catalogue/`
- [X] T129 [P] [US1] Create admin Next.js page `wallet-ecosystem/apps/admin/src/modules/community-services/catalogue/ServiceEntitlementsPage.tsx` with service type filter, jurisdiction column, activation gate status badge, enable/disable toggle, and gate-evidence-required modal for raffles
- [X] T130 [US1] Update `OrderService::createOrder()` (T036) to call `ServiceEntitlementService::isServiceEnabled()` before creating an order; throw `ServiceNotEnabledForOrganisationException` (HTTP 422) if disabled; add catalogue coverage to `tests/Feature/CommunityServices/OrganisationTest.php`: raffle disabled by default returns 422, gate not passed returns 422, consumer discovery scoped to approved orgs, funeral offering hidden without public-campaign approval

**Checkpoint**: Organisations can be created, approved and isolated. Service catalogue and entitlements are enforced at the API layer â€” raffle activation gate prevents premature use. E50 Funeral community configuration can reference this.

---

## Phase 4: User Story 2 â€” Common Order and Payment Lifecycle (Priority: P1)

**Goal**: Consumers create orders, initiate payments, and receive exactly one financial effect regardless of retries or duplicate callbacks. Idempotency guard, pending-state machine and `PaymentOrchestrator` wrapper all operational.

**Independent Test**: SC-03 and SC-04 from quickstart.md â€” 5 concurrent identical requests produce one order; duplicate callback produces one receipt.

### Implementation â€” US2

- [X] T031 [P] [US2] Create migration `2026_10_19_049007_create_orders_table.php` for `community_services.orders` (all columns, state machine, bigint amounts, idempotency_key unique index) in `wallet-ecosystem/services/api/database/migrations/`
- [X] T032 [P] [US2] Create migration `2026_10_19_049008_create_order_lines_table.php` for `community_services.order_lines` in `wallet-ecosystem/services/api/database/migrations/`
- [X] T033 [P] [US2] Create migration `2026_10_19_049009_create_payment_attempts_table.php` for `community_services.payment_attempts` (no raw card data columns) in `wallet-ecosystem/services/api/database/migrations/`
- [X] T034 [P] [US2] Create `Order` Eloquent model with status state machine enum, `lines()`, `paymentAttempts()` relations, and `grand_total` computed attribute in `wallet-ecosystem/services/api/app/Modules/CommunityServices/Order/Domain/`
- [X] T035 [P] [US2] Create `PaymentAttempt` Eloquent model in `wallet-ecosystem/services/api/app/Modules/CommunityServices/Order/Domain/`
- [X] T036 [US2] Create `OrderService` with `createOrder()` (holds inventory, writes idempotency record), `cancelOrder()` and `processCallback()` methods; `createOrder()` uses `IdempotencyGuard` from Phase 2 in `wallet-ecosystem/services/api/app/Modules/CommunityServices/Order/Application/`
- [X] T037 [US2] Create `PaymentOrchestrator` wrapping the existing `Payment` module's `PaymentGateway` contract; routes payment initiation, queries status and handles callback verification; emits `Payment.Confirmed`/`Payment.Reversed` domain events via outbox; `confirmCapture()` MUST call `ReceiptService::issue()` (T133) inside the same `DB::transaction()` so a receipt is atomically created with the confirmed payment state in `wallet-ecosystem/services/api/app/Modules/CommunityServices/Order/Application/`
- [X] T038 [US2] Create `OrderController` with `store`, `show`, `initiatePayment`, `queryPaymentStatus`, `cancel`, `adminIndex`, `adminEscalate` actions in `wallet-ecosystem/services/api/app/Modules/CommunityServices/Order/`
- [X] T039 [US2] Register order routes in `routes/community-services.php` per `contracts/community-orders.yaml` â€” consumer routes and `/v1/community/admin/orders` routes with scoped auth
- [X] T040 [US2] Create `ProcessWebhookCallbackJob` handling provider success/failure callbacks: verify idempotency on provider reference, transition order state, emit domain event in `wallet-ecosystem/services/api/app/Modules/CommunityServices/Order/`
- [X] T041 [US2] Create admin Next.js page `wallet-ecosystem/apps/admin/src/modules/community-services/orders/OrderListPage.tsx` with search, purpose_type filter, status filter and escalate action
- [X] T042 [US2] Create admin Next.js page `wallet-ecosystem/apps/admin/src/modules/community-services/orders/OrderDetailPage.tsx` showing order lines, payment attempts and status timeline
- [X] T043 [US2] Create Flutter `CommunityOrderScreen` with order summary, grand total display (locale-aware BWP formatting), payment method selection and pending-state safe-refresh in `wallet-ecosystem/apps/customer/lib/modules/community_order/`
- [X] T044 [US2] Create feature test `tests/Feature/CommunityServices/OrderTest.php` covering: concurrent identical creates (SC-03), duplicate callback (SC-04), timeout stays pending, idempotency key conflict (409), timeout-no-entitlement invariant

### Receipt Extension â€” US2 (HZ07 Receipts and Transaction History)

- [X] T131 [P] [US2] Create migration `2026_10_19_049026_create_receipts_table.php` for `community_services.receipts` (id UUID v7, order_id FK, organisation_id FK, external_ref VARCHAR UNIQUE â€” opaque non-guessable ref for consumer enumeration prevention, purpose_type, purpose_description, currency CHAR(3), amount BIGINT CHECK(amount > 0), payer_person_id UUID, provider_ref_masked VARCHAR nullable, issued_at TIMESTAMPTZ NOT NULL; no `updated_at` â€” immutable after insert) in `wallet-ecosystem/services/api/database/migrations/`
- [X] T132 [P] [US2] Create `Receipt` Eloquent model with immutability guard (`static::updating()` and `static::deleting()` hooks throw `ReceiptImmutableException`), UUID v7 cast and `ScopedToOrganisation` scope in `wallet-ecosystem/services/api/app/Modules/CommunityServices/Receipt/Domain/`
- [X] T133 [US2] Create `ReceiptService` with `issue(Order $order, string $providerRef): Receipt` (generates UUID v7 `external_ref`, called inside `PaymentOrchestrator::confirmCapture()` DB transaction â€” idempotent: returns existing receipt if `order_id` already has one) and `getForConsumer(string $externalRef, string $personId): Receipt` (returns 404 if `external_ref` exists but `payer_person_id` differs â€” prevents enumeration) in `wallet-ecosystem/services/api/app/Modules/CommunityServices/Receipt/Application/`
- [X] T134 [US2] Create `ReceiptController` with `index()` (consumer â€” cursor-paginated own receipts only, filtered by `payer_person_id` from auth context) and `showByRef()` (consumer â€” by `external_ref` path param, NOT internal UUID) actions; register consumer routes `GET /v1/community/receipts`, `GET /v1/community/receipts/{ref}` and admin routes `GET /v1/community/admin/receipts` per `contracts/community-receipts.yaml`; admin `index()` applies field masking (`payer_person_id` â†’ masked ref) and writes audit event in `wallet-ecosystem/services/api/app/Modules/CommunityServices/Receipt/`
- [X] T135 [US2] Add receipt tests to `tests/Feature/CommunityServices/OrderTest.php`: receipt issued atomically with payment confirmation, idempotent on duplicate callback (SC-04 â€” exactly one receipt row), consumer guessing another consumer's `external_ref` returns 404, receipt immutable after issuance (update/delete throw 405), admin masked search creates audit log entry

**Checkpoint**: Orders, payments and receipts are idempotent and enumeration-proof. Consumer receipt retrieval is scoped by identity. Downstream epics (E50 contributions, E51 tickets) can reuse this order and receipt lifecycle.

---

## Phase 5: User Story 3 â€” Staff Invitation and MFA Enforcement (Priority: P1)

**Goal**: Organisers invite staff to scoped roles. Gate Operators are denied all finance endpoints. MFA enforced for admin users. Revoked staff lose access on next request.

**Independent Test**: SC-02 (Gate Operator denied finance endpoints), SC-07 (expired invitation fails), SC-11 (revoked staff loses access) from quickstart.md.

### Implementation â€” US3

- [X] T045 [P] [US3] Create migration `2026_10_19_049010_create_staff_memberships_table.php` for `community_services.staff_memberships` with unique constraint `(person_id, organisation_id, role)` in `wallet-ecosystem/services/api/database/migrations/`
- [X] T046 [P] [US3] Create migration `2026_10_19_049011_create_staff_invitations_table.php` for `community_services.staff_invitations` with unique `token_hash` in `wallet-ecosystem/services/api/database/migrations/`
- [X] T047 [P] [US3] Create `StaffMembership` Eloquent model with role enum, scope fields and status state machine in `wallet-ecosystem/services/api/app/Modules/CommunityServices/Membership/Domain/`
- [X] T048 [P] [US3] Create `StaffInvitation` Eloquent model with expiry check method in `wallet-ecosystem/services/api/app/Modules/CommunityServices/Membership/Domain/`
- [X] T049 [US3] Create `MembershipService` with `invite()`, `acceptInvitation()`, `updateMembership()` and `revokeAccess()` in `wallet-ecosystem/services/api/app/Modules/CommunityServices/Membership/Application/`; `invite()` hashes token, sets expiry; `revokeAccess()` calls existing E01 session revocation contract
- [X] T050 [US3] Create `InvitationService` validating token hash, expiry and single-use status; marks invitation as `accepted` atomically in `wallet-ecosystem/services/api/app/Modules/CommunityServices/Membership/Application/`
- [X] T051 [US3] Create `StaffMembershipController` with `index`, `store` (invite), `update`, `accept` actions; `store` enforces Organiser Admin scope in `wallet-ecosystem/services/api/app/Modules/CommunityServices/Membership/`
- [X] T052 [US3] Create `GateOperatorPolicy` extending base auth policy; deny `finance.*`, `ledger.*`, `export.*` and `participant.search` permissions for `gate_operator` role in `wallet-ecosystem/services/api/app/Modules/CommunityServices/Membership/`
- [X] T053 [US3] Register membership routes in `routes/community-services.php` per `contracts/community-membership.yaml`
- [X] T054 [US3] Create admin Next.js page `wallet-ecosystem/apps/admin/src/modules/community-services/memberships/StaffListPage.tsx` with role filter, status badges and revoke action
- [X] T055 [US3] Create feature test `tests/Feature/CommunityServices/MembershipTest.php` covering: invite, accept, expiry (SC-07), duplicate acceptance rejected, revocation (SC-11), Gate Operator finance denial (SC-02), cross-org membership isolation

**Checkpoint**: Staff access control is live. E50 Community Representatives and E51 Gate Operators can be onboarded.

---

## Phase 6: User Story 4 â€” Consent Lifecycle and Data Rights (Priority: P2)

**Goal**: Consumers manage consent purposes independently. Marketing withdrawal propagates immediately. Data rights requests (DSAR) are trackable and auditable. Cross-organiser sharing requires explicit approved purpose.

**Independent Test**: SC-08 from quickstart.md â€” withdraw marketing consent, confirm `ConsentService::hasConsent()` returns false for `marketing`, confirm no marketing jobs dispatched.

### Implementation â€” US4

- [X] T056 [P] [US4] Create migration `2026_10_19_049012_create_consent_notices_table.php` for `community_services.consent_notices` (versioned, locale-aware) in `wallet-ecosystem/services/api/database/migrations/`
- [X] T057 [P] [US4] Create migration `2026_10_19_049013_create_consent_records_table.php` for `community_services.consent_records` (append-only, no updates/deletes) in `wallet-ecosystem/services/api/database/migrations/`
- [X] T058 [P] [US4] Create migration `2026_10_19_049014_create_data_rights_requests_table.php` for `community_services.data_rights_requests` in `wallet-ecosystem/services/api/database/migrations/`
- [X] T059 [P] [US4] Create `ConsentNotice` and `ConsentRecord` Eloquent models in `wallet-ecosystem/services/api/app/Modules/CommunityServices/Consent/Domain/`; `ConsentRecord` model must not implement `update()` or `delete()`
- [X] T060 [US4] Create `ConsentService` with `grant()`, `withdraw()`, `hasConsent()` and `getConsentState()` methods; `withdraw()` emits `ConsentWithdrawn` domain event via outbox to allow notification job filter to exclude consumer in `wallet-ecosystem/services/api/app/Modules/CommunityServices/Consent/Application/`
- [X] T061 [US4] Create `DataRightsService` with `submit()`, `list()`, `process()` and `complete()` methods enforcing access-control on person scope in `wallet-ecosystem/services/api/app/Modules/CommunityServices/Consent/Application/`
- [X] T062 [US4] Create `ConsentController` and `DataRightsController` with routes per `contracts/community-consent.yaml` in `wallet-ecosystem/services/api/app/Modules/CommunityServices/Consent/`
- [X] T063 [US4] Create admin Next.js page `wallet-ecosystem/apps/admin/src/modules/community-services/consent/ConsentNoticesPage.tsx` for managing versioned notices per purpose with effective date
- [X] T064 [US4] Create Flutter `CommunityConsentScreen` showing purpose toggles for optional consent (marketing, sponsor_sharing); operational consent displayed read-only in `wallet-ecosystem/apps/customer/lib/modules/community_consent/`
- [X] T065 [US4] Create feature test `tests/Feature/CommunityServices/ConsentTest.php` covering: grant/withdraw cycle (SC-08), operational consent immutable by consumer, cross-organiser sharing blocked without purpose, DSAR submission and status lifecycle

---

## Phase 7: User Story 5 â€” Refund and Dispute Handling (Priority: P2)

**Goal**: Refunds cannot exceed captured amount under concurrent requests. Dispute holds block payouts. Failed refunds display as failed. Finance Approver cannot self-approve.

**Independent Test**: SC-05 (race condition) and SC-06 (self-approval) from quickstart.md.

### Implementation â€” US5

- [X] T066 [P] [US5] Create migration `2026_10_19_049015_create_refund_requests_table.php` for `community_services.refund_requests` with DB-level `CHECK (approved_by != requested_by)` constraint in `wallet-ecosystem/services/api/database/migrations/`
- [X] T067 [P] [US5] Create migration `2026_10_19_049016_create_dispute_holds_table.php` for `community_services.dispute_holds` in `wallet-ecosystem/services/api/database/migrations/`
- [X] T068 [P] [US5] Create migration `2026_10_19_049017_create_refund_policies_table.php` for `community_services.refund_policies` with consumer-visible terms in `wallet-ecosystem/services/api/database/migrations/`
- [X] T069 [P] [US5] Create `RefundRequest` and `DisputeHold` Eloquent models in `wallet-ecosystem/services/api/app/Modules/CommunityServices/Refund/Domain/`
- [X] T070 [US5] Create `RefundService` with `request()`, `approve()`, `reject()`, `processProvider()` methods; `approve()` uses `SELECT FOR UPDATE` on order to serialise concurrent refunds and enforce cumulative cap; enforces `approved_by != requested_by` in `wallet-ecosystem/services/api/app/Modules/CommunityServices/Refund/Application/`
- [X] T071 [US5] Create `DisputeService` with `hold()`, `resolve()`, `release()` methods; `hold()` blocks any payout on the affected order; uses `IdempotencyGuard` for hold creation in `wallet-ecosystem/services/api/app/Modules/CommunityServices/Refund/Application/`
- [X] T072 [US5] Create `RefundController` and `DisputeController` with routes per `contracts/community-refunds.yaml` in `wallet-ecosystem/services/api/app/Modules/CommunityServices/Refund/`; refund `approve()` requires `Finance Approver` role
- [X] T073 [US5] Create admin Next.js page `wallet-ecosystem/apps/admin/src/modules/community-services/refunds/RefundQueuePage.tsx` with status filters, approve/reject actions and self-approval guard (disable approve if viewer is requester)
- [X] T074 [US5] Create Flutter consumer refund request widget in `wallet-ecosystem/apps/customer/lib/modules/community_receipt/` showing refund policy before payment confirmation and tracking refund status
- [X] T075 [US5] Create feature test `tests/Feature/CommunityServices/RefundTest.php` covering: concurrent over-refund race (SC-05), self-approval rejection (SC-06), failed refund displays failed, dispute hold blocks payout, compensating ledger entry posted

**Checkpoint**: Financial integrity layer complete â€” concurrent financial operations are safe.

---

## Phase 8: User Story 6 â€” Asynchronous Reports and Exports (Priority: P2)

**Goal**: Finance-team exports are scoped, asynchronous, expiring and formula-injection-safe. Dashboard figures trace to ledger records with a refresh timestamp.

**Independent Test**: SC-10 from quickstart.md â€” export CSV containing a `=SUM(...)` description; confirm cell is escaped.

### Implementation â€” US6

- [X] T076 [P] [US6] Create migration `2026_10_19_049018_create_export_jobs_table.php` for `community_services.export_jobs` (job_id, organisation_id, status, download_url, expires_at, requested_by, created_at) in `wallet-ecosystem/services/api/database/migrations/`
- [X] T077 [US6] Create `ReportService` with `requestExport()`, `getExportStatus()` and `generateCsvAsync()` methods; `generateCsvAsync()` is a Horizon job; export scoped by `organisation_id` from auth context; applies formula-injection escaping (`= + - @` prefix escape) in `wallet-ecosystem/services/api/app/Modules/CommunityServices/Organisation/Application/`
- [X] T078 [US6] Create `ExportDownloadController` serving expiring signed URLs; validates download token has not expired and belongs to requesting user's organisation scope; writes audit event on each download in `wallet-ecosystem/services/api/app/Modules/CommunityServices/Organisation/`
- [X] T079 [US6] Register export routes in `routes/community-services.php` per `contracts/community-receipts.yaml`: `POST /v1/community/admin/receipts/export`, `GET /v1/community/admin/exports/{job_id}/download`
- [X] T080 [US6] Create admin Next.js page `wallet-ecosystem/apps/admin/src/modules/community-services/receipts/ExportPage.tsx` with date-range selector, organisation filter, job status polling and expiring download link
- [X] T081 [US6] Create dashboard query in `ReportService::getDashboardSummary()` returning gross, refunds, fees, net, liabilities, attendance and disbursements separately with `last_refreshed_at` timestamp; values derive from `community_services.receipts` and `community_services.refund_requests` (ledger-linked)
- [X] T082 [US6] Create feature test `tests/Feature/CommunityServices/ReportExportTest.php` covering: cross-org export isolation, CSV formula-injection escaping (SC-10), expired download link returns 401, dashboard totals reconcile to underlying records, pending/reversed excluded from received totals

---

## Phase 9: User Story 7 â€” Notifications with Outbox Reliability (Priority: P2)

**Goal**: All transactional notifications are dispatched through the `community_services.domain_events` outbox. Delivery failure does not roll back confirmed payments. Duplicate jobs produce one notification. Invalid tokens are retired.

**Independent Test**: Confirm a payment, take the notification service offline, confirm payment remains confirmed, bring service back online and confirm notification dispatches without duplicate.

### Implementation â€” US7

- [X] T083 [US7] Create `CommunityNotificationService` consuming `Payment.Confirmed`, `Contribution.Recorded`, `Ticket.Issued`, `Collection.Closed`, `Payout.Settled` domain events from the outbox and enqueuing into existing E17 notification jobs in `wallet-ecosystem/services/api/app/Modules/CommunityServices/Shared/`
- [X] T084 [US7] Add `source_module` discriminator column to the existing `notifications` table via migration `2026_10_19_049019_add_source_module_to_notifications.php` to support `community_services` filter on E17 notification queries
- [X] T085 [US7] Create `DeduplicateCommunityNotificationJob` using `deduplication_key = sha256(event_id + channel)` before dispatch; retire permanently-failed push device tokens via E17 `DeviceTokenService` in `wallet-ecosystem/services/api/app/Modules/CommunityServices/Shared/`
- [X] T086 [US7] Register `ConsentWithdrawn` domain event listener in `CommunityServicesProvider` to synchronously exclude consumer from marketing notification queries (no async gap)
- [X] T087 [US7] Create Flutter `CommunityInboxScreen` in `wallet-ecosystem/apps/customer/lib/modules/community_home/` showing in-app notifications; navigates securely to relevant record (receipt, ticket, case) via deep link; shows payment/ticket status even when push delivery fails
- [X] T088 [US7] Create unit test `tests/Unit/CommunityServices/CommunityNotificationServiceTest.php` covering: duplicate job deduplication, delivery failure does not revert confirmed payment, invalid token retired, contributed household receives no outstanding reminders, notification payload contains no sensitive financial data

---

## Phase 10: User Story 8 â€” Audit and Support Case Lifecycle (Priority: P2)

**Goal**: Support cases are linked to transactions/tickets and retain full conversation history. Audit records are append-only and deletion-proof. Support Officers cannot access raw payment data.

**Independent Test**: Create a case, reassign it, resolve it â€” full history preserved. Attempt to delete an audit record â€” rejected.

### Implementation â€” US8

- [X] T089 [US8] Verify existing E22 `Support` module exposes a `SupportCaseService` interface; create `CommunitySupportAdapter` in `wallet-ecosystem/services/api/app/Modules/CommunityServices/Shared/` wrapping `SupportCaseService` and adding `organisation_id` scope to all case queries
- [X] T090 [US8] Verify existing E23 `Audit` module exposes an `AuditLogger` interface; create `CommunityAuditService` in `wallet-ecosystem/services/api/app/Modules/CommunityServices/Shared/` that calls `AuditLogger` for all CommunityServices financial, permission and configuration changes, with `CommunityServices` as the module scope
- [X] T091 [US8] Implement `CommunityAuditService::logEvent()` with required fields: actor, organisation_scope, action, target_entity, before_summary, after_summary, reason, UTC timestamp, correlation_id â€” with a compile-time check that secrets/PII are not in the `before_summary`/`after_summary` fields
- [X] T092 [US8] Create `SupportCaseController` under community-services routes allowing consumers to `POST /v1/community/cases` (linked to order_id, entitlement_id, etc.), `GET /v1/community/cases/{id}` and `POST /v1/community/cases/{id}/evidence`; delegate to `CommunitySupportAdapter`
- [X] T093 [US8] Add document malware-scan middleware to evidence upload routes; evidence files stored with private ACL, served via expiring signed URLs; max file size and allowed MIME types configured in `config/community_services.php`
- [X] T094 [US8] Create Flutter `CommunitySupportScreen` in `wallet-ecosystem/apps/customer/lib/modules/community_support/` allowing consumer to raise case from receipt or ticket detail, attach evidence, and track case status
- [X] T095 [US8] Create feature test `tests/Feature/CommunityServices/AuditSupportTest.php` covering: audit record immutability (delete/update rejected), Support Officer cannot view unmasked payment data, case retains full conversation history, evidence served via expiring link only

---

## Phase 11: User Story 9 â€” Partner Connector Isolation (Priority: P3)

**Goal**: A second partner adapter can be added through configuration and a new adapter class without modifying core domain code. Webhook signature verification rejects tampered payloads before any processing.

**Independent Test**: SC-09 from quickstart.md â€” send webhook with invalid signature; confirm 400 response and no receipt row created.

### Implementation â€” US9

- [X] T096 [P] [US9] Create migration `2026_10_19_049020_create_partner_connectors_table.php` for `community_services.partner_connectors` (credential_vault_path â€” no actual secrets) in `wallet-ecosystem/services/api/database/migrations/`
- [X] T097 [P] [US9] Create migration `2026_10_19_049021_create_webhook_receipts_table.php` for `community_services.webhook_receipts` with unique `(connector_id, provider_event_id)` in `wallet-ecosystem/services/api/database/migrations/`
- [X] T098 [P] [US9] Create `PartnerConnector` Eloquent model with `capability_manifest` JSON cast in `wallet-ecosystem/services/api/app/Modules/CommunityServices/Partner/Domain/`
- [X] T099 [US9] Create `PartnerAdapterContract` interface defining `createIntent()`, `queryStatus()`, `confirmCapture()`, `initiateRefund()`, `getCapabilities()` in `wallet-ecosystem/services/api/app/Modules/CommunityServices/Partner/Domain/`
- [X] T100 [US9] Create `StubPartnerAdapter` (sandbox-only) implementing `PartnerAdapterContract` for use in dev/test environments; no production credentials; demonstrates that a new adapter requires zero changes to core domain in `wallet-ecosystem/services/api/app/Modules/CommunityServices/Partner/Infrastructure/`
- [X] T101 [US9] Create `PartnerConnectorService` with `register()`, `disable()`, `rotateSecret()` and `resolveAdapter()` methods; `resolveAdapter()` instantiates adapter by `adapter_class` from `partner_connectors` table, never hardcoded in `wallet-ecosystem/services/api/app/Modules/CommunityServices/Partner/Application/`
- [X] T102 [US9] Create `WebhookIngressService` with HMAC-SHA256 signature verification, timestamp freshness check (configurable window), `WebhookReceipt` deduplication write and async dispatch in `wallet-ecosystem/services/api/app/Modules/CommunityServices/Partner/Application/`
- [X] T103 [US9] Create `PartnerConnectorController` and `WebhookController` with routes per `contracts/community-partners.yaml`; webhook route bypasses CSRF, applies signature-verify middleware before any auth
- [X] T104 [US9] Create admin Next.js page `wallet-ecosystem/apps/admin/src/modules/community-services/partners/PartnerConnectorListPage.tsx` with health status indicators, sandbox badge, enable/disable toggle and disable-reason modal
- [X] T105 [US9] Create feature test `tests/Feature/CommunityServices/PartnerConnectorTest.php` covering: invalid webhook signature (SC-09), stale timestamp rejected, duplicate webhook receipt deduplicated, `StubPartnerAdapter` processes order lifecycle without core changes, unsupported capability fails explicitly

---

## Phase 12: User Story 10 â€” Approvals, Policy Versioning and Maker-Checker (Priority: P3)

**Goal**: All high-risk financial actions require independent approval. Self-approval is rejected at application and DB layers. Concurrent approvals serialised. Policy versions immutably linked to purchases.

**Independent Test**: Submit an approval as user X; have X attempt self-approval â€” 403 returned. Approve and edit the subject; confirm it returns to `pending_review`.

### Implementation â€” US10

- [X] T106 [P] [US10] Create migration `2026_10_19_049022_create_approval_requests_table.php` for `community_services.approval_requests` with `CHECK (approved_by != submitted_by)` DB constraint and row-level lock note in `wallet-ecosystem/services/api/database/migrations/`
- [X] T107 [P] [US10] Create migration `2026_10_19_049023_create_policy_versions_table.php` for `community_services.policy_versions` in `wallet-ecosystem/services/api/database/migrations/`
- [X] T108 [P] [US10] Create migration `2026_10_19_049024_create_purchase_policy_links_table.php` for `community_services.purchase_policy_links` (immutable, no update/delete) in `wallet-ecosystem/services/api/database/migrations/`
- [X] T109 [P] [US10] Create `ApprovalRequest` and `PolicyVersion` Eloquent models in `wallet-ecosystem/services/api/app/Modules/CommunityServices/Approval/Domain/`; `ApprovalRequest` must not allow direct `approved_by` write without going through `ApprovalService`
- [X] T110 [US10] Create `ApprovalService` with `submit()`, `approve()`, `reject()`, `cancel()` methods; `approve()` uses `DB::transaction()` with `lockForUpdate()` on the approval row to serialise concurrent approvals; returns early (idempotent) if already decided with same actor in `wallet-ecosystem/services/api/app/Modules/CommunityServices/Approval/Application/`
- [X] T111 [US10] Create `PolicyVersionService` with `create()`, `getActiveVersion()` and `linkToPurchase()` methods; `linkToPurchase()` called during `OrderService::createOrder()` to snapshot the policy version at time of purchase in `wallet-ecosystem/services/api/app/Modules/CommunityServices/Approval/Application/`
- [X] T112 [US10] Wire `ApprovalService` into `RefundService::approve()` (from Phase 7) and `VerificationService::approvePayoutDestination()` (from Phase 3) â€” both must call `ApprovalService` for amounts above configured threshold
- [X] T113 [US10] Create `ApprovalController` with routes per `contracts/community-approvals.yaml`; `approve` action requires `Finance Approver` role; includes `Idempotency-Key` requirement
- [X] T114 [US10] Create admin Next.js page `wallet-ecosystem/apps/admin/src/modules/community-services/approvals/ApprovalInboxPage.tsx` with type/status filters, subject entity detail panel, approve/reject actions with reason text, and policy version display
- [X] T115 [US10] Create feature test `tests/Feature/CommunityServices/ApprovalTest.php` covering: self-approval rejection (SC-06), concurrent approval serialisation (one execution), edit-after-approval returns to review, policy version linked to purchase and unchanged by later policy update, emergency suspension creates audit event with expiry

**Checkpoint**: Full maker-checker safety net in place. E50/E51/E52 payout and high-value refund flows can use `ApprovalService`.

---

## Phase 13: Polish and Cross-Cutting Concerns

**Purpose**: Static analysis, OpenAPI documentation, accessibility audit, seeder and final acceptance-test run.

- [X] T116 [P] Run PHPStan level 8 across `app/Modules/CommunityServices/` and fix any errors: `./vendor/bin/phpstan analyse app/Modules/CommunityServices --level=8`
- [X] T117 [P] Write `CommunityServicesSeeder` creating two test organisations (one approved, one pending), two staff roles, a consumer with active membership, and a test order in `wallet-ecosystem/services/api/database/seeders/`
- [X] T118 Create `openapi/v1/community-services.yaml` master OpenAPI file referencing all contract fragment files from `specs/049-community-services-horizontal/contracts/` in `wallet-ecosystem/services/api/openapi/v1/`
- [X] T119 [P] Run axe-core accessibility audit on all new admin Next.js pages; fix any WCAG 2.2 AA violations; confirm ARIA labels on all action buttons and form fields in `wallet-ecosystem/apps/admin/`
- [X] T120 [P] Run `flutter analyze` on all new Flutter modules; fix any warnings; verify bottom navigation has accessible labels and correct screen-reader announcements in `wallet-ecosystem/apps/customer/`
- [X] T121 Create `config/community_services.php` configuration file with all configurable values: invitation expiry (hours), webhook freshness window (seconds), export link expiry (hours), malware-scan enabled flag, formula-injection escape char, step-up threshold reference in `wallet-ecosystem/services/api/config/`
- [X] T122 [P] Add structured logging and Horizon metric tags to `PaymentOrchestrator`, `RefundService` and `ApprovalService`; verify correlation IDs propagate through outbox events
- [ ] T123 Run full quickstart.md validation (SC-01 through SC-12) against the local Docker environment and record pass/fail for each scenario
- [X] T124 Update `specs/000-master-roadmap/spec.md` to add E49 row (status: Complete/Release Ready) and E50/E51/E52 rows (status: Planned) to the roadmap table

---

## Dependencies and Execution Order

### Phase Dependencies

- **Phase 1 (Setup)**: No dependencies â€” start immediately
- **Phase 2 (Foundational)**: Depends on Phase 1 â€” **BLOCKS all user story phases**
- **Phase 3 (US1 Org + Catalogue)**: Depends on Phase 2; catalogue extension (T125â€“T130) must complete before Phase 4
- **Phase 4 (US2 Order + Receipt)**: Depends on Phase 2 **and** Phase 3 catalogue extension (T127 `ServiceEntitlementService` must exist before T036 `OrderService` can call `isServiceEnabled()`); receipt extension (T131â€“T135) runs within Phase 4
- **Phase 5 (US3 Membership)**: Depends on Phase 2; may run in parallel with Phases 3â€“4
- **Phase 6 (US4 Consent)**: Depends on Phase 2; may run in parallel with Phases 3â€“5
- **Phase 7 (US5 Refund)**: Depends on Phase 4 (Order); may run in parallel with Phases 5â€“6
- **Phase 8 (US6 Reports)**: Depends on Phase 4 (Order) and Phase 7 (Refund)
- **Phase 9 (US7 Notifications)**: Depends on Phase 2 (outbox); may run in parallel with Phases 3â€“8
- **Phase 10 (US8 Audit/Support)**: Depends on Phase 2; may run in parallel with Phases 3â€“9
- **Phase 11 (US9 Partners)**: Depends on Phase 4 (Order, PaymentOrchestrator)
- **Phase 12 (US10 Approvals)**: Depends on Phase 4 (Order) and Phase 7 (Refund)
- **Phase 13 (Polish)**: Depends on all prior phases

### User Story Dependencies

| Story | Depends on |
|---|---|
| US1 Organisation Onboarding | Phase 2 (Foundation) |
| US2 Order/Payment | Phase 2 (Foundation) |
| US3 Staff/MFA | Phase 2 + US1 (org must exist) |
| US4 Consent | Phase 2 |
| US5 Refunds | US2 (orders must exist) |
| US6 Reports | US2 + US5 (orders and refunds) |
| US7 Notifications | Phase 2 (outbox); US2 domain events |
| US8 Audit/Support | Phase 2; reuses E22/E23 adapters |
| US9 Partners | US2 (PaymentOrchestrator wraps adapters) |
| US10 Approvals | US2 (OrderService) + US5 (RefundService) |

### Within Each Story

- Migrations â†’ Models â†’ Services â†’ Controllers â†’ Routes â†’ Admin/Flutter UI
- Feature tests written alongside each service (not after the fact)

### Parallel Opportunities

```
# Phase 1 â€” all tasks parallelisable:
T002 (Provider) || T003 (Routes) || T005 (ScopedToOrganisation) || T006 (Exception) || T008 (Admin stubs)

# Phase 2 â€” foundational tasks parallel after T009/T012 migrations:
T010 (DomainEvent model) || T011 (ProcessDomainEventJob) || T013 (IdempotencyGuard) || T014 (BaseModel)

# Phase 3 (US1) â€” migrations parallel:
T017 || T018 || T019 || T020  â†’  T021 || T022  â†’  T023  â†’  T024  â†’  T025

# Phase 4 (US2) â€” migrations parallel:
T031 || T032 || T033  â†’  T034 || T035  â†’  T036  â†’  T037  â†’  T038

# Phases 3, 5, 6, 9, 10 can all run in parallel once Phase 2 is complete
```

---

## Parallel Example: User Story 1 (Organisation Onboarding)

```bash
# Step 1 â€” run all 4 migrations in parallel:
Task T017  # organisations table
Task T018  # organisation_evidence table
Task T019  # payout_destinations table
Task T020  # organisation_status_history table

# Step 2 â€” run both models in parallel (after migrations):
Task T021  # Organisation model
Task T022  # OrganisationEvidence + PayoutDestination models

# Step 3 â€” sequential service and controller:
Task T023  # OrganisationService
Task T024  # VerificationService
Task T025  # OrganisationController
Task T026  # Route registration

# Step 4 â€” admin UI and tests in parallel:
Task T027  # Evidence upload
Task T028  # OrganisationListPage
Task T029  # OrganisationDetailPage
Task T030  # Feature tests
```

---

## Implementation Strategy

### MVP First (US1 + US2 + US3 â€” P1 Stories Only)

1. Complete Phase 1 (Setup) â€” T001â€“T008
2. Complete Phase 2 (Foundational) â€” T009â€“T016
3. Complete Phase 3 (US1 Org Onboarding) â€” T017â€“T030
4. Complete Phase 4 (US2 Order/Payment) â€” T031â€“T044
5. Complete Phase 5 (US3 Membership) â€” T045â€“T055
6. **STOP and VALIDATE**: run SC-01 through SC-04, SC-07, SC-11, SC-12 from quickstart.md
7. **E49 MVP is deployable** â€” enough for E50 to start Community Funeral Assistance

### Incremental Delivery

- P1 stories â†’ E50/E51 can start their foundation work
- P2 stories â†’ complete financial safety net; E50 payout, E51 refunds safe to implement
- P3 stories â†’ governance and compliance controls; E52 Raffles activation gate available
- Polish phase â†’ PHPStan clean, full quickstart pass, roadmap updated

### Parallel Team Strategy

With 3 developers after Phase 2:

- **Dev A**: US1 (Org) + US3 (Membership) â€” identity and access
- **Dev B**: US2 (Order) + US5 (Refund) + US10 (Approvals) â€” financial core
- **Dev C**: US4 (Consent) + US7 (Notifications) + US8 (Audit/Support) â€” platform controls

---

## Notes

- All monetary amounts: `bigint` minor units; `currency char(3)` â€” never `decimal`/`float`
- All new PHP code must pass `phpstan --level=8` before task completion
- `[P]` tasks operate on different files; safe to run concurrently
- `[USn]` label maps each task to a user story for traceability
- Each story phase ends with a **Checkpoint** â€” validate independently before proceeding
- E50 (Funeral), E51 (Events), E52 (Raffles) depend on E49 being complete; this tasks.md is the gate
