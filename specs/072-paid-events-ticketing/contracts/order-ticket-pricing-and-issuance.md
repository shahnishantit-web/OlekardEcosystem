# P25 order, pricing and durable issuance contract

Status: implemented for development; operational pricing, independent review and activation remain blocked.

## Pricing and order boundary

`EventPricingContract` returns an immutable `EventPricingBreakdown` with currency precision, seller reference, fee/tax policy versions, component amounts, total, expiry and evidence reference. `DenyEventPricingContract` is the production default and fails closed with `EVENT_PRICING_UNAVAILABLE`; test pricing is permitted only in the testing environment. No zero-fee or zero-tax fallback exists. B-06 approval and seller/Finance evidence are still required before production binding.

`EventOrderService` derives purchaser and organisation from authenticated server context, reads category pricing and quantities from authoritative Events records, and binds quote/order to event, purchaser, organisation, currency, holds and immutable line/component snapshots. Client totals are ignored. Quote expiry is the minimum of configured Payments quote TTL, pricing expiry and hold expiry. Any payable component change requires a new customer confirmation. Order creation locks the event currency for `durable_order` inside the same Events database transaction before the first durable order insert. A repeated idempotency key returns the existing order only when its request fingerprint agrees; conflicting reuse is denied.

### Project-owner accepted B-06 direction and production snapshot validation

This is project-owner acceptance for development, not Finance/Tax/Legal approval. `EventPricingBreakdown` keeps the existing customer-payable `feesMinor` and adds an explicit organiser platform-fee amount plus an immutable fee-schedule snapshot. The organiser platform fee is not added to the customer's `totalMinor`; the order snapshot preserves it for later owner-approved proceeds accounting. P25 does not deduct proceeds or post that accounting entry. Customer-payable booking fees are rejected for non-test pricing unless a separate policy is approved and implemented. No test-only fee/tax fixture is a production schedule.

For non-test pricing, the breakdown must include:

- An approved and version-matched organiser fee schedule with evidence reference, effective start/end, currency, opaque approved calculation-basis identifier, organisation/event scope, and fixed/percentage components. Each component retains its configured decimal value as a string, applied integer minor-unit amount, and `payer: organiser`. Events checks that currency and scope match the authoritative request and that the schedule is currently effective. P25 does not calculate fixed/percentage fees itself because the calculation basis and rates remain Finance-owned inputs.
- A version-matched qualified tax snapshot containing approval-evidence reference, effective period, jurisdiction, ticket classification, separate ticket/service-fee tax treatment, rate rules and/or explicit exemptions, taxable bases, calculation order, currency precision, rounding scope/method, customer disclosures, invoice responsibility, and inclusive/exclusive presentation. Events rejects binary floating-point values anywhere in the policy snapshot. Tax amounts remain supplied by the approved pricing provider; no rate, exemption, or rounding rule is inferred.
- `policyApproved`, verified seller evidence, and a non-empty seller/merchant reference from the authoritative pricing service. These booleans/references do not independently establish seller legality, Finance approval, qualified tax approval, or independent review.

`pricing_snapshot` stores the fee and tax policy versions, evidence references, full immutable fee/tax components and organiser fee. `confirmationSnapshot()` includes these fields so a changed payable or policy component requires a fresh quote confirmation. A later configuration change cannot rewrite a persisted quote/order. Existing test-only pricing remains explicitly marked `test_only` and may use synthetic components. Production remains unavailable while the default `DenyEventPricingContract` is bound and until actual schedule values, seller verification, qualified tax evidence, and required specialist approvals are supplied.

The purpose resolver is registered only for an existing valid order. It validates purchaser, organisation, total/currency, active holds and expiry. Payment initiation separately checks customer-specific eligible methods using the server-derived Events module/purpose and venue country. Event-level static readiness is not customer checkout eligibility.

## Confirmation and ticket issuance

The Events consumer accepts `payment.confirmed` only after querying P08's typed internal confirmation evidence. It verifies payment intent, purpose reference/order, purchaser, organisation, currency, amount, posted ledger status and journal reference. Raw event data alone cannot authorize issuance. Missing evidence stays `awaiting_evidence`; contradictory evidence is retained as `blocked_reconciliation`. A verified capture after hold expiry is preserved as a financial fact and blocked for reconciliation. Neither case is marked failed/refunded, refunded, or issued.

For eligible on-time evidence, a transaction locks the order/event/lines and referenced holds, inserts one opaque credential per admission entitlement, consumes each hold, and commits the inbox/payment/accounting/fulfilment state. Each credential uses 256 bits of randomness, a hash for lookup and encrypted storage for authenticated purchaser retrieval. Tickets are durable before Events reports issuance complete. After commit, Events acknowledges fulfilment to P08. If the process fails after ticket commit but before acknowledgement, replay finds the durable inbox/order effect, creates no additional tickets and retries acknowledgement. Separate Events and Payments transactions are not globally atomic.

Payment, accounting, order and fulfilment states remain distinct. Duplicate confirmations are deduplicated by payment intent/source event; conflicting payloads are recorded and blocked. Out-of-order/unverifiable evidence cannot regress an authoritative terminal state.

## HTTP surfaces

- `POST /v1/events/{eventId}/quotes`
- `POST /v1/events/{eventId}/orders` (requires explicit customer confirmation)
- `POST /v1/events/orders/{orderId}/payment-intent`
- `GET /v1/events/orders/{orderId}` (authenticated purchaser only)

An unavailable production pricing contract returns a safe 503. No publication, hold acquisition, admission, cancellation or refund activation is added by these routes.

## Recovery and operator handling

For missing P08 evidence, retry/query the authoritative P08 evidence contract; preserve the order and inbox for reconciliation. For contradictory evidence, late capture, expired/missing hold or currency drift, do not issue, refund, cancel or rewrite money automatically. Preserve verified facts and route to the existing reconciliation/operator review workflow. Retry delivery after restoring the evidence service; replay is safe after issuance commit. Operators must not mark an unknown attempt failed/refunded without the separately approved financial disposition.

## Open scope

B-06 fee payer/schedule, seller identity, tax determination, calculation/rounding and payable-total disclosure remain unresolved. Late capture, cancellation finance, refund eligibility/failure, credential replacement and admission authorization remain separate unresolved scope. AD-08/AD-09 operational mappings, independent Finance/Security/Privacy review and activation are not established by test fixtures or these code changes.
