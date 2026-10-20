# Configuration key verification — 2026-10-19

The configuration list already links with the complete registered key, such as
`/configuration/auth/auth.biometric.enabled`. The detail route incorrectly
prepended the grouping domain again, requesting `auth.auth.biometric.enabled`.
It now passes the key unchanged to both the detail and version-history client.
The canonical API is `/v1/admin/configuration/keys/auth.biometric.enabled`.

The list also lacked pagination, hiding registered keys after the first 50.
It now uses Laravel's top-level pagination fields and provides Previous/Next
controls, resetting to page 1 when the domain or search changes. Domain typing
now reflects backend-registered module domains instead of restricting it to
three policy groups.

Verification against the running local API and admin app:

- Enumerated all 76 registered keys across two pages, including inactive keys.
- All 154 API requests (two list pages, 76 details, 76 histories) returned 200
  and `Access-Control-Allow-Origin: http://localhost:3000`.
- All detail keys and values matched their list entries, including null values.
- Authenticated Chrome checked a real key in each of 10 domains; all 20 underlying
  detail/history requests used canonical keys and returned 200.
- Configuration Jest suite: 27 tests passed, including canonical route keys,
  cross-domain key names, pagination, permissions and existing maker/checker tests.
- `tsc --noEmit --pretty false`: passed.

No configuration change requests or value updates were submitted. Existing TBD
values and authorization/approval behavior are preserved. The earlier page-only
link audit did not detect the duplicated API parameter; this audit verifies the
actual requests. Machine-readable results are in `configuration-key-audit.json`;
rerun with `node specs/admin-browser-validation/configuration-key-audit.cjs`.
