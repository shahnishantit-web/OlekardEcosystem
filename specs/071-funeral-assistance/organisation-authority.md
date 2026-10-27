# P24 Identity organisation authority contract — 2026-10-27

**Status:** local contract implemented and tested; governance evidence and operational acceptance pending. P24 HTTP, shared checkout and live processing remain disabled.

## Boundary and lifecycle

- **Trigger:** a privileged administrator submits an organisation registry reference, display name and opaque restricted evidence reference. Identity stores `pending_review`; this asserts no legal or provider certification.
- **Prerequisites:** active ADMIN actor with `organisation.registry.request`. A different active ADMIN with `organisation.registry.review` must inspect the restricted evidence outside the API and approve or reject. Permissions are declared by `OrganisationRegistryPermissionSeeder` but deliberately are not auto-assigned. A security administrator must assign the request, review and revoke roles under the existing role workflow.
- **Membership:** an authorised requester submits an active Identity user and opaque evidence reference under an active reviewed organisation. A separate reviewer, who is neither requester nor target, grants active membership. A reviewed organisation and active user are rechecked at approval and lookup. Cross-organisation identifiers and pending, rejected, revoked, suspended or inactive records deny access.
- **Revocation:** `organisation.registry.revoke` can revoke a membership or suspend an organisation. Both take effect at the next database lookup; there is no JWT claim or membership cache to invalidate. A regrant starts pending review again with a new evidence reference and event.
- **Evidence:** append-only `identity.organisation_authority_events` records subject ID, actor ID, old/new state and time in the same transaction; after commit the state transition is forwarded to Platform AuditService. Evidence reference contents are never included in events or returned by mutation responses. Restricted evidence storage, legal verification criteria, retention and permission assignment require approved operations outside this code. Platform AuditService currently logs and swallows its own write failures, so the local event is the durable source and audit replay remains operational debt.
- **P24 consumption:** `OrganisationMembershipVerifier` delegates to Identity's `OrganisationMembershipAuthority`. Community creation, manager/reviewer actions and staff assignment require active actor-to-organisation membership. P24's separate household/representative scope still applies. The `CommunityFuneral` module never grants Identity memberships.

## API and recovery

All mutation routes require Identity bearer authentication, ADMIN actor type, the named permission and `Idempotency-Key` via `idempotency:require-identity`. The key is scoped to the authenticated Identity actor rather than a caller-supplied header. Existing financial routes retain their prior key namespace pending a separately planned migration.

| Route under `/v1/identity/organisations` | Permission | Result |
|---|---|---|
| `POST /` | `organisation.registry.request` | pending organisation ID |
| `POST /{id}/review` | `organisation.registry.review` | active or rejected |
| `POST /{id}/suspend` | `organisation.registry.revoke` | suspended |
| `POST /{id}/memberships` | `organisation.registry.request` | pending membership ID |
| `POST /memberships/{id}/review` | `organisation.registry.review` | active or rejected |
| `POST /memberships/{id}/revoke` | `organisation.registry.revoke` | revoked |

Duplicate, stale or conflicting transitions return 409; missing authority returns 403; invalid input returns 422. Retry the same idempotency key after a network timeout, then inspect the restricted event record and current state before a new request. A rejected/revoked membership can be requested again; an organisation rejection or suspension has no automatic reinstatement path. The operator must use approved governance to resolve that case. There is no balance, ledger, payment, settlement or payout effect.

## Acceptance still required

Security/operations must approve the authoritative organisation source and evidence review criteria, assign separate roles, verify audit retention and restricted storage, and test a two-process approval/revocation race in the deployment database. The local tests prove code behaviour only. Late-capture, P18 payout and AD-14/AD-10/LC14 decisions remain independent gates.
