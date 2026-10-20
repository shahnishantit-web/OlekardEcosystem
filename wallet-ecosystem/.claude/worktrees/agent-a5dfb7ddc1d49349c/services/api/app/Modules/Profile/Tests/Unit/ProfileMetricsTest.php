<?php

declare(strict_types=1);

use App\Modules\Platform\Domain\Contracts\MetricsCollectorInterface;
use App\Modules\Profile\Domain\Services\ProfileMetrics;

describe('ProfileMetrics', function (): void {

    it('increments profile.view.success with lower-cased actor_type label', function (): void {
        $collector = Mockery::mock(MetricsCollectorInterface::class);
        $collector->shouldReceive('increment')
            ->once()
            ->with('profile.view.success', ['actor_type' => 'customer']);

        $metrics = new ProfileMetrics($collector);
        $metrics->viewSuccess('CUSTOMER');
    });

    it('increments profile.view.success for ADMIN actor type', function (): void {
        $collector = Mockery::mock(MetricsCollectorInterface::class);
        $collector->shouldReceive('increment')
            ->once()
            ->with('profile.view.success', ['actor_type' => 'admin']);

        $metrics = new ProfileMetrics($collector);
        $metrics->viewSuccess('ADMIN');
    });

    it('does not include any PII in metric labels', function (): void {
        $collector = Mockery::mock(MetricsCollectorInterface::class);
        $collector->shouldReceive('increment')
            ->once()
            ->withArgs(function (string $metric, array $tags): bool {
                // Only actor_type label — no user_id, email, mobile
                return $metric === 'profile.view.success'
                    && array_keys($tags) === ['actor_type']
                    && !array_key_exists('user_id', $tags)
                    && !array_key_exists('email', $tags)
                    && !array_key_exists('mobile', $tags);
            });

        $metrics = new ProfileMetrics($collector);
        $metrics->viewSuccess('CUSTOMER');
    });

    it('increments profile.contact_change.alert_delivery_failed with contact_type label', function (): void {
        $collector = Mockery::mock(MetricsCollectorInterface::class);
        $collector->shouldReceive('increment')
            ->once()
            ->with('profile.contact_change.alert_delivery_failed', ['contact_type' => 'email']);

        $metrics = new ProfileMetrics($collector);
        $metrics->contactChangeAlertDeliveryFailed('email');
    });

    it('increments profile.closure.blocked with blocked_reason label', function (): void {
        $collector = Mockery::mock(MetricsCollectorInterface::class);
        $collector->shouldReceive('increment')
            ->once()
            ->with('profile.closure.blocked', ['blocked_reason' => 'open_wallet_balance']);

        $metrics = new ProfileMetrics($collector);
        $metrics->closureBlocked('open_wallet_balance');
    });

    it('defaults blocked_reason to unknown when no reason supplied', function (): void {
        $collector = Mockery::mock(MetricsCollectorInterface::class);
        $collector->shouldReceive('increment')
            ->once()
            ->with('profile.closure.blocked', ['blocked_reason' => 'unknown']);

        $metrics = new ProfileMetrics($collector);
        $metrics->closureBlocked();
    });

    it('records profile.api.latency histogram with operation label', function (): void {
        $collector = Mockery::mock(MetricsCollectorInterface::class);
        $collector->shouldReceive('histogram')
            ->once()
            ->with('profile.api.latency', 123.4, ['operation' => 'profile.api']);

        $metrics = new ProfileMetrics($collector);
        $metrics->apiLatency('profile.api', 123.4);
    });

    it('increments profile.update.success with both actor_type and fields_changed labels', function (): void {
        $collector = Mockery::mock(MetricsCollectorInterface::class);
        $collector->shouldReceive('increment')
            ->once()
            ->with('profile.update.success', [
                'actor_type'     => 'user',
                'fields_changed' => 'first_name,last_name',
            ]);

        $metrics = new ProfileMetrics($collector);
        $metrics->updateSuccess('USER', ['first_name', 'last_name']);
    });

    it('emits empty fields_changed label when no fields are passed to updateSuccess', function (): void {
        $collector = Mockery::mock(MetricsCollectorInterface::class);
        $collector->shouldReceive('increment')
            ->once()
            ->with('profile.update.success', [
                'actor_type'     => 'admin',
                'fields_changed' => '',
            ]);

        $metrics = new ProfileMetrics($collector);
        $metrics->updateSuccess('ADMIN');
    });

    it('does not include PII in apiLatency operation label', function (): void {
        $collector = Mockery::mock(MetricsCollectorInterface::class);
        $collector->shouldReceive('histogram')
            ->once()
            ->withArgs(function (string $metric, float $value, array $tags): bool {
                return $metric === 'profile.api.latency'
                    && array_keys($tags) === ['operation']
                    && !array_key_exists('user_id', $tags)
                    && !array_key_exists('email', $tags)
                    && !array_key_exists('mobile', $tags);
            });

        $metrics = new ProfileMetrics($collector);
        $metrics->apiLatency('profile.api', 200.0);
    });
});
