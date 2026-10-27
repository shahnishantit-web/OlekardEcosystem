<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Platform;

use Tests\TestCase;

/**
 * Full replay cycle requires a live platform DB and a seeded admin actor.
 * Run after `docker compose exec api php artisan migrate --seed`.
 */
final class OutboxReplayFeatureTest extends TestCase
{
    public function test_replay_resets_status_and_writes_audit_record(): void
    {
        $this->markTestSkipped(
            'T023/P11: requires live DB with platform.outbox_events; Docker-gated; '
            . 'validates SC-005 replay audit chain. '
            . 'Expected test body: seed dead_letter row; authenticate as admin with '
            . 'operations.dead_letter.manage; POST /v1/operations/outbox/dead-letters/{id}/replay; '
            . 'assert status=pending; assert platform.audit_logs has action=outbox.dead_letter.replay; '
            . 'assert event re-dispatched on next Horizon cycle.'
        );
    }
}
