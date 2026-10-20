# ADM-P1-021 Support & Dispute Management
Date: 2026-10-15
Epic status: **COMPLETE** for the approved Admin implementation scope.
Execution: Codex + installed Spec Kit. No subsequent epic started.

## Stories
| Story | Status | Delivered |
|---|---|---|
| US-P1-021-001 | COMPLETE | Unified Support/Dispute queue, all required columns, type/status/priority/date filters, pagination and overdue highlighting |
| US-P1-021-002 | COMPLETE | Customer/transaction links, description, legacy evidence and chronological conversation, internal notes, private attachments and related cases |
| US-P1-021-003 | COMPLETE | E22-approved dispute refund, amount/reason, actor/session-bound MFA, central maker-checker policy, atomic compensating journal and linked refund transaction, durable customer notification |
| US-P1-021-004 | COMPLETE | Selected escalation reason and eligible senior assignment, Escalated projection, exact 24-hour deadline, reliable senior notification |
Pending mandatory stories: none.

## Implementation
Reuses E22 ticket notes, assignment, status transitions and dispute investigation. SQL-unified queue excludes linked-ticket duplicates. New Admin metadata/activity records support generic cases, private investigation attachments and related links while preserving source enum states.

The shared refund engine serves new Admin and original E22 refund URLs. It locks the dispute and authoritative original transaction, verifies original customer/currency/wallet-pocket ownership, enforces positive remaining-amount caps across refunds, fingerprints intent and serializes concurrent work. The existing Ledger and Fees contracts post balanced compensation and fee reversal within the same platform transaction as dispute state, Transaction projection/history, activity audit and outbox. Existing TransactionProjectionService records the new type=refund transaction linked to its original and posted journal.

Identity owns the additive ActorStepUpVerifierContract. Signed proof must match scope support.refund:{caseId}, current actor and current session. The original IdentityModuleContract is preserved. Signature validation now explicitly checks ES256, expiry and supported token format. Existing MFA endpoints issue durable events within platform transactions.

Notification-owned listeners feed real E17 NotificationService using stable source-event IDs and mandatory templates. DISPUTE_RESOLVED_WITH_REFUND is published alongside the existing E22 refund event. Senior escalation starts an exact 24-hour UTC deadline. Existing escalations derive that approved deadline from their escalation time. Ordinary support SLA remains unset.

Shared Next.js components/tokens, server permissions and existing API client authentication remain in use. Evidence is MIME/size/count checked, stored privately and downloaded only through a case-scoped authenticated endpoint. Storage keys and MFA proofs are excluded from case responses/activity records.

## Acceptance convergence
| AC | Story | Result | Evidence |
|---|---|---|---|
| AC01 required queue columns | US001 | PASS | Queue API/schema, Jest and Chromium queue screenshot |
| AC02 filters, pagination, overdue | US001 | PASS | Combined filters, 21-row pagination, to-only/invalid dates, new/legacy escalation deadlines |
| AC03 detail and investigation context | US002 | PASS | Existing notes/evidence mapping, chronological mixed timestamps, real customer/transaction route links, browser detail |
| AC04 notes, attachments, related cases | US002 | PASS | Auditable notes/links, private scoped download, executable MIME denial, size/count limits and exact 10 MiB PDF acceptance |
| AC05 privileged verified refund | US003 | PASS | Real enrolled-PIN challenge, single-use challenge, signed/scope/actor/session/expiry checks, permission denial, central maker-checker override |
| AC06 compensating transaction/ledger integrity | US003 | PASS | Real balanced Ledger journal, real fee reversal, linked Transaction/history, original eligibility/cumulative caps, replay/conflict/second-actor controls, cross-connection exclusion and atomic rollback |
| AC07 event and customer notification | US003 | PASS | Exact outbox event, real E17 creation/deduplication and delivered in-app channel |
| AC08 escalation, senior notification, SLA | US004 | PASS | Eligible senior checks, selected reason, persistent Escalated state/deadline, exact 24h assertion, outbox-to-E17 senior notification/deduplication |

All seven cross-cutting requirements FR01-FR07 pass within the assessed scope. See traceability.md and convergence.md.

## Tests
| Gate | Result | Evidence |
|---|---|---|
| Support acceptance + complete existing E22 feature regression | **78 PASS, 250 assertions** | support-final.log |
| Related Risk, Notification, Integration and Identity regression | **62 PASS, 278 assertions** | related-regression.log |
| Frontend Support/Integration/auth/MFA/navigation/middleware | **118 PASS, 13 suites** | frontend-final.log |
| Chromium workflows | **3 PASS** | browser-final.log, browser-results.json |
| Scoped PHPStan level 6, all changed production PHP | **PASS, no errors** | phpstan-final.log |
| TypeScript + scoped ESLint/a11y | **PASS** | types.log, lint.log |
| Controller architecture | **4 PASS, 18 assertions; 1 vendor deprecation** | architecture.log |
| PHP formatting + OpenAPI parse/operations | **PASS** | php-format.log, contracts.log |
| Runtime module bindings | **PASS, real Wallet/Ledger/Fees/Identity/Transaction adapters** | bindings.log |

HTTP identity is supplied by test helpers for feature tests; database authorization, signed step-up proof, enrolled-PIN verification, Ledger, Fees, Transaction and E17 behavior are exercised through real services. Wallet boundary lookups use controlled test doubles in refund tests; production binding was separately checked. Browser API responses are isolated synthetic fixtures; backend tests independently verify authorization and financial effects. Tests roll back data and fake queued external delivery.

Existing tooling notices remain: obsolete PHPUnit coverage XML attribute, vendor architecture deprecation, PHPStan deprecated configuration, and pre-existing React act warnings. No coverage percentage or full-repository release certification is claimed.

## Security and visual verification
No unresolved critical/high finding in the assessed implementation. Closed findings and protections are detailed in security-findings.md.
Inspected desktop 1440x1000 and narrow 390x844 screenshots: queue, case overview/timeline/evidence, Escalated deadline and masked MFA confirmation. Browser assertions verify keyboard focus, no page-level horizontal overflow, permission denial, authenticated evidence download and no localStorage secrets.

## Definition of Done
**PASS** for ADM-P1-021 implementation: four mandatory stories complete; eight AC groups pass; source/constitution review and all Spec Kit stages recorded; financial/security controls tested; relevant regressions pass; accessible responsive UI inspected; API contracts and traceability updated; no open mandatory/high implementation blocker.

## Deployment and next action
Review the changed-file manifest and perform operator UAT. Rebuild the API image to load docker/support-uploads.ini (20M request / 10M file limits); the pre-existing running image retains its previous limits until rebuilt/reloaded. New limits were verified with the same INI during boundary tests.
Apply the two new additive migrations after existing E22/E21/E11/E17 prerequisites. Preserve role grants; assign existing Support permissions through approved RBAC administration as needed.
Original refund URLs/response shapes remain. Legacy financial callers must now supply step_up_token scoped to their case and a valid Idempotency-Key.
Customer in-app delivery is verified. Existing external email/SMS/push adapters remain logging adapters; live provider delivery is an operational integration prerequisite.
Implementation is not deployed or committed by this task. Do not start ADM-P1-022 automatically.
