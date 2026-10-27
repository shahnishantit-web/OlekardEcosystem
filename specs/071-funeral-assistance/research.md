# Research and Decisions: P24 Funeral Assistance

**Date**: 2026-10-27

## Source verification

- Original source: `C:\Users\BAPS\Downloads\OleKard_Shared_Payment_Architecture.docx`.
- Repository copy: `docs/sources/OleKard_Shared_Payment_Architecture_v1.1.docx`.
- Both are version 1.1, dated 19 October 2026, SHA-256 `B5D07562EE2497C726AABEFE18782ABFFC094A39CED8D0CF3E16AA0F2CFF7837`.
- LC06 is §7. It sets BWP20 per participating family and explicitly requires independent request/beneficiary review, frozen eligible household snapshot, one obligation per household, private notification, once-only fulfilment, aggregate reporting, separate approvals and archive only after liabilities are resolved/carried forward.
- FD02/FD07/FD10 are in §3. FD02 makes domain obligation authoritative and immutable in the Payment snapshot; FD07 requires accounting acknowledgement before fulfilment; FD10 requires separate beneficiary verification, holds, payout approval and settlement.

## Decision: keep Funeral Assistance separate from shared Payments

**Decision**: Use `CommunityFuneral` as the business bounded module, register its `funeral_contribution` resolver with Payment, and subscribe to the existing durable `payment.confirmed` event.

**Evidence**: `BusinessPurposeResolverInterface`, `BusinessPurposeResolverRegistry`, `PaymentConfirmedEvent`, and the P22 common checkout are implemented. Payment intent ownership and financial event handling are already shared.

**Alternatives rejected**: A funeral-owned payment intent/adapter would duplicate financial state and violate LC06/FD02 boundaries.

## Decision: BWP20 default in minor units

**Decision**: 2,000 BWP minor units is server-owned default, from LC06 §7. Do not accept client amount. Changes require independently approved, versioned community terms before activation. Exemptions are explicit admin-reviewed eligibility decisions; no criteria or mandatory debt is inferred.

## Decision: once-only fulfilment and duplicate delegates

**Decision**: Database uniqueness on collection snapshot version, household snapshot member, collection+household obligation, and payment-intent receipt; serialize activation/payment handling on the collection row. Confirmation arrives only after Payment accounting acknowledgement. Replay is a no-op after the unique receipt.

**Alternative rejected**: Cache/Redis-only deduplication is not authoritative under eviction or concurrent workers.

## Decision: late capture remains quarantined

**Decision**: When a confirmed event is received after collection close, store it as `late_review` and retain the unresolved liability. Do not fulfill, refund, silently discard, or call the payout service.

**Rationale**: The Payment event contains confirmation time, not authoritative capture-at-cutoff time; the architecture says disposition follows an approved acceptance/refund policy. Neither that policy nor FD10 payout settlement decisions is approved in ADR-E48-003.

## Decision: privacy and reports

**Decision**: Customer reads are scoped to verified representatives of their household. Operator evidence is an opaque private-storage reference. Aggregate report fields include confirmed gross, fee total, confirmed refund total, net support, open payment/late/refund/reconciliation liabilities and independent settlement/payout states. Never emit named non-contributor lists.

**Open governance input**: Consent wording, permitted retention period and jurisdictional legal review remain external.

## Decision: payout integration stays blocked

**Evidence**: `PayoutExecutionService::submitExecution()` and `PayoutSettlementService::confirmSettlement()` remain explicit `LogicException` stubs. ADR-E48-003 AD-14 is unresolved; AD-10 and LC14 are awaiting finance/legal confirmation. Existing beneficiary verification and reservation components are not proof of executable payout/settlement readiness.

**Decision**: Implement a domain entitlement/query boundary and expose the P18 prerequisite; never call `StubPayoutAdapter` as a real family payout. No provider contract, bank endpoint, credential, or settlement journal is invented.

## Predecessor gate findings

| Package | Checked evidence | Runtime evidence/gap | Result |
|---|---|---|---|
| P18 | 62 tasks complete; beneficiaries/reservations code present | T059/T060 unchecked; execution/settlement services are stubs; AD-14/AD-10/LC14 unresolved | PARTIAL; blocks payout completion |
| P19 | 45 tasks complete; receipt/history contracts and service code present | P24 must run its specific contract tests; prior task checkboxes alone are not accepted | IMPLEMENTED; P24 can reuse APIs |
| P22 | 50 tasks complete | T048–T051 and T054 are open; shared checkout exists | PARTIAL; code integration possible, release evidence open |
| P23 | 88 tasks complete | T083/T090/T091 open; shared history/refund/receipt surfaces exist | PARTIAL; code integration possible, release evidence open |

