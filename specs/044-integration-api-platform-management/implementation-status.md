# ADM-P1-020 — Integration & API Platform Management
Date: 2026-10-15
Epic status: **COMPLETE** for the approved Admin implementation scope.
Execution: Codex + installed Spec Kit under the user's approved fallback; Claude Code remained quota-limited.
No subsequent epic started.

## Completed and pending stories
| Story | Status | Outcome |
|---|---|---|
| US-P1-020-001 | COMPLETE | Partner-linked client creation, permissions and IP/CIDR controls, hash-only one-time secrets, rotation and terminal revocation |
| US-P1-020-002 | COMPLETE | Incoming callback delivery/processing log with endpoint, attempts/time, filters and durable failed callback retry |
| US-P1-020-003 | COMPLETE | Actual per-integration health/timestamp, 24 UTC observed-uptime buckets, safe recent error log and unknown coverage |

Pending mandatory stories: none.

## Technical implementation
Extended working E25 credential generation/bcrypt/outbox/audit and existing M2M authentication. Optional DTO fields preserve callers; nullable partner association and empty allowlists preserve legacy clients. IPv4/IPv6 addresses and CIDRs are validated/enforced server-side against trusted request IP.
Creation/rotation are no-store responses; safe Admin reads expose key prefix, never hash or secret. Browser keeps new credentials only in temporary component state, with explicit dismissal and visibility cleanup. Screenshot evidence masks test credentials.
Rotation/status operations serialize through platform row locks. Revocation sets inactive plus an immutable revoked_at marker; legacy activate/rotate cannot revive it.
Reused E25 callback records, handlers and retry/backoff. Added safe Admin aliases, status/event filters and attempt aggregation. Endpoint is the existing inbound receiver. Durable platform retry intents survive broker failure; shared advisory locks, completion/state guards and retry generation/attempt checks prevent stale/manual/automatic duplicate work. Receiver idempotency now locks and rechecks before insert/dispatch.
Extended existing Ops collector rather than adding arbitrary HTTP probes. Integration inventory and Ops history interfaces preserve module boundaries. Both successful and failed probes retain integration identity; historical type-only samples are not attributed to arbitrary partners, and nondefault checks do not overwrite default-provider status. Raw probe errors are replaced with safe outcomes.
Health reports 24 UTC hourly healthy-check percentages with sample counts and explicit unknown buckets; it does not fabricate continuous uptime or SLA. Last-check timestamp retains the actual latest observation.
Existing provider type filter, default/status actions and client activation/rate/last-used visibility retained. Existing APIs remain available; signed webhook verification now fails closed when missing or invalid.

## Story-to-Acceptance Criteria convergence
| AC | Story | Result | Evidence |
|---|---|---|---|
| AC1 | US001 partner/name/permissions/IP allowlist | PASS | real DB creation/IP validation and IPv4/IPv6 authentication tests; create form/browser |
| AC2 | US001 one-time hash-only secret | PASS | bcrypt/model-hidden/no-store/read omission/audit tests; browser dismissal and storage checks |
| AC3 | US001 rotation and revocation | PASS | old-secret401/new-secret200/revoke401/legacy-reactivation409; browser confirmations |
| AC4 | US002 event/endpoint/status/attempts/last attempt | PASS | real aggregation/status-map test; desktop callback screenshot |
| AC5 | US002 status/event filtering | PASS | combined backend filters and browser query assertions |
| AC6 | US002 failed callback retry | PASS | durable intent/double-request/completed/stale-generation tests, two-connection worker lock test, original retry regressions and browser retry |
| AC7 | US003 attributable health and last checked | PASS | same-type partner separation, successful/failed actual collector contract tests, list browser |
| AC8 | US003 24h chart/recent errors | PASS | 24-bucket history/unknown coverage/safe errors tests; SVG, keyboard data table and screenshot |

Clarification: E25's approved source covers incoming callbacks. Delivered denotes successful platform processing, not outbound HTTP delivery. Provider choice remains TBD, and unprobed integrations display Not checked. These semantics are explicit in spec/contracts/UI.

## Test and regression results
| Gate | Result | Evidence |
|---|---|---|
| New Admin + selected existing E25 + prior Notification Admin backend regression | 49 PASS / 230 assertions | backend-final.log |
| Focused Admin security/acceptance (included above) | 12 PASS / 65 assertions | security-final.log |
| Architecture | 4 PASS / 18 assertions; 1 vendor deprecation | architecture.log |
| Frontend Integration/Notification/auth/MFA/navigation/middleware | 118 PASS / 13 suites | frontend-final.log |
| TypeScript | PASS | types.log |
| Scoped Integration ESLint | PASS / zero warnings | lint.log |
| Chromium | 3 PASS | browser.log, browser-results.json |
| OpenAPI | YAML parse PASS / 7 paths, 9 operations | openapi/v1/admin-integration.yaml |
| Local schema dependencies + additive migration | PASS, no seeds/reset/grants | migrations.log |

Existing legacy fixtures were corrected to actual /v1 paths, lowercase enums, Platform audit storage, shared validation envelope, injected handler execution, signed callbacks and durable retry dispatch; business assertions retained.
The destructive shared-Redis flushdb rate-limit/quickstart suites were not executed. Authentication/IP and relevant API/provider/retry/notification regressions above were executed. No production load, live provider call, broad penetration test or user UAT is claimed.
Baseline tooling warnings: obsolete PHPUnit coverage XML attribute, one architecture/vendor deprecation and existing React act warnings in passing auth tests. No coverage report claimed.

## Security findings
Resolved: missing allowlist enforcement; hash serialization risk; cacheable one-time responses; revoked-client revival; unsigned-verifier fallback; duplicate/stale callback worker races; raw provider error disclosure; failed-probe identity loss.
Scoped security gate: PASS. No new unresolved high/critical issue identified in this implementation review.
Verified real PostgreSQL/RBAC and M2M bcrypt/IP behavior with repository test authentication helpers. Browser APIs/cookies are isolated mocks; credentials are test-only. Secrets and payloads are excluded from new Admin logs/audits/responses other than authorized one-time secret responses.
Domain handlers still own business idempotency across crash windows; no claim of external exactly-once effects.

## Browser visual verification
PASS at 1440x1000 and 390x844. Inspected:
- screenshots/credentials-once.png (credential values masked)
- screenshots/providers-desktop.png
- screenshots/webhooks-desktop.png
- screenshots/webhooks-mobile.png
- screenshots/health-desktop.png
Verified one-time dismissal, rotate/revoke confirmation, responsive callback filters/table scrolling, no document horizontal overflow, health scale/unknown bars/error log, keyboard chart-data expansion and unauthorized redirect.

## Definition of Done
**PASS** for implementation scope: three mandatory stories, eight AC groups and fourteen tasks complete; constitution/stack respected; relevant regressions, scoped security and browser checks pass; contracts/status/traceability updated.
Production deployment/UAT/live provider readiness remain separate.

## Recommended next action and deployment dependencies
Review the change manifest and conduct operator UAT under approved existing integrations.manage roles. Apply the additive migration and E25/Ops dependencies in target environments; enable existing scheduler/Horizon workers, including retry-intent polling.
Register approved provider adapters/signature verifiers and implementations of the existing health-probe contract before relying on live measurements. No concrete provider was selected or credentials installed. Unsupported providers remain safely unmeasured, and unsigned callbacks are rejected.
Do not begin ADM-P1-021 without a separate instruction.
