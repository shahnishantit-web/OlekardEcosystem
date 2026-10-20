# Raw Query Audit — E26 Security Hardening

**Date**: 2026-10-02
**Scope**: `wallet-ecosystem/services/api/app/` — all `DB::statement`, `DB::unprepared`, `whereRaw`, `selectRaw`, `orderByRaw`, `havingRaw`, `fromRaw` calls.

## Summary

**Result: PASS — Zero injection-risk findings.**

All raw query calls fall into one of two safe categories:

1. **Migration DDL** — `DB::statement()` in `*_table.php` migration files. These are hardcoded schema operations (CREATE SCHEMA, ALTER TABLE ADD CONSTRAINT, CREATE INDEX). No user input is ever passed to these calls. Migrations run in privileged contexts only.

2. **Parameterized bindings** — `whereRaw`, `selectRaw`, `orderByRaw` in application services and repositories. All variable data is passed via Laravel's binding mechanism (second argument array), never via string interpolation.

## Findings Detail

| File | Line | Pattern | Assessment |
|------|------|---------|------------|
| `Modules/Risk/Infrastructure/Http/Controllers/RiskEvaluationController.php` | 156 | `orderByRaw("CASE WHEN applies_to = 'global' THEN 1 ELSE 0 END")` | SAFE — fully hardcoded |
| `Modules/Risk/Infrastructure/Http/Controllers/RiskEvaluationController.php` | 414 | `orderByRaw("CASE WHEN subject_id = ? THEN 0 ELSE 1 END", [$subjectId])` | SAFE — parameterized |
| `Modules/Risk/Application/Services/WatchlistScreenerService.php` | 54–55 | `whereRaw('similarity(...) >= ?', [$normalized, $threshold])` | SAFE — parameterized |
| `Modules/Risk/Application/Services/RuleEngineService.php` | 118 | `orderByRaw("CASE WHEN subject_id = ? THEN 0 ELSE 1 END", [$subjectId])` | SAFE — parameterized |
| `Modules/Reconciliation/Application/Services/ReconciliationMatchingService.php` | 273 | `whereRaw('ABS(amount - ?) < 0.00005', [...])` | SAFE — parameterized |
| `Modules/Audit/Infrastructure/Models/SuspiciousActivityReportModel.php` | 66 | `whereRaw("report_number LIKE ?", ['SAR-'.$date.'-%'])` | SAFE — parameterized; `$date` is internally computed, not user-supplied |
| All `*/Migrations/*_table.php` | various | `DB::statement(...)` | SAFE — hardcoded DDL only |

## Recommendations

- No immediate fixes required.
- Future contributors must use parameterized bindings (never string interpolation) for any `*Raw()` call.
- The `FormRequestCoverageTest` (T020) provides ongoing protection by ensuring all state-changing endpoints are guarded by `FormRequest` validation before values reach the persistence layer.
