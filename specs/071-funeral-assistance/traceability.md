# P24 Source-to-Story/Task/Code/Test Traceability

Verified source: `docs/sources/OleKard_Shared_Payment_Architecture_v1.1.docx`; original path and SHA-256 are in `research.md`. This maps partial implementation, not release acceptance.

| Source | Story / requirement | Tasks | Code or API | Test / status |
|---|---|---|---|---|
| LC06 steps 1-2; FD02 | US1/US2 household, representative, independent review, beneficiary | T010-T017 | `FuneralAssistanceService`, controller, migrations; `contracts/api.yaml`; repaired P18 beneficiary migration | `FuneralAssistanceFlowTest` passes locally; authoritative tenant authority remains blocked. |
| LC06 steps 2-3; FD02 | US3 frozen snapshot, one BWP20 obligation per eligible household | T018-T022 | `activateCollection`, unique constraints, `FuneralObligationResolver`, private notice outbox/job | Activation/amount/privacy assertions pass; two-process concurrency pending. |
| FD07; LC06 steps 4-5 | US4 shared checkout, post-ledger once-only fulfilment | T023-T027 | Resolver registration, `FuneralPaymentConfirmedListener`, receipt unique by intent, row lock; disabled Flutter entry | Replay/extra-capture tests pass; checkout remains closed. |
| LC06 exception path | US5 cutoff, late confirmation | T028-T030 | `closeCollection`, resolver active-only predicate, `funeral_late_reviews` | Late-confirmation test passes; policy pending. |
| LC06 steps 6-7; FD10 | US5 aggregate report, liabilities, archive | T031-T034 | `refreshAggregate`, closure table, maker/checker carry-forward, report API; disabled admin controls | Report/closure test passes; finance approval/full admin UI pending. |
| LC06 step 7; FD10 | US6 family payout through P18 | T035-T036 | Payout request 503; no local debit, adapter or journal | BLOCKED: P18 execution/settlement stubs, AD-14/AD-10/LC14. |
| LC06 privacy; architecture sections 1 and 8 | Tenant, permissions, audit, private notice | T004-T009, T013, T022 | Identity reviewed organisation registry migration/service/API, `OrganisationMembershipAuthority`, P24 verifier adapter, `FuneralReadinessGate`, audit table, in-app notice job/retry | `ReviewedOrganisationMembershipTest` (3), `OrganisationRegistryHttpTest` (1) and P24 flow/gate tests pass locally; governance evidence, two-process race and full operational tests pending. |
| P19/P22/P23 predecessor evidence | Receipt/history, checkout, recovery | T026-T027, T038 | Existing shared modules inspected in `research.md` | UI integration and runtime gates pending. |

Tenant and replay continuation: T007/T010 and LC06 privacy are exercised through the actual Identity adapter by `tests/Feature/Modules/CommunityFuneral/FuneralTenantHttpTest.php`. P24 and the Identity registry opt into authenticated-actor key scoping in `IdempotencyMiddleware`; `IdempotencyService::resolve` verifies stored request identity before replay. The Platform idempotency feature suite covers same-key payload conflicts. This is local technical evidence; authority governance and multi-process races remain open.

Phase 10 convergence continuation:
- Database concurrency and event replay races (`T045`, `T018`, `T023`) are verified by `tests/Concurrency/Modules/CommunityFuneral/FuneralConcurrencyTest.php`.
- Representative revocation and customer obligation access (`T046`, `T051`, `T012`, `T013`) are verified by `tests/Feature/Modules/CommunityFuneral/FuneralRepresentativeHttpTest.php`.
- Independent collection review, verified P18 beneficiary check, beneficiary edit approval invalidation, and payout 503 error contract (`T052`, `T057`, `T014`, `T035`, `T036`) are verified by `tests/Feature/Modules/CommunityFuneral/FuneralCollectionReviewTest.php`.
- Post-closure cutoff, late capture quarantine, aggregate report privacy, and archive carry-forward guard (`T053`, `T028`, `T033`) are verified by `tests/Feature/Modules/CommunityFuneral/FuneralReportingAndArchiveHttpTest.php`.
- Customer mobile collection/obligation UI with shared payment checkout (`T047`, `T048`, `T026`, `T027`) is implemented in `funeral_assistance_screen.dart` (`dart analyze` clean).
- Admin Next.js management page (`T049`, `T034`) is implemented in `funeral-collections-page.tsx`.
- Domain models, enums, value objects, repositories (`T050`, `T006`) and typed FormRequests/JsonResources (`T054`, `T009`) are implemented in `app/Modules/CommunityFuneral/` (PHPStan level 5 clean).
- Configurable defaults (`T055`, `T020`) and predecessor route fix (`T056`, `T038`) are verified (18 routes active).

See `evidence.md` for commands/counts/DoD and `flow-runbook.md` for triggers, responsibilities, states, success, failure and operations.
