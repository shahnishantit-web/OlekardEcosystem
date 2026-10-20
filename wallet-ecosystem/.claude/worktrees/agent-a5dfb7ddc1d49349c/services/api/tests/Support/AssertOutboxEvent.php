<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

trait AssertOutboxEvent
{
    public function assertOutboxEventPublished(string $eventName, array $payloadSubset = []): void
    {
        $rows = DB::connection('platform')
            ->table('platform.outbox_events')
            ->where('event_name', $eventName)
            ->get();

        $this->assertGreaterThan(
            0,
            $rows->count(),
            "Expected outbox event '{$eventName}' to be published, but none was found.",
        );

        if (! empty($payloadSubset)) {
            $matched = $rows->first(function ($row) use ($payloadSubset) {
                $payload = json_decode($row->payload, true) ?? [];
                foreach ($payloadSubset as $key => $value) {
                    if (($payload[$key] ?? null) !== $value) {
                        return false;
                    }
                }
                return true;
            });

            $this->assertNotNull(
                $matched,
                "Outbox event '{$eventName}' found but payload does not contain expected subset.",
            );
        }
    }

    public function assertOutboxEventNotPublished(string $eventName): void
    {
        $count = DB::connection('platform')
            ->table('platform.outbox_events')
            ->where('event_name', $eventName)
            ->count();

        $this->assertEquals(
            0,
            $count,
            "Expected no outbox event '{$eventName}', but one was found.",
        );
    }
}
