# P26 Release Scope Change: Online-Only Admission

**Record:** P26-SC-014  
**Requirement:** T014 / FR-012 (offline admission)  
**Status:** APPROVED DEFERRED FOR P26 SCOPE — project-owner acceptance recorded; no repository-specific scope-change approver or separate approval workflow was found  
**Prepared:** 2026-11-03  
**Project-owner direction:** Defer offline admission; use online-only validation for this release.

## Requested scope change

For this release, gate admission requires a live connection to the authoritative Events validation API. When the operator device is offline or the request outcome is unknown, the interface must not report admission as confirmed. No offline credential validation, local admission ledger, queued offline scan, or global single-admission guarantee is included. Re-entry and transfer remain unavailable under their existing P25 decisions.

## Reason and consequences

The repository has no approved offline credential signing/key-distribution, staleness, revocation synchronization, local-storage protection, conflict-resolution, or lost-device recovery contract. Disconnected scanners cannot guarantee global single admission without coordination. Deferring offline mode avoids representing an unverified local scan as authoritative. The operational consequence is that a venue cannot admit guests through this Events flow during connectivity loss; operators must wait for connectivity or follow an independently approved venue-contingency process, if one exists.

## Current behavior and development verification

- The Agent gate UI disables start/validation while connectivity is reported unavailable.
- Network loss or a server error after submission is displayed as **Admission not confirmed**, never as admitted or denied. The operator is instructed to reconnect and scan again.
- The online Events endpoint serializes by ticket and returns `already_admitted` on a retry after a committed first scan; the existing PostgreSQL feature evidence asserts one attendance row and one admission audit row.
- Offline routes/modes are not exposed. Reconnection retries the authoritative online request; no local queue or admission state is created.
- UI tests use a labeled adapter to simulate an ambiguous response; they do not simulate a physical radio/network outage or a server process crash. Native device T023 evidence covers online/manual-entry admission, not camera capture or a physical network transition.

## Recorded project-owner direction

On 2026-11-03 the project owner accepted this online-only release direction: gate admission requires a live connection; offline scans fail closed; an unknown response is never admission success. This is a project scope decision and is not an independent Security/Operations approval or production activation.

## Remaining governance evidence

No additional P26 scope-change approval is specified by the checked-in change-control artifacts. Constitution Principle 15 still requires separate release acceptance and security gates before any production release; this record does not satisfy that release decision.

The online-only direction is accepted by the project owner on 2026-11-03, not by an invented release approver. Development evidence consists of Agent widget tests for offline denial, reconnection, ambiguous/lost response and idempotent authoritative retry, plus the previously verified same-run native online admission retry journey. The adapter models a committed operation with a lost response; it is not physical radio-loss evidence. No physical network fault injection was required to verify the approved deferred scope. Offline validation remains unimplemented and denied. This record does not approve Security/Operations controls beyond the online-only scope, customer communications, production activation or an offline implementation.

## 2026-11-03 acceptance reconciliation

Project-owner online-only direction is accepted for P26 scope. Inspection of the repository change-control artifacts found no separate package-level scope-change approver/workflow. Constitution Principle 15 still requires production release acceptance and security gates. Therefore offline admission is **APPROVED DEFERRED for P26 scope**, and remains unavailable; no production release or online-service activation is approved by this record.