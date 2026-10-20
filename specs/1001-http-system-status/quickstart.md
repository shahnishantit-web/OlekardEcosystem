# Verification and operator setup
Use installed Jest/TypeScript/ESLint/Playwright. Tests do not require database/externalservices.
Set MAINTENANCE_MODE=true and optional MAINTENANCE_RETURN_AT UTCISOtimestamp. Configure MAINTENANCE_BYPASS_SECRET fromsecretmanager (>=32randomcharacters). Trustedoperations mint a purpose-bound max1htoken with scripts/maintenance-token.cjs and deliver onlytosuperadmin; paste into maintenanceform orsend X-Maintenance-Bypass. NeverputinURL/logs. Itdoesnotreplaceauthentication.
Run node node_modules/jest/bin/jest.js --runInBand --testPathPattern 'p2001|middleware|auth|error-boundary|AdminShell|api' --watch=false; npx tsc --noEmit; scopedESLint; Playwrightconfigp2001 normalandmaintenanceprojects. Inspect screenshots desktop/mobile.
