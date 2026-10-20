<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'platform';

    public function up(): void
    {
        // OutboxEventPublisher accepts IntegrationEventInterface (no aggregate methods)
        // as well as BaseIntegrationEvent (has aggregateId/Type). Basic events
        // legitimately have no aggregate context, so these columns must be nullable.
        DB::connection('platform')->statement(
            'ALTER TABLE platform.outbox_events ALTER COLUMN aggregate_id DROP NOT NULL'
        );

        DB::connection('platform')->statement(
            'ALTER TABLE platform.outbox_events ALTER COLUMN aggregate_type DROP NOT NULL'
        );
    }

    public function down(): void
    {
        DB::connection('platform')->statement(
            'ALTER TABLE platform.outbox_events ALTER COLUMN aggregate_id SET NOT NULL'
        );

        DB::connection('platform')->statement(
            'ALTER TABLE platform.outbox_events ALTER COLUMN aggregate_type SET NOT NULL'
        );
    }
};
