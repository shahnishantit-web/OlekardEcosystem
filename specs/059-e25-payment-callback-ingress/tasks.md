# P10 tasks

Paths below are relative to `wallet-ecosystem/services/api/` unless stated.

## Setup and prerequisite gates

- [x] T001 Verify source version and retain full extraction in repository `docs/sources/`.
- [x] T002 Inspect P05/P06/P07 code and run predecessor tests; record blockers in `specs/059-e25-payment-callback-ingress/evidence.md`.

## US1 capability framework (independent MVP)

- [x] T003 [US1] Add separate contracts in `app/Modules/Payment/Domain/Contracts/` and capability rejection tests.
- [x] T004 [US1] Harden operation-time guards/scenarios in `app/Modules/Payment/Infrastructure/Adapters/MockBankPaymentAdapter.php` and parser.

## US2 E25 ingress

- [x] T005 [US2] Add trusted account parser and E25 factory extension in `app/Modules/Payment/Infrastructure/Adapters/` and `app/Modules/Integration/Infrastructure/Providers/IntegrationRegistry.php`.
- [x] T006 [US2] Extend E25 receipt migration/model/service for normalized notification, encrypted evidence and collision detection in `app/Modules/Integration/`.
- [x] T007 [US2] Route legacy callback through E25 in `app/Modules/Payment/Http/Controllers/PaymentCallbackController.php`; test raw signature/durable ACK/failure recovery.

## US3 semantics and operations

- [x] T008 [US3] Validate account/money before existing orchestration in `app/Modules/Payment/Application/Services/PaymentWebhookHandler.php`; quarantine unresolved or mismatched receipts.
- [x] T009 [US3] Reuse E25 retry and add quarantine controls/API in `app/Modules/Integration/` and `routes/modules/admin-integration.php`.
- [x] T010 [US3] Test signature, mapping, duplicate/replay, money mismatch, production guards and admin authorization in `tests/Feature/Modules/Payment/` and `tests/Unit/Modules/Payment/`.
- [x] T011 [US3] Verify P05/P06/P07 prerequisite tests and concurrency/crash/ledger evidence. Activation scope is explicitly removed by the user's instruction to keep live processing disabled; no activation is claimed. See prerequisite-closure.md.
- [ ] T012 [US3] BLOCKED: certify real provider parser/transport with actual contracts; no Bank 1/2/3 endpoints. Track in `specs/059-e25-payment-callback-ingress/research.md`.

## Verification and handover

- [x] T013 Run actual tests/static checks and update source-to-test traceability in `specs/059-e25-payment-callback-ingress/evidence.md`.
- [x] T014 Document flows/runbook, update managerial register and restart notes in `specs/059-e25-payment-callback-ingress/` and `specs/049-payment-delivery-register/spec.md`.

Dependencies: T001–T002 precede implementation; US1 independent; US2 before US3.
T003 and T005 concern separate files but execute sequentially in this session.
No next prompt or production deployment is authorized.

## Authorized continuation — local readiness repair

- [x] T015 Repair selected P05/P06/P07 fixtures and recovery/idempotency/submission safety defects; preserve UNKNOWN holds, original operations and owner-scoped locks. Remaining broad gates are T019/T011.
- [x] T016 Review and apply only required local predecessor schema migrations; add missing state constraints in `app/Modules/Payment/Infrastructure/Migrations/`; never infer historical financial completion.
- [x] T017 Verify real database recovery/idempotency and affected integration regressions; record passing and failing results in `specs/059-e25-payment-callback-ingress/readiness.md`.
- [x] T018 Update package evidence, managerial register and restart notes with remaining external gates in `specs/059-e25-payment-callback-ingress/` and `specs/049-payment-delivery-register/spec.md`.
- [x] T019 Resolve remaining prerequisite unit/static findings and complete real concurrent submission/crash/ledger evidence. Reconcile P06 FR-07 with P10-R1; see prerequisite-closure.md (442 tests, 45-file PHPStan).
- [x] T020 Verify and explicitly adopt existing local package schema as batch-0 baseline without inventing historic execution; preserve source/schema hashes and scoped migration verification. See gates-schema-adoption.json and prerequisite-closure.md.
