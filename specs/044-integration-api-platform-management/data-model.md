# Data Model
integration.api_clients: nullable integration_id UUID FK, ip_whitelist JSONB default[], revoked_at nullable UTC. Existing key/hash/status fields preserved; secret_hash hidden.
integration.admin_retry_intents: UUID id, webhook_event_id, created_at, dispatched_at nullable; pending index.
integration.admin_actions: UUID id, actor_id, action, resource_id, correlation_id, metadata JSON, timestamp; no secrets/payload.
integration.webhook_events: processing_generation integer default0; automatic retry job carries generation, manual retry increments and resets retry_count.
ops.provider_health_checks: nullable integration_id UUID and (integration_id,checked_at) index; old type-only samples retained.
Credential state: active/inactive legacy; revoked_at makes terminal inactive. Retry: failed/dead_letter -> received -> processing -> processed|failed/dead_letter; completed jobs no-op.
