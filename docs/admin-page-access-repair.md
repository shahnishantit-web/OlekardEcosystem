# Local admin access and page data repair — 2026-10-18

The development database was missing 66 route permission definitions. The local
administrator lacked 78 required grants. The first authenticated GET sweep
returned 71 forbidden responses out of 100 requests. The application also had
missing module migrations, obsolete schema queries, conflicting user routes,
and UI permission names that did not match the backend.

## Repairs

- `DevAdminFullPermissionsSeeder` registers explicit route permissions and grants
  the existing local admin role the active catalogue. It uses current schema
  fields, records an audit event, and invalidates the named actor's permission
  cache instead of flushing Redis. It only runs in local/testing environments.
- The active local account now has 190 permissions, with no missing route
  definitions or grants. API authorization, runtime security policy, MFA and
  maker/checker checks remain enforced. Sign out and sign in again to refresh
  the browser's JWT permission claims.
- Configuration, compliance and reporting screens now use backend permission
  names instead of nonexistent `adminapp.config.*`/compliance/report aliases.
- `/v1/admin/customers` and `/v1/admin/customers/{userId}` provide customer reads
  without colliding with Identity's administrator-account `/v1/admin/users`
  resource. Existing Identity routes retain their account-management behavior.
- Notification preferences now have canonical `/v1/notifications` routes while
  preserving `/api/v1/notifications` compatibility. CORS covers both prefixes.
  The missing support-summary read and incorrect transaction-export status path
  were corrected.
- Wallet currency/pocket reads and session activity fields use the current
  schemas. Ledger history reads go through the Ledger module contract and
  preserve date/type filters. Compliance transactions support filtering and
  cursor pagination. Verified JWT authentication also populates Laravel's
  request user after session/device/runtime-policy checks.
- QA API adapters consume the actual single `data` wrapper. Financial dashboards
  return the fields consumed by the screen, aggregate current module tables and
  use ledger balances. Local dashboard reads refresh an expired cache on demand;
  deployed environments retain scheduled refresh and unavailable-cache errors.
  Query failures are no longer silently represented as zero-valued metrics.
- Applied 55 missing migrations for audit/compliance, reporting, service/QR,
  reliability, operations, QA and other already implemented modules. Existing
  Reconciliation migrations were already recorded in its separate repository
  and were not rerun.

## Local data

Run from `wallet-ecosystem/services/api`:

```powershell
php artisan db:seed --class=DevAdminFullPermissionsSeeder
php artisan db:seed --class=DevAdminDemoDataSeeder
php artisan adminapp:refresh-dashboard-cache
```

The idempotent demo seeder adds an admin profile, KYC review example, pending
transaction projection, support case, risk alert, informational security
finding, empty draft reconciliation and settlement batches, a paused report
schedule, an inactive integration, a disabled operations alert rule and an
explicitly unsent notification-log example. It registers metadata for existing
configuration values without changing those values or resolving TBD policies.
It also seeds the approved report definitions and QA test-case catalogue.

Current data includes 76 configuration keys, 10 report definitions and 144 QA
test cases. No money was posted or moved, no provider was selected or activated,
and no notification was sent. The development ledger remains at 16 journals and
32 journal lines. Credentials and external provider configuration are not
invented to fill empty screens. Operational telemetry, real report/QA execution
history and legally defined retention policies can legitimately remain empty.

## Verification and limits

Evidence is in `specs/admin-browser-validation/current-*`:

- Source dependency inventory covers 94 page routes (75 static, 19 dynamic).
  It is a static import/API mapping, not observed browser navigation.
- Final live sweep: 127 authenticated GET requests, including representative
  detail APIs; 124 returned 200, zero returned 403, zero returned 500.
  The remaining responses are two expected customer-only 404s (the administrator
  has no personal wallet/KYC case) and a 422 for an FX preview without inputs.
- Full frontend regression: 778 tests in 52 suites and 21 snapshots pass.
  TypeScript and scoped frontend lint pass.
- Backend regression uses the isolated database
  `olekard_admin_repair_tests_20261018`, including real JWT request identity,
  negative authorization, permission provisioning, demo-seed idempotency,
  compliance pagination, ledger history, dashboards and CORS. See
  `current-regression-final.log` for the final result. The final suite has 30
  tests, including preservation of existing notification policies.
- Browser inventory exposed no controllable browser; opening the in-app browser
  returned "Browser is not available". No visual page verification or financial
  mutation/provider-delivery workflow is claimed. Report/QA run detail pages and
  approval details without real records remain unverified. An unused audit-detail
  helper pointing to an unregistered route was removed; the current audit page
  uses list-provided detail data.

A pre-migration backup is retained inside the PostgreSQL container at
`/tmp/admin-page-repair-20261018.dump`. The separate regression-test database and
its snapshot do not change the development database.
