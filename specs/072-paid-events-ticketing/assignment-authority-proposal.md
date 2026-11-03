# P25 assignment authority proposal — for owner decision

**Status: PROPOSAL ONLY.** This is development design evidence, not an approved production authority model or independent Security/Finance/Privacy review. Assignment-management endpoints remain unavailable until the authority and bootstrap questions below are decided.

## Proposed grant and revoke authority

| Assignment | Proposed grant/revoke authority | Scope limit |
|---|---|---|
| Organisation-scoped `events.event.create` | Designated Events Assignment Authority, using separately governed grant/revoke capabilities | One organisation only; cannot grant event, Finance, publisher, sales-resume, or gate-management permissions implicitly |
| Event-scoped organiser, reviewer, publisher, sales-resume | Designated Events Assignment Authority | One exact event and only the approved permission set; org membership does not inherit to an event |
| Event-scoped Finance price approver | Designated Finance authority, with Events Assignment Authority recording the exact event/version scope | Finance approval authority is separate from event review; contributors and event reviewer remain ineligible under A-02 |
| Gate validation | An event-scoped holder of `events.gate.assignment.manage` may grant/revoke only `events.gate.validate` to another actor, at one gate in that same event; central authority may also administer it | No self-assignment; no delegation of reviewer, Finance, publisher, or assignment-administration capability |
| Ticket-holder refund request / organiser payout request | No separate authority assignment proposed here beyond the accepted permission and exact ticket ownership or event assignment | Payments owns refund decision/execution; P18 owns payout eligibility/execution; B-11 remains a hard boundary |

The existing Identity `hasPermission(actor, slug)` contract supplies the coarse capability. Events owns and checks the authoritative assignment rows and exact resource scope. Global admin status or membership alone confers no grant/revoke authority. The two proposed central permission slugs `events.assignment.grant` and `events.assignment.revoke` are **not registered** and are suggestions requiring owner approval; they are intentionally excluded from the accepted action mapping and current migration.

## Bootstrap, escalation, revocation and audit

- **First assignment administrator:** no current contract establishes one. Recommended proposal: a separately approved two-person bootstrap by the Identity/Security owner and Events product owner, recorded in an auditable Identity/Events change record. Do not auto-assign a user or role in a migration, and do not infer authority from the global `admin` role. Owner approval and the concrete bootstrap operator/runbook are required before any grant endpoint can be enabled.
- **Self-assignment/escalation:** proposed prohibition for all assignment administrators. Reject a grant where grantor equals target. A grantor may issue only the permission types and resource scopes explicitly delegated to them; no grantor may grant or expand their own grant/revoke authority. Gate delegation is limited to validation at a gate in the grantor's assigned event.
- **Assignment administrator revocation:** recommended action by a different, independently authorized assignment administrator; revocation of the administrator also revokes that actor's active assignment-administration grants and any grants they issued only if the approved policy explicitly requires it. The cascading revocation choice remains for owner decision. All revocation is durable and immediately effective for subsequent authorization checks.
- **Audit:** append-only grant/revoke event with acting and target actor IDs, assignment ID, permission, organisation/event/gate scope, reason code, timestamp, correlation ID, and approval/change reference. Store no credentials or ticket-holder PII. Keep assignment rows and audit records in Events; call shared Audit after commit using the repository audit contract.
- **Concurrency:** gate validation must lock the active gate assignment in the same Events transaction that validates and records the scan. A revoke racing with a scan follows commit order. No gate scan endpoint or concurrent runtime proof exists yet.

## Focused owner decisions required

1. Approve or revise the authority split in the table, especially central authority for organisation/event grants and Finance-only control of Finance reviewer assignment. **Owners:** Events product and Identity/Security; Finance owner for Finance assignments.
2. Select and evidence the first assignment-administrator bootstrap, including the two-person approvers and operational runbook. A recommendation is not approval evidence.
3. Confirm self-assignment/self-escalation prohibition and the proposed gate-only delegation boundary.
4. Decide whether revoking an assignment administrator automatically revokes grants they issued, or whether each grant must be separately reviewed/revoked.
5. Approve the immutable audit fields and required approval references. **Owner:** Security/Audit; independent Security review remains necessary.

Until resolved, only the enforcement/read-side service and inert schema may exist; no assignment write API, management UI, initial production grant or default role grant is permitted.

## Resolution update — 2026-10-28

The user has accepted this authority model for P25 development. The assignment write path and explicit bootstrap command are now implemented in `EventAssignmentManagementService`; the Events provider loads its routes and command. Two distinct permission layers are enforced (Identity coarse permission and Events assignment), with organisation and Finance administrators separated. Revocation is immediate for subsequent operations and does not cascade issued assignments. The bootstrap command is not operationally approved or executed; no role grants or administrator rows were seeded. The opaque approval reference has no platform governance verifier, so verified evidence remains required before execution.

The earlier blocker for assignment grant/revoke design is resolved for development. Independent Identity/Security/Events review remains unverified. Gate write support was completed within P25 after adding an Events-owned event/gate registry; it grants only validation permission for gates in the manager's exact assigned event. Admission and scanner flows remain blocked by ticket/credential/financial rules and verification.