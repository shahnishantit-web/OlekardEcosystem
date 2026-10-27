<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Platform;

use Tests\TestCase;

/**
 * Full lag accuracy validation requires a seeded outbox with controlled timestamps.
 * Run after `docker compose exec api php artisan migrate --seed`.
 */
final class OutboxQueueHealthFeatureTest extends TestCase
{
    public function test_oldest_pending_age_seconds_reflects_wall_clock_age(): void
    {
        $this->markTestSkipped(
            'T027/P11: requires live DB; Docker-gated; validates SC-004 lag reporting accuracy. '
            . 'Expected test body: insert outbox row with created_at = now() - 10 minutes; '
            . 'assert GET /v1/operations/outbox/health returns oldest_pending_age_seconds between 590 and 610; '
            . 'assert response time < 200ms.'
        );
    }
}
