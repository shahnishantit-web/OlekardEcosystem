# Implementation Plan: P24 — Funeral Assistance Shared Payment Journey

**Branch**: `071-funeral-assistance` | **Date**: 2026-10-27 | **Spec**: [spec.md](spec.md)

## Summary

Create a distinct Laravel `CommunityFuneral` domain module that owns community household eligibility, funeral collection review, frozen obligation snapshots and one-time fulfilment. Register a purpose resolver with the existing Payment module and consume its durable confirmation event only after accounting acknowledgement. Use the existing P19 notifications, receipts/history, P22 shared checkout, P23 history and P18 beneficiary/payout contracts. Keep all payout execution and settlement blocked until approved accounting/adapter decisions exist. Use strict database uniqueness and row locking to prevent duplicate snapshots, obligations and fulfilment.

## Technical context

**Language/version**: PHP 8.4+, Laravel (existing service)  
**Dependencies**: PostgreSQL, existing Payment/P18/P19/Notification/Identity/Platform services, Next.js admin shell, Flutter shared-payment package  
**Storage**: `community_services` schema for module-owned prefixed tables using the configured horizontal foundation connection; Payment remains the owner of payment intents and financial records.  
**Testing**: PHPUnit feature/API tests and existing targeted Payment checkout/history tests.  
**Target**: API, admin operator workflow, Flutter household contribution entry point.  
**Constraints**: UUID v7; BWP20 source default = 2,000 minor units; explicit idempotency on writes; server-derived tenant/money context; no direct wallet/ledger code; live processing unchanged/disabled.  
**Scale**: One collection per funeral request; concurrency safety at household and collection level.

## Constitution check

| Principle | Gate | Evidence/plan |
|---|---|---|
| Source of truth | PASS | Use verified LC06, FD02/07/10 in v1.1 source; preserve TBD policies as blocked. |
| TBD governance | PASS | No invented exemption criteria, late-payment policy, extra donation policy, legal retention, or custody accounts. |
| Configuration | PASS | BWP20 is approved source default; configuration changes require versioned approval before new collections. |
| Financial integrity | PASS | P24 stores obligation/fulfilment references only; shared Payment/Wallet/Ledger remain authorities. Unique keys + transaction locks protect one-time effects. |
| Identity/authorization | PASS | Server-side permission checks, actor scoping, tenant scoping, audit. |
| Zero trust/privacy | PASS | Minimal evidence references, verified actors, private notices, aggregate-only public report. |
| Domain boundaries | PASS | `CommunityFuneral` owns funeral rules; Payments owns intent/capture/refund; Wallet/Ledger/P18 own financial balances and payout. |
| API contract | PASS | Versioned `/api/v1/community/funeral` endpoints; UUIDv7, correlation ID and idempotency on writes. |
| Event reliability | PASS | Consume durable Payment outbox confirmation; idempotent receipt and obligation transition. |
| UX/accessibility | PASS | Reuse app shells and design tokens, private household view, clear pending/late/confirmed labels, accessible error states. |
| Test quality | PASS | Concurrency, callback replay, beneficiary edit and post-close capture scenarios specified and linked to tests. |
| Observability | PASS | Correlation IDs and audit-safe transition events; no PII or raw evidence in logs. |
| Architecture | PASS | No new deployment or payment module; no fabricated bank contracts. |
| Release governance | PARTIAL | P18 payout finance gates and P22/P23 Docker acceptance remain open; no live enablement or production release. |

## Verified predecessor contracts

- **P18**: `PayoutBeneficiaryModel` and verification service exist; `PayoutExecutionService` and `PayoutSettlementService` still throw `LogicException`. ADR-E48-003 AD-14, AD-10 and LC14 remain unresolved. Family payout execution/settlement is therefore a hard blocker.
- **P19**: Existing receipt/history APIs and `payment.confirmed` notifications are present; all 45 P19 task checkboxes are marked done. P24 tests will verify actual use and no duplicate receipt state.
- **P22**: `packages/shared-payment` supplies common checkout/recovery. Five tasks (analysis, package tests, two app analyzer checks and Docker quickstart) remain unchecked.
- **P23**: Customer/agent history/receipt/refund flows are implemented. Docker quickstart and two performance tasks remain unchecked.
- **Payment**: `BusinessPurposeResolverInterface`, resolver registry, `PaymentConfirmedEvent` and Payment’s durable outbox are present. A confirmed event follows accounting acknowledgement. P24 registers a domain resolver and consumer; it does not call a provider or wallet.
- **Source**: Original `C:\Users\BAPS\Downloads\OleKard_Shared_Payment_Architecture.docx` matches the repository copy by SHA-256. LC06 is §7; BWP20 default is explicitly stated; FD02/FD07/FD10 appear in §3; further contract/policy references are §6 and §8.

## Architecture

`CommunityFuneral` owns household/representative status, funeral review, the eligible household snapshot, obligations, late review and closure. It uses `community_services` storage namespace with `funeral_*` tables under the existing community connection; the Payment schema stays untouched by domain writes. Its `FuneralObligationResolver` is registered into Payment for `funeral_contribution`. Its confirmation consumer locks the collection and obligation, persists one shared intent reference and either fulfils once (collection still open) or records late review (collection closed). Shared checkout launches from an obligation reference; no amount or tenant comes from Flutter.

P18 beneficiary lookup must check verified status and organisation at review and activation. Beneficiary edits invalidate collection approval. P18 payout request/approval is reached only through its shared API. Until payout contracts and accounting are approved, collection closure retains explicit open family liability. Late confirmed payments use an unresolved review state because the Payment event has confirmation time but no authoritative capture-at-cutoff evidence and no approved policy exists.

## Data / API / UI scope

- Domain records: community, household, representative, funeral request, immutable eligibility snapshot/members, obligation, payment receipt reference, late-review record, closure summary.
- Admin API: community and household management, representative verification, request review/beneficiary edit, activation, close, aggregate summary, archive check. All writes permissioned, idempotent and audited.
- Customer API: own household obligations only; checkout purpose reference is the obligation UUID. The common P22 component handles checkout and P23/P19 provide payment history and receipt.
- Admin UI: pages under existing Community navigation for funeral requests, collection review/closure and aggregate support report.
- Mobile UI: household collection card and “Contribute” route into shared checkout; status deep link to payment history.
- Payout action remains visibly unavailable with an operator blocker until P18 execution/settlement is released.

## Implementation phases

1. Add P24 service provider, permission seeding, storage/migrations, domain models/contracts and API schemas.
2. Implement household/representative and funeral-request review lifecycles, including beneficiary edit invalidation.
3. Implement activation snapshot, unique per-household obligations, server-authoritative resolver and private notifications.
4. Implement idempotent confirmation fulfilment, late capture review, collection close/reconcile/report/archive guards.
5. Wire admin and household mobile flows to API and shared checkout/history.
6. Verify concurrency, replay, auth/tenant boundaries, beneficiary edit, late capture and gross/fee/refund/net independence.
7. Record actual P18/P19/P22/P23 evidence, managerial register, operations runbook and restart note. Do not enable live processing or deploy.

## Blocking gates

| Gate | Owner/evidence | P24 effect |
|---|---|---|
| P18 AD-14 | Architect-approved payout adapter contract + implementation + tests | Family payout execution cannot be wired. |
| P18 AD-10 + LC14 | Finance/legal accounting decision and ledger contract | Settlement journal/disbursement cannot be posted or marked settled. |
| LC06 late capture | Product + Finance-approved cutoff and accept/refund policy with evidence contract | Late captured payments remain quarantined and liabilities unresolved. |
| P22/P23 release checks | Mobile owner Docker quickstart/analyze/performance evidence | Journey is code-integrated but not fully release-verified. |
| Source governance | Legal/compliance approved retention/consent schedule | Preserve opaque evidence references; no custom retention or public disclosure. |

## Project structure

```text
specs/071-funeral-assistance/                 # spec, plan, tasks, traceability, evidence, runbook
wallet-ecosystem/services/api/app/Modules/CommunityFuneral/
  Domain/ Application/ Infrastructure/ Http/ Providers/
wallet-ecosystem/services/api/routes/modules/community-funeral.php
wallet-ecosystem/services/api/app/Modules/CommunityFuneral/Infrastructure/Migrations/
wallet-ecosystem/services/api/tests/Feature/Modules/CommunityFuneral/
wallet-ecosystem/apps/admin/src/modules/community/funeral/
wallet-ecosystem/apps/customer/lib/modules/funeral_assistance/
```

**Structure decision**: Maintain a distinct funeral business module and app surfaces while reusing the existing modular Laravel host, `community_services` connection, shared Payment module, P19 notifications/history and P22 checkout.

## Risks and mitigations

- **Late callback after cutoff**: Event lacks authoritative capture time; quarantine, preserve event/intent, require approved policy before disposition.
- **Duplicate delegates/replay**: Unique obligation/snapshot/payment keys plus collection-then-obligation locking and replay tests.
- **Unauthorized household visibility**: Actor-to-household lookup on every customer read; tenant and permission checks on every admin operation.
- **Financial double count**: Payment event values are references/snapshots only; collection summary reads distinct confirmed payment receipts and refund events, does not create journals or wallet balances.
- **Unapproved payout**: Surface request as blocked/open liability; do not dispatch adapter or fabricate evidence.

## Complexity tracking

No architecture boundary changes. A dedicated bounded module is needed to isolate household eligibility and one-time funeral fulfilment from shared Payment orchestration and the horizontal community foundation.
