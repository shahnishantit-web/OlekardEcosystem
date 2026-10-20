# ADM-P1-021 Support & Dispute Management
Created: 2026-10-15. Source: approved Admin catalogue ADM-P1-021; E22 supplies supporting contracts, not an assertion of backend completion.
## Mandatory stories and acceptance criteria
- US-P1-021-001: AC01 queue at /support/cases shows ID, customer, Support/Dispute type, priority, status, created and assignee. AC02 filter type/status/priority/date, paginate, highlight overdue cases.
- US-P1-021-002: AC03 detail includes customer profile, description, related transactions, existing evidence and conversation timeline. AC04 add internal notes, private attachments and related case links.
- US-P1-021-003: AC05 initiate refund with positive amount and reason, requires fresh actor/session-bound step-up; enforce approval, configurable maker-checker and original amount/currency/ownership. AC06 create immutable balanced compensating journal AND refund transaction, atomic state/fees/outbox, idempotent including concurrent different keys. AC07 emit DISPUTE_RESOLVED_WITH_REFUND and deliver customer notification through E17.
- US-P1-021-004: AC08 select escalation reason and senior assignee, persist Escalated state and exact 24-hour deadline, notify senior reliably.
## Cross-cutting requirements
FR01 reuse E22 services and original APIs. FR02 server permission checks independently of UI; customer permissions do not confer Admin access. FR03 private evidence, MIME/size/count validation, authorized retrieval. FR04 durable actor/correlation audit; no secret logging. FR05 existing authentication, MFA, APIs and business behavior preserved. FR06 token-driven keyboard-accessible desktop/mobile UI. FR07 tests and evidence for acceptance, regression and convergence.
## Edge cases
Invalid UUID/filters, stale requests, terminal cases, missing senior/transaction/wallet/template, oversized executable evidence, same-key changed payload, repeated escalation, concurrent refunds, ledger/notification failure must fail safely.
## Clarifications
Ordinary support SLA is TBD: no invented default. Only approved escalation SLA is 24 hours. Existing enum states are projected to Admin labels; linked tickets/disputes appear once. Financial refund is eligible only against authoritative completed original transaction and actual owned wallet pocket. No synthetic account or balance writes. Existing fee reversal contract is reused.
## Success
All eight AC groups pass with reproducible evidence; no unresolved mandatory/high security gap. Otherwise PARTIAL/BLOCKED, never COMPLETE.
