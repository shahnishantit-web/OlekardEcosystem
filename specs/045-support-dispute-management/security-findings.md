# Security findings
Scope: ADM-P1-021 and modified financial/Identity/outbox dependencies. Date: 2026-10-15.
| Finding | Severity | Resolution | Evidence |
|---|---|---|---|
| Identity contract parser omitted explicit signature verification; scope-only proof was not actor/session bound | Critical | CLOSED: SignedWith ES256 + time/token-format validation and dedicated actor/session-bound verifier | Forged, expired, wrong scope/actor/session rejected; real enrolled-PIN challenge and refund pass |
| Existing dispute refund lacked authoritative amount/currency/customer/wallet validation | High | CLOSED: original Transaction lookup, owned wallet pocket, remaining-amount cap and partial reason | Bounds, wrong currency/customer/status/wallet, partial and cumulative-cap tests |
| Existing refund locking/idempotency could permit conflicting keys or ambiguous replay | High | CLOSED: shared financial engine across both APIs, dispute/original row locks, transaction advisory lock, stable journal key, intent hash and actor match | Same-key replay/conflict, different-key terminal denial, cross-connection exclusion, maker-checker and legacy regressions |
| Old refund used journal UUID as refund transaction ID | High | CLOSED: actual linked Transaction projection and immutable history in the same transaction | Real journal/fee/transaction/history assertions and rollback |
| Important Support notifications had no reliable E17 integration | Medium | CLOSED: exact durable event, event-envelope adapter, stable event ID and E17 listeners/templates | Real outbox driver replay/deduplication and customer in-app delivery |
| Evidence upload limits and privileged retrieval | Medium | CLOSED: private case-scoped storage/download, content MIME/decoded-size/count checks, no exposed storage key, deployable PHP upload INI | Executable rejection, wrong-case denial, size/count tests, full10 MiB PDF, authenticated browser download |

No unresolved critical/high issue in this assessed scope. Financial/MFA tokens, credentials and file storage keys are not included in case activity or UI persistence. Central RBAC grants are preserved. All financial effects use immutable balanced journals; code does not directly change wallet balances.
Live external notification-provider delivery remains dependent on the project's existing logging-adapter replacement/configuration. Current API image must be rebuilt/reloaded to adopt the committed upload configuration. These are recorded deployment steps.
