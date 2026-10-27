# P24 Flow Responsibilities and Operations

**Operational status:** P24 HTTP and shared-checkout resolution are disabled. This describes the intended journey and partial backend implementation. Draft, suspension, revocation and closing intermediate transitions, foundation audit forwarding, full admin/mobile flows and P18 disbursement remain pending; do not treat planned states as deployed transitions.

Local backend now records restricted state-only audit rows and private in-app notices for verified representatives. The customer/admin UI shows the closed state; full contribution and operations UI remains pending. P24 now reads a reviewed Identity actor-to-organisation registry; this work granted no membership, and the HTTP/payment gates remain closed. Its trigger, reviewer separation, revocation and recovery are in `organisation-authority.md`. After fixing a notification failure, operators may run `php artisan funeral:notices-retry --limit=100` in an authorised environment; the notice ID deduplicates the inbox item. Do not use a generic SMS/email notification route for this private collection notice.

## Community/household onboarding

- **Trigger**: Authorised community onboarding.
- **Prerequisites**: Authenticated actor, tenant scope, approved joining/exemption rules, household consent and private evidence storage.
- **States**: community draft/active; household pending review/eligible/exempt/suspended; representative unverified/verified/revoked.
- **Responsibilities**: Admin registers household and representatives; independent reviewer verifies; mobile user sees only own household; foundation provides Identity, permissions, audit, private evidence references and notification delivery.
- **Success**: verified association and approved eligibility state.
- **Failure/recovery**: incomplete evidence remains pending; wrong tenant/actor is denied; revoked representatives immediately lose access.
- **Completion evidence**: audit transition, reviewer, restricted evidence reference, actor-household scope test.
- **Runbook**: keep evidence out of logs/exports; never publish a list of non-contributors.

## Collection review and activation

- **Trigger**: Verified representative submits a funeral support request.
- **Prerequisites**: Minimal evidence, community eligibility terms, organisation-scoped verified P18 beneficiary, separate reviewer, approved BWP20 terms.
- **States**: draft → under_review → approved → active → closing → closed → archived. Beneficiary change returns request to under_review and invalidates approval.
- **Responsibilities**: Admin/representative submit; independent admin reviewer verifies request and beneficiary; foundation validates permissions and stores immutable snapshot; mobile receives private notice.
- **Success**: unique snapshot version plus one obligation per eligible non-exempt household.
- **Failure/recovery**: approval missing, beneficiary invalid, duplicate activation or no eligible population blocks activation without partial obligations.
- **Completion evidence**: snapshot digest/count, terms version, activation actor/time, unique constraint and concurrent activation test.
- **Runbook**: reconcile eligible households and exemptions before activation; never edit a snapshot after activation.

## Household contribution and fulfilment

- **Trigger**: Household representative chooses the “Contribute” action.
- **Prerequisites**: Verified representative, active collection, payable obligation, current server quote, shared checkout and configured eligible payment option.
- **States**: obligation unpaid → pending/unknown → confirmed; fulfilment pending → fulfilled; post-close confirmation → late_review. Refund and settlement/payout states remain separate.
- **Responsibilities**: Mobile launches P22 checkout with obligation reference; Payments resolves amount/tenant and owns attempt/capture/accounting; Ledger owns journals; Funeral consumes durable confirmation and fulfils once; P19 supplies receipt/history.
- **Success**: accounting-confirmed event is durably recorded once and fulfils one obligation.
- **Failure/recovery**: unknown stays unresolved; query same Payment intent; duplicate event no-op; close-race confirmation moves to late review.
- **Completion evidence**: shared payment intent + accounting evidence + unique domain receipt + obligation transition + P19 receipt link.
- **Runbook**: do not retry by creating a new intent for an unknown payment. Reconcile with Payment before support action.

## Close, reconcile, report, archive and family payout

- **Trigger**: Authorised operator reaches the published collection cutoff.
- **Prerequisites**: Freeze new initiation, all active attempts identified, refunds/disputes/settlement and P18 destination reviewed.
- **States**: active → closing → closed with independent liability, settlement and payout states; archive only when resolved or approved carry-forward exists.
- **Responsibilities**: Admin closes/reconciles; Payments/P17/P18 own financial states; community domain owns collection and aggregate support summary; Finance approves carry-forward/report; family payout must use P18.
- **Success**: aggregate report reconciles shared records, disbursement is requested via P18 and archive occurs only after liabilities meet approved closure rule.
- **Failure/recovery**: late confirmed payment stays in late review; unknown payout remains open; unresolved liability blocks archive.
- **Completion evidence**: closure record, independent gross/fee/refund/net/settlement/payout totals, redacted report, carry-forward approvals and P18 evidence.
- **Runbook**: actual family payout is BLOCKED until AD-14, AD-10 and LC14 and P18 execution/settlement tests are approved. Do not use a development mock for an actual family disbursement.

## Explicit pending decisions

- Late capture disposition (accept/fulfil or refund), cutoff evidence, and operator authority.
- AD-14 payout contract ownership; AD-10 custody account/legal custodian; LC14 disbursement journal mapping.
- Approved legal retention period, consent wording, and jurisdictional requirements for funeral evidence.
- P22/P23 runtime acceptance/performance evidence.

