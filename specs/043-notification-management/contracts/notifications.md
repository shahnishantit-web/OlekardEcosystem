# Admin Notification Contract
All endpoints /v1/admin/notifications, identity.auth plus per-action permission.
GET templates: notifications.templates.read; response data array of existing templates incl key/channels/language/revision.
PATCH templates: notifications.templates.write; {id UUID, revision int, subject_template string <=500, body_template string <=20000, language? nullable language tag}; 409 stale.
POST templates/preview: read; {subject_template,body_template,variables scalar map}; data {subject,body}; missing variables [Unknown], no executable markup rendering.
POST templates/test: write, throttled 5/min; {id,request_id UUID,variables}; sends via existing service to authenticated actor only, request id dedup.
GET log: notifications.log.read; channel email/sms/push/in_app; status sent/failed/bounced/queued/delivered/permanently_failed; from/to YYYY-MM-DD inclusive UTC; page positive. data rows + meta pagination.
POST retry/{id}: notifications.retry; delivery UUID; failed/permanently_failed only; 409 otherwise, 202 queued.
GET rules: notifications.rules.read; data template_id,event,channel,enabled,mandatory,revision.
PATCH rules: notifications.rules.write; {template_id UUID,channel,enabled bool,revision int}; 409 stale, 422 mandatory disable. Durable NOTIFICATION_RULE_UPDATED includes event id, actor, rule and correlation (no copy).
All mutations persist domain audit and central audit. Permission is never accepted from request body.
