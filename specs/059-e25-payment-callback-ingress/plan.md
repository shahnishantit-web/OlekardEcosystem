# P10 implementation plan

Laravel/PHP approved baseline 8.4+, PostgreSQL, existing E25 queue and outbox,
existing Next.js integration delivery UI. Default PHP remains 8.3.28; continuation
checks use installed PHP 8.4.15 with per-command PostgreSQL extensions. No global
runtime or dependency upgrade. Financial module remains Payment.

## Constitution check

PASS by design: raw signatures before effects; server-side context; integer minor
units; existing ledger/orchestration only; receipt uniqueness/advisory locks;
encrypted restricted evidence; audited retry; no invented secrets/endpoints or
retention periods. Local predecessor gates PASS per prerequisite-closure.md. Live release remains blocked on provider/policy evidence and user instruction.

## Design and phases

1. Record sources and prerequisite test evidence; use installed Spec Kit templates
   and setup scripts. Existing dirty predecessor code stays intact.
2. Split capability contracts while retaining PaymentAdapterInterface; guard
   mocks at every entry point using an explicit development environment allowlist.
3. Extend E25 registry with a typed factory extension for payment account parsers.
   Integration configuration stores `payment_provider_account_id`, `environment`;
   account/provider records supply merchant and secret reference. Inactive E25
   connectors require explicit `allow_resolution_when_inactive` for resolution.
4. Extend E25 receipt with verified notification, hash and encrypted evidence.
   Common ingress persists and outboxes before ACK; queue failure leaves durable
   receipt. Payment event identity hashes account/environment/event ID. Reject
   content collisions. Preserve legacy nonpayment ingress behavior.
5. Payments verifies account, provider, reference and money against durable attempt
   and intent. Quarantine invalid mapping and unresolved evidence; existing
   orchestration remains sole semantic transition implementation. Live activation
   fails closed while prerequisites lack evidence. Retain legacy URL as an E25
   facade requiring explicit unambiguous mapping; no old insecure bypass.
6. Reuse E25 delivery/retry screens and add quarantine API/filter/actions where
   needed. Restrict evidence; no mobile provider-specific execution UI.
7. Run unit, integration/API and predecessor checks; document gaps honestly.

## Research and decisions

No applicable AGENTS.md found. Architecture v1.1 verified outside repo and complete
text retained. Existing E25 owns durable receipt/outbox/retry and foundation secret
provider. No reusable E25 outbound HTTP transport implementation was found; actual
bank protocol execution is BLOCKED until reviewed contracts/transport exist.
P11 event/entitlement delivery and bank certification remain out of scope.

## Data model

Add nullable `verified_notification` JSON, `payload_hash` SHA256 and
`encrypted_evidence` text to integration.webhook_events. No financial table
migration in the original P10 scope. The authorized continuation also reviewed and
applied local predecessor additive schema and missing state checks; see readiness.md.
Existing unique provider_slug/idempotency_key and advisory locking
remain; parser scopes identity by trusted account/environment. Quarantine reuses
dead_letter with safe error code; no new incompatible status enum.

## Verification

Tests exercise signature byte sensitivity, account/environment scoping, collisions,
unknown outcomes, money mismatch, production guards, explicit unsupported payout,
durability/dispatch/replay and authorization. See evidence.md for actual outcomes.
