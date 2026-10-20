# UI and middleware contract
Public exactroutes:/401,/429,/503,/504,/maintenance,/offline,/unsupported-browser. Authenticated429/503 can use existingAdminShell; anonymous503/504 rootonly.
MAINTENANCE_MODE=true =>503rewrite /maintenance; Next API =>503JSON except POST /api/system/maintenance-bypass. Cache-Control:no-store. MAINTENANCE_RETURN_AT optionalUTCISOtime. Header X-Maintenance-Bypass or HttpOnlycookie maintenance_bypass signedcapability. Noquerytokens.
POST /api/system/maintenance-bypass: sameoriginform token max2048, valid purpose/role/HMAC/expiry =>303dashboard+HttpOnlycookie; otherwise403. Doesnotsetauthcookies/permissions.
LegacyUA response: status200text/html,no scripts, no-store and browserdownloadlinks. Offline changesrenderinloadedshell, no firstvisitofflineavailabilitypromise.
