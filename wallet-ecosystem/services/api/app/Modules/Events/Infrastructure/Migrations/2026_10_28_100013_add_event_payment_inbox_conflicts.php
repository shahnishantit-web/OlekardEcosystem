<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'platform';

    public function up(): void
    {
        $db = Schema::connection('community_services');
        if (! $db->hasTable('community_services.event_payment_inbox_conflicts')) {
            $db->create('community_services.event_payment_inbox_conflicts', static function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('payment_intent_id')->index();
                $table->char('payload_hash', 64);
                $table->jsonb('financial_facts');
                $table->jsonb('verified_evidence')->nullable();
                $table->timestampTz('received_at');
                $table->unique(['payment_intent_id', 'payload_hash'], 'event_payment_inbox_conflict_uq');
            });
        }
    }

    public function down(): void
    {
        Schema::connection('community_services')->dropIfExists('community_services.event_payment_inbox_conflicts');
    }
};
