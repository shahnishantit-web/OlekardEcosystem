<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'platform';

    public function up(): void
    {
        $schema = Schema::connection('community_services');
        if (! $schema->hasColumn('community_services.event_drafts', 'max_tickets_per_order')) {
            $schema->table('community_services.event_drafts', function (Blueprint $table): void {
                $table->integer('max_tickets_per_order')->nullable();
            });
        }

        DB::connection('community_services')->statement(
            'ALTER TABLE community_services.event_drafts ALTER COLUMN currency_code DROP NOT NULL',
        );
    }

    public function down(): void
    {
        $drafts = DB::connection('community_services')->table('community_services.event_drafts');
        if ($drafts->whereNull('currency_code')->exists()) {
            throw new RuntimeException('Cannot restore required event currency while drafts have no Finance-assigned currency.');
        }

        DB::connection('community_services')->statement(
            'ALTER TABLE community_services.event_drafts ALTER COLUMN currency_code SET NOT NULL',
        );

        // Keep max_tickets_per_order: it may predate this migration on an existing database.
    }
};
