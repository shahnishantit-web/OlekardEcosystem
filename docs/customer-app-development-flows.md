# Customer app development flows

The customer app uses the canonical profile, wallet, transfer, funding, withdrawal,
service, and transaction APIs. Financial amounts use integer minor units. Review
shows the server fee; confirmation uses the entered PIN and scoped identity proof.
An uncertain/pending response is never displayed as a completed payment.

## Local setup

Run the API migrations and `php artisan db:seed --class=DevCustomerAppSeeder` in
`wallet-ecosystem/services/api`. The seeder is restricted to local/testing, grants
explicit consumer permissions, provisions wallets through domain services, and
adds clearly named development services and mock-bank accounts. It never writes
wallet balances. Existing configuration values are preserved.

Run the API on port 8000. Android emulators use `http://10.0.2.2:8000` by
default, so `adb reverse` is unnecessary. Physical devices and deployed builds
must supply `--dart-define=API_BASE_URL=<reachable API origin>`. Run the queue worker
with `php artisan queue:work --queue=outbox,withdrawal,notifications-critical,default`
and the scheduler with `php artisan schedule:work` so Activity projections update.

Start Flutter with `flutter run --dart-define=ENABLE_DEV_MOCK_BANK=true`.
The pending bank-funding and withdrawal screens expose an explicit development
completion button. Its endpoints require authentication, ownership, permission,
idempotency, and prior PIN confirmation, and are registered only in local/testing.
They invoke bank matching/provider callbacks and the real balanced ledger and
receipt services. No live bank integration is configured.

Local customer: `+26771000001`, PIN `123456`; transfer recipient: `+26771000003`.
For a mock withdrawal use bank code `MOCK` and a development account number.

## Verification

Focused Flutter authentication, biometric, wallet, checkout, financial, and money
orchestration tests pass (38 tests). The Android opt-in test
`integration_test/customer_money_connection_test.dart` passed with
`--dart-define=RUN_LOCAL_MONEY_SMOKE=true`. It performs actual local login, profile
and wallet reads, top-up and replay, transfer, bill payment, mock withdrawal,
balance checks, and route smoke checks. It creates small development ledger entries.
The Android debug APK builds successfully.

Existing backend withdrawal fee tests pass (6), as do the new typed-outbox and
currency normalization tests (2). Profile save was verified with HTTP 200 while
preserving the customer's existing name. Transfer/funding feature tests
currently fail in legacy fixture setup (including obsolete configuration `key`
and wallet schema fields), before exercising the repaired application paths.
Two unrelated Firebase token API deprecation notices remain in Flutter analysis.

Encrypted withdrawal destinations now use text storage compatible with Laravel's
encrypted array cast. Activity delivery restores known typed integration contracts;
transfer views use deterministic UUIDs and service payments have an explicit type.

## Login bootstrap regression

A UTF-8 byte-order mark in `services/api/config/database.php` caused PHP to fail
before Laravel booted. That prevented both customer authentication and admin
CORS middleware from running, producing an HTML fatal error instead of API JSON.
The mark was removed without changing database configuration. The standalone
`tests/Architecture/PhpSourceEncodingTest.php` now checks runtime PHP source
headers and can run directly through PHPUnit before bootstrapping Laravel.

Real browser verification (`specs/admin-browser-validation/login-repair-smoke.cjs`)
confirmed admin password login, the allowed-origin response header, and navigation
to `/dashboard`. Customer challenge/PIN login and CORS regression tests also pass.

Login repair verification: 23 Flutter authentication/endpoint/biometric tests,
7 backend PIN/CORS tests, the source-encoding check (2,569 assertions), and both
Android login integration tests passed. Scoped Flutter analysis found no issues.
The emulator test verified the PIN sheet, successful PIN submission, and `/home`
using the Android host endpoint without relying on localhost forwarding.
