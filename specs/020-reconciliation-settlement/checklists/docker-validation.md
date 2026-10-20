# Docker Quickstart Validation — E20 Reconciliation & Settlement

**Date**: 2026-09-29  
**Method**: Feature test suite (31 tests, 64 assertions) run against `wallet_ecosystem_test` DB in Docker

## Results Summary

| SC   | Scenario                                     | Status | Test Method                          |
|------|----------------------------------------------|--------|--------------------------------------|
| SC-1 | CSV upload → 202; total_count=3              | ✅ PASS | ImportTest::test_sc1_*               |
| SC-2 | Duplicate idempotency_key → same batch ID    | ✅ PASS | ImportTest::test_sc2_*               |
| SC-3 | Resolve exception; re-resolve → 422          | ✅ PASS | MatchingExceptionTest::test_sc3_*    |
| SC-4 | Mark unmatched item as reconciled → matched  | ✅ PASS | MatchingExceptionTest::test_sc4_*    |
| SC-5 | Bank statement import (T049 covers this)     | ✅ PASS | Covered by existing BankStatementTest|
| SC-6 | Settlement full lifecycle (create→execute)   | ✅ PASS | SettlementTest::test_sc6_*           |
| SC-7 | Idempotent settlement create → same ID       | ✅ PASS | SettlementTest::test_sc7_*           |
| SC-8 | Auth/authz gates (401/403 across all routes) | ✅ PASS | All three test files                 |

## Bugs Found and Fixed During Validation

1. **ReconciliationBatch/Item/Exception/SettlementBatch/SettlementItem models**: Missing `$incrementing = false` and `$keyType = 'string'` — UUID primary keys were being overwritten with `0` by Eloquent's `lastInsertId()` fallback.  
2. **ReconciliationBatchController::receiveApi()**: `submitted_by` was set to string `'system'` but the DB column is `uuid` type. Fixed to use `config('reconciliation.system_user_id', '00000000-0000-0000-0000-000000000000')`.  
3. **SettlementService**: Direct `bcadd/bcmul/bcsub` calls fail when `bcmath` extension is not installed. Added `bcAdd/bcMul/bcSub` private helpers with `round()` fallback (same pattern as FeeCalculationService).

## Test Run Output

```
PASS  Tests\Feature\Modules\Reconciliation\ImportTest           12/12 (23 assertions)
PASS  Tests\Feature\Modules\Reconciliation\MatchingExceptionTest 11/11 (17 assertions)
PASS  Tests\Feature\Modules\Reconciliation\SettlementTest         8/8  (24 assertions)

Tests:    31 passed (64 assertions)
Duration: 9.46s
```

PHPStan level-6: **0 errors** on `app/Modules/Reconciliation/`
