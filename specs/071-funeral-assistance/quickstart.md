# P24 Quickstart and Acceptance Validation

**Date**: 2026-10-27  
**Mode**: local/dev and test only; live payment processing remains disabled.

**Current gate:** All P24 HTTP routes and the Payment funeral purpose resolver are closed. The repaired P18 beneficiary table exists locally and focused domain tests pass, but organisation membership, concurrency, finance approvals and full UI journeys remain acceptance targets. See `evidence.md` before attempting them.

## Prerequisites

- API and PostgreSQL service available; apply the P24 migration using the repository's Laravel migration workflow.
- P18 beneficiary record is `verified` and belongs to the same organisation. No payout adapter or settlement credentials are required or permitted for these scenarios.
- Test community, households, representatives, and actor permissions are seeded in an isolated test database.
- P22 shared checkout and P19 history/receipt APIs are available for integration tests.

## Validation scenarios

| ID | Flow | Acceptance evidence |
|---|---|---|
| SC-01 | Create community, household and representative | Correct tenant; representative begins unverified; evidence returned only as restricted reference. |
| SC-02 | Verify representative and scope mobile read | Verified actor sees own household obligation; other household/community IDs return not found/denied. |
| SC-03 | Review funeral request; edit beneficiary | Verified organisation beneficiary required; edit clears reviewer/approval and blocks activation. |
| SC-04 | Activate collection twice/concurrently | One frozen snapshot, one snapshot member/obligation per eligible household, no obligation for approved exemption. |
| SC-05 | Resolve purpose through Payments | Returns 2,000 BWP server-side for authorized household only; client-supplied amount cannot override. |
| SC-06 | Shared checkout then duplicate confirmed event | One P19/shared payment reference and one obligation fulfilment; duplicate event is no-op. |
| SC-07 | Close with pending intent then confirm late | New intent blocked; confirmation retained as `late_review`; no fulfilment/refund/settlement/payout transition. |
| SC-08 | Close and aggregate report | Gross, fees, refunds, net, settlement and payout remain distinct; report contains no household identifiers. |
| SC-09 | Archive with unresolved late/refund/payout liability | Archive returns conflict; an approved carry-forward record is required. |
| SC-10 | Attempt payout request | P18 execution gate returns explicit unavailable/blocked response; no mock provider call or financial posting occurs. |

## Automated checks

Run focused backend tests from `wallet-ecosystem/services/api`:

```powershell
php artisan test tests/Feature/Modules/CommunityFuneral
```

Run P22/P23 shared checkout/history tests as configured in their package tasks. Docker-gated P18/P22/P23 acceptance remains a release gate; report actual run counts and skips in `evidence.md`.

## Operator runbook

1. Review request and beneficiary verification evidence under the authorised community scope.
2. Activate only after independent reviewer approval; record collection terms/version and verify frozen snapshot counts.
3. Do not edit snapshot membership or create replacement payment intents for unknown outcomes. Query the existing shared Payment intent and use P19 history/receipt evidence.
4. On post-close confirmation, retain event and payment reference in `late_review`, notify Finance/Community reviewer, and keep the liability open. Do not auto-fulfil, refund or discard.
5. Close new initiation; reconcile every obligation to shared Payment, P15 refund, P17 settlement and P18 payout state separately.
6. Publish only the aggregate report. Do not export named non-contributors or private evidence.
7. Keep collection unarchived until liabilities are resolved or an approved carry-forward is recorded.
8. Family payout execution and settlement are prohibited while P18 AD-14, AD-10 and LC14 remain unresolved.

