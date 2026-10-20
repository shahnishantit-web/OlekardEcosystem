# ADM-P1-022 acceptance traceability
| AC | Story | Implementation | Verification | Outcome |
|---|---|---|---|---|
| AC01 | 001 | AdminFxManagementService currencies; currencies.tsx | currency_list_has_precision_and_base_and_status; base_currency_resolves_from_approved_configuration_envelope; browser | PASS |
| AC02 | 001 | E28 submit/approve; currency_change_applications; distinct checker; audit | currency_status_requires_distinct_checker_and_replays_safely; applied_currency_request_cannot_undo_a_later_decision; currency_request_payload_conflict_and_unknown_currency | PASS |
| AC03 | 002 | FxRateResource; active/pending statuses; percent table; source/provider/update | inactive_pairs_not_quoted_or_listed; frontend rate display; browser | PASS (configured rates; provider unconfigured explicit) |
| AC04 | 002 | Hardened FxRateAdminService; manual active-source lock; provider ingestion gate | rate_approval_manual_lock_idempotency_and_history; provider_revert_and_ingestion_respects_manual_lock; exact effective-date and original-proposal replay tests | PASS |
| AC05 | 002 | FxRateProviderInterface; unavailable default; provider proposals/refresh/reversion | injected provider path PASS; unavailable503 PASS; no live vendor approved | FAIL/BLOCKED; T015 |
| AC06 | 003 | History last30days effective rates; accessible SVG manual annotations and exact table alternative | history_validation_and_old_rates_pagination; frontend chart; focused desktop/mobile images | PASS |
| AC07 | 003 | history page/per_page1..100; source and timestamp | provider reversion history total3/perpage1; older-than30-day table; frontend history | PASS |

## Definition of Done
| Gate | Result |
|---|---|
| Approved source / constitution / scope / reuse / Spec Kit | PASS |
| Scoped implementation, API contract, migrations, audit and security review | PASS |
| Scoped unit/integration, UI, browser, types/lint/static | PASS |
| All mandatory acceptance criteria live-capable | FAIL: AC05 |
| Broad financial regression | FAIL/BLOCKED: T011/T016 |
| Status, traceability, concrete pending tasks | PASS |
| Overall DoD | FAIL |

T001-T010,T012-T014,T017 verified. T011 remains open until broad regression passes. T015 is provider dependency; T016 captures E29 fixture/expectation work. No mandatory criterion waived.
