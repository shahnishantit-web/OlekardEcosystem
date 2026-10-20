# ADM-P1-019 — Notification Management
Date: 2026-10-15
Epic status: **COMPLETE** (bounded implementation and automated/local visual verification).
Execution: Codex + installed Spec Kit, explicitly authorized after Claude Code HTTP 429 weekly quota. No next epic started.

## Stories
| Story | Status | Implementation |
|---|---|---|
| US-P1-019-001 | COMPLETE | Grouped templates, shared subject/body editor, optimistic revision, safe sample preview, self-only test send |
| US-P1-019-002 | COMPLETE | Paginated delivery log, channel/status/inclusive UTC date filters, durable failed-row retry |
| US-P1-019-003 | COMPLETE | Optional channel rule persistence, durable NOTIFICATION_RULE_UPDATED, new-event snapshot, mandatory policy protection |

Pending stories: none.

## Technical implementation
Reused E17 rendering, NotificationService, preferences, delivery records and channel jobs. Added thin Admin controller/application service, six explicit permissions, OpenAPI and typed Next.js client/pages. Existing key uniqueness/shared channel copy is preserved. Nullable language metadata groups unclassified legacy templates as Unspecified; no invented locale selection behavior.
Optional rules are read without cache at creation and snapshotted on the notification. Dispatch intersects that snapshot with existing user preferences; financial/security categories or explicit mandatory flags bypass rule suppression. Existing queued records with null snapshots keep legacy behavior.
Retry reuses the failed delivery and original channel job. PostgreSQL per-delivery advisory locks serialize automatic/manual workers; successful/bounced outcomes are guarded and stale failure callbacks cannot overwrite successful delivery. A second database connection verifies lock contention.
Rule changes and retry intents use a notification-owned durable outbox; stable event IDs support consumer deduplication. Scheduler retries pending outbox rows every minute. Mutations record domain audit plus existing central audit without message bodies/sample variables/contact addresses.
Additive migration applied locally. Permissions registered only; no user role grants or auth/MFA policy changes.

## Story-to-acceptance convergence
| AC | Source/story | Result | Evidence |
|---|---|---|---|
| AC1 | US001 event/channel/language grouping | PASS | template API metadata test; grouped editor browser flow and screenshots |
| AC2 | US001 subject/body edit | PASS | revision/edit/stale test; browser save; existing renderer regression |
| AC3 | US001 sample variable preview | PASS | substitution/missing-variable test; literal markup Jest check; preview screenshot |
| AC4 | US002 recipient/channel/event/status/timestamp | PASS | log API tests including bounced; desktop log screenshot |
| AC5 | US002 combined channel/status/date filters | PASS | real database filter test; invalid/reversed dates and to-only checks; browser query assertions |
| AC6 | US002 failed delivery retry | PASS | failed-only/double-request tests, durable dispatch, completed-worker/stale-callback guards, two-connection lock test, browser retry |
| AC7 | US003 save + NOTIFICATION_RULE_UPDATED | PASS | rule revision/persistence test, outbox event once, consumer-failure recovery, browser optional toggle |
| AC8 | US003 immediate effect for new events | PASS | before/after creation and dispatch snapshot tests; mandatory override tests |

Policy clarification: the catalogue's suspicious-login example is illustrative. E17 FR-E17-008 requires security and financial delivery on all available channels; mandatory rows are locked and disable requests return 422. Optional operational/marketing rules are editable.

## Tests and regression
| Check | Result | Evidence |
|---|---|---|
| Backend acceptance + E17 Unit/Integration + previous AML Admin feature | 63 PASS, 230 assertions | backend-final.log |
| New Admin acceptance/security subset | 12 PASS, 69 assertions (included above) | backend-hardening.log |
| Architecture | 4 PASS, 18 assertions; 1 vendor deprecation | architecture.log |
| Frontend notification + prior security + auth/MFA/navigation/middleware | 120 PASS across 13 suites | frontend-final.log |
| Final focused frontend rerun | 6 PASS (subset, not additional coverage count) | frontend-p1019-final.log |
| TypeScript | PASS | types.log |
| Scoped notification ESLint | PASS, zero warnings | lint.log |
| Chromium | 3 PASS | browser.log, browser-results.json |
| OpenAPI | YAML parse PASS, six paths/eight operations | installed js-yaml parser |
| Local migration | PASS | migration.log |

Known existing tooling warnings: PHPUnit coverage XML attribute is obsolete; no coverage driver/report was claimed. Architecture emits one deprecation. Existing auth tests emit React act warnings while passing. An exploratory lint of existing middleware emits six strict-boolean warnings; its auth logic was not rewritten.

## Security findings
No new unresolved high/critical finding identified in the scoped implementation review.
Verified anonymous/read-only/unauthorized denial; authenticated actor rather than request-supplied identity; mandatory disable rejection; preview text escaping; bounded inputs; optimistic revisions; self-only rate-limited test sends; retries limited to failed rows; no duplicate provider call after recorded success; durable audit and pending outbox recovery.
This is a scoped code/test review, not a full penetration test. UI browser tests mock API responses with isolated test-only cookies; backend feature tests use real PostgreSQL and permission resolution with the repository's test authentication helper.

## Browser visual verification
PASS at 1440x1000 and 390x844. Inspected all six screenshots:
- screenshots/templates-desktop.png
- screenshots/template-preview.png
- screenshots/log-desktop.png
- screenshots/log-mobile.png
- screenshots/log-empty-mobile.png
- screenshots/rules-desktop.png
Verified editor focus, keyboard Tab order, safe preview, rule state/locked mandatory control, retry removal, permission redirect, responsive filters and no document-level horizontal overflow. Wide log columns scroll within the shared table on narrow screens.

## Definition of Done
**PASS** for this epic's implementation scope: all mandatory stories and eight AC groups pass; 14 technical tasks completed; approved stack/constitution followed; related regression green; scoped security and visual review complete; contracts/status/traceability updated.
No deployment, real provider delivery, production load test or user UAT is claimed.

## Deployment dependencies and recommended next action
Review the changes and perform operator UAT. Assign the new permissions to approved roles through existing role management; none were granted automatically. Apply the additive migration in target environments and run the existing scheduler/Horizon workers.
Existing E17 adapters are LogEmail/LogSms/LogPush: real provider delivery/bounce receipt and crash-window external exactly-once guarantees require provider integration/idempotency support and are not demonstrated here. No bounce webhook or new provider integration was added.
Proceed to another epic only on a separate user instruction.
