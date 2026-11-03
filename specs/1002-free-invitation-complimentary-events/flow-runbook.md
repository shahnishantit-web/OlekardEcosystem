# P26 Flow and Operator Runbook

## Configure and issue an invitation

- **Trigger/prerequisites:** an authenticated event operator with `events.event.update` and an active event-scoped Events assignment selects a zero-price free/complimentary category, recipient customer actor ID, expiry and use count. Expiry must precede event start. Events now calls Identity's typed active-CUSTOMER lookup. Production issue/redemption remains denied until exact organisation/event/category complimentary-approval evidence is available and verified.
- **State owners:** Events owns category quota, invitation state (`active`/`revoked`, validity and use count), invitation audit, registration and entitlement. Identity owns actor identity and authentication. Payments, Wallet and Ledger have no role in zero-price issuance.
- **Responsibilities:** Admin/event operator configures the category and issues/revokes within assigned scope. Customer mobile receives a token through an approved private channel and never places it in a URL. Foundation/Identity authenticates actors and verifies that a recipient is an active CUSTOMER; Events validates allocation approval/state and writes the audit.
- **Success:** Events stores only a token hash plus encrypted token for issuer replay recovery; it returns the opaque token to the authorised issuer once. P26 does not deliver tokens; any sharing must use the organisation's existing approved private communications process, which P26 does not implement. A retry with the same idempotency key recovers the same secret.
- **Failure/recovery:** invalid category, expiry, quota, permission, assignment or idempotency conflict fails closed. Invitation revoke records actor/time; it does not revoke an already issued ticket. Lost token recovery uses the same idempotency key and authenticated issuer; do not regenerate on arbitrary list/read requests.
- **Evidence:** invitation row, issuer provenance, hashed token, invitation audit record, event audit; never log or copy the token into analytics or audit details.

## Free registration

- **Trigger/prerequisites:** authenticated customer submits event ID, category ID, optional invitation token and `Idempotency-Key`. Identity middleware derives the actor. Event must be currently published at the current version with sales active; category must be explicitly free/complimentary and zero-price; the approval contract must authorize the exact organisation/event/category. The production binding currently denies until that evidence source is supplied.
- **Transaction/state ordering:** lock event, category, then invitation; check event/version/sales, token hash and recipient/expiry/use limit, per-person limit, complimentary quota and shared capacity (`issued_quantity` plus active paid holds); insert registration and free ticket; increment use/issued counters; write invitation/event audit. Commit together. No currency lock or payment interaction occurs.
- **Responsibilities:** Customer mobile submits the minimal registration request and later opens My Tickets. Admin manages event/category and invitation configuration. Foundation/Identity derives authenticated customer context; Events owns all eligibility, capacity and entitlement decisions. Payments, Wallet and Ledger remain uninvolved.
- **Success:** one registration and one unique credential-backed domain entitlement. Ticket source is `free` or `complimentary`; `order_id` and `order_line_id` remain null. Customer is redirected to the existing purchaser-scoped My Tickets view to retrieve the credential.
- **Failure/recovery:** expired/revoked/used/mismatched invitation and sold-out allocation return a non-disclosing error. Database rollback restores invitation use and capacity. Same idempotency key/request returns the stored registration/ticket; a conflicting request is denied. If the same completed request is replayed after the invitation is later revoked, the historical result is returned without a second use or ticket; a new request with a new key is denied.
- **Evidence:** registration and ticket rows, category `issued_quantity`, invitation use/audit where applicable, and absence of payment intent/order/ledger effects.

## Invitation revocation and redemption ordering

- Both registration and invitation revocation lock the Events event row first. Registration then locks category and invitation; revocation locks invitation after the event. These operations serialize on the shared event row.
- If revocation commits first, a new redemption sees `revoked` and fails without registration, ticket, capacity increment, invitation-use increment or redemption audit.
- If redemption commits first, the registration/ticket and invitation use remain committed; a later invitation revocation blocks future new-key redemption but does not invalidate the issued ticket.
- Replaying the same committed idempotency key after later revocation returns that historical registration/ticket and does not add an invitation use or ticket. A new request key is checked against current revocation state and fails.
- Audit order reflects the committed serialization (`revoked` only, or `redeemed` followed by `revoked`). Separate PostgreSQL sessions tested both serial orders and a synchronized competing race. If either transaction fails before commit, retry only with the same request key and same input; rollback restores capacity/use and the retry issues at most one entitlement.

## Online admission

- **Trigger/prerequisites:** operator is authenticated, has `events.gate.validate` and active assignment to the exact event and gate; the event is open for entry; customer presents the private credential in the existing gate control.
- **State owners:** Events owns credential/ticket status, gate assignment, attendance and admission audit. A persisted, registration-linked free ticket bypasses only payment/refund lookup; paid tickets retain P25 payment/refund checks.
- **Responsibilities:** Gate operator uses the authorised scanner interface; customer mobile displays the purchaser-owned credential; admin/event operators manage event and gate assignments; foundation provides online authenticated identity and authorization. Events performs the final scan and attendance decision.
- **Success:** online validation writes one attendance and audit record atomically and returns status/category only. Concurrent attempts serialize on the ticket; a duplicate is denied.
- **Failure/recovery:** invalid/revoked credentials, wrong gate/event/tenant or offline scanner request fail closed. No re-entry, transfer or offline exception is enabled. Correct network/service availability and retry a denied transient request; do not manually mark admitted.
- **Evidence:** ticket admission state, attendance and audit record. Credential/token is excluded from URLs, audit payload and report fields.

## Aggregate attendance report

- **Trigger/prerequisites:** an authorised event-scoped attendance reader requests the event report.
- **State owners:** Events aggregates registered free entitlements, valid issued paid tickets, unique attendance, and no-shows only after event end. No attendee names, ticket IDs or scan records are exposed.
- **Responsibilities:** Admin/organiser with event-scoped attendance permission views the report; customer mobile receives no organizer report data; foundation enforces authorization and Events returns aggregate-only counts.
- **Success:** category-level counts only. A pre-event report marks no-shows unfinalized; post-event counts are finalized from eligible issued entitlements without attendance.
- **Failure/recovery:** authorization and event-scope errors reveal no report. Re-run the query; it is derived from durable Events state and creates no financial effect.

## Blocked operational flows

- **Event cancellation / cancellation notices:** blocked until P25 supplies an approved cancellation lifecycle, entitlement invalidation contract, notification template and delivery/reconciliation evidence. Invitation revocation is not event cancellation. Revoking an invitation is audited but no recipient notification is currently queued; it requires an approved notification contract/template and delivered-status evidence.
- **Offline admission:** remains unavailable pending approved P25 offline policy and conflict/reconciliation controls.
- **Production invitation issuance:** Identity active-CUSTOMER verification is implemented. Issue/redemption remains blocked by the missing complimentary-allocation authority and verifiable approval evidence (T006).
- **Free-only publication/activation:** blocked; do not bypass Finance review or P25 publication/sales deny bindings.

## Isolated browser verification (T023)

### Agent API monitoring note (2026-11-02)

The Agent dashboard uses `GET /v1/agent/dashboard`, defined by P032. It resolves the Agent from authenticated Identity context and enforces existing own-float and transaction-view permissions. A missing Agent profile is unavailable; a missing/TBD low-float threshold returns a service-unavailable response rather than fabricated metrics. The unread-count and inbox views use the shared recipient-scoped Notifications routes. Do not use client Agent IDs to select another Agent or treat a failed dashboard request as successful empty financial data. The Event Gate action remains available, with gate authorization enforced by the API.

- **Trigger/prerequisites:** developer runs `./specs/1002-free-invitation-complimentary-events/run-p26-browser-e2e.ps1` from the repository root with Docker, the isolated P08 PostgreSQL/Redis network, Playwright/Chrome, and Flutter web available. The runner chooses unique ports and a unique dated P26 database, verifies the database is absent before creation, applies project bootstrap/migrations, and checks the API's effective database before launching clients.
- **Authority/data boundary:** only the guarded test seeder creates synthetic identities, scoped assignments, test event/category/gate state and test complimentary-approval binding. `APP_ENV=testing`, the P26 fixture flag and the dedicated database-name pattern are required. No production grant or approval is produced. Payment callbacks/live processing are disabled.
- **Success/recovery evidence:** successful run must show Playwright journey results and persisted registration/ticket/attendance/audit counts with unchanged financial-record counts. Successful runs clean up only their exact database; failed runs retain the exact synthetic database for inspection and stop API/browser processes. Do not clean or reset shared/development databases.
- **Current status (superseding):** isolated Chromium journey and persisted PostgreSQL assertions pass. The native Customer free-registration/ticket-access and Agent exact-gate online admission journeys also pass on `emulator-5554` using the manual credential-entry UI. Native camera capture, native aggregate-report UI and native Admin invitation UI were not exercised. The earlier MFA/CORS failure is historical and resolved; see the dated evidence in `evidence.md` and `browser-e2e-handoff.md`.
- **Optional native smoke:** run `.\specs\1002-free-invitation-complimentary-events\run-p26-browser-e2e.ps1 -NativeCustomerLogin` from the repository root. The existing runner first builds/starts the unique isolated database/API and verifies its runtime identity, then uses `http://10.0.2.2:8100` only for the Android emulator test. The Customer test uses the isolated fixture's seeded identity, verifies challenge and PIN login, then the runner continues the existing Chromium journey and exact final-state assertions. No production/shared development API fallback exists in this invocation.

### Native Customer/Agent event journey

- **Command:** `.\specs\1002-free-invitation-complimentary-events\run-p26-browser-e2e.ps1 -NativeOnly -NativeEventJourney` from the repository root. `-NativeOnly` requires `-NativeEventJourney`; the runner still starts its isolated PostgreSQL/Redis/API fixture, validates the exact disposable DB and effective API URL, and does not fall back to port 8000 or production.
- **Customer:** real native login challenge/PIN verification, free registration form, and My Event Tickets view. The seeded event, approval test fixture and one existing ticket are synthetic. The customer-created registration is a real API mutation from the native UI.
- **Agent:** real native login, Event Gate UI, manual credential entry, wrong-gate rejection, exact assigned-gate admission, and duplicate scan rejection. Camera capture is outside the verified path.
- **After UI:** the runner asserts persisted registration/ticket/admission/audit counts and confirms no payment, capture, refund, Ledger, payout or settlement records. A successful run removes only its own disposable database; failed run databases are retained for diagnosis. Never clean old failed DBs unless an exact-name cleanup is separately authorized by retention policy.
- **Evidence boundary:** fixtures prove only development behavior. Admin invitation controls and aggregate reports were verified by the separate Chromium journey. Native aggregate-report and camera UI are not claimed.
- **Follow-up readiness:** before rerunning the stricter native financial assertion, confirm `adb -s emulator-5554 shell getprop sys.boot_completed` returns `1` and `flutter devices --machine` exits. A later isolated run stalled at these device-shell checks while `adb devices -l` still showed the emulator as connected; the failed run's DB was retained. Do not reset old P26 databases. The runner's final native query now explicitly includes Wallet capture records.

## 2026-11-03 release and publication controls

- **Development-only free publication:** an internal Events workflow may publish only a current-version, event-reviewed event whose categories are all explicitly free/complimentary at authoritative zero price. Complimentary allocations must pass the T006 approval contract. Any paid category retains distinct Finance price approval. Successful publication leaves sales paused. The production readiness provider remains a deny binding; this is not an operational runbook for live publication.
- **Offline admission:** project-owner direction selected online-only for this release. Keep offline scanner actions unavailable; do not manually bypass online gate validation during an outage. Reconnect and retry through the online API; duplicate scans remain rejected by the authoritative admission service. The formal release-scope exclusion and connectivity-loss verification are still pending.
- **Cancellation:** unknown financial outcomes remain in containment. Do not mark tickets cancelled, refunded or financially resolved without the P25 cancellation and Payments/Ledger recovery contracts. Do not send a cancellation notice from a queued event until the owning Notification delivery contract exists.

T006 implementation delta: typed evidence snapshots on invitations/registrations/publication; bounded scope checks and tests are complete for development. The port does not itself prove authority, because only a Governance-owned verifier/provider could establish that; no such production binding or operational assignment exists. The new snapshot migration is applied only by the isolated harness. See data-model.md and evidence.md.

### 2026-11-03 native verification checkpoint

The guarded native command is `.\specs\1002-free-invitation-complimentary-events\run-p26-browser-e2e.ps1 -NativeOnly -NativeEventJourney`. It validates a unique disposable PostgreSQL target before migration, assigns a per-run API port, and sends both emulator apps to that isolated API through `10.0.2.2:<port>`. Require `adb devices` state `device`, `sys.boot_completed=1`, and Flutter device discovery before execution. The latest run passed Customer login/PIN, free registration and purchaser ticket access, plus Agent wrong-gate denial, assigned-gate admission and repeat-scan denial; exact persisted counts including Wallet captures are in `evidence.md`. Setup approvals, publication and gate assignments are synthetic test fixtures. Manual credential entry is exercised; camera capture is not. The runner cleans only a successful run's exact DB/container/image; failed databases are retained for diagnosis and must not be reused or deleted outside the documented retention policy.
