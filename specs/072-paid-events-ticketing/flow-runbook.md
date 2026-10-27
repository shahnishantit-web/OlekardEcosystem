# P25 Flow and Operational Boundaries — Review Draft

No P25 flow is enabled. A draft-only backend foundation exists with no HTTP routes; the default organiser authorizer denies every caller. The following identifies the evidence needed to complete each flow; it does not define missing policy.

| Flow / trigger | Prerequisites and owners | Success evidence | Failure and recovery / operator action |
|---|---|---|---|
| Author and publish | Approved organiser, reviewer, organisation scope and published policies; admin owns authoring, Identity authority. | Approved version, category capacities, sales window and audit. | Keep draft/unpublished on missing review; correct and re-review after changes. |
| Hold and checkout | Published event, approved hold expiry, available capacity and server quote; mobile selects, Events owns allocation, Payments owns financial execution. | One bounded reservation, linked quote and shared Payment intent. | Unknown outcomes remain unresolved; query the same intent; do not create a replacement charge. Expiry and late capture require approved policy. |
| Confirm and issue | Approved verified-payment/accounting threshold and durable confirmation; Payments owns financial state, Events owns entitlements. | Shared payment and journal references linked to one ticket set. | Persist an unresolved issuance exception; replay same confirmed reference through an idempotent handler after failure. Never post a second charge or journal. Exact transaction/outbox boundary requires design review. |
| Online admission | Authorised tenant, event, gate and operator; Events verifies valid credential under approved refund/revocation policy. | One admission and auditable scan attempts. | Deny out-of-scope/used/revoked; investigate without exposing ticket details. Concurrency acceptance required. |
| Offline admission | Explicitly approved device/manifest, expiry and revocation policy. | Reconciled device logs and exception record. | Disconnected devices cannot guarantee global single admission; reconcile conflicts on reconnect. No offline feature approved here. |
| Close and payout | Approved cancellation/completion policy, resolved refunds/disputes, reserve reconciliation, finance approval and verified P18. | Separate event completion, settlement and payout records with ledger and provider evidence. | Preserve open liabilities and unknown payout; do not settle solely because event is complete. Escalate to finance; no local bank calls or journal invention. |

## Required flow behavior already selected

- Gate validation returns only `valid/invalid` and ticket category. It never returns customer or payment fields. Entry and exit state updates require online validation and fail closed on connectivity/service failure; a person may physically leave during an outage, but cannot re-enter until an online exit record exists. Re-entry allowances count returns only, not initial entry. These requirements do not remove the remaining permission, credential, limit-value, retention or concurrency gates in `research.md`.
- A cancelled event remains visible with sales disabled. Reactivation remains blocked pending approved lifecycle policy. Do not add a reactivation command or infer that a new publication review alone authorizes reactivation.
- Finance-owned currency cannot be submitted by the organiser. Until supported currency, fee/tax rules and a shared Payments quote contract are available, no paid checkout or charge is exposed.

## Operator runbook boundary

No P25 routes or live journeys are registered. Do not enable an event from draft records. If draft validation fails, correct the draft and resubmit against the current version; the default authorizer currently denies every caller, so access failures require a reviewed authority implementation, not a local bypass. For unknown payments, refunds, settlement or payout, leave financial state unresolved and use the relevant shared Payments/P18 evidence procedures; do not issue tickets, release payout or call a bank directly. Offline gate attempts are denied and retried online. Checklist approvals and support contacts are tracked in `research.md` C-01–C-07 and must be recorded before affected flows are enabled.

## Draft foundation currently implemented

An approved future caller may submit a draft and supplied category capacities/prices, event/sales times and policy text after Identity membership and a separate approved organiser authorizer are both satisfied. Currently the authorizer denies all actors. Create and update are transactional on `community_services`; edits compare a version and replace categories atomically; the sum of category capacities cannot exceed event capacity. A state-only audit row is written in the same transaction and forwarded to Platform AuditService after commit. Stored policy text is unapproved input, never a published policy or proof of authorisation. No purchase, credential, admission, payout or financial event exists in this foundation. Platform audit replay and cross-connection revocation-race acceptance remain release checks.
