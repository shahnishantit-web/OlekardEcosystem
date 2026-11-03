# P25 Scoped Authorization Dependency Assessment

**Status: proposal only; no Identity work package started.** P25 remains PARTIAL. This document records the module boundary assessment and a bounded Identity/E04 extension in case Identity owners require the scope grant mechanism to live there.

## Ownership finding

Identity/E04 owns authentication, the global permission catalogue, role-permission assignments, global actor `hasPermission(actor, permission)` checks and organisation membership identity. The current permission resolver and middleware do not evaluate organisation/event/gate scope. E04 explicitly excludes row-level/ABAC authorization and says later modules extend the permission catalogue through their own idempotent seed migrations.

Events/P25 owns event and gate resources, event workflow and admission; LC07/section 2 places admission in Events. The architecture uses one schema/connection per module, prohibits cross-schema joins, and keeps resource state with its owning module. On that basis, the proposed P25 design is: Identity checks the coarse `events.*` role permission; Events owns and checks scoped event/gate assignments in `community_services`, validating event-to-organisation and gate-to-event relationships locally. This does not require changing Identity's existing `hasPermission` contract. Exact action mappings and assignment grant/revoke authority still need approval, so no P25 scoped grants or endpoints can be enabled yet.

## Conditional bounded change if Identity must own scoped grants

### Existing capability and exact missing behavior

- Existing `IdentityModuleContract::hasPermission(string $actorId, string $permission): bool` returns a global role-derived permission. `PermissionResolverService` reads global `identity.user_roles`/`identity.role_permissions`, caches the actor's permission set (default TTL 300 seconds), and Identity JWTs carry global permission claims.
- Existing `OrganisationMembershipAuthority::assertActiveMembership(actor, org)` confirms active actor/org/membership. It does not authorize an action or bind event/gate scope.
- Missing behavior: check a global Events permission together with an active grant scoped to the exact org, event or gate; grant/revoke authority; immediate revocation behavior; auditable scope lifecycle; no parent-scope inheritance; safe integration with concurrent gate scans.

### Owner and contracts affected

- Owner: Identity module / E04 Roles, Permissions & Authorization. E04 is currently `Specified` in `specs/004-roles-permissions-authorization`; its approved scope explicitly excludes fine-grained row-level/ABAC scope.
- Affected Identity contracts: `IdentityModuleContract`, possibly a new generic `ScopedPermissionAuthority`/scope value object; `PermissionResolverService` only if required, without putting scope grants into JWT claims or the existing global permission cache.
- P25 consumers: `EventDraftAuthorizer`, `EventWorkflowAuthorizer`, gate assignment/validation services. Events must still validate event/gate ownership locally and must not make cross-schema joins or trust tenant/event IDs from the client.
- Change process: create a separate E04 scoped-authorization extension with installed Spec Kit specify/plan/tasks, architecture/security review and Identity tests. Do not edit the E04 package or add Identity migrations until explicitly authorized.

### Proposed grant model (requires Identity/Security approval)

An explicit grant tuple would contain an actor, global permission ID, scope kind (`organisation`, `event`, `gate`), organisation ID and optional event/gate IDs, grantor, grant time, state/revoker/time and optimistic revision. Resource IDs remain opaque in Identity; the owning Events service validates resource relationships. Scope matching is exact: organisation grants do not silently authorize event actions; event grants do not silently authorize gate actions. The only proposed delegation exception is Events' `events.gate.assignment.manage`, which can delegate only `events.gate.validate` for gates belonging to that event. A global role or organisation membership alone never authorizes an Events action. No default `admin` role grants are proposed.

This model is not selected. The current P25 assessment instead recommends Events-owned resource assignments to preserve module ownership and same-transaction gate revocation. Identity owners may reject that boundary; if so, the cross-module concurrency contract below must be designed before implementation.

### Grant/revoke authority and revocation

Proposed central grant/revoke authority: a specifically designated Identity/Security administrator with separate proposed `identity.scoped_permission.grant` and `identity.scoped_permission.revoke` capabilities; do not grant these by default to the global `admin` role. P25's recorded gate rule delegates only gate operator assignment to an authorised organiser, limited to gate validation for that organiser's event. Events must be able to revoke that gate assignment so the next online scan denies. Maker/checker for ordinary scoped grants is not decided.

Scope lookups must not be cached in JWT claims or the actor-wide permission cache. A revocation must invalidate/deny on the next authorization attempt. Gate scan and assignment revocation must follow the recorded commit-order rule. Because Identity and Events use separate named DB connections and cross-schema joins are prohibited, an Identity-owned grant check alone does not serialize a grant revocation with an Events scan commit. Before selecting Identity ownership, Identity and Events must design either a lock/lease protocol that holds the scoped grant stable through the Events commit, or approve Events as the authoritative gate-assignment owner. Do not claim commit-order protection without a two-transaction race test.

### Migrations and backward compatibility

If Identity owns grants, add an additive Identity migration for grant/revocation state and immutable audit provenance (or an approved append-only audit pattern). Keep existing `identity.roles`, `permissions`, `user_roles`, `role_permissions`, `IdentityModuleContract::hasPermission`, middleware and existing APIs behavior unchanged. Add any new `events.*` permission catalog entries with no role assignment by default, only after P25 mapping approval. Do not copy event/gate business rows into Identity or add cross-schema foreign keys. Identity API callers without scope continue using the existing global contract; P25 scoped access remains denied until the new contract and owner approval are present.

### Required verification

- Global permission without matching scoped grant denies; membership without permission denies; admin role without an explicit action grant denies.
- Exact org/event/gate boundary tests: another organisation, sibling event, wrong gate, revoked/unknown actor all deny; no implicit scope inheritance.
- Grant/revoke authority and audit tests: only approved grantor can issue allowed scope/action; revocation is durable, attributed and immediate; no self-grant or escalation through the gate delegation allowlist.
- Reviewer/Finance separation and contributor exclusion remain enforced by Events.
- Real concurrent gate scan versus assignment revocation proves commit-order outcome using separate PostgreSQL sessions and the approved cross-module locking design.
- Backward-compatibility regression tests for E04 global permission checks, admin APIs and existing JWT claims; static analysis, migration tests, authorization integration tests and independent Security review.

### P25 tasks potentially unblocked

The scoped authority contract would unblock only the authorization dependency of T005/T008/T011/T015 and scoped portions of T016. It does not resolve B-03 currency/payment-method readiness, D-02 numeric re-entry limit, refund/cancellation rules, B-11/P18 payout, P25 API/UI implementation, or independent Security/Finance/Privacy acceptance. T010 checkout still needs separate pricing/tax/payment contracts and T009/T010 inventory/checkout implementation.

## Exact authorization needed to start the conditional E04 work

The user must explicitly authorize a separate **E04 Identity scoped-authorization extension** using the existing Spec Kit workflow, including Identity-owned contract/schema implementation and its tests. That authorization would permit preparing the E04 specification and plan; it would not approve any P25 permission mapping, role grants, P25 production access, independent Security/Finance approval, live processing or deployment. Until such authorization and the owner decision on ownership arrive, this proposal remains documentation only and P25 production bindings remain deny-by-default.

## Reassessment — 2026-10-28

The user accepted development of the two-part authorization model: existing `IdentityModuleContract::hasPermission(actor, slug)` checks the coarse registered permission, and Events checks authoritative organisation/event/gate assignments. Repository inspection found no contract limitation that prevents this design. P25 implemented it without changing E04 contracts or starting an E04 work package. The conditional E04 extension described above is not currently required. Reopen only if an evidenced Identity contract limitation prevents coarse permission checks or safe module boundaries under this accepted design.
