# ADM-P1-022 security review
Scope: current changed E29/E28 code, routes, financial configuration and UI; review plus scoped tests/static analysis, not penetration certification.

| Finding | Disposition / evidence |
|---|---|
| Existing FX activation trusted pending status without approved E28 request or distinct checker | Fixed: E28 approved matching ID/key/maker/content, second-checker and configuration.approve gates; legacy bypass and self-approval negative tests. |
| Repeated submission created new pending rate despite E28 returning original request | Fixed: actor/payload/date binding and original proposed ID, UUID key + PostgreSQL advisory transaction locks; replay/conflict tests. |
| Direct currency status/precision/upsert bypassed financial approval | Fixed: status E28 workflow, immutable precision, inactive audited registration; legacy bypass tests. |
| Reapplying an old approved currency change could undo a later decision | Fixed: durable applied-request registry; stale-replay test. |
| Provider updates could override a manual quote or expose upstream exception contents | Fixed: manual-source/provider/time gates; sanitized503; injected adapter and secret-marker negative tests. |
| Repeated E28 approvals collided on default draft version1 | Fixed: per-key locked next version allocation; repeated approval integration test. |

Permissions preserved: multi_currency.manage_currencies / configure_rate; checker additionally configuration.approve. No automatic grant or role seed run. Existing centralized signed JWT/MFA implementation unchanged. UUID keys for financial config changes and legacy rate activation. Decimal18,8 and fractional spread6,5 validated without float conversion; financial values remain strings. UI numeric conversion limited to chart geometry. No balance, journal or historical rate-number edits.

Release blockers: provider selection/configuration and live verification; failing broad E29 regression fixtures. No confirmed new critical/high security defect remains in the scoped checks, but broad financial preservation cannot be certified until T016 passes. Provider freshness/refresh/vendor policy remains to be approved/configured with the live adapter; no guessed values or provider secrets committed.
