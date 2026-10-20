# Contracts
All Admin aliases /v1/admin require identity.auth and integrations.manage, matching E25 FR007a.
GET/POST integrations/api-clients: paginated safe clients / create {name,permissions[],integration_id,ip_whitelist[],rate_limit_per_minute}; 201 data with api_key/raw_secret once; Cache-Control no-store.
GET integrations/api-clients/{id}: safe detail, never secret/hash.
POST integrations/api-clients/{id}/rotate: existing service; 200 one-time secret; 409 revoked.
DELETE integrations/api-clients/{id}: terminal revoke, repeat-safe.
GET integrations/providers: safe provider records and attributable health, timestamp; paginate.
GET integrations/providers/{id}/health: 24 UTC buckets of observed healthy-check percent, sample counts, recent safe structured errors.
GET webhooks/deliveries: status delivered/failed/retrying and event_type; endpoint derived inbound receiver, attempts, last_attempt. Paginated25.
POST webhooks/deliveries/{id}/retry: failed/dead_letter only;202 durable intent;409 queued/processed.
Legacy /v1/integrations endpoints unchanged and benefit from same security/retry protections.
Health contracts: Integration inventory targets active integration adapters; Ops history records per-id checks and returns safe aggregate. Unsupported probes yield no invented observations.
