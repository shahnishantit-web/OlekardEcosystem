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
        Schema::connection('platform')->create('integration.webhook_processing_attempts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('webhook_event_id');
            $table->integer('attempt_number');
            $table->string('outcome', 16);
            $table->text('error_message')->nullable();
            $table->timestampTz('attempted_at');
        });

        DB::connection('platform')->statement(
            'CREATE INDEX idx_webhook_attempts_event ON integration.webhook_processing_attempts (webhook_event_id, attempted_at)'
        );
    }

    public function down(): void
    {
        Schema::connection('platform')->dropIfExists('integration.webhook_processing_attempts');
    }
};
