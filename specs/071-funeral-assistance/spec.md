# Feature Specification: P24 — Funeral Assistance Shared Payment Journey

**Feature package**: `specs/071-funeral-assistance`; working branch remains `main`  
**Created**: 2026-10-27  
**Status**: PARTIAL/BLOCKED — HTTP and shared-checkout resolution are disabled; reviewed organisation registry code awaits governance evidence, while payout execution and late-payment policy remain blocked  
**Input**: Execute P24 — Funeral Assistance complete shared payment journey. Mode: Implement.  
**Required predecessors**: P18, P19, P22, P23.  
**Source references**: LC06; FD02, FD07, FD10; source document `docs/sources/OleKard_Shared_Payment_Architecture_v1.1.docx`, version 1.1 (19 October 2026), SHA-256 `B5D07562EE2497C726AABEFE18782ABFFC094A39CED8D0CF3E16AA0F2CFF7837`.

## Source and prerequisite evidence

- The accessible original is `C:\Users\BAPS\Downloads\OleKard_Shared_Payment_Architecture.docx`; its copied repository source is byte-identical at `docs/sources/OleKard_Shared_Payment_Architecture_v1.1.docx` (SHA-256 above). The internal document title/version/date are “OleKard Shared Payment Architecture”, v1.1, 19 October 2026. The LC06 flow is in §7; the functional boundaries are in §§1, 3, 6 and 8.
- P18 has beneficiary and reservation implementation; its beneficiary migration was repaired and applied locally. Execution and settlement services remain explicit `LogicException` stubs. ADR-E48-003 AD-10 is awaiting finance/legal confirmation; LC14 is not confirmed. P18 tasks T059–T060 are still Docker-gated. P24 payout requests remain disabled and must not claim a family disbursement or settled liability.
- P19 task inventory is complete (45 checked, zero unchecked); its history/receipt APIs and transactional notification infrastructure exist. P24 still verifies relevant API and event contracts with tests.
- P22 has 5 unchecked tasks, including analyzer and Docker quickstart tasks. P23 has 3 unchecked tasks, including Docker scenarios and two unimplemented performance tests. Implemented code and targeted tests exist, but the complete release acceptance record is outstanding.
- Existing repo code has a shared Payments purpose resolver and a durable `payment.confirmed` event after ledger acknowledgement. P24 uses these contracts; it does not create payment intents, debit wallets, post journals, or create a second payment state machine.
- The repository has no Funeral Assistance domain module. The horizontal community API is a separate foundation. P24 introduces a distinct `CommunityFuneral` bounded module and only reuses shared community storage infrastructure where the installed platform contract requires it.

## User scenarios & testing

### US1 — Register households and verified representatives (P1)

An authorised community administrator creates a community-linked household register, associates household representatives, and records verification with a private evidence reference. A representative can see only their own household and obligation. Exemption/joining status is explicitly recorded and approved; the system does not invent eligibility tests or treat contributions as mandatory debt.

**Acceptance scenarios**

1. A new household and representative begin unverified; the household cannot enter an active collection snapshot until its eligibility is reviewed.
2. A verified representative is associated with a household and can retrieve only that household’s permitted collection information.
3. A non-authorised actor cannot create, verify, export, or inspect another community’s household records.
4. Evidence is retained by reference to restricted storage; API responses and logs do not expose document contents or full beneficiary account data.

### US2 — Review and approve a funeral collection (P1)

An authorised representative creates a funeral request with minimal evidence and a payout beneficiary reference. An independent reviewer checks legitimacy, beneficiary verification and collection terms. Editing the beneficiary invalidates all collection approval and requires a new review.

**Acceptance scenarios**

1. A collection cannot be activated until a distinct reviewer approves it and the beneficiary is verified and organisation-scoped in P18.
2. Changing the beneficiary after review clears approval and returns the request to review; no prior approval can be reused.
3. Unverified, suspended, or cross-organisation beneficiaries are rejected.
4. The request exposes separate collection, payment, fulfilment, refund, reconciliation, settlement and payout statuses.

### US3 — Freeze eligible households and create obligations (P1)

On approved activation, the system freezes a versioned eligible-household snapshot and creates at most one obligation per household in that collection. LC06’s source-standard contribution is BWP 20.00 (2,000 minor units). Explicitly approved exemptions create no payable obligation. The system sends a respectful private notice to eligible households without exposing other households’ payment state.

**Acceptance scenarios**

1. Repeated or concurrent activation creates one snapshot and one obligation per eligible household.
2. The amount and currency are server-derived from the approved LC06 rule; submitted client values cannot alter them.
3. An exempt household receives no payment obligation; its status is not exposed as a public non-contributor list.
4. Snapshot membership is immutable; later household edits affect only a new, explicitly approved collection.
5. Additional donations remain unavailable until a separate approved policy exists.

### US4 — Pay an obligation through shared checkout and fulfil once (P1)

A household representative opens the household’s payable obligation and launches the common P22 checkout using its trusted purpose reference. Payments resolves the amount and tenant context server-side. Fulfilment occurs only after the shared Payments module publishes confirmed accounting evidence.

**Acceptance scenarios**

1. An altered purpose reference, household, payer, organisation, currency or amount cannot redirect or change the payable obligation.
2. Concurrent delegate checkouts or duplicate/out-of-order confirmation events produce no more than one fulfilled obligation.
3. Pending, failed, unknown, captured, accounting-confirmed, fulfilled and refunded states remain distinguishable.
4. The receipt/history link is the P19 payment receipt; the Funeral module does not invent an independent financial receipt.
5. An unknown or unverified payment outcome remains unresolved and cannot be retried as a new charge.

### US5 — Close, reconcile and report the collection (P1)

An authorised operator closes new obligation checkout, reconciles payment and fulfilment populations, and publishes only an approved aggregate report. Gross confirmed payments, fees, refunds, net support, settlement and payout remain separate. The collection can be archived only after liabilities are resolved or explicitly carried forward.

**Acceptance scenarios**

1. Closure prevents new payment intents for obligations not already in progress.
2. A late capture is held in a review/quarantine state; it is never silently fulfilled, refunded, or treated as failed.
3. No late-capture disposition is applied until an approved acceptance/refund policy and authoritative capture evidence exist.
4. Aggregate reports contain no household names, personal payment statuses, evidence references, or bank details.
5. Archival is blocked when unresolved payments, refunds, reconciliation differences, or payout liabilities remain unless an explicit carry-forward record is approved.

### US6 — Request family disbursement through shared payout (P2, BLOCKED)

After collection closure, reconciliation and beneficiary verification, finance may request disbursement through P18’s shared payout lifecycle. P24 must not call banks, debit Wallet, post ledger entries, or mark payout/settlement complete itself.

**Acceptance scenarios**

1. A payout request is unavailable until collection entitlement, verified beneficiary, cleared balance and independent approvals exist.
2. A beneficiary change invalidates approval and requires renewed verification and approval.
3. Pending/unknown payout outcomes keep liabilities open; no duplicate payout operation is created.
4. Payout execution and settlement are blocked until AD-14, AD-10 and LC14 have approved, executable contracts and evidence.

## Functional requirements

- **FR-001**: The system MUST create tenant-scoped communities, households and representative associations with explicit verification status and auditable transitions.
- **FR-002**: The system MUST store only restricted evidence references and MUST scope reads, writes, queues and exports to the authorised community and actor.
- **FR-003**: The system MUST require independent review of a funeral request and verified organisation-scoped beneficiary before activating a collection.
- **FR-004**: A beneficiary edit MUST invalidate prior review and approval.
- **FR-005**: Activation MUST freeze an immutable, versioned household eligibility snapshot and create exactly one obligation per eligible non-exempt household.
- **FR-006**: The default approved LC06 contribution MUST be BWP 20.00 (2,000 minor units), server-derived; there MUST be no mandatory-debt, public non-contributor list, or extra-donation behavior.
- **FR-007**: P24 MUST register its obligation resolver with the existing shared Payments module and MUST NOT create payment intents, call providers, debit wallets, post ledger entries, or duplicate financial state.
- **FR-008**: A payment obligation MUST be fulfilled at most once and only after a durable `payment.confirmed` event establishes accounting acknowledgement.
- **FR-009**: Unknown, pending, late, refund, settlement and payout outcomes MUST remain separate and unresolved until authoritative evidence or approved policy changes them.
- **FR-010**: The late-capture path MUST quarantine and preserve evidence without automatically fulfilling, refunding, or discarding the payment.
- **FR-011**: Collection close MUST prevent new payment initiation while retaining in-flight attempts and late confirmations for reconciliation.
- **FR-012**: Aggregate reports MUST separate confirmed gross, fees, refunds and net support and MUST exclude household identities and private evidence.
- **FR-013**: P24 MUST request disbursement only through the shared P18 payout API and MUST preserve a liability when P18 execution/settlement is blocked or unknown.
- **FR-014**: Collection archive MUST require no unresolved liabilities or an explicit approved carry-forward record.
- **FR-015**: State-changing endpoints MUST enforce server-side permission, tenant scope, idempotency and audit evidence.
- **FR-016**: Notifications MUST be private, respectful, non-marketing and sent through the existing notification/outbox service.

## Key entities

- **Community**: tenant-owned register and approved participation rules.
- **Household**: community-linked family record, visibility controls and participation/exemption review state.
- **Representative**: actor association to a household with verification evidence reference.
- **Funeral request**: minimal incident evidence reference, beneficiary, review and collection lifecycle.
- **Eligibility snapshot**: immutable versioned list of participating households and approved exemptions at activation.
- **Household obligation**: unique household/collection payable reference and separate payment/fulfilment/refund state.
- **Payment confirmation receipt**: idempotent reference to shared Payment intent and its gross/fee figures; not a new journal or balance.
- **Closure record**: cutoff, reconciliation evidence, aggregate figures and unresolved liability/carry-forward status.
- **Late payment review**: quarantined confirmed event requiring policy-backed disposition.

## Success criteria

- **SC-001**: Repeated and concurrent collection activation results in one frozen snapshot and one obligation per eligible household.
- **SC-002**: Every payable obligation resolves server-side to exactly BWP 20.00 unless an approved, versioned rule explicitly replaces the default; a client-supplied amount has no effect.
- **SC-003**: Concurrent duplicate payment confirmations fulfil an obligation no more than once.
- **SC-004**: Beneficiary edits invalidate approval and prevent activation until independent re-review.
- **SC-005**: A post-close confirmed payment remains visible as late-review liability and has no automatic fulfilment/refund transition.
- **SC-006**: Aggregate closure totals reconcile to shared payment records while excluding household identifiers.
- **SC-007**: No P24 path writes Wallet balances or ledger journals; disbursement remains blocked until approved P18 contracts are available.

## Assumptions and decisions

- The source-standard BWP 20.00 contribution is the default for participating families; exemption and joining criteria remain community-approved configuration, not rules inferred by this package.
- The package allows only the standard contribution. Additional donations are disabled pending explicit policy.
- Until the approved late-capture acceptance/refund policy is supplied, P24 quarantines late captures and carries their liability unresolved.
- The source specifies respectful private notifications, minimal evidence and aggregate reporting; local legal retention periods, consent language and evidence schedule remain external governance inputs.
- Actual family payout remains blocked by unresolved AD-14, AD-10, LC14 and the P18 execution/settlement stubs. P24 may create a guarded request/entitlement record only if the existing shared payout contract supports it; it cannot claim payout completion.
- P22/P23 Docker acceptance and performance gates remain outstanding. Their existing typed client contracts may be reused, but they are not considered fully release-verified.

## Out of scope

- Bank APIs, credentials, destination account schemas beyond P18, or provider certification.
- Mandatory debt/enforcement, named non-contributor lists, additional donation rules, or late-payment/refund policy invention.
- Direct wallet debit, a second ledger, new payment or refund state machines, provider settlement simulation, or deployment/live processing.
