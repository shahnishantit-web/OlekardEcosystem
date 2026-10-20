# Admin Support API
All /v1/admin/support, identity.auth + support.investigate; mutations require exact action permission.
GET cases (type,status,priority,date_from,date_to,page); GET cases/{UUID}.
PATCH cases/{UUID}: action note|link|assign|investigate|escalate. POST cases/{UUID}/attachments JSON filename/content_base64 (max configured 10MB, JPEG/PNG/WebP/PDF). GET cases/{UUID}/attachments/{entryUUID} authorized binary download.
POST cases/{UUID}/resolve: resolution_note, support.resolve.
POST cases/{UUID}/refund: amount_minor,reason,step_up_token,confirm optional; dispute.refund, Idempotency-Key UUID, actor/session step-up scope support.refund:{UUID}; 409 conflicting replay/terminal/maker-checker,422 invalid bounds,403 missingstepup.
Response {data:...}; UTC times; no-store on sensitive responses; original E22 endpoints preserved.
