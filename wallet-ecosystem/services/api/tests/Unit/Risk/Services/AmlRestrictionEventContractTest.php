<?php

declare(strict_types=1);

namespace Tests\Unit\Risk\Services;

use App\Modules\Risk\Domain\Events\AmlRestrictionAppliedEvent;
use App\Modules\Risk\Domain\Events\AmlRestrictionLiftedEvent;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

final class AmlRestrictionEventContractTest extends TestCase
{
    public function test_aml_event_classes_preserve_named_timestamp_and_payload_contracts(): void
    {
        $time = CarbonImmutable::parse('2026-10-15T00:00:00Z');
        $applied = new AmlRestrictionAppliedEvent(holdId: 'hold', actorId: 'actor', correlationId: 'correlation', userId: 'user', restrictions: ['aml_freeze'], auditEventId: 'audit', occurredAt: $time);
        $lifted = new AmlRestrictionLiftedEvent(holdId: 'hold', actorId: 'actor', correlationId: 'correlation', userId: 'user', liftedRestrictions: ['aml_freeze'], auditEventId: 'audit', occurredAt: $time);
        foreach ([$applied, $lifted] as $event) {
            $this->assertSame($time, $event->getOccurredAtTimestamp());
            $this->assertSame($time->toIso8601String(), $event->payload()['occurred_at']);
            $this->assertSame('correlation', $event->correlationId());
        }
        $this->assertSame(['aml_freeze'], $applied->getRestrictions());
        $this->assertSame(['aml_freeze'], $lifted->getLiftedRestrictions());
    }
}
