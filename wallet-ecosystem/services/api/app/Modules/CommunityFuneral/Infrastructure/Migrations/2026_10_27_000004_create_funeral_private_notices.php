<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Ramsey\Uuid\Uuid;

return new class extends Migration
{
    protected $connection = 'platform';

    public function up(): void
    {
        Schema::connection('community_services')->create('funeral_private_notices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('snapshot_household_id');
            $table->uuid('recipient_actor_id');
            $table->string('status', 16)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampsTz();
            $table->unique(['snapshot_household_id', 'recipient_actor_id'], 'funeral_notice_snapshot_recipient_uq');
            $table->index(['status', 'created_at'], 'funeral_notice_status_idx');
        });

        DB::connection('notification')->table('notification.notification_templates')->insertOrIgnore([
            'id' => (string) Uuid::uuid7(),
            'key' => 'funeral.collection.opened', 'category' => 'financial',
            'subject_template' => 'Funeral Assistance collection is open',
            'body_template' => 'A collection for your household is open. Review your private obligation in the OleKard app before deciding whether to contribute.',
            'supported_channels' => json_encode(['in_app']), 'variables' => json_encode([]),
            'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::connection('community_services')->dropIfExists('funeral_private_notices');
        DB::connection('notification')->table('notification.notification_templates')->where('key', 'funeral.collection.opened')->delete();
    }
};
