# Admin security contracts
identity.auth on all routes. Reads security.alerts.read; investigation mutations additionally risk.manage_alerts; freeze risk.freeze_account. Findings read/write unchanged.
GET /v1/admin/security/alerts?severity=&status=open|in_review|resolved&page= returns data/meta.
GET /v1/admin/security/alerts/{id} returns alert, trigger, affected_transactions, entity_history, risk_timeline, notes, related_alerts, active_hold, freeze_requests.
POST /alerts/{id}/{assign|notes|links|resolve|escalate}: version plus note/related_alert_id/outcome/rationale. Outcomes false_positive/confirmed_fraud reuse clear/block.
POST /alerts/{id}/freeze-requests: version/reason, authenticated maker.
POST /alerts/{id}/freeze-requests/{request}/approve: independent authenticated checker; replay returns approved result; no body checker/subject.
GET /v1/admin/security/findings aliases existing findings.
PATCH /v1/security/findings/{id}/status adds acknowledged/in_remediation.
UTC/UUIDv7/correlation; 401/403 authorization, 404 missing, 409 stale/conflict, 422 invalid/terminal.
