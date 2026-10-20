# API Contract — KYC Lifecycle Management

**Feature**: E05 — KYC & Identity Verification
**Base path**: `/v1/kyc`
**Auth**: `identity.auth` + `identity.require-permission:{kyc.case.manage}` on reverification

---

## `POST /v1/kyc/cases/{id}/reverify`

Trigger manual reverification of an approved or expired KYC case.

**Required permission**: `kyc.case.manage`

**Request body**: none

**Response** `200 OK`:
```json
{
  "success": true,
  "data": {
    "case_id": "01966b4c-...",
    "status": "initiated",
    "attempt_number": 3
  }
}
```

`422 Unprocessable` — `INVALID_CASE_STATUS` (not `approved` or `expired`).

---

## Artisan Command — `kyc:detect-expired`

Batch job that transitions all `approved` cases with `expires_at < NOW()` to `expired`.

**How to run**:
```bash
php artisan kyc:detect-expired [--batch-size=100] [--dry-run]
```

**Options**:

| Option | Default | Description |
|--------|---------|-------------|
| `--batch-size` | 100 | Cases processed per iteration |
| `--dry-run` | false | Report without writing changes |

**Output example**:
```
KYC expiry detection started.
Processed 47 cases. Expired: 12. Skipped: 35. Errors: 0.
KYC expiry detection complete.
```

**Scheduling**: Registered in `KycServiceProvider::boot()` via Laravel Scheduler:
```php
$this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
    $schedule->command('kyc:detect-expired')->dailyAt('02:00');
});
```

**Behavior for each expired case**:
1. Transition `status` → `expired`
2. Write `kyc.case.expired` audit record
3. Publish `Kyc.Case.Expired` domain event to outbox
4. Log structured event: `kyc.expiry.detected`

**TBD Note**: If `kyc.validity_period_days` is TBD, the command will log a warning and skip all cases rather than incorrectly expiring them.

---

## Internal: `PerformAutomatedVerificationJob`

Not a REST endpoint — a queued job dispatched after submission when `kyc.auto_verify_enabled = true`.

**Queue**: `kyc-verification` (Laravel Horizon)
**Retry**: Up to `kyc.provider_max_retries` times with exponential back-off
**Timeout**: 60 seconds per attempt

**Job behavior**:
1. Load case from `kyc.kyc_cases` (re-fetch to avoid stale data)
2. Verify case is still in `under_review` (guard against race conditions)
3. Resolve `KycVerificationProvider` from container
4. Call `provider->verify($caseData)`
5. Hash raw response: `hash_hmac('sha256', $json, config('app.key'))`
6. Insert `KycVerificationResult` record
7. On `pass`: transition to `approved`, emit `Kyc.Case.Approved` event
8. On `fail`/`manual_review_required`: leave `under_review`, log for manual queue
9. On `error`: retry via job mechanism; log structured error after exhausting retries

**On TBD provider** (when `kyc.auto_verify_enabled = false` or provider not configured):
- Job is never dispatched
- Log warning: `kyc.auto_verify.skipped - provider not configured`
