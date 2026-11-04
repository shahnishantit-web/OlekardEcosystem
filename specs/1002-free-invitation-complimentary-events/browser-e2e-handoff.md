# P26 Browser/device E2E handoff

**Current status: isolated Chromium journey VERIFIED; scoped native Customer and Agent event journeys VERIFIED.** Earlier native-not-run statements below are historical and superseded by the dated evidence. Native camera capture and native aggregate-report/Admin interactions remain unverified. Production approvals and activation remain separate and closed.

## 2026-11-02 — Customer PIN and full browser journey verified

- Customer PIN root cause was the legacy segmented `pin_code_fields` browser input bridge: typed digits could appear in the proxy without delivering completion. The PIN component now uses a standard Flutter editable `TextField` over its visual digit cells. Playwright focuses that actual input and enters digits with keyboard events. It does not mutate `input.value`, inject auth state or bypass the login challenge.
- Agent session initialization could race a successful login and overwrite the authenticated state. A generation guard prevents stale startup restoration from changing state after login/logout begins; the async race has a regression test.
- Agent dashboard and unread-count 404s are resolved: the dashboard API is implemented from the existing P032 contract and the Agent inbox client now uses the existing Notifications API contract. Dashboard and inbox behavior is covered by Agent tests. The dashboard still retains actions on metric failure and a distinct Event Gate action; operator gate access remains server-authorized by permission plus exact event/gate assignment.
- Full isolated Chromium journey passed headlessly on 2026-11-02. Stages: Admin password/MFA and event view; Admin invitation issue/revoke; Customer login; complimentary registration; My Tickets/QR card; customer-scoped credential retrieval; other-customer denial; Agent login; exact assigned-gate admission; repeat scan; wrong-gate denial; aggregate report. Playwright reported **2 tests passed** (focused Customer login and full journey).
- Final isolated PostgreSQL assertions passed: registrations `3`, tickets `3`, attendance `1`, attendance audit `1`, free-issuance audit `3`, invitation audit `5`; payment intents/attempts, refunds, journals, payout records and settlement batches all `0`. The successful unique database was removed by the runner.
- Focused Customer PIN login also passed headed Chromium (**1 test passed**); the full journey was run headlessly only. Browser login success in both modes confirms one verify POST and authenticated home navigation. Native Android remains unverified: Pixel_10 boots and Flutter sees it, but SDK license state is unknown and no native P26 journey was run.
- Admin/MFA preflight and runtime suites passed without PHPUnit warning annotations. The runner removes `NO_COLOR` only within its own process and restores it afterward; the latest run emitted no Node color-conflict warning. Flutter Web still emits Wasm dry-run incompatibility and missing CupertinoIcons-font notices; see `evidence.md`.
- Development approvals, publication, identities, pricing and gate assignments were supplied only by the guarded test seeder. The journey is development verification, not production approval, provider certification, independent review or activation readiness.

## 2026-10-31 continuation — MFA CORS fixed, customer PIN bridge remains

- Root cause: the successful MFA path invoked `chillerlan/php-authenticator` 5.0.0, whose PHP 8.4 constructor deprecation emitted output before headers under the isolated runtime. This caused PHP's built-in server to send response bytes before central middleware could attach CORS headers. Error/preflight paths did not invoke that constructor and therefore retained CORS. The resolved dependency is 5.4.1; centralized origin allowlisting and `Vary: Origin` remain in force. No endpoint-specific CORS headers or wildcard origins were added.
- Verified through the isolated runner: exact-origin preflight, allowed-origin password login, invalid-MFA 401, successful-MFA 200 with exact `Access-Control-Allow-Origin`, and Admin event-authoring browser requests. Playwright proceeds beyond MFA without CORS errors.
- Regression checks: central CORS suite exited 0 with 52 assertions and 3 warning-marked tests; Identity MFA suite exited 0 with 70 assertions and 10 warning-marked tests. The warning annotations were emitted by PHPUnit/Pest; no failures were reported. The allowed-origin successful and invalid MFA assertions inspect `Access-Control-Allow-Origin` and `Vary: Origin`.
- The remaining browser failure is after the customer login challenge: the PIN sheet is visible; Playwright sequentially enters six characters into Flutter Web's proxy input (diagnostics retain only `valueLength`, input type and focus state), but Flutter does not dispatch the PIN completion callback and no `/v1/customer/auth/login/verify` request is sent. The current isolated headless environment therefore has not verified customer registration, My Tickets, admission or aggregate reporting. This is an automation/runtime input-bridge limitation at present, not evidence of authentication success or rejection. Do not bypass the UI with a direct API call and call it browser coverage.
- The runner reused current Customer/Agent bundles only after validating their embedded isolated API URL and source mtimes. It created unique DB `wallet_ecosystem_p26_e2e_20261031_22ea2dab`; as a failed-run database, it is preserved and must not be cleaned except by an exact-name, isolated cleanup process.
- Native Android remains unverified because `adb` is unavailable. The Admin MFA browser proof is not native-device evidence.

## Current isolated harness and result (2026-10-30)

- `run-p26-browser-e2e.ps1` creates a new database named `wallet_ecosystem_p26_e2e_YYYYMMDD_<runid>` only after confirming that the exact target does not exist. It uses the isolated `p08-isolated-20261028_wallet_test` PostgreSQL/Redis network, unique API/Admin/Customer/Agent ports, per-run Redis prefix, array cache/session, synchronous queue, live callbacks disabled, ephemeral signing/app/IP-HMAC secrets, and a guarded deterministic test seeder. Failed-run databases are retained; they contain only synthetic fixtures. The existing development database and tracked Admin auth-state file were not touched by the P26 runner.
- API bootstrap, clean migrations and runtime database assertion have passed repeatedly. The runner now also checks exact-origin preflight and invalid-MFA error CORS before starting browser verification.
- Seven runner invocations occurred; one stopped before Playwright because this PowerShell version lacks `-SkipHttpErrorCheck`. Six Chromium attempts failed before the customer journey. The latest reached the real Admin login and MFA flow: login returned HTTP 200; valid MFA returned HTTP 200 through an API request context but lacked `Access-Control-Allow-Origin`; Chromium then reported `net::ERR_FAILED` for `/v1/auth/mfa/verify`. The CORS preflight and invalid-token 401 probes passed. This isolates the remaining harness/API issue to the successful MFA response path (or response-header handling); do not interpret it as an OTP rejection or a passed browser login.
- The first three attempts exposed harness adjustments (PowerShell compatibility, response/request correlation and excess direct login probe); these were corrected. A later run intermittently timed out at the browser password POST with the UI still loading. No run reached invitation issue, customer registration, My Tickets, gate admission or aggregate reporting. Persisted end-state assertions have not executed.
- Native Android E2E remains unavailable because `adb` is absent. Flutter web servers started, but no customer/agent interaction was reached. The exact rerun command is `./specs/1002-free-invitation-complimentary-events/run-p26-browser-e2e.ps1` from the repository root.

### Immediate engineering action

Trace why a successful `MfaController::verify` response has no allowed-origin header although the runtime preflight and invalid-token error response pass. Inspect the actual response headers and middleware/exception path using the isolated harness; correct only the CORS response handling, add a regression test for successful MFA from an allowed origin, then rerun the complete script. Keep login credentials, MFA tokens and OTP codes out of test artifacts and logs.

## Initial environment discovery (superseded by current isolated harness above)

- Admin Playwright CLI is installed (`1.64.0`); Chrome and Edge executables are installed; local ports 3000 and 8000 accept connections. Playwright's `install --list` failed with `EPERM` while reading `%LOCALAPPDATA%\ms-playwright\.links`; use system Chrome in a dedicated config if the browser registry remains inaccessible.
- The current Admin Playwright config runs `playwright/global-setup.ts`, which logs into the development API using a fixed local admin credential and writes `playwright/.auth/admin.json`. That file is already part of the uncommitted worktree. Do not run the current global setup for P26, overwrite that auth file, or use the development API to create invitations/registrations.
- `adb` is not installed/available in PATH. `flutter devices` did not return a device list during the bounded check. No Android emulator/device E2E can be claimed.
- P26 component/widget tests and API/PostgreSQL tests passed, but they do not exercise the whole journey in a browser/device.

## Earlier setup plan (superseded; the harness is now implemented)

1. Create/use a disposable API runtime attached only to `p08-isolated-20261028_wallet_test` and database `wallet_ecosystem_p26_review_20261030`; point Admin and Flutter web to that API, never port 8000.
2. Provide a deterministic, test-only bootstrap for organisation-scoped event author, event/gate assignments, active CUSTOMER identities, event/category/capacity and approved complimentary-allocation test evidence. The bootstrap must be impossible to run outside the isolated test environment and must leave production bindings deny-by-default.
3. Add a P26-specific Playwright config with a temporary auth-state path and no existing global setup. Do not edit/reuse `playwright/.auth/admin.json`. Use normal API authentication and authorization with fixture-issued test credentials; do not intercept calls to bypass server authorization.
4. Start isolated Admin and Flutter web servers with explicit API base URL to the disposable API. Run Chromium tests for: issue/revoke; standard free registration; invitation redemption; capacity/duplicate-submit; expired/revoked/mismatched invitations; purchaser ticket/credential access and another-customer denial; exact event/gate operator admission; repeat scan; aggregate-only report.
5. Assert persisted ticket, registration, invitation usage, attendance/audit and financial-row absence in the disposable PostgreSQL database after each flow. Clean up only that disposable database, never the development DB.

## Earlier API command skeleton (obsolete; run the isolated script above)

The API runtime already has a volume-mounted source tree and Composer vendor volume (`wallet-ecosystem-api-1`); the isolated DB/Redis hostnames and database were verified on the P08 network. The exact API command skeleton, once fixture bootstrap is supplied, is:

```powershell
docker run --rm --network p08-isolated-20261028_wallet_test `
  --volumes-from wallet-ecosystem-api-1 `
  -p 127.0.0.1:8001:8000 -w /var/www/html `
  --env APP_ENV=testing --env DB_CONNECTION=platform `
  --env DB_HOST=p08-isolated-20261028-postgres-1 --env DB_PORT=5432 `
  --env DB_DATABASE=wallet_ecosystem_p26_review_20261030 `
  --env REDIS_HOST=p08-isolated-20261028-redis-1 --env REDIS_PORT=6379 `
  wallet-ecosystem-api php artisan serve --host=0.0.0.0 --port=8000
```

At initial discovery, the P26 fixture and Playwright harness did not exist. They have since been implemented in `run-p26-browser-e2e.ps1`, `playwright.p26.config.ts`, `e2e/p26-free-journey.spec.ts`, the guarded seeder and test provider. Use the exact runner documented above; do not use this historical command skeleton or the ordinary development global setup.

The historical no-journey and CORS/PIN failure notes above are superseded by the 2026-11-02 execution section. T023 is verified for isolated Chromium browser criteria; native-device criteria remain unverified. Component/widget and backend results retain their own evidence boundaries.

## 2026-11-02 latest native continuation

- Agent Android debug APK build passed with JDK 17/Gradle 9.3.1 and the Agent-only `printing` compileSdk 36 override. This is packaging evidence, not a gate-admission journey.
- The optional isolated-runner step `-NativeCustomerLogin` passed **2 Android integration tests** on `emulator-5554`. The challenge and `/v1/customer/auth/login/verify` were sent to the API bound at `10.0.2.2:8100`, after the runner verified the actual database name/host/port. A first test attempt failed because the test selected the phone field for PIN entry; the corrected numeric-field selector passed. No auth bypass or direct API success injection was used.
- The runner then passed the full isolated Chromium journey (**2 Playwright tests**) and persisted state assertions (`3|3|1|1|3|5|0|0|0|0|0|0`); successful test database was removed by exact name.
- Native limitation: no native Customer registration, My Tickets/credential, gate-admission, wrong-gate, repeat-scan or aggregate-report flow was run. No Agent gate UI integration test exists in the exercised suite. Do not promote the login smoke to full native E2E evidence. No Android license acceptance command was run.

## 2026-11-02 native Customer/Agent event journey — latest status

- Exact command: `.\specs\1002-free-invitation-complimentary-events\run-p26-browser-e2e.ps1 -NativeOnly -NativeEventJourney`; passed on Android emulator `emulator-5554`, isolated API `http://10.0.2.2:8100`, disposable DB `wallet_ecosystem_p26_e2e_20261102_b645e29f` (successful DB cleaned by the runner).
- Customer native integration passed 1/1: ordinary phone challenge/PIN login, free-registration UI submission, then purchaser My Event Tickets/credential access. Agent native integration passed 1/1: login, Event Gate navigation, manual-entry credential input, wrong-gate rejection, exact assigned-gate admission and repeat-scan rejection.
- Persisted counts were `2|2|1|1|1|0|0|0|0|0|0`: registrations, tickets, attendance, admission audit, free-issuance audit, payment intents, attempts, refunds, journals, payout records and settlement batches. One registration/ticket was a test fixture and one was created through Customer UI; one seeded ticket was admitted exactly once. Financial records remained absent.
- Setup authority/publication/assignments are synthetic test fixtures. No token, PIN or credential is recorded. Camera capture was not exercised. Native Admin invitation and aggregate-report screens were not exercised; they remain verified only by the separate Chromium journey. Native cross-customer credential denial was not separately exercised.
- Full Customer and Agent analyzers passed with no issues (248.7s and 192.8s). The Agent test build emitted non-fatal Kotlin Gradle Plugin migration warnings. No SDK license terms were accepted.
- A stricter repeat after adding `wallet.wallet_capture_records` to the persisted native assertion passed CORS/MFA and isolated DB identity checks but stalled before the UI tests: ADB showed the emulator as `device`, while `adb shell getprop`, `flutter devices --machine`, and a non-destructive reboot command did not return. Its failed DB `wallet_ecosystem_p26_e2e_20261103_cd9f81a8` was preserved; only fixture state existed. A separate prior Customer-native DB query confirms zero captures after free registration, but the same-run combined capture assertion remains pending. Rerun after emulator shell/device discovery responds; see `evidence.md`.

## 2026-11-02 continuation: Agent APIs and browser rerun

- Existing P032 contract defined `/v1/agent/dashboard`; the missing route was implemented in AgentFloat. It derives Agent identity server-side, requires the existing float and transaction view permissions, reads Ledger balance and completed float transactions, and returns 503 when the configured threshold remains unresolved. Tests deny query-selected cross-Agent access and missing permissions.
- The Agent notification client now uses the existing `/v1/notifications/inbox` and unread-count contracts, including actual server field names and PATCH read semantics. No endpoint was added for notifications.
- Rerun `run-p26-browser-e2e.ps1`: CORS 3/52 assertions, MFA 10/70 assertions, Playwright 2/2 passed; final PostgreSQL invariants are listed in `evidence.md`. The journey remained isolated and used the guarded test fixture provider.
- Native Android remains unverified: `flutter doctor -v` now lists `emulator-5554` after Pixel_10 boot. The license state is unknown, Customer's available local-login smoke targets development API port 8000 by default, and Agent lacks Gradle wrapper/settings files. Do not run an unsafe local smoke or claim native evidence.

## 2026-11-03 same-run native Customer/Agent verification (latest)

- After project-owner approval, the existing Pixel_10 emulator was restarted without wiping data. ADB returned `emulator-5554 device`, boot property `1`, and Flutter discovery listed Android 17/API 37. No SDK license was accepted.
- `run-p26-browser-e2e.ps1 -NativeOnly -NativeEventJourney` passed on fresh disposable DB `wallet_ecosystem_p26_e2e_20261103_d3fc5751` after runtime target validation. Customer completed real challenge/PIN login, free registration, My Tickets and credential access. Agent completed login, Event Gate navigation, wrong-gate rejection, exact-gate admission and repeat-scan rejection using supported manual entry.
- Same-run counts `2|2|1|1|1|0|0|0|0|0|0|0` verified registrations, tickets, attendance, admission audit, free-issuance audit, Payment intents, attempts, Wallet capture records, refunds, Ledger journals, payout records and settlements. Test setup supplied one seeded ticket and synthetic approvals/publication/assignment; Customer UI created the second registration/ticket; Agent admitted the seeded ticket once. The runner cleaned only this successful run.
- The earlier Agent test failure was caused by not awaiting async `app.main()` before locating the login screen. The integration test now awaits startup. A separate Customer challenge failure did not recur on the successful run. Customer and Agent `flutter analyze` both passed with zero issues after the test change.
- No native camera capture or native Admin/aggregate-report screen was exercised. The same-run counts establish absence of financial effects for this free path only; they do not verify paid or Wallet capture behavior. Earlier offline/no-device notes above are historical and superseded for T023's tested native scope.

## 2026-11-04 current-source rerun

The guarded command .\specs\1002-free-invitation-complimentary-events\run-p26-browser-e2e.ps1 passed the focused login and Admin/customer/gate journey (2/2 Playwright tests) using isolated database wallet_ecosystem_p26_e2e_20261104_5e26ee81. Persisted counts matched 3 registrations, 3 tickets, 1 admission, 1 admission audit, 3 free-issuance audits, 5 invitation audits and zero payment intents, attempts, refunds, journals, payouts or settlement batches. Synthetic approval/publication/operator fixtures were used. The browser journey is not production approval or native camera evidence. Customer and Agent JavaScript web builds succeeded; optional Wasm dry-run and Cupertino font warnings are recorded in evidence.md.