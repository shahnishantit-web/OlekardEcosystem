# P25 Source → Requirement → Story → Task → Evidence

| Source | Requirement | Story / task | Code / test status |
|---|---|---|---|
| §2 ownership; LC07 1 | FR-001/002/010 | US1 / T005–T008, T017–T020 | Draft-only Events service/schema exists, defaults to deny, has no route/publication/UI. T020 validates timezone-safe timestamps, sales deadline, positive proposed order limit and Finance-owned currency boundary. Publication and approved authority remain blocked. |
| LC07 2–4; FD02/FD07 | FR-003/004 | US2 / T004, T009–T010 | Shared Payment resolver/checkout exist; no P25 resolver, hold or issuance; concurrency and recovery tests pending. |
| LC07 5–6 | FR-005/008 | US3 / T005, T011–T012 | No approved gate authority or admission implementation; online race/privacy tests pending. |
| LC07 7 and exceptions; FD08/FD10 | FR-006/007 | US4 / T004, T006, T013–T014 | P18 execution/settlement are stubs; policies and finance approval pending. |
| LC07 completion evidence; constitution | FR-008/009 | All / T015–T016 | No end-to-end verification, release or certification claimed. |

Actual inspected files and predecessor gaps are in `research.md`; workflow and test evidence in `evidence.md`.

Continuation: LC07 draft event authoring, categories, organiser/sponsor and policy text → FR-001/002 → US1/T017–T018 → `app/Modules/Events/Application/EventDraftService.php`, `EventDraftAuthorizer`, deny binding and `2026_10_27_100001_create_event_drafts.php` → `tests/Feature/Modules/Events/EventDraftFoundationTest.php` (local tests). The default binding denies every caller and no route, approval or publication exists; approved access remains T005/T008. The table's original Events-module statement records the pre-implementation inspection.

T020 continuation: FR-002/003/004/010 → US1 acceptance 1–6 → `EventDraftService::fields()` plus migration `2026_10_27_100002_add_draft_order_limit_and_optional_currency.php` → `EventDraftFoundationTest` timestamp/order-limit/currency-boundary cases. Currency is nullable in draft and organizer-supplied currency is discarded; this is not Finance assignment or paid-event readiness. See `evidence.md` for migration and test output. Checklist gate reconciliation and missing decision/contracts map to `research.md` D-01–D-07/C-01–C-07.

The draft test additionally verifies that caller-supplied organisation, creator and version fields cannot override the authenticated service context. This is scoped draft evidence, not purchase, admission or finance acceptance.
