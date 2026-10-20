<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Contracts\Providers;

use App\Modules\Platform\Domain\Exceptions\ProviderException;
use App\Modules\Platform\Domain\Exceptions\WebhookSignatureException;
use App\Modules\Platform\Domain\ValueObjects\Providers\NormalizedWebhookEvent;

/**
 * Port for normalizing provider-specific webhook payloads into platform events.
 *
 * Implementor obligations:
 * 1. Validate the cryptographic signature of the raw payload using $signature
 *    and $headers BEFORE any parsing. Throw WebhookSignatureException if the
 *    signature is absent or invalid — never silently accept unsigned payloads.
 * 2. Return a NormalizedWebhookEvent whose idempotencyKey is stable across
 *    retries of the same delivery (use provider-assigned delivery/event ID, or
 *    a deterministic hash of $rawPayload and a stable provider key constant).
 * 3. Set NormalizedWebhookEvent::$platformEntityId to null (not throw) when
 *    the ExternalReferenceMapping lookup returns no match — deferred resolution
 *    applies per FR-E1000-019.
 * 4. Preserve $rawPayload verbatim in the returned NormalizedWebhookEvent.
 * 5. Never log or surface provider credentials, OTP values, or PII from the
 *    raw payload in audit events, logs, or the NormalizedWebhookEvent fields.
 * 6. This contract is ONLY for parsing/normalization. E25 owns the HTTP
 *    receiver layer (controller, routing, middleware). Do not introduce
 *    Laravel HTTP coupling here.
 *
 * @see NormalizedWebhookEvent
 */
interface ProviderWebhookContract
{
    /**
     * Parse and normalize a raw provider webhook payload.
     *
     * @param  string               $rawPayload  Raw request body bytes as received from the provider.
     * @param  string               $signature   Provider-supplied cryptographic signature (typically
     *                                           a single header value, e.g., X-Signature or X-Hub-Signature).
     * @param  array<string,string> $headers     All HTTP headers forwarded from the E25 receiver
     *                                           (for providers that distribute signature material
     *                                           across multiple header fields).
     *
     * @throws WebhookSignatureException  When signature validation fails.
     * @throws ProviderException          When the payload is structurally invalid.
     *
     * @return NormalizedWebhookEvent  Parsed event; platformEntityId may be null when unresolvable.
     */
    public function parse(
        string $rawPayload,
        string $signature,
        array $headers,
    ): NormalizedWebhookEvent;
}
