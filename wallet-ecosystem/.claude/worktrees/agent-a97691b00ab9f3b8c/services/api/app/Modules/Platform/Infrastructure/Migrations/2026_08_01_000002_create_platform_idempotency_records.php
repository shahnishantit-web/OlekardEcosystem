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
        Schema::connection('platform')->create('platform.idempotency_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('idempotency_key', 255)->notNull();
            $table->uuid('actor_id')->notNull();
            $table->string('actor_type', 20)->notNull()->default('USER');
            $table->string('request_hash', 64)->notNull();
            $table->string('http_method', 10)->notNull();
            $table->string('http_path', 500)->notNull();
            $table->string('status', 20)->notNull()->default('processing');
            $table->integer('response_status')->nullable();
            $table->jsonb('response_body')->nullable();
            $table->timestamp('processing_started_at')->nullable();
            $table->integer('attempt_count')->notNull()->default(1);
            $table->timestamp('created_at')->notNull()->useCurrent();
            $table->timestamp('updated_at')->notNull()->useCurrent();
            $table->timestamp('expires_at')->notNull();

            $table->unique(['actor_id', 'idempotency_key'], 'idempotency_records_actor_key_unique');
            $table->index('status', 'idempotency_records_status_idx');
            $table->index('processing_started_at', 'idempotency_records_processing_started_at_idx');
            $table->index('updated_at', 'idempotency_records_updated_at_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('platform')->dropIfExists('platform.idempotency_records');
    }
};
