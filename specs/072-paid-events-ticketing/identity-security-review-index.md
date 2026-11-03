# P25 Identity and Security review index

**Status: prepared for independent review; not approved.** Product/design decisions authorize development only. This index does not constitute Identity, Security, Privacy or Finance acceptance.

## Review scope and evidence

| Review area | Implementation/evidence to inspect | Acceptance criteria |
|---|---|---|
| Permission plus assignment enforcement | `EventPermissions.php`; `ScopedEventAuthorization.php`; `ScopedEventDraftAuthorizer.php`; `ScopedEventWorkflowAuthorizer.php`; migrations `100005`–`100007`; `authorization-matrix.md` | Each request needs both the registered coarse permission and an active exact organisation/event/gate assignment. No role or membership-only path grants Events access; no new global-role grants. |
| Grant/revoke, delegation and boundaries | `EventAssignmentManagementService.php`; `EventAssignmentController.php`; `routes/modules/events.php`; `EventAssignmentManagementTest` cases in `EventDraftFoundationTest.php`; `assignment-authority-proposal.md` | Grantors hold applicable authority and scope; self-assignment/escalation and broader delegation are denied. Gate managers delegate only gate validation inside their event. Revocation denies subsequent operations; administrator revocation does not silently cascade. |
| Finance authority separation | `authorityType()` and Finance permission classification in `EventAssignmentManagementService.php`; `EventWorkflowService.php`; `EventPermissions.php` | Finance assignments require the separate Finance authority; event reviewer and price approver are different people; contributors to the current version cannot review it. No Finance authority is inferred from global administration. |
| Tenant/event/gate isolation | `ScopedEventAuthorization.php`; assignment and gate migrations; `test_assignment_requires_permission_and_exact_resource_scope`, gate delegation and revocation tests in `EventDraftFoundationTest.php` | Cross-organisation, cross-event and cross-gate access is denied; gate IDs must belong to the requested event/organisation; client-supplied actor and tenant context cannot override server-derived values. |
| Bootstrap and governance | `BootstrapEventAssignmentAuthority.php`; `BootstrapApprovalEvidenceVerifier.php`; `DenyBootstrapApprovalEvidenceVerifier.php`; `EventsServiceProvider.php`; bootstrap cases in `EventDraftFoundationTest.php` | No authority is inserted until an approved governance verifier validates two distinct approvers, target, organisation, authority and reference. A reference string by itself is rejected; no production bootstrap/grant is executed. |
| Idempotency/concurrent authority changes | `routes/modules/events.php` (`idempotency:require-identity`); writer transactions, uniqueness constraints and advisory locks in `EventAssignmentManagementService.php`; migration `100005` | Retried writes return the same active assignment or conflict safely. Concurrent grant/revoke/delegation cannot create duplicate active authority or authorize a write after revocation wins its transaction order. A separate-session concurrency test is still required. |
| Audit atomicity and issuer provenance | assignment/admin audit tables in migrations `100005`–`100006`; `assignmentAudit()`, `administratorAudit()`, `afterAudit()`, issuer-history query in `EventAssignmentManagementService.php` | State and in-module audit row commit/roll back together; actor, issuer, scope, action, reason/reference and correlation are recoverable. External Platform Audit forwarding is after-commit and its failure/retry guarantee must be reviewed. Revocation retains grantor provenance. |
| Routes, checks and gaps | `routes/modules/events.php`; `EventAuthoringController.php`; `EventAssignmentController.php`; `EventDraftFoundationTest.php`; `evidence.md` | Review the listed focused test run and static checks, then require route/API authorization tests, separate-connection concurrency evidence and audit failure/recovery evidence before acceptance. No production bindings or live payment behavior are enabled. |

## Questions for the independent reviewers

1. **Identity:** Does the coarse-permission plus Events-owned exact assignment design satisfy Identity contracts, tenant isolation and grant/revoke authority? Are any registered permission slugs or permission boundaries incorrect?
2. **Security:** Do transaction locking, revocation ordering, idempotency, bootstrap denial, audit provenance and failure handling prevent privilege escalation or replay? What additional concurrency, threat-model or audit evidence is required?
3. **Security/Privacy:** Is the current authoring/review API response limited to the authorized scope and fields? (Gate status/category minimization and aggregate-only organiser reporting are not implemented because admission/attendance persistence is absent.)
4. **Governance owner:** Who appoints and records the independent Identity/Security reviewers, and where is their signed outcome recorded? Existing P25/P18 records identify reviewer functions but no named P25 reviewer or appointment process.

## Review evidence required to close the gate

- Named independent reviewer(s), their authority/independence basis, date and scope.
- Review findings and disposition, linked to the exact commit/tree and the tests below; unresolved findings remain open.
- Passing route authorization tests, real separate-session grant/revoke concurrency tests, atomic-audit failure/recovery tests and reviewer-requested regression checks.
- Explicit reviewer outcome in the established P25 evidence record. This document and automated self-checks do not satisfy that criterion.

## Current verification snapshot

The recorded Events feature suite, Pint, scoped PHPStan, route registration and local migrations are engineering evidence only. See `evidence.md` for executed commands and results. The broad `php artisan test --filter` attempt failed during unrelated suite construction in `ReceiptDownloadTokenServiceTest`; that harness failure is separate from the focused P25 result. No reviewer has approved this package, no bootstrap was executed, and no production grants were created.
