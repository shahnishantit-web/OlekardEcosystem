# ADM-P1-022 — Multi-Currency & FX Management
Source: docs/admin-p1p2-epic-catalogue.md ADM-P1-022; specs/029-multi-currency-wallet/spec.md US-MCW-005; constitution v1.0.0.
Scope: only US-P1-022-001/002/003. Preserve existing customer APIs, ledger and quote confirmation.
## Acceptance criteria
- AC01 / US-P1-022-001: /multi-currency/currencies lists Code, Name, Active/Inactive, Precision, Base.
- AC02 / US-P1-022-001: enable/disable uses financial-impact approval; maker cannot approve own change; no effect before approval.
- AC03 / US-P1-022-002: /multi-currency/fx-rates shows active currency pairs, Rate, Spread %, Provider, Last Updated.
- AC04 / US-P1-022-002: manual override locks pair to manual mode after approval, with configurable spread.
- AC05 / US-P1-022-002: revert to provider-sourced rates after approval; unconfigured provider fails closed without fabricated rates.
- AC06 / US-P1-022-003: pair history has last-30-days chart with manual annotations.
- AC07 / US-P1-022-003: historical table includes source and UTC timestamp, supports all history through pagination.
## Security and compatibility
Server permissions mandatory. Financial configuration mutations require UUID idempotency keys, audit and atomic maker-checker. Preserve signed authentication and MFA services. Decimal rates remain strings at API boundaries; validate database precision. No wallet or ledger writes.
## Clarifications — 2026-10-15
No user questions needed: E29 explicitly requires E28 second approver for rate configuration. Currency status also goes through E28. Existing fractional spread is retained in API, displayed as percent. Source provider selection remains TBD: expose an explicit injectable provider contract with safe unavailable implementation; never choose an external vendor or seed invented provider quotes. Missing live provider prevents unconditional COMPLETE for AC05. Base comes from existing central configuration if available, otherwise explicitly unconfigured. Existing currency metadata API remains, but financial status and precision cannot bypass approval.
## Definition of Done
All seven criteria, security/decimal/idempotency/concurrency tests, related regression, browser desktop/mobile verification, contracts and traceability evidence. No unresolved critical/high scope defects. Any mandatory unverified/unavailable capability => PARTIAL/BLOCKED.
