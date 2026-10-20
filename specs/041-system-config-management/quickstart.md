# Quickstart / Smoke Tests: System Configuration Management (ADM-P1-017)

## Prerequisites

- Docker stack running (`docker compose up` from `wallet-ecosystem/`)
- Admin seeded: `admin@olekard.local` / `Admin123!`
- E28 configuration keys seeded in DB

## SC-01 — Config list RBAC gate

```
role: user without adminapp.config.read
GET /configuration
expected: access-denied card visible; no config keys table
```

## SC-02 — Edit form visible for writer, hidden for reader

```
role A (config.read only): navigate to /configuration/{domain}/{key}
expected: current value shown, version history shown, NO edit form / textarea

role B (config.write): same URL
expected: edit form (textarea) visible and enabled
```

## SC-03 — Diff preview before submit

```
role: config.write
action: enter new value in textarea, click "Preview Change"
expected: before/after side-by-side panel appears with current value and proposed value
action: click "Confirm Submit"
expected: API call made; success banner shown
```

## SC-04 — Pending change disables edit form

```
pre-condition: key already has pending_change_request_id set
role: config.write
navigate to key detail
expected: edit form is disabled; notice "A change is pending approval" with link to /configuration/approvals/{id}
```

## SC-05 — Approvals list RBAC gate

```
role: user without adminapp.config.approve
GET /configuration/approvals
expected: access-denied card; no table
```

## SC-06 — Maker cannot approve own change

```
role: admin who submitted the pending change
navigate to /configuration/approvals/{id}
expected: Approve button is disabled; notice "You cannot approve your own change request" visible
Withdraw button is enabled
```

## SC-07 — Checker approves change

```
role: admin who did NOT submit the pending change (has adminapp.config.approve)
navigate to /configuration/approvals/{id}
click "Approve"
expected: redirect to /configuration/approvals; change no longer in pending list
```

## SC-08 — Reject with reason

```
role: checker (config.approve, not maker)
click "Reject" on approval detail
expected: reject dialog appears with textarea
leave textarea empty → "Confirm Rejection" button disabled
type reason → button enabled
click "Confirm Rejection"
expected: redirect to /configuration/approvals
```

## SC-09 — Version history with approved_by and effective_from

```
role: config.read
navigate to config key with history entries
expected: table shows columns: Event, Value, Changed By, Approved By, Occurred At, Effective From
rows with approval actor: Approved By shows truncated UUID
rows without approval actor (non-critical/direct): Approved By shows "—"
```

## Run unit tests

```powershell
cd wallet-ecosystem/apps/admin
npx jest --testPathPattern="p1017" --no-coverage
```

Expected: 22+ tests pass, 0 failures.
