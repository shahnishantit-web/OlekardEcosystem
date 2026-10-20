# Feature Specification: ADM-P2-001 HTTP Error & System Status Pages
Created:2026-10-16. Status:Approved scope transcribed for implementation. Feature:1001-http-system-status.
Source: docs/admin-p1p2-epic-catalogue.md ADM-P2-001; ADM-FOUND-001; constitution1.0.0. User explicitly authorized this epic.

## User Scenarios & Testing
US-P2-001-001 (P2): Given HTTP429/503/504, admin sees code, friendly message and suggested action. Authenticated429/503 retain shell; standalone503/504 work without shell. Seven scoped routes include401 and offline.
US-P2-001-002 (P2): With MAINTENANCE_MODE=true, visiting any app page is intercepted to maintenance with estimated return time. Authorized superadmin bypass credential skips maintenance without bypassing authentication/MFA/RBAC.
US-P2-001-003 (P2): IE11/EdgeHTML requests see a supported-browser list and download links, without JavaScript errors or blank screen.

## Functional Requirements / Acceptance Criteria
- AC01: All seven routes /401,/429,/503,/504,/maintenance,/offline,/unsupported-browser render accessible code/state, message and meaningful action.
- AC02: Real service-level API429/503/504, including non-JSON proxy responses and authenticated blob downloads, trigger friendly status UI. Existing401 authentication flow remains. Explicit domain errors already handled by a business flow retain their actionable local error presentation per AC09.
- AC03: Authenticated429/503 retain AdminShell; root503/504 function independently of shell. No automatic mutation retry.
- AC04: MAINTENANCE_MODE=true intercepts page navigation, serves maintenance state; flag-off preserves normal routing; assets/status/bypass entry cannot loop.
- AC05: Maintenance displays configured estimated return time, or explicitly unavailable when not configured. Never invent an estimate.
- AC06: Superadmin maintenance bypass is expiring, cryptographically verified and purpose-bound; server cookie HttpOnly/SameSite. Invalid/expired/altered tokens fail. It grants no API, login, MFA or RBAC privilege.
- AC07: Root/edge detects IE11/oldEdge, excludes modern Edg; presents supported browser names and official HTTPS downloads.
- AC08: Unsupported browser response contains no JavaScript and works without JS; root fallback does not require providers.
- AC09: Existing error boundaries, login/MFA/permission gates and business APIs preserved; mobile/keyboard/status states and offline recovery verified.

## Clarifications / Assumptions
No policy version matrix is approved: detect the specifically named legacy engines (MSIE/Trident/EdgeHTML), recommend current Chrome/Edge/Firefox/Safari without inventing minimum versions.
Return-time server config MAINTENANCE_RETURN_AT is an operator-supplied UTC ISO time; unknown explicitly shown. Maintenance bypass is a signed short-lived purpose=maintenance,role=superadmin capability provisioned by trusted operations with server MAINTENANCE_BYPASS_SECRET, supplied by password form/header (never URL), does not assert identity/login. Issuance is outside this UI-only epic; documented operator script signs a bounded token. Anonymous bypass still redirects login.
Maintenance covers UI and Next API requests except explicit bypass endpoint/assets; Laravel API authorization unchanged. Unsupported-browser response takes precedence so its links remain usable during maintenance.
Offline UI handles connectivity changes in already-loaded shell; no service worker or sensitive offline data cache added. Direct offline page supplies recovery guidance; no claim of first-visit offline HTML availability.
Status information is normalized, never raw backend exception/HTML. Correlation sanitized/length-bounded. No financial operation replay on retry.

2026-10-16 authorized regression clarification: the user requested fixing session persistence and FX error handling after browser validation. The existing FX_PROVIDER_UNAVAILABLE JSON503 envelope is a domain configuration failure and remains on the FX submission form. Only this explicit code is opted into by that caller; unknown JSON/HTML503 responses retain the global safe status screen. Large full-permission JWTs use bounded cookie chunks without changing backend authentication, RBAC or MFA contracts.

## Success Criteria
All9 criteria pass automated targeted/regression tests plus real Next middleware/browser checks, desktop/mobile visual inspection. All3 mandatory stories done; otherwise PARTIAL/BLOCKED. No next epic.
## Entities
Ephemeral system status and server maintenance configuration only; no database or financial model changes.
