<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Services;

use App\Modules\Platform\Domain\Contracts\MetricsCollectorInterface;

/**
 * Emits the 7 approved E01 observability metrics.
 *
 * All labels are bounded and low-cardinality.
 * PII, credentials, OTP codes, tokens, and IDs MUST NOT appear in metric labels.
 * Meter namespace: identity (spec §12; research Decision 10).
 */
final class IdentityMetrics
{
    public function __construct(private readonly MetricsCollectorInterface $collector) {}

    public function authSuccess(string $actorType, string $credentialType): void
    {
        $this->collector->increment('identity.auth.success', [
            'actor_type'      => strtolower($actorType),
            'credential_type' => strtolower($credentialType),
        ]);
    }

    public function authFailure(string $actorType, string $reason): void
    {
        $this->collector->increment('identity.auth.failure', [
            'actor_type' => strtolower($actorType),
            'reason'     => $reason,
        ]);
    }

    /** channel_type and challenge_type are low-cardinality enum values — no identifier. */
    public function otpSent(string $channelType, string $challengeType): void
    {
        $this->collector->increment('identity.otp.sent', [
            'channel_type'   => $channelType,
            'challenge_type' => $challengeType,
        ]);
    }

    public function accountLocked(string $actorType): void
    {
        $this->collector->increment('identity.account.locked', [
            'actor_type' => strtolower($actorType),
        ]);
    }

    /** result is 'success' or 'failure' — low-cardinality, no PII (spec §12). */
    public function tokenRefresh(string $result = 'success'): void
    {
        $this->collector->increment('identity.token.refresh', ['result' => $result]);
    }

    /** triggerType is 'amount_threshold' or 'risk_signal'. */
    public function stepUpTriggered(string $triggerType): void
    {
        $this->collector->increment('identity.step_up.triggered', [
            'trigger_type' => $triggerType,
        ]);
    }

    /**
     * Record authentication hot-path latency in milliseconds.
     * Approved histogram buckets (ms): [50, 100, 200, 300, 500, 750, 1000, 2500].
     * P95 targets: login ≤ 500ms, token_refresh ≤ 200ms, otp_verify ≤ 500ms.
     */
    public function authLatency(float $milliseconds, string $operation): void
    {
        $this->collector->histogram('identity.auth.latency', $milliseconds, [
            'operation' => $operation,
        ]);
    }

    // E03 Device Management Metrics

    public function incrementDeviceRegistered(string $platform): void
    {
        $this->collector->increment('device.registered', ['platform' => strtolower($platform)]);
    }

    public function incrementDeviceRemoved(string $actorType): void
    {
        $this->collector->increment('device.removed', ['actor_type' => strtolower($actorType)]);
    }

    public function incrementDeviceBlocked(string $actorType): void
    {
        $this->collector->increment('device.blocked', ['actor_type' => strtolower($actorType)]);
    }

    public function incrementDeviceChangeDetected(): void
    {
        $this->collector->increment('device.change_detected');
    }

    public function incrementDeviceSuspiciousDetected(string $rule): void
    {
        $this->collector->increment('device.suspicious_detected', ['rule' => $rule]);
    }

    public function incrementNewDeviceNotificationSent(): void
    {
        $this->collector->increment('device.new_device_notification.sent');
    }

    public function incrementNewDeviceNotificationAcknowledged(bool $acknowledged): void
    {
        $this->collector->increment('device.new_device_notification.acknowledged', [
            'acknowledged' => $acknowledged ? 'true' : 'false',
        ]);
    }

    public function incrementNewDeviceNotificationFailed(): void
    {
        $this->collector->increment('device.new_device_notification.failed');
    }
}
