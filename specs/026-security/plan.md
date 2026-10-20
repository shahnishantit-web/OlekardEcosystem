# Implementation Plan — E26 Security Hardening

## Constitution Check

- [x] Approved Laravel/Next.js/Flutter stack respected — no new stack additions; all implementation uses existing PHP/Laravel, Next.js admin, existing modules
- [x] No TBD value invented — secrets manager vendor remains TBD (infrastructure); integration contract defined as env-var injection
- [x] Authorization remains server-side — new `security.findings.read/write` permissions enforced by `AuthorizationGuard`
- [x] Financial integrity preserved — E26 is not a financial feature; no ledger/wallet mutations
- [x] Security/audit requirements incorporated — FR-018 new event types; `SensitiveDataProcessor`; at-rest encryption; findings dashboard
- [x] UX/design-system requirements incorporated — admin portal findings dashboard uses shared design tokens
- [x] QA strategy incorporated — SC-1 through SC-9 in quickstart; OWASP Top 10 checklist; `composer audit` CI gate

## Technical Context

- Backend: Laravel / PHP 8.4+, `wallet-ecosystem/services/api`
- Database: PostgreSQL, `security` schema, `platform` connection
- Queue/cache: Redis — no new queue workers needed for E26
- Web: Next.js + TypeScript, `wallet-ecosystem/apps/admin`
- Mobile: Flutter — no E26 mobile scope (security controls are backend + admin portal)
- API: REST / OpenAPI — `contracts/security-admin-api.yaml`
- Testing: PHPUnit/Pest feature + unit tests; security-specific test classes

## Key Architecture Decisions (see research.md for rationale)

| Topic | Decision |
|-------|----------|
| Secure headers | Extend existing `SecurityHeadersMiddleware` — add `Permissions-Policy`; make CSP configurable |
| CSRF | Bearer token auth already CSRF-resistant; no CSRF token middleware needed |
| At-rest encryption | Laravel `'encrypted'` Eloquent cast on designated fields |
| Log masking | New `SensitiveDataProcessor` Monolog processor in Platform module |
| API masking | Audit & extend existing `PiiMasker` usage in API Resources |
| Brute-force | `LockoutService` (E01) already complete; E26 audits endpoint coverage |
| Secrets | Env-var pattern; add startup fail-closed assertion in `AppServiceProvider` |
| New module | `App\Modules\Security\` — `VulnerabilityFinding` entity + admin CRUD only |
| Audit events | Extend existing `AuditService` with 5 new security action types |
| Vuln scanning | `composer audit` in CI + `VulnerabilityFinding` admin table |

## New Module Structure: `App\Modules\Security\`

```
app/Modules/Security/
├── Domain/
│   ├── Contracts/
│   │   └── VulnerabilityFindingRepositoryInterface.php
│   ├── Enums/
│   │   ├── VulnerabilityFindingSeverity.php
│   │   └── VulnerabilityFindingStatus.php
│   └── ValueObjects/
│       └── SecurityAuditActions.php          ← new audit action type constants
├── Application/
│   └── Services/
│       └── VulnerabilityFindingService.php   ← create, list, updateStatus
├── Http/
│   ├── Controllers/
│   │   └── SecurityAdminController.php
│   ├── Requests/
│   │   ├── CreateFindingRequest.php
│   │   └── UpdateFindingStatusRequest.php
│   └── Resources/
│       └── VulnerabilityFindingResource.php
├── Infrastructure/
│   ├── Models/
│   │   └── VulnerabilityFindingModel.php
│   ├── Migrations/
│   │   └── 2026_10_02_026000_create_security_vulnerability_findings_table.php
│   └── Repositories/
│       └── EloquentVulnerabilityFindingRepository.php
└── Providers/
    └── SecurityServiceProvider.php
```

Routes are loaded from `routes/modules/security.php` (consistent with other modules).

## Cross-Cutting Hardening (not in Security module)

| File | Change |
|------|--------|
| `app/Http/Middleware/SecurityHeadersMiddleware.php` | Add `Permissions-Policy` header; convert CSP to config-driven value |
| `app/Modules/Platform/Infrastructure/Logging/SensitiveDataProcessor.php` | **NEW** — Monolog processor |
| `config/logging.php` | Register `SensitiveDataProcessor` globally |
| `app/Providers/AppServiceProvider.php` | Add secrets boot-check assertion |
| `app/Modules/Identity/Infrastructure/Models/Credential.php` | Add `'value' => 'encrypted'` cast |
| `app/Modules/Kyc/Infrastructure/Models/KycApplicant.php` | Add `'date_of_birth' => 'encrypted'` cast |
| `app/Modules/Kyc/Infrastructure/Models/KycDocument.php` | Add `'document_number' => 'encrypted'`, `'extracted_data' => 'encrypted:array'` casts |
| API Resources (audit) | Verify `PiiMasker` called for account_number, national_id, wallet_number in all resources |
| `app/Modules/Identity/Domain/Services/LockoutService.php` | No changes — verify coverage in auth endpoint tests |

## Admin Portal (Next.js)

New page: `/admin/security/findings` — vulnerability findings list + suppress/resolve actions
New page: `/admin/security/dashboard` — aggregate counts by severity + status

Uses existing admin layout, table components, and design tokens.
Permission guard: `security.findings.read` for view; `security.findings.write` for suppress/resolve.

## New Permissions

| Permission | Description |
|-----------|-------------|
| `security.findings.read` | View vulnerability findings and dashboard |
| `security.findings.write` | Create, suppress, and resolve vulnerability findings |

Seed in `E26SecurityPermissionsSeeder` extending `DatabaseSeeder`.

## Phase 0 — Research ✓ COMPLETE

See `research.md` — all NEEDS CLARIFICATION items resolved.

## Phase 1 — Design ✓ COMPLETE

- `data-model.md` — `security.vulnerability_findings` table + at-rest encryption fields
- `contracts/security-admin-api.yaml` — findings CRUD + dashboard endpoints
- `quickstart.md` — SC-1 through SC-9 + OWASP Top 10 checklist

## Implementation Order

1. **Security module scaffold**: ServiceProvider, enums, migration
2. **Platform hardening**: `SensitiveDataProcessor`, `SecurityHeadersMiddleware` extension, boot check
3. **At-rest encryption**: `encrypted` casts on E01/E05 models + migration for re-encryption if needed
4. **VulnerabilityFinding CRUD**: repository, service, controller, routes
5. **New audit event types**: `SecurityAuditActions` constants + emission in relevant services
6. **API masking audit**: review all API Resources; add missing `PiiMasker` calls
7. **Brute-force coverage audit**: verify `LockoutService` called from all auth endpoints
8. **Admin portal pages**: findings list + dashboard
9. **CI integration**: `composer audit` step
10. **Tests**: unit + feature + security scenario tests
