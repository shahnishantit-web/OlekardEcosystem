# Research — E25 API & Integration Platform

## D1: Module Boundary and DB Schema

**Decision**: New `App\Modules\Integration\` module; PostgreSQL schema `integration`.

**Rationale**: Follows the established one-module-per-epic pattern. The integration module is extraction-ready per constitution Principle 7. All E25 tables live in the `integration` schema. The `platform` schema is not extended — `Integration` entities are domain-specific, not shared infrastructure.

**Alternatives considered**: Extending the Platform module — rejected because it would conflate platform infrastructure (outbox, idempotency) with domain behaviour (API client lifecycle, webhook pipeline).

---

## D2: Provider Adapter Contracts Already Defined

**Decision**: E25 does NOT create new provider adapter or webhook contracts. The Platform module (`App\Modules\Platform\Domain\Contracts\Providers\`) already defines:

- `ProviderAdapterInterface` — `send(ProviderRequest): ProviderResponse`, `getCapabilities()`, `getProviderIdentifier()`
- `ProviderWebhookContract` — `parse(rawPayload, signature, headers): NormalizedWebhookEvent`
- `NormalizedWebhookEvent` — complete value object for parsed webhook events

E25's role: implement the HTTP **receiver** layer, the `WebhookEvent` persistence/state-machine, the `Integration` configuration registry, and the `ApiClient` authentication layer. Existing modules (Funding, Withdrawal, KYC, Notification) own their specific adapter implementations. E25 registers the `Integration` model as the configuration source for which provider adapter to use.

**Rationale**: Reusing existing contracts prevents interface duplication and keeps the Platform module as the single authority for provider abstractions (Principle 7).

---

## D3: OTP/SMS/Email Adapter Ownership

**Decision**: The Notification module already defines `SmsChannelInterface` and `EmailChannelInterface` with `LogSmsChannel` / `LogEmailChannel` stub implementations. E25 introduces **real implementations** (e.g., `TwilioSmsChannel`, `SendgridEmailChannel`) bound to those existing interfaces. OTP is routed through the SMS channel (an OTP is a specialised SMS message). E25 does not create a separate `OtpChannelInterface`.

**Rationale**: Avoids interface duplication. The Notification module consumers (`DeliverSmsNotificationJob`, `DeliverEmailNotificationJob`) already use the channel interfaces — swapping the implementation is a service-container binding change driven by the `Integration` registry.

---

## D4: API Client Authentication Mechanism

**Decision**: API key + secret pair. Authentication header: `Authorization: Bearer base64(api_key:secret)`. On each request:
1. Decode Base64, split on first `:`, extract `api_key` and `raw_secret`.
2. Look up `ApiClientModel` by `api_key`.
3. Verify `hash_verify(raw_secret, $model->secret_hash)` using `password_verify()` (bcrypt).
4. Reject `401` if client not found, deactivated, or hash mismatch.

Secret storage: `bcrypt` via `password_hash()`. Secret is shown once on creation/rotation and never stored in plaintext. API key is a 32-character random hex string (public). Secret is a 64-character random hex string (hashed before storage).

**Rationale**: HTTP Basic-style credential via Bearer header is simple for callers, compatible with OpenAPI `securitySchemes.ApiKeyAuth`, and avoids the token-exchange round trip of OAuth2 client credentials. Password verify is constant-time, preventing timing attacks.

**Alternatives considered**: HMAC-SHA256 request signing (AWS Signature v4 style) — rejected for MVP due to implementation complexity for API consumers. OAuth2 client credentials — rejected as it adds a token endpoint and short-lived token management; appropriate for future upgrade.

---

## D5: Dual Rate Limiting (Per-Client + Per-IP)

**Decision**: Two independent Redis sorted-set sliding windows evaluated per request:
- `ratelimit:client:{api_client_id}:{window_minute}` — per-client quota (default 60 req/min, configurable)
- `ratelimit:ip:{hashed_ip}:{window_minute}` — per-IP quota (default 300 req/min, configurable)

Uses Lua atomic script: ZADD + ZCOUNT + EXPIRE in a single Redis round trip per window. Either window exceeding quota returns `429 Too Many Requests` with `Retry-After` and `X-RateLimit-Limit` / `X-RateLimit-Remaining` headers. IP is SHA-256 hashed before use as a Redis key (no plaintext IPs in cache keys).

**Rationale**: Laravel's built-in `ThrottleRequests` middleware supports single-key throttling via named rate limiters but does not natively evaluate two independent windows simultaneously. A custom `DualRateLimitMiddleware` wraps the same Redis connection. Per-IP hashing prevents PII exposure in cache keys.

**Alternatives considered**: Fixed-window per client only — rejected as it doesn't catch credential-sharing or DDoS patterns.

---

## D6: Webhook Receiver and Idempotency

**Decision**: Public endpoint `POST /api/v1/webhooks/{providerSlug}` (no `identity.auth` middleware). Per-request flow:
1. Resolve provider slug → `IntegrationModel` (provider must be registered and active, else `404`).
2. Resolve `ProviderWebhookContract` implementation from service container binding keyed by `{providerSlug}`.
3. Call `$contract->parse(rawPayload, signature, headers)` — throws `WebhookSignatureException` on bad signature → `401`.
4. Check `integration.webhook_events` for existing row with `(provider_slug, idempotency_key)` — if found and `status != received`, return `200` immediately (idempotent re-delivery).
5. Persist `WebhookEvent` with `status = received`.
6. Return `200 OK` immediately.
7. Dispatch `ProcessWebhookEventJob` to `webhooks` queue.

**Retry logic**: `ProcessWebhookEventJob` sets status `processing`, calls domain handler, on success sets `processed`. On exception: increments `retry_count`, sets `failed`. `RetryWebhookEventJob` schedules back to `processing` via exponential back-off. After `max_retry_count` exceeded: sets `dead_letter`, emits `WebhookEventDeadLetteredEvent` (triggers alert via E17 notification).

**Rationale**: Returning `200` before handler execution is standard (prevents provider from re-delivering while the platform is still processing). Idempotency at the DB level (unique constraint on `provider_slug + idempotency_key`) is the ground truth for de-duplication, not cache.

---

## D7: Integration (Provider Configuration) Registry

**Decision**: `IntegrationModel` (DB table `integration.integrations`) stores provider type, slug, credentials reference (pointer to platform config key — never raw credentials), endpoint override, and `is_default` flag per provider type. At runtime, `IntegrationRegistry` resolves the active adapter for a given `ProviderType` enum by:
1. Loading the active/default `IntegrationModel` for that type.
2. Resolving the Laravel service container binding keyed by `$model->provider_slug`.

Provider adapter implementations are bound in their owning modules' service providers (e.g., `FundingServiceProvider` binds the payment adapter). E25's `IntegrationServiceProvider` owns the registry lookup logic and the `ApiClient` / `WebhookEvent` persistence.

**Rationale**: Avoids a god-module. Each domain module owns its adapter implementation; E25 provides the discovery and routing layer only.
