# Feature Specification: P01 — Architecture Contracts and Accounting Decisions

**Feature Branch**: `050-payment-architecture-contracts`

**Created**: 2026-10-21

**Status**: COMPLETE / RELEASE READY

**Work Package**: P01 (per `OleKard_Claude_SpecKit_Managed_Prompts.docx` v1.0)

**Predecessor**: P00 (`specs/049-payment-delivery-register/spec.md`) — PARTIAL/IN_PROGRESS ✅ predecessor evidence verified

**Mode**: Design only

**Responsible role**: Architect and finance reviewer

**Source references**: Architecture doc Sections 1, 2, 6, 11; FD05, FD06, FD10

> **Source document availability**: `OleKard_Shared_Payment_Architecture.docx v1.1` is NOT in the repository. Source references FD05, FD06, FD10 and Sections 1, 2, 6, 11 cannot be fully verified. All decisions derived from that document remain recorded as AWAITING_DOCUMENT. P01 proceeds on the basis of repository evidence (ADR-E48-001, ADR-E48-002, existing PHP interfaces) and the governing constitution.

**Boundary**: Do not move working wallet code or select undocumented bank behaviour. This is design/documentation output only — no code changes, migrations, or provider execution.

---

## Overview

P01 produces the authoritative architecture contract register and accounting decision record for the OleKard Shared Payment Architecture. It serves two primary consumers:

1. **Downstream implementation packages (P02–P11)**: need unambiguous module boundaries, approved interfaces, and financial vocabulary before writing code.
2. **Finance reviewer**: needs a confirmed or explicitly-unresolved record of custody arrangements, chart-of-accounts mappings, fee bearer rules, and late-payment policy before P08 (ledger acknowledgement) and P18 (payout) can be implemented.

P01 is complete when an architect and a finance reviewer can both sign off on the register — meaning every contract is unambiguous, every accounting decision is recorded (confirmed or deferred with a named owner), and no P02–P11 engineer needs to invent a financial or boundary decision.

---

## User Scenarios & Testing

### User Story 1 — Architect Documents Module Boundaries (Priority: P1)

An architect opens the module boundary register and can immediately determine: which module owns which tables, which interfaces cross module boundaries, and which direction dependencies run. No engineer needs to grep across modules to decide whether a dependency is permitted.

**Why this priority**: Every downstream P-ID (P02–P11) depends on boundaries being unambiguous. An unclear boundary produces duplicate logic or cross-module data access.

**Independent Test**: Given the boundary register is complete, a backend engineer can answer "does module X have permission to call module Y?" from the register alone, without reading code.

**Acceptance Scenarios**:

1. **Given** the boundary register lists `App\Modules\Payment` as owner of `payment.*` tables, **When** an engineer reviews whether `App\Modules\Funding` may query `payment_intents` directly, **Then** the register clearly states "NO — use `PaymentModuleContract`" with the approved interface method cited.
2. **Given** the boundary register identifies E25 (`App\Modules\Integration`) as the owner of connector lifecycle and webhook ingress infrastructure, **When** the Payment adapter needs to call a bank, **Then** the register specifies whether Payment reuses E25 transport or owns its own HTTP client — with the decision recorded (confirmed or unresolved).
3. **Given** `App\Modules\Payment` must not duplicate wallet or ledger logic, **When** an engineer verifies whether `WalletModuleContract::createHold()` is the only way to reserve a wallet balance from the Payment module, **Then** the boundary register confirms this and cites `WalletPaymentAdapter` as the single permitted caller.

---

### User Story 2 — Finance Reviewer Records Accounting Decisions (Priority: P1)

A finance reviewer reads the accounting decision register and can determine: who holds collected funds in custody before disbursement, how each payment event maps to a chart-of-accounts entry, which party bears the payment fee, and what happens to funds when a payment is late or a refund is requested.

**Why this priority**: Without confirmed accounting decisions, P08 (ledger acknowledgement) cannot post correct journals, and P18 (payout) cannot determine which balance is available to disburse.

**Independent Test**: Given the accounting decision register exists, P08 engineers can construct the Wallet payment journal entry (debit: consumer wallet pocket — confirmed via AD-05) from the register alone. For bank payments, the register explicitly records which decisions are CONFIRMED and which are AWAITING_DOCUMENT with a named owner and blocking condition — no engineer needs to guess.

**Acceptance Scenarios**:

1. **Given** a consumer pays BWP 20 via OleKard Wallet for a funeral contribution, **When** the finance reviewer reads the register, **Then** the register specifies the confirmed debit side (consumer wallet pocket) and either specifies the credit side (organisation suspense/receivable account) as CONFIRMED with source evidence, or records it as UNRESOLVED/AWAITING_DOCUMENT with Finance Lead as named owner and a blocking condition referencing the architecture document.
2. **Given** a consumer pays via a bank (external capture), **When** the finance reviewer reads the register, **Then** the register explicitly states "a bank capture does NOT top up the consumer's OleKard Wallet" and records the correct bank settlement account mapping.
3. **Given** a payment fee is charged, **When** the register is consulted, **Then** fee bearer (consumer vs. organisation vs. OleKard) is recorded for each payment method type as confirmed or explicitly unresolved with a named decision owner.

---

### User Story 3 — Engineer Reads Financial Vocabulary Glossary (Priority: P2)

An engineer reading P02–P08 specifications never needs to infer the meaning of "capture", "accounting acknowledgement", "fulfilment", "settlement", or "payout". The P01 glossary is the single reference.

**Why this priority**: Vocabulary drift between engineers produces state machines with inconsistent transitions and events that mean different things to different modules.

**Independent Test**: Given the glossary is complete, a new engineer can determine the exact moment at which `payment.confirmed` is emitted (capture threshold, not redirect arrival) from the glossary alone.

**Acceptance Scenarios**:

1. **Given** the glossary defines "capture" as the moment an external provider reports authoritative debit, **When** an engineer asks "does redirect arrival from a bank count as capture?", **Then** the glossary explicitly answers "NO — redirect arrival is never treated as capture proof; only provider callback or query-by-reference result is authoritative."
2. **Given** the glossary defines "fulfilment" as the domain-specific completion (ticket issued, contribution recorded), **When** an engineer asks "can fulfilment happen before capture?", **Then** the glossary explicitly states the required sequencing: capture → accounting acknowledgement → `payment.confirmed` event → domain fulfilment.
3. **Given** the glossary defines "settlement" as the moment provider funds clear to OleKard's bank account, **When** an engineer asks "does a confirmed payment mean funds have settled?", **Then** the glossary explicitly states settlement is a later, separate event from capture.

---

### Edge Cases

- What happens when a bank purchase is made but the consumer's OleKard Wallet balance must NOT change? The register must record why and what account receives the credit instead.
- What happens to funds that are captured but where the ledger acknowledgement step fails? The capture survives; accounting is deferred — the register must record the suspense account and recovery path.
- What is the accounting treatment for a partial refund where the original fee is non-refundable? The register must record the policy (confirmed or unresolved).
- Which party bears exchange-rate risk on a multi-currency payment where the provider settles in a different currency than the consumer paid? (Expected answer: UNRESOLVED — FX policy is E29 scope; record as DEC-E50-FX-01 or equivalent.)
- Who holds the collected funeral contribution funds between collection and family disbursement, and what account type is that? (Expected: suspense liability account owned by OleKard on behalf of the organisation.)

---

## Requirements

### Functional Requirements

- **FR-01**: The module boundary register MUST list every application module with: (a) owned database schema/tables, (b) approved outbound interface(s) callable by other modules, (c) inbound dependency direction (which modules may call it), and (d) explicitly prohibited cross-module data accesses.
- **FR-02**: The boundary register MUST record the E25 ↔ Payment adapter boundary decision: whether Payment reuses E25 connector lifecycle / HTTP transport, or owns its own — as confirmed or explicitly unresolved.
- **FR-03**: The contract register MUST document the semantic invariants of each existing interface: `PaymentModuleContract`, `PaymentAdapterInterface`, `BusinessPurposeResolverInterface`, `WalletModuleContract` (payment-relevant methods), and `LedgerModuleContract` — in plain language without PHP syntax.
- **FR-04**: The contract register MUST separately define: provider, provider account, instrument, and consumer display option — their ownership, lifecycle, and relationship.
- **FR-05**: The financial vocabulary glossary MUST define capture, accounting acknowledgement, fulfilment, refund, reversal, settlement, payout, and cancellation with: trigger, preconditions, evidence requirement, event emitted, and whether the definition is confirmed or unresolved.
- **FR-06**: The glossary MUST explicitly state why a bank payment capture does NOT implicitly top up the consumer's OleKard Wallet, and which ledger account receives the credit instead.
- **FR-07**: The accounting decision register MUST record for each of the following, whether the decision is CONFIRMED (with the specific mapping) or UNRESOLVED (with a named decision owner):
  - (a) Custody: who holds collected funds before disbursement, and on which ledger account type.
  - (b) Merchant / organisation account ownership: which party owns the settlement bank account and how it maps to the ledger.
  - (c) Chart-of-accounts mappings: for a Wallet payment, a bank payment, and a refund — the full debit/credit chain.
  - (d) Fee bearer: consumer, organisation, or OleKard — per payment method.
  - (e) Late-payment policy: what happens to a captured payment if ledger posting fails; which suspense account is used.
- **FR-08**: The register MUST use existing approved ADRs (ADR-E48-001, ADR-E48-002) as input before recording any new decision — no confirmed decision may contradict an accepted ADR without a superseding ADR.
- **FR-09**: Every UNRESOLVED decision MUST record: decision identifier, description, named owner role, risk if deferred, and expected resolution date or blocking condition.
- **FR-10**: The output artifacts of P01 MUST include a new ADR (`ADR-E48-003-accounting-decisions.md`) that records all confirmed accounting decisions and lists all unresolved ones as open items.

### Key Entities

- **Module Boundary**: A named application module with its owned schema, approved interfaces, permitted callers, and prohibited accesses.
- **Contract**: A named interface with its methods, semantic invariants, pre/post-conditions, and which module owns its implementation.
- **Financial State**: A named lifecycle stage (capture, accounting, fulfilment, settlement, payout) with its trigger, evidence requirement, and emitted event.
- **Accounting Decision**: A named financial policy (custody, chart-of-accounts, fee bearer, late-payment) recorded as CONFIRMED (with specifics) or UNRESOLVED (with owner and risk).
- **Provider**: A named payment service with capabilities, lifecycle, and account association. Distinct from instrument (Visa card) and display option (how the consumer sees it).
- **Provider Account**: Organisation-specific credentials and settings for a provider. Distinct from the provider itself.

---

## Success Criteria

### Measurable Outcomes

- **SC-01**: Every application module is listed in the boundary register — verified by counting modules in `app/Modules/` against register entries (currently 30 modules observed).
- **SC-02**: Every cross-module interface used by `App\Modules\Payment` is documented with semantic invariants — verified by confirming `PaymentModuleContract`, `PaymentAdapterInterface`, `BusinessPurposeResolverInterface`, `WalletModuleContract` (5 payment-relevant methods), and `LedgerModuleContract` (5 methods) all have plain-language contract descriptions.
- **SC-03**: Every accounting decision in FR-07 (a)–(e) is recorded as either CONFIRMED with specifics or UNRESOLVED with a named owner — verified by reviewing ADR-E48-003 for completeness.
- **SC-04**: P08 engineers can construct the complete debit/credit chain for a Wallet payment and a bank payment from the accounting register alone, without reading code — verified by a peer review: an engineer reads the register and writes the journal entry; an architect checks it is correct.
- **SC-05**: Zero confirmed decisions contradict ADR-E48-001 or ADR-E48-002 — verified by cross-checking the new ADR-E48-003 against the two accepted ADRs.
- **SC-06**: The financial vocabulary glossary explicitly answers all three of the questions in User Story 3's acceptance scenarios — verified by reading the glossary and finding each answer within 60 seconds.

---

## Assumptions

- ADR-E48-001 (module ownership) and ADR-E48-002 (wallet adapter delegation) are accepted and form the baseline. P01 must not contradict them without a new ADR.
- The existing PHP interfaces (`PaymentModuleContract`, `PaymentAdapterInterface`, `BusinessPurposeResolverInterface`, `WalletModuleContract`, `LedgerModuleContract`) are the authoritative contract implementations. P01 documents their semantics; it does not change their signatures.
- The architecture document (`OleKard_Shared_Payment_Architecture.docx v1.1`) is not yet in the repository. Accounting decisions that require it (FD05 wallet/ledger, FD06 adapters, FD10 payout) are recorded as UNRESOLVED in ADR-E48-003 pending document review.
- A "bank purchase" in OleKard context means: the consumer pays via an external bank (card, mobile money, bank transfer). Funds flow to OleKard's merchant bank account, not to the consumer's OleKard Wallet. This is a confirmed architectural principle derived from the two-sided nature of the platform.
- All 30 application modules are modular-monolith bounded contexts. Each module boundary register entry covers its current extraction-ready state.
- "Payout" contract is a new capability not yet implemented (P18 status: MISSING). P01 records the payout contract interface definition as a design output; implementation is P18 scope.
- Fee bearer decisions may be partially unresolved pending the architecture document; P01 records what is known from E21 (Fees & Pricing) and marks the rest as UNRESOLVED.
- E25 ↔ Payment adapter boundary is currently undocumented (P00 gap R16). P01 records this as UNRESOLVED with the architect as owner, to be resolved in ADR-E48-003 or a dedicated ADR-E48-004.
