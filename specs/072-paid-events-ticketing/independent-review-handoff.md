# P25 independent review handoff

**Prepared for routing; not sent. No reviewer is recorded as appointed.** Product decisions and automated tests are not independent approval.

## Review entry point

Start with [`identity-security-review-index.md`](identity-security-review-index.md). It links the permission/scope enforcement, grant/revoke and bootstrap boundaries, Finance separation, tenant/event/gate isolation, idempotency, audit provenance, routes, tests, and exact review questions/acceptance criteria. Review current source at the repository commit/tree identified in `restart.md`; no commit was created for this handoff.

## Current implementation and evidence

- Identity coarse permission plus exact Events-owned assignment; migrations register permissions without assigning global roles.
- Grant/revoke, authority delegation/revocation, gate registry and validation-only delegation exist. Bootstrap’s production verifier denies until a governance evidence service is configured; bootstrap has not run.
- Draft authoring/review and inventory-hold foundation are implemented for development. Paid publication/sales and hold routes remain unavailable; no checkout, ticket issuance, admission, refund, aggregate attendance or payout route was added.
- Focused Events suite currently: 31 passed, 0 failed, 0 skipped, 152 assertions. Scoped admin lint passes with warnings; whole-app `tsc --noEmit` passes after Next regenerated stale route types. Next build remains blocked by repository-wide lint errors in unrelated files. See `evidence.md` for exact commands, outcomes and database migration evidence.
- The P25 inventory hold service uses per-event row locking, config-bounded expiry, capacity counters and idempotency, but a real separate-session PostgreSQL last-ticket race test has not run. Route-level authorization tests and audit failure/recovery tests also remain gaps.

## Requested reviewer competencies

1. **Identity/RBAC:** validate coarse permissions, resource assignments, scope inheritance, separation of duties, active authority revocation and the designated bootstrap governance process.
2. **Application security:** review server-derived actor/tenant context, authorization at every endpoint/service boundary, input/version/idempotency handling, error/data minimization and privilege-escalation cases.
3. **Security engineering:** assess transaction locks, race ordering, replay behavior, category capacity/hold expiry, and audit atomicity/after-commit recovery; request real PostgreSQL concurrency evidence.
4. **Privacy:** review gate status/category minimization and aggregate-only organiser reporting. Attendance records/report implementation is not present and remains a release gate.

Reviewers must be independent of the implementation and authorized under the repository’s governance process. Existing records identify reviewer functions but do not identify a named reviewer or who appoints one; governance must record that ownership decision before review is represented as complete.

## Acceptance criteria

- Every protected action requires the applicable registered Identity permission and exact scoped Events assignment; global administrator status/membership alone does not grant access.
- Cross-tenant/event/gate requests, permission-only, assignment-only, self-assignment/escalation and out-of-scope delegation are denied; revocation takes effect in the specified transaction order.
- Finance authority is distinct, contributors cannot approve their version, event and Finance approvers differ, and no global roles receive automatic grants.
- A reference string cannot authorize bootstrap; two approvers and their authority, target, organisation, authority type and approval record are verified by an approved governance source before any write. Until then bootstrap fails before mutation.
- Duplicate/concurrent authority and inventory operations cannot create duplicate active grants or oversell; audit state and attributed audit evidence have a reviewed atomicity/recovery story.
- Exact review findings, evidence and signed disposition are recorded against the reviewed source tree; unresolved findings remain open and required regression checks pass.

## Governance routing decision needed

Identify who appoints the independent Identity/Security reviewer, how independence/authority is verified, and which established repository record stores the signed review. Do not send this handoff externally until an authorized owner routes it. Independent Finance and Privacy review remain separate gates.

## Review records to complete after authorised reviewer appointment

These are blank records for independent reviewers. They record no appointment or outcome. Each reviewer must identify the exact reviewed commit and any worktree patch/evidence versions; the current HEAD alone does not include this uncommitted implementation slice.

### Identity review record

- Reviewer / authorised role: **Not appointed**
- Independence basis and appointing authority: **Not recorded**
- Reviewed commit and source-tree/patch identifier: **Pending reviewer entry**
- Evidence versions and scope: Identity permission plus Events scoped assignment enforcement; assignment grant/revoke/delegation and revocation; bootstrap governance checks; actor/tenant/event/gate isolation; idempotency, concurrency and audit provenance.
- Exclusions: Finance pricing approval, tax/legal determination, provider certification, P18 payout and production activation.
- Findings and remediation links: **Pending**
- Outcome (`approved` / `approved with conditions` / `rejected`): **Pending**
- Review date and reviewer sign-off evidence: **Pending**

### Security review record

- Reviewer / authorised role: **Not appointed**
- Independence basis and appointing authority: **Not recorded**
- Reviewed commit and source-tree/patch identifier: **Pending reviewer entry**
- Evidence versions and scope: Service authorization, bootstrap fail-closed behavior, credential vault and replacement, scanner concurrency/revocation, duplicate/replay recovery, audit atomicity, logging/cache/error data minimization, and monetary pricing-contract boundary.
- Exclusions: Provider/live certification, tax/legal opinion, payout activation, deployment.
- Findings and remediation links: **Pending**
- Outcome (`approved` / `approved with conditions` / `rejected`): **Pending**
- Review date and reviewer sign-off evidence: **Pending**

### Privacy review record

- Reviewer / authorised role: **Not appointed**
- Independence basis and appointing authority: **Not recorded**
- Reviewed commit and source-tree/patch identifier: **Pending reviewer entry**
- Evidence versions and scope: Status/category-only gate result, purchaser-only credential access, aggregate-only organiser attendance, credential secrecy in logs/audit/analytics/URLs, response caching, retention and customer communications.
- Exclusions: Finance/tax approval, provider certification, payout activation, deployment.
- Findings and remediation links: **Pending**
- Outcome (`approved` / `approved with conditions` / `rejected`): **Pending**
- Review date and reviewer sign-off evidence: **Pending**

### Finance review record

- Reviewer / authorised role: **Not appointed**
- Independence basis and appointing authority: **Not recorded**
- Reviewed commit and source-tree/patch identifier: **Pending reviewer entry**
- Evidence versions and scope: Fee-payer separation and immutable schedule snapshot, seller/merchant evidence, tax matrix and rounding, complete payable total, P08 capture and posted-journal contract, refund/cancellation/late-capture state separation, AD-08/AD-09 limits and payout separation.
- Exclusions: Qualified Tax/Legal determination unless the reviewer separately holds that authority; independent Identity/Security/Privacy review; provider certification; live activation.
- Findings and remediation links: **Pending**
- Outcome (`approved` / `approved with conditions` / `rejected`): **Pending**
- Review date and reviewer sign-off evidence: **Pending**

## Review evidence update - 2026-10-30
The earlier implementation-summary bullets above are historical and are superseded by the current acceptance matrix and evidence. Current development evidence includes the isolated PostgreSQL P25 Wallet path (E2E-01), purchaser ticket access, exact event/gate online scan and aggregate reporting, plus T030 level-8 scoped PHPStan and pricing contract tests. E2E-01 does not cover providers, browser/mobile journey, worker termination or production readiness. Cancellation request/review, ticket invalidation and customer notice remain unimplemented; cancellation finance and Payments refund recovery are open. The four review records remain unassigned and unsigned. No reviewer was appointed, no external message was sent, and project-owner acceptance is not specialist approval.
